<?php
require_once 'includes/bootstrap.php';
require_once 'includes/layout.php';

$user = require_login(['CFO', 'PM']);
$isCfo = is_cfo($user);

$projectWhere = 'p.activo = 1';
$projectParams = [];
if (is_pm($user)) {
    $projectWhere .= ' AND p.id_manager = ?';
    $projectParams[] = (int)$user['id_usuario'];
}
$projects = fetch_all("SELECT p.*, c.nombre AS cliente FROM Proyecto p JOIN Cliente c ON c.id_cliente = p.id_cliente WHERE $projectWhere ORDER BY p.codigo_unico", $projectParams);
$visibleIds = array_map(fn($p) => (int)$p['id_proyecto'], $projects);
$projectsById = [];
foreach ($projects as $p) {
    $projectsById[(int)$p['id_proyecto']] = $p;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$isCfo) {
        flash('danger', 'Solo el CFO puede modificar reglas contables o capitalizar activos.');
        redirect('accounting.php');
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'update_rule') {
        $projectId = (int)($_POST['id_proyecto'] ?? 0);
        $phase = in_array($_POST['fase'] ?? '', ['Investigacion', 'Desarrollo'], true) ? $_POST['fase'] : 'Desarrollo';
        $treatment = in_array($_POST['tratamiento'] ?? '', ['OpEx', 'CapEx'], true) ? $_POST['tratamiento'] : 'OpEx';
        $life = max(1, (int)($_POST['vida_util_meses'] ?? 36));
        if (!in_array($projectId, $visibleIds, true)) {
            flash('danger', 'Proyecto no autorizado.');
        } else {
            q(
                'INSERT INTO ReglaContable (id_proyecto, fase, tratamiento, vida_util_meses, metodo_amortizacion, activo)
                 VALUES (?,?,?,?,"Lineal",1)
                 ON DUPLICATE KEY UPDATE tratamiento = VALUES(tratamiento), vida_util_meses = VALUES(vida_util_meses), activo = 1',
                [$projectId, $phase, $treatment, $life]
            );
            audit('actualizar', 'ReglaContable', $projectId, "Regla $phase -> $treatment ($life meses)");
            flash('success', 'Regla contable actualizada.');
        }
        redirect('accounting.php?proyecto=' . $projectId);
    }

    if ($action === 'capitalize') {
        $projectId = (int)($_POST['id_proyecto'] ?? 0);
        $date = (string)($_POST['fecha_capitalizacion'] ?? date('Y-m-d'));
        $life = max(1, (int)($_POST['vida_util_meses'] ?? 36));
        if (!in_array($projectId, $visibleIds, true)) {
            flash('danger', 'Proyecto no autorizado.');
        } else {
            $accounting = project_accounting($projectId);
            $capex = (float)$accounting['capex'];
            $devRule = fetch_one("SELECT * FROM ReglaContable WHERE id_proyecto = ? AND fase = 'Desarrollo' AND activo = 1", [$projectId]);
            if (!$devRule || $devRule['tratamiento'] !== 'CapEx') {
                flash('danger', 'La fase de Desarrollo debe estar configurada como CapEx antes de capitalizar.');
            } elseif ($capex <= 0) {
                flash('warning', 'Aún no hay costos aprobados en la fase de Desarrollo para capitalizar.');
            } else {
                $monthly = round($capex / $life, 4);
                $existing = fetch_one('SELECT * FROM ActivoIntangible WHERE id_proyecto = ?', [$projectId]);
                if ($existing) {
                    q(
                        'UPDATE ActivoIntangible SET nombre = ?, fecha_capitalizacion = ?, monto_capitalizado = ?, vida_util_meses = ?, amortizacion_mensual = ?, estado = "Activo" WHERE id_activo = ?',
                        ['Software capitalizado · ' . ($projectsById[$projectId]['nombre_proyecto'] ?? ('PRJ-' . $projectId)), $date, $capex, $life, $monthly, $existing['id_activo']]
                    );
                    update_asset_amortization((int)$existing['id_activo']);
                    audit('actualizar', 'ActivoIntangible', (int)$existing['id_activo'], 'Activo intangible recalculado');
                    flash('success', 'Activo intangible actualizado con el costo capitalizable actual.');
                } else {
                    q(
                        'INSERT INTO ActivoIntangible (id_proyecto, nombre, fecha_capitalizacion, monto_capitalizado, vida_util_meses, amortizacion_mensual, amortizacion_acumulada, estado) VALUES (?,?,?,?,?,?,0,"Activo")',
                        [$projectId, 'Software capitalizado · ' . ($projectsById[$projectId]['nombre_proyecto'] ?? ('PRJ-' . $projectId)), $date, $capex, $life, $monthly]
                    );
                    $assetId = (int)db()->lastInsertId();
                    update_asset_amortization($assetId);
                    audit('crear', 'ActivoIntangible', $assetId, "Capitalización IAS 38 por $capex USD");
                    flash('success', 'Desarrollo capitalizado como activo intangible conforme a IAS 38.');
                }
            }
        }
        redirect('accounting.php?proyecto=' . $projectId);
    }

    if ($action === 'recalc') {
        $count = 0;
        foreach (fetch_all('SELECT id_activo FROM ActivoIntangible') as $asset) {
            update_asset_amortization((int)$asset['id_activo']);
            $count++;
        }
        audit('actualizar', 'ActivoIntangible', null, "Recálculo de amortización de $count activos");
        flash('success', "Amortización recalculada en $count activo(s).");
        redirect('accounting.php');
    }

    if ($action === 'remove_asset') {
        $assetId = (int)($_POST['id_activo'] ?? 0);
        $asset = fetch_one('SELECT * FROM ActivoIntangible WHERE id_activo = ?', [$assetId]);
        if ($asset && in_array((int)$asset['id_proyecto'], $visibleIds, true)) {
            q('DELETE FROM ActivoIntangible WHERE id_activo = ?', [$assetId]);
            audit('eliminar', 'ActivoIntangible', $assetId, 'Activo intangible eliminado');
            flash('success', 'Activo intangible eliminado. El costo vuelve a tratarse según las reglas vigentes.');
        }
        redirect('accounting.php');
    }
}

$filterId = (int)($_GET['proyecto'] ?? 0);
$rows = [];
$totalOpex = 0.0;
$totalCapex = 0.0;
$totalLabor = 0.0;
$totalCloud = 0.0;
foreach ($projects as $project) {
    $pid = (int)$project['id_proyecto'];
    if ($filterId && $pid !== $filterId) {
        continue;
    }
    $accounting = project_accounting($pid);
    $rules = fetch_all('SELECT * FROM ReglaContable WHERE id_proyecto = ? AND activo = 1', [$pid]);
    $ruleMap = [];
    foreach ($rules as $rule) {
        $ruleMap[$rule['fase']] = $rule;
    }
    $asset = fetch_one('SELECT * FROM ActivoIntangible WHERE id_proyecto = ?', [$pid]);
    $row = [
        'project' => $project,
        'accounting' => $accounting,
        'rules' => $ruleMap,
        'asset' => $asset,
    ];
    $rows[] = $row;
    $totalOpex += (float)$accounting['opex'];
    $totalCapex += (float)$accounting['capex'];
    foreach ($accounting['fases'] as $phase) {
        $totalLabor += (float)$phase['labor'];
        $totalCloud += (float)$phase['cloud'];
    }
}
$assets = fetch_all(
    'SELECT a.*, p.codigo_unico, p.nombre_proyecto FROM ActivoIntangible a JOIN Proyecto p ON p.id_proyecto = a.id_proyecto
     WHERE a.id_proyecto IN (' . implode(',', array_map('intval', $visibleIds ?: [0])) . ') ORDER BY a.fecha_capitalizacion DESC'
);
$assetTotal = array_sum(array_map(fn($a) => (float)$a['monto_capitalizado'], $assets));
$accumulatedTotal = array_sum(array_map(fn($a) => (float)$a['amortizacion_acumulada'], $assets));
$monthlyTotal = array_sum(array_map(fn($a) => (float)$a['amortizacion_mensual'], $assets));
$chartLabels = array_map(fn($r) => $r['project']['codigo_unico'], $rows);
$chartOpex = array_map(fn($r) => round((float)$r['accounting']['opex'], 2), $rows);
$chartCapex = array_map(fn($r) => round((float)$r['accounting']['capex'], 2), $rows);

render_header('Contabilidad IAS 38', $user, 'accounting');
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
        <h2 class="section-title">Tratamiento contable y capitalización</h2>
        <div class="section-sub">Separación OpEx / CapEx, activos intangibles y amortización conforme a IAS 38 / NIIF</div>
    </div>
    <?php if ($isCfo): ?>
        <form method="post" data-confirm="Se recalculará la amortización de todos los activos. ¿Continuar?">
            <?= csrf_field() ?><input type="hidden" name="action" value="recalc">
            <button class="btn btn-ghost btn-sm" type="submit"><i class="bi bi-arrow-repeat"></i> Recalcular amortización</button>
        </form>
    <?php endif; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="kpi-card" style="--accent:#e11d48;--accent-soft:#ffe4e6">
            <div class="kpi-label">OpEx · investigación</div>
            <div class="kpi-value"><?= fmt_money_short($totalOpex) ?></div>
            <div class="kpi-foot"><i class="bi bi-journal-arrow-down"></i> Gastado contra resultados</div>
            <div class="kpi-icon"><i class="bi bi-journal-arrow-down"></i></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card" style="--accent:#4f46e5;--accent-soft:#eef2ff">
            <div class="kpi-label">CapEx · desarrollo</div>
            <div class="kpi-value"><?= fmt_money_short($totalCapex) ?></div>
            <div class="kpi-foot"><i class="bi bi-journal-arrow-up"></i> Activado como intangible</div>
            <div class="kpi-icon"><i class="bi bi-journal-arrow-up"></i></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card" style="--accent:#16a34a;--accent-soft:#dcfce7">
            <div class="kpi-label">Valor neto de activos</div>
            <div class="kpi-value"><?= fmt_money_short($assetTotal - $accumulatedTotal) ?></div>
            <div class="kpi-foot"><i class="bi bi-safe"></i> <?= count($assets) ?> activo(s) intangible(es)</div>
            <div class="kpi-icon"><i class="bi bi-safe"></i></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card" style="--accent:#d97706;--accent-soft:#fef3c7">
            <div class="kpi-label">Amortización mensual</div>
            <div class="kpi-value"><?= fmt_money_short($monthlyTotal) ?></div>
            <div class="kpi-foot"><i class="bi bi-calendar-range"></i> Acumulada <?= fmt_money_short($accumulatedTotal) ?></div>
            <div class="kpi-icon"><i class="bi bi-calendar-range"></i></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><span>OpEx vs. CapEx por proyecto</span><span class="section-sub">USD</span></div>
            <div class="card-body"><div class="chart-box"><canvas id="accountingChart"></canvas></div></div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><span>Composición del costo directo</span><span class="section-sub">Fase acumulada</span></div>
            <div class="card-body">
                <div class="stat-row"><span>Mano de obra aprobada</span><strong><?= fmt_money($totalLabor) ?></strong></div>
                <div class="stat-row"><span>Cloud / APIs imputados</span><strong><?= fmt_money($totalCloud) ?></strong></div>
                <div class="stat-row"><span>Costo directo total</span><strong><?= fmt_money($totalLabor + $totalCloud) ?></strong></div>
                <div class="stat-row"><span>Tratado como OpEx</span><strong class="text-negative"><?= fmt_money($totalOpex) ?></strong></div>
                <div class="stat-row"><span>Tratado como CapEx</span><strong class="text-positive"><?= fmt_money($totalCapex) ?></strong></div>
                <div class="mt-3 p-3 rounded" style="background:#f8fafc;border:1px solid var(--cl-line)">
                    <div class="small text-muted"><i class="bi bi-info-circle"></i> IAS 38: la fase de investigación se reconoce como gasto. La fase de desarrollo puede activarse si el proyecto es técnicamente viable, tiene intención y capacidad de completarse, y generará beneficios económicos futuros.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<form class="filter-bar mb-4" method="get">
    <div class="row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label">Proyecto</label>
            <select class="form-select form-select-sm" name="proyecto">
                <option value="">Todos los proyectos visibles</option>
                <?php foreach ($projects as $p): ?>
                    <option value="<?= (int)$p['id_proyecto'] ?>" <?= $filterId === (int)$p['id_proyecto'] ? 'selected' : '' ?>><?= e($p['codigo_unico']) ?> · <?= e($p['nombre_proyecto']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button class="btn btn-primary btn-sm" type="submit">Filtrar</button>
            <a href="accounting.php" class="btn btn-ghost btn-sm"><i class="bi bi-arrow-counterclockwise"></i> Limpiar</a>
        </div>
    </div>
</form>

<div class="card mb-4">
    <div class="card-header"><span>Reglas de capitalización y costos por fase</span><span class="section-sub"><?= $isCfo ? 'Configurables por proyecto' : 'Solo lectura · los cambios los realiza el CFO' ?></span></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Proyecto</th><th>Fase</th><th class="num">Costo acumulado</th><th>Tratamiento</th><th class="num">Vida útil</th><th class="num">CapEx estimado</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                $project = $row['project'];
                foreach (['Investigacion', 'Desarrollo'] as $phase):
                    $detail = $row['accounting']['fases'][$phase];
                    $rule = $row['rules'][$phase] ?? null;
                    $treatment = $detail['tratamiento'];
                ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= e($project['codigo_unico']) ?></div>
                            <div class="text-muted small"><?= e($project['nombre_proyecto']) ?></div>
                        </td>
                        <td><span class="tag"><?= e($phase) ?></span></td>
                        <td class="num"><?= fmt_money($detail['total']) ?></td>
                        <td>
                            <?php if ($isCfo): ?>
                                <form method="post" class="d-flex gap-1 align-items-center" data-confirm-change="¿Cambiar la regla contable? Afecta la clasificación OpEx/CapEx y el cálculo de amortización.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_rule">
                                    <input type="hidden" name="id_proyecto" value="<?= (int)$project['id_proyecto'] ?>">
                                    <input type="hidden" name="fase" value="<?= e($phase) ?>">
                                    <input type="hidden" name="vida_util_meses" value="<?= (int)($detail['vida_util_meses'] ?: 36) ?>">
                                    <select class="form-select form-select-sm" name="tratamiento" style="width:auto">
                                        <option <?= $treatment === 'OpEx' ? 'selected' : '' ?>>OpEx</option>
                                        <option <?= $treatment === 'CapEx' ? 'selected' : '' ?>>CapEx</option>
                                    </select>
                                </form>
                            <?php else: ?>
                                <span class="badge text-bg-<?= $treatment === 'CapEx' ? 'success' : 'danger' ?> badge-soft"><?= e($treatment) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="num">
                            <?php if ($isCfo): ?>
                                <form method="post" class="d-flex justify-content-end" data-confirm-change="¿Cambiar la regla contable? Afecta la clasificación OpEx/CapEx y el cálculo de amortización.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_rule">
                                    <input type="hidden" name="id_proyecto" value="<?= (int)$project['id_proyecto'] ?>">
                                    <input type="hidden" name="fase" value="<?= e($phase) ?>">
                                    <input type="hidden" name="tratamiento" value="<?= e($treatment) ?>">
                                    <input type="number" min="1" max="120" class="form-control form-control-sm text-end" style="width:76px" name="vida_util_meses" value="<?= (int)($detail['vida_util_meses'] ?: 36) ?>">
                                </form>
                            <?php else: ?>
                                <?= (int)$detail['vida_util_meses'] ?> m
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= $treatment === 'CapEx' ? fmt_money($detail['total']) : '—' ?></td>
                        <td class="text-end">
                            <?php if ($isCfo && $phase === 'Desarrollo' && $treatment === 'CapEx' && $detail['total'] > 0): ?>
                                <form method="post" data-confirm="¿Capitalizar el costo de desarrollo de este proyecto como activo intangible?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="capitalize">
                                    <input type="hidden" name="id_proyecto" value="<?= (int)$project['id_proyecto'] ?>">
                                    <input type="hidden" name="fecha_capitalizacion" value="<?= date('Y-m-d') ?>">
                                    <input type="hidden" name="vida_util_meses" value="<?= (int)($detail['vida_util_meses'] ?: 36) ?>">
                                    <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-arrow-up-circle"></i> Capitalizar</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="7"><div class="empty-state"><i class="bi bi-journal-x"></i>No hay proyectos visibles.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><span>Registro de activos intangibles y amortización</span><span class="section-sub">Método lineal</span></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr><th>Activo</th><th>Proyecto</th><th>Capitalización</th><th class="num">Monto</th><th class="num">Vida útil</th><th class="num">Cuota mensual</th><th class="num">Amortizado</th><th class="num">Valor neto</th><th>Estado</th><?php if ($isCfo): ?><th></th><?php endif; ?></tr>
            </thead>
            <tbody>
            <?php foreach ($assets as $asset): ?>
                <?php
                $net = (float)$asset['monto_capitalizado'] - (float)$asset['amortizacion_acumulada'];
                $progress = (float)$asset['monto_capitalizado'] > 0 ? (float)$asset['amortizacion_acumulada'] / (float)$asset['monto_capitalizado'] : 0;
                ?>
                <tr>
                    <td><div class="fw-semibold"><?= e($asset['nombre']) ?></div><div class="text-muted small">ID <?= (int)$asset['id_activo'] ?></div></td>
                    <td><div class="fw-semibold"><?= e($asset['codigo_unico']) ?></div><div class="text-muted small"><?= e($asset['nombre_proyecto']) ?></div></td>
                    <td class="text-muted small"><?= fmt_date($asset['fecha_capitalizacion']) ?></td>
                    <td class="num"><?= fmt_money($asset['monto_capitalizado']) ?></td>
                    <td class="num"><?= (int)$asset['vida_util_meses'] ?> m</td>
                    <td class="num"><?= fmt_money($asset['amortizacion_mensual']) ?></td>
                    <td class="num" style="min-width:130px">
                        <?= fmt_money($asset['amortizacion_acumulada']) ?>
                        <div class="progress mt-1"><div class="progress-bar bg-primary" style="width:<?= min(100, $progress * 100) ?>%"></div></div>
                    </td>
                    <td class="num fw-semibold"><?= fmt_money($net) ?></td>
                    <td><?= status_badge($asset['estado']) ?></td>
                    <?php if ($isCfo): ?>
                        <td class="text-end">
                            <form method="post" data-confirm="¿Eliminar este activo? El costo volverá a las reglas contables.">
                                <?= csrf_field() ?><input type="hidden" name="action" value="remove_asset"><input type="hidden" name="id_activo" value="<?= (int)$asset['id_activo'] ?>">
                                <button class="btn btn-sm btn-ghost" type="submit"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$assets): ?><tr><td colspan="<?= $isCfo ? 10 : 9 ?>"><div class="empty-state"><i class="bi bi-journal-x"></i>No hay activos intangibles capitalizados. Configura Desarrollo como CapEx y usa “Capitalizar”.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('accountingChart');
    if (!canvas) return;
    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: <?= json_encode($chartLabels) ?>,
            datasets: [
                { label: 'OpEx', data: <?= json_encode($chartOpex) ?>, backgroundColor: 'rgba(225,29,72,.72)', borderRadius: 5 },
                { label: 'CapEx', data: <?= json_encode($chartCapex) ?>, backgroundColor: 'rgba(79,70,229,.8)', borderRadius: 5 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: { x: { stacked: true }, y: { stacked: true, ticks: { callback: v => 'USD ' + v } } }
        }
    });
});
</script>
<?php
render_footer();

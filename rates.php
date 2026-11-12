<?php
require_once 'includes/bootstrap.php';
require_once 'includes/layout.php';

$user = require_login(['CFO', 'PM']);
$isCfo = is_cfo($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isCfo) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'update_ficha') {
        $id = (int)($_POST['id_usuario'] ?? 0);
        $exist = fetch_one('SELECT * FROM FichaSalarial WHERE id_usuario = ?', [$id]);
        $data = [
            in_array($_POST['modalidad_contratacion'] ?? '', ['Interno', 'Contractor', 'Agencia'], true) ? $_POST['modalidad_contratacion'] : 'Interno',
            (float)($_POST['salario_base'] ?? 0),
            (float)($_POST['prestaciones_sociales'] ?? 0),
            (float)($_POST['impuestos_patronales'] ?? 0),
            max(1, (float)($_POST['horas_laborales_mes'] ?? 160)),
            in_array($_POST['moneda'] ?? '', ['USD', 'EUR', 'VES', 'COP'], true) ? $_POST['moneda'] : 'USD',
            $_POST['vigente_desde'] ?? date('Y-m-d'),
        ];
        if ($exist) {
            q('UPDATE FichaSalarial SET modalidad_contratacion = ?, salario_base = ?, prestaciones_sociales = ?, impuestos_patronales = ?, horas_laborales_mes = ?, moneda = ?, vigente_desde = ? WHERE id_usuario = ?', array_merge($data, [$id]));
        } else {
            q('INSERT INTO FichaSalarial (modalidad_contratacion, salario_base, prestaciones_sociales, impuestos_patronales, horas_laborales_mes, moneda, vigente_desde, id_usuario) VALUES (?,?,?,?,?,?,?,?)', array_merge($data, [$id]));
        }
        audit('actualizar', 'FichaSalarial', $id, 'Ficha salarial actualizada');
        flash('success', 'Ficha salarial actualizada.');
        redirect('rates.php?usuario=' . $id);
    }

    if ($action === 'add_costo') {
        $id = (int)($_POST['id_usuario'] ?? 0);
        $concept = trim((string)($_POST['concepto'] ?? ''));
        if ($concept !== '') {
            q(
                'INSERT INTO CostoAsignadoUsuario (id_usuario, concepto, categoria, monto_mensual, vida_util_meses, activo) VALUES (?,?,?,?,?,1)',
                [$id, $concept, in_array($_POST['categoria'] ?? '', ['Licencia', 'Hardware', 'Beneficio', 'Otro'], true) ? $_POST['categoria'] : 'Licencia', (float)($_POST['monto_mensual'] ?? 0), (int)($_POST['vida_util_meses'] ?? 0) ?: null]
            );
            audit('crear', 'CostoAsignadoUsuario', (int)db()->lastInsertId(), "Costo asignado a usuario $id");
            flash('success', 'Costo indirecto agregado.');
        }
        redirect('rates.php?usuario=' . $id);
    }

    if ($action === 'delete_costo') {
        $id = (int)($_POST['id_costo'] ?? 0);
        $cost = fetch_one('SELECT * FROM CostoAsignadoUsuario WHERE id_costo = ?', [$id]);
        if ($cost) {
            q('DELETE FROM CostoAsignadoUsuario WHERE id_costo = ?', [$id]);
            audit('eliminar', 'CostoAsignadoUsuario', $id, 'Costo indirecto eliminado');
            flash('success', 'Costo eliminado.');
            redirect('rates.php?usuario=' . (int)$cost['id_usuario']);
        }
        redirect('rates.php');
    }

    if ($action === 'update_tarifa') {
        $role = in_array($_POST['rol'] ?? '', ['Junior', 'Senior', 'Architect'], true) ? $_POST['rol'] : 'Senior';
        $modality = in_array($_POST['modalidad_contratacion'] ?? '', ['Interno', 'Contractor', 'Agencia'], true) ? $_POST['modalidad_contratacion'] : 'Interno';
        $rate = (float)($_POST['monto_hora'] ?? 0);
        q(
            'INSERT INTO TarifaVenta (rol, modalidad_contratacion, monto_hora, moneda, vigente_desde) VALUES (?,?,?,"USD",?)
             ON DUPLICATE KEY UPDATE monto_hora = VALUES(monto_hora)',
            [$role, $modality, $rate, date('Y-m-d')]
        );
        audit('actualizar', 'TarifaVenta', null, "Tarifa $role/$modality actualizada a $rate");
        flash('success', 'Matriz de tarifas actualizada.');
        redirect('rates.php');
    }
}

$selectedId = (int)($_GET['usuario'] ?? 0);
$team = fetch_all(
    "SELECT u.*, fs.modalidad_contratacion, fs.salario_base, fs.prestaciones_sociales, fs.impuestos_patronales, fs.horas_laborales_mes, fs.moneda, fs.vigente_desde, fs.id_ficha
     FROM Usuario u LEFT JOIN FichaSalarial fs ON fs.id_usuario = u.id_usuario
     WHERE u.estado_operativo = 1 ORDER BY FIELD(u.rol,'CFO','PM','DEV'), u.nombre_completo"
);
$utilRows = utilization_rows(date('Y-m-01'), date('Y-m-d'), is_pm($user) ? (int)$user['id_usuario'] : null);
$utilById = [];
foreach ($utilRows as $row) { $utilById[(int)$row['id_usuario']] = $row; }
$matrix = rate_matrix();
$costsByUser = [];
foreach (fetch_all('SELECT * FROM CostoAsignadoUsuario WHERE activo = 1 ORDER BY id_usuario, categoria') as $cost) {
    $costsByUser[(int)$cost['id_usuario']][] = $cost;
}
$selected = null;
if ($isCfo && $selectedId) {
    $selected = fetch_one('SELECT * FROM Usuario WHERE id_usuario = ?', [$selectedId]);
}
$totalMonthly = 0.0;
$totalBillableCapacity = 0.0;
$totalPotentialValue = 0.0;
$totalHours = 0.0;
foreach ($team as $member) {
    if ($member['rol'] !== 'DEV') { continue; }
    $rate = fully_loaded_rate((int)$member['id_usuario']);
    $billable = $rate['nivel'] && $member['modalidad_contratacion'] ? (($matrix[$rate['nivel']][$member['modalidad_contratacion']] ?? 0)) : 0;
    $totalMonthly += $rate['mensual'];
    $totalHours += $rate['horas'];
    $totalPotentialValue += $billable * $rate['horas'];
    $totalBillableCapacity += $billable * $rate['horas'];
}
$avgRate = $totalHours > 0 ? $totalMonthly / $totalHours : 0;
$avgBillable = $totalHours > 0 ? $totalPotentialValue / $totalHours : 0;
$monthHoursAll = array_sum(array_map(fn($r) => (float)$r['horas'], $utilRows));
$monthBillableAll = array_sum(array_map(fn($r) => (float)$r['billable'], $utilRows));

render_header('Tarifas y equipo', $user, 'rates');
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
        <h2 class="section-title">Economía de la mano de obra</h2>
        <div class="section-sub"><?= $isCfo ? 'Fully Loaded Rate, costos indirectos y matriz de tarifas de venta' : 'Tarifas de venta y utilización del equipo (sin acceso a nómina)' ?></div>
    </div>
    <?php if ($isCfo): ?><span class="tag"><i class="bi bi-shield-lock"></i> Información salarial confidencial · solo CFO</span><?php endif; ?>
</div>

<?php if ($isCfo): ?>
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="kpi-card" style="--accent:#4f46e5;--accent-soft:#eef2ff"><div class="kpi-label">Costo mensual del equipo</div><div class="kpi-value"><?= fmt_money_short($totalMonthly) ?></div><div class="kpi-foot"><i class="bi bi-people"></i> Incluye cargas y licencias</div><div class="kpi-icon"><i class="bi bi-people"></i></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card" style="--accent:#e11d48;--accent-soft:#ffe4e6"><div class="kpi-label">Fully Loaded Rate promedio</div><div class="kpi-value"><?= fmt_money($avgRate) ?>/h</div><div class="kpi-foot"><i class="bi bi-calculator"></i> Costo real por hora</div><div class="kpi-icon"><i class="bi bi-calculator"></i></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card" style="--accent:#16a34a;--accent-soft:#dcfce7"><div class="kpi-label">Tarifa de venta promedio</div><div class="kpi-value"><?= fmt_money($avgBillable) ?>/h</div><div class="kpi-foot"><i class="bi bi-tag"></i> Potencial a capacidad plena</div><div class="kpi-icon"><i class="bi bi-tag"></i></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card" style="--accent:#0d9488;--accent-soft:#ccfbf1"><div class="kpi-label">Utilización facturable</div><div class="kpi-value"><?= fmt_ratio_pct($monthHoursAll > 0 ? $monthBillableAll / $monthHoursAll : 0) ?></div><div class="kpi-foot"><i class="bi bi-speedometer"></i> <?= fmt_number($monthHoursAll, 1) ?> h registradas este mes</div><div class="kpi-icon"><i class="bi bi-speedometer2"></i></div></div></div>
</div>
<?php else: ?>
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-4"><div class="kpi-card" style="--accent:#4f46e5;--accent-soft:#eef2ff"><div class="kpi-label">Equipo técnico</div><div class="kpi-value"><?= count($utilRows) ?></div><div class="kpi-foot"><i class="bi bi-people"></i> Con horas en tus proyectos</div><div class="kpi-icon"><i class="bi bi-people"></i></div></div></div>
    <div class="col-6 col-xl-4"><div class="kpi-card" style="--accent:#0d9488;--accent-soft:#ccfbf1"><div class="kpi-label">Horas del mes</div><div class="kpi-value"><?= fmt_number($monthHoursAll, 1) ?> h</div><div class="kpi-foot"><i class="bi bi-clock"></i> Aprobadas y facturadas</div><div class="kpi-icon"><i class="bi bi-clock"></i></div></div></div>
    <div class="col-6 col-xl-4"><div class="kpi-card" style="--accent:#16a34a;--accent-soft:#dcfce7"><div class="kpi-label">Utilización facturable</div><div class="kpi-value"><?= fmt_ratio_pct($monthHoursAll > 0 ? $monthBillableAll / $monthHoursAll : 0) ?></div><div class="kpi-foot"><i class="bi bi-graph-up"></i> Sobre horas registradas</div><div class="kpi-icon"><i class="bi bi-graph-up"></i></div></div></div>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-xxl-8">
        <div class="card">
            <div class="card-header"><span>Equipo y tasas</span><span class="section-sub">Costo/hora real vs. tarifa de venta</span></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                    <tr>
                        <th>Colaborador</th><th>Nivel / Modalidad</th>
                        <?php if ($isCfo): ?><th class="num">Costo mensual</th><th class="num">Costo/hora</th><?php endif; ?>
                        <th class="num">Tarifa venta</th>
                        <?php if ($isCfo): ?><th class="num">Margen/hora</th><?php endif; ?>
                        <th class="num">Horas mes</th><th>Utilización</th>
                        <?php if ($isCfo): ?><th></th><?php endif; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($team as $member): ?>
                        <?php
                        if (is_pm($user) && ($member['rol'] !== 'DEV' || !isset($utilById[(int)$member['id_usuario']]))) { continue; }
                        $rate = fully_loaded_rate((int)$member['id_usuario']);
                        $billable = ($matrix[$member['nivel']][$member['modalidad_contratacion'] ?? 'Interno'] ?? 0);
                        $util = $utilById[(int)$member['id_usuario']] ?? null;
                        $marginHour = $billable - $rate['rate'];
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="avatar avatar-sm"><?= e(initials($member['nombre_completo'])) ?></div>
                                    <div><div class="fw-semibold"><?= e($member['nombre_completo']) ?></div><div class="text-muted small"><?= e($member['correo_electronico']) ?> · <?= e($member['rol']) ?></div></div>
                                </div>
                            </td>
                            <td><span class="tag"><?= e($member['nivel']) ?></span><div class="text-muted small mt-1"><?= e($member['modalidad_contratacion'] ?: 'Sin ficha') ?></div></td>
                            <?php if ($isCfo): ?><td class="num"><?= fmt_money_short($rate['mensual']) ?></td><td class="num"><?= fmt_money($rate['rate']) ?></td><?php endif; ?>
                            <td class="num fw-semibold"><?= $billable > 0 ? fmt_money($billable) : '—' ?></td>
                            <?php if ($isCfo): ?><td class="num <?= $marginHour >= 0 ? 'text-positive' : 'text-negative' ?>"><?= $billable > 0 ? fmt_money($marginHour) : '—' ?></td><?php endif; ?>
                            <td class="num"><?= fmt_number($util['horas'] ?? 0, 1) ?></td>
                            <td style="min-width:100px">
                                <?php $uPct = ($util && (float)$util['horas'] > 0) ? (float)$util['billable'] / (float)$util['horas'] : 0; ?>
                                <div class="progress-label"><span><?= fmt_ratio_pct($uPct, 0) ?></span></div>
                                <div class="progress"><div class="progress-bar <?= $uPct >= .7 ? 'bg-success' : ($uPct >= .5 ? 'bg-warning' : 'bg-danger') ?>" style="width:<?= min(100, $uPct * 100) ?>%"></div></div>
                            </td>
                            <?php if ($isCfo): ?>
                                <td class="text-end"><a href="rates.php?usuario=<?= (int)$member['id_usuario'] ?>" class="btn btn-sm btn-ghost"><i class="bi bi-pencil"></i></a></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xxl-4">
        <div class="card mb-3">
            <div class="card-header"><span>Matriz de tarifas de venta</span><span class="section-sub">USD / hora</span></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>Rol</th><th class="num">Interno</th><th class="num">Contractor</th><th class="num">Agencia</th></tr></thead>
                        <tbody>
                        <?php foreach (['Junior', 'Senior', 'Architect'] as $role): ?>
                            <tr>
                                <td class="fw-semibold"><?= $role ?></td>
                                <?php foreach (['Interno', 'Contractor', 'Agencia'] as $modality): ?>
                                    <td class="num">
                                        <?php $value = $matrix[$role][$modality] ?? 0; ?>
                                        <?php if ($isCfo): ?>
                                            <form method="post" class="d-inline">
                                                <?= csrf_field() ?><input type="hidden" name="action" value="update_tarifa"><input type="hidden" name="rol" value="<?= e($role) ?>"><input type="hidden" name="modalidad_contratacion" value="<?= e($modality) ?>">
                                                <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end" style="width:82px" name="monto_hora" value="<?= e($value) ?>" onchange="this.form.submit()">
                                            </form>
                                        <?php else: ?>
                                            <?= $value > 0 ? fmt_money($value) : '—' ?>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($isCfo): ?><div class="text-muted small mt-2"><i class="bi bi-info-circle"></i> Cambia un valor y se guarda automáticamente con vigencia de hoy.</div><?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Composición del Fully Loaded Rate</div>
            <div class="card-body">
                <div class="small text-muted mb-3">Costo mensual = salario base + prestaciones + impuestos patronales + licencias/hardware. Se divide entre las horas laborales del mes.</div>
                <div class="stat-row"><span>Salario base</span><strong>Incluido</strong></div>
                <div class="stat-row"><span>Prestaciones sociales</span><strong>Incluido</strong></div>
                <div class="stat-row"><span>Impuestos patronales</span><strong>Incluido</strong></div>
                <div class="stat-row"><span>Licencias (IDE, Copilot, Figma)</span><strong>Incluido</strong></div>
                <div class="stat-row"><span>Hardware amortizado</span><strong>Incluido</strong></div>
                <div class="alert alert-info mt-3 mb-0 small"><i class="bi bi-lock"></i> <?= $isCfo ? 'Solo el CFO puede ver el desglose salarial individual.' : 'El detalle salarial individual está restringido al CFO.' ?></div>
            </div>
        </div>
    </div>
</div>

<?php if ($isCfo && $selected): ?>
    <?php $rate = fully_loaded_rate((int)$selected['id_usuario']); $costs = $costsByUser[(int)$selected['id_usuario']] ?? []; $billable = $matrix[$selected['nivel']][$selected['modalidad_contratacion'] ?? 'Interno'] ?? 0; ?>
    <div class="card mt-4">
        <div class="card-header">
            <span>Detalle de costos · <?= e($selected['nombre_completo']) ?></span>
            <a href="rates.php" class="btn btn-sm btn-ghost">Cerrar</a>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-lg-6">
                    <h4 class="section-title mb-3" style="font-size:.92rem">Ficha salarial</h4>
                    <form method="post">
                        <?= csrf_field() ?><input type="hidden" name="action" value="update_ficha"><input type="hidden" name="id_usuario" value="<?= (int)$selected['id_usuario'] ?>">
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label">Modalidad</label><select class="form-select" name="modalidad_contratacion"><?php foreach (['Interno','Contractor','Agencia'] as $m): ?><option <?= ($selected['modalidad_contratacion'] ?? '') === $m ? 'selected' : '' ?>><?= $m ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-6"><label class="form-label">Moneda</label><select class="form-select" name="moneda"><?php foreach (['USD','EUR','VES','COP'] as $c): ?><option <?= ($selected['moneda'] ?? 'USD') === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-6"><label class="form-label">Salario base mensual</label><input type="number" step="0.01" class="form-control" name="salario_base" value="<?= e($selected['salario_base'] ?? 0) ?>"></div>
                            <div class="col-md-6"><label class="form-label">Horas laborales / mes</label><input type="number" step="0.5" class="form-control" name="horas_laborales_mes" value="<?= e($selected['horas_laborales_mes'] ?? 160) ?>"></div>
                            <div class="col-md-6"><label class="form-label">Prestaciones sociales</label><input type="number" step="0.01" class="form-control" name="prestaciones_sociales" value="<?= e($selected['prestaciones_sociales'] ?? 0) ?>"></div>
                            <div class="col-md-6"><label class="form-label">Impuestos patronales</label><input type="number" step="0.01" class="form-control" name="impuestos_patronales" value="<?= e($selected['impuestos_patronales'] ?? 0) ?>"></div>
                            <div class="col-md-6"><label class="form-label">Vigente desde</label><input type="date" class="form-control" name="vigente_desde" value="<?= e($selected['vigente_desde'] ?? date('Y-m-d')) ?>"></div>
                            <div class="col-md-6 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit">Guardar ficha</button></div>
                        </div>
                    </form>

                    <div class="row g-2 mt-2">
                        <div class="col-4"><div class="mini-stat"><span>Base</span><strong><?= fmt_money_short($rate['base']) ?></strong></div></div>
                        <div class="col-4"><div class="mini-stat"><span>Cargas</span><strong><?= fmt_money_short($rate['prestaciones'] + $rate['impuestos']) ?></strong></div></div>
                        <div class="col-4"><div class="mini-stat"><span>Indirectos</span><strong><?= fmt_money_short($rate['asignados']) ?></strong></div></div>
                    </div>
                    <div class="p-3 rounded mt-3" style="background:#f8fafc;border:1px solid var(--cl-line)">
                        <div class="d-flex justify-content-between"><span class="text-muted">Costo mensual total</span><strong><?= fmt_money($rate['mensual']) ?></strong></div>
                        <div class="d-flex justify-content-between"><span class="text-muted">Fully Loaded Rate</span><strong><?= fmt_money($rate['rate']) ?>/h</strong></div>
                        <div class="d-flex justify-content-between"><span class="text-muted">Tarifa de venta</span><strong><?= fmt_money($billable) ?>/h</strong></div>
                        <div class="d-flex justify-content-between mt-1 pt-1 border-top"><span class="text-muted">Margen teórico por hora</span><strong class="<?= $billable - $rate['rate'] >= 0 ? 'text-positive' : 'text-negative' ?>"><?= fmt_money($billable - $rate['rate']) ?></strong></div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <h4 class="section-title mb-3" style="font-size:.92rem">Costos indirectos asignados</h4>
                    <div class="table-responsive">
                        <table class="table">
                            <thead><tr><th>Concepto</th><th>Categoría</th><th class="num">Mensual</th><th class="num">Vida útil</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($costs as $cost): ?>
                                <tr>
                                    <td><?= e($cost['concepto']) ?></td>
                                    <td><span class="tag"><?= e($cost['categoria']) ?></span></td>
                                    <td class="num"><?= fmt_money($cost['monto_mensual']) ?></td>
                                    <td class="num"><?= $cost['vida_util_meses'] ? (int)$cost['vida_util_meses'] . ' m' : '—' ?></td>
                                    <td class="text-end">
                                        <form method="post" data-confirm="¿Eliminar este costo?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_costo"><input type="hidden" name="id_costo" value="<?= (int)$cost['id_costo'] ?>"><button class="btn btn-sm btn-ghost"><i class="bi bi-trash"></i></button></form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$costs): ?><tr><td colspan="5" class="text-muted text-center py-3">Sin costos indirectos.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <form method="post" class="mt-3">
                        <?= csrf_field() ?><input type="hidden" name="action" value="add_costo"><input type="hidden" name="id_usuario" value="<?= (int)$selected['id_usuario'] ?>">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-5"><label class="form-label">Concepto</label><input class="form-control" name="concepto" placeholder="GitHub Copilot" required></div>
                            <div class="col-md-3"><label class="form-label">Categoría</label><select class="form-select" name="categoria"><option>Licencia</option><option>Hardware</option><option>Beneficio</option><option>Otro</option></select></div>
                            <div class="col-md-2"><label class="form-label">Mensual</label><input type="number" step="0.01" class="form-control" name="monto_mensual" required></div>
                            <div class="col-md-2"><label class="form-label">Vida útil</label><input type="number" class="form-control" name="vida_util_meses" placeholder="meses"></div>
                            <div class="col-12"><button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-plus-lg"></i> Agregar costo</button></div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php
render_footer();

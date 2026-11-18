<?php
require_once 'includes/bootstrap.php';
require_once 'includes/layout.php';

$user = require_login();
if (!can_view_financials($user)) {
    flash('danger', 'No tienes acceso al módulo FinOps.');
    redirect('dashboard.php');
}
$isCfo = is_cfo($user);

$projectWhere = 'p.activo = 1';
$projectParams = [];
if (is_pm($user)) {
    $projectWhere .= ' AND p.id_manager = ?';
    $projectParams[] = (int)$user['id_usuario'];
}
$projects = fetch_all("SELECT p.* FROM Proyecto p WHERE $projectWhere ORDER BY p.nombre_proyecto", $projectParams);
$visibleProjectIds = array_map(fn($p) => (int)$p['id_proyecto'], $projects);

function can_touch_project(int $projectId, array $visibleIds): bool
{
    return in_array($projectId, $visibleIds, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'sync') {
        $result = sync_local_cloud_costs();
        $alerts = evaluar_alertas_finops();
        $integrationId = (int)scalar("SELECT id_integracion FROM Integracion WHERE proveedor LIKE 'Amazon%' LIMIT 1");
        if ($integrationId) {
            q("INSERT INTO SyncLog (id_integracion, tipo, referencia, mensaje, registros, estado) VALUES (?, 'Cloud', 'AWS/Azure mocks', 'Importación manual de costos cloud', ?, 'OK')", [$integrationId, $result['inserted']]);
            q('UPDATE Integracion SET ultima_sincronizacion = NOW() WHERE id_integracion = ?', [$integrationId]);
        }
        audit('sincronizar', 'ConsumoFinOps', null, "Sincronización cloud: {$result['inserted']} cargos");
        flash('success', "Sincronización completada: {$result['inserted']} cargos importados" . ($alerts ? ", $alerts alertas generadas." : '.'));
        redirect('finops.php');
    }

    if ($action === 'add_consumo') {
        $projectId = (int)($_POST['id_proyecto'] ?? 0);
        $hitoId = (int)($_POST['id_hito'] ?? 0) ?: null;
        $provider = trim((string)($_POST['proveedor'] ?? ''));
        $service = trim((string)($_POST['servicio'] ?? ''));
        $category = in_array($_POST['categoria'] ?? '', ['Cloud', 'API', 'Licencia', 'Otro'], true) ? $_POST['categoria'] : 'Cloud';
        $amount = (float)($_POST['monto_cargado'] ?? 0);
        $currency = in_array($_POST['moneda'] ?? '', ['USD', 'EUR'], true) ? $_POST['moneda'] : 'USD';
        $date = $_POST['fecha_consumo'] ?? date('Y-m-d');
        if (!can_touch_project($projectId, $visibleProjectIds) || $provider === '' || $service === '' || $amount <= 0) {
            flash('danger', 'Consumo inválido o proyecto no autorizado.');
        } else {
            if ($hitoId) {
                $hito = fetch_one('SELECT id_hito FROM Hito WHERE id_hito = ? AND id_proyecto = ?', [$hitoId, $projectId]);
                $hitoId = $hito['id_hito'] ?? null;
            }
            q(
                'INSERT INTO ConsumoFinOps (id_proyecto, id_hito, proveedor, categoria, servicio, monto_cargado, moneda, fecha_consumo, etiqueta_proyecto) VALUES (?,?,?,?,?,?,?,?,?)',
                [$projectId, $hitoId, $provider, $category, $service, $amount, $currency, $date, 'MANUAL']
            );
            evaluar_alertas_finops();
            audit('crear', 'ConsumoFinOps', (int)db()->lastInsertId(), "Costo $provider/$service por $amount $currency");
            flash('success', 'Consumo registrado e imputado al proyecto.');
        }
        redirect('finops.php');
    }

    if ($action === 'add_presupuesto') {
        $projectId = (int)($_POST['id_proyecto'] ?? 0);
        $provider = trim((string)($_POST['proveedor'] ?? '')) ?: null;
        $service = trim((string)($_POST['servicio'] ?? '')) ?: null;
        $budget = (float)($_POST['presupuesto_mensual'] ?? 0);
        $threshold = min(100, max(1, (float)($_POST['umbral_alerta'] ?? 80)));
        if (!can_touch_project($projectId, $visibleProjectIds) || $budget <= 0) {
            flash('danger', 'Presupuesto inválido o proyecto no autorizado.');
        } else {
            q('INSERT INTO PresupuestoFinOps (id_proyecto, id_hito, proveedor, servicio, presupuesto_mensual, umbral_alerta) VALUES (?,?,?,?,?,?)', [$projectId, null, $provider, $service, $budget, $threshold]);
            evaluar_alertas_finops();
            audit('crear', 'PresupuestoFinOps', (int)db()->lastInsertId(), "Presupuesto $provider por $budget");
            flash('success', 'Presupuesto FinOps creado.');
        }
        redirect('finops.php#presupuestos');
    }

    if ($action === 'leer_alerta') {
        $alertId = (int)($_POST['id_alerta'] ?? 0);
        q('UPDATE AlertaFinOps SET leida = 1 WHERE id_alerta = ? AND id_proyecto IN (' . implode(',', array_map('intval', $visibleProjectIds ?: [0])) . ')', [$alertId]);
        flash('success', 'Alerta marcada como leída.');
        redirect('finops.php#alertas');
    }

    if ($action === 'leer_todas') {
        q('UPDATE AlertaFinOps SET leida = 1 WHERE id_proyecto IN (' . implode(',', array_map('intval', $visibleProjectIds ?: [0])) . ')');
        flash('success', 'Todas las alertas fueron marcadas como leídas.');
        redirect('finops.php#alertas');
    }
}

evaluar_alertas_finops();

$month = (string)($_GET['mes'] ?? date('Y-m'));
$projectFilter = (int)($_GET['proyecto'] ?? 0);
$providerFilter = (string)($_GET['proveedor'] ?? '');
$categoryFilter = (string)($_GET['categoria'] ?? '');

$where = ["DATE_FORMAT(c.fecha_consumo, '%Y-%m') = ?"];
$params = [$month];
if ($visibleProjectIds) {
    $where[] = 'c.id_proyecto IN (' . implode(',', array_map('intval', $visibleProjectIds)) . ')';
} else {
    $where[] = '1 = 0';
}
if ($projectFilter > 0 && in_array($projectFilter, $visibleProjectIds, true)) {
    $where[] = 'c.id_proyecto = ?';
    $params[] = $projectFilter;
}
if ($providerFilter !== '') {
    $where[] = 'c.proveedor = ?';
    $params[] = $providerFilter;
}
if ($categoryFilter !== '') {
    $where[] = 'c.categoria = ?';
    $params[] = $categoryFilter;
}
$sqlWhere = implode(' AND ', $where);
$consumptions = fetch_all(
    "SELECT c.*, p.codigo_unico, p.nombre_proyecto, h.nombre_hito
     FROM ConsumoFinOps c JOIN Proyecto p ON p.id_proyecto = c.id_proyecto LEFT JOIN Hito h ON h.id_hito = c.id_hito
     WHERE $sqlWhere ORDER BY c.fecha_consumo DESC, c.id_consumo DESC LIMIT 300",
    $params
);
$monthTotal = array_sum(array_map(fn($c) => (float)$c['monto_cargado'], $consumptions));
$cloudTotal = array_sum(array_map(fn($c) => $c['categoria'] === 'Cloud' ? (float)$c['monto_cargado'] : 0, $consumptions));
$apiTotal = array_sum(array_map(fn($c) => $c['categoria'] === 'API' ? (float)$c['monto_cargado'] : 0, $consumptions));
$budgets = fetch_all(
    'SELECT b.*, p.codigo_unico, p.nombre_proyecto,
        (SELECT COALESCE(SUM(monto_cargado),0) FROM ConsumoFinOps c WHERE c.id_proyecto = b.id_proyecto
            AND (b.proveedor IS NULL OR c.proveedor = b.proveedor)
            AND (b.servicio IS NULL OR c.servicio = b.servicio)
            AND DATE_FORMAT(c.fecha_consumo, "%Y-%m") = DATE_FORMAT(CURDATE(), "%Y-%m")) actual
     FROM PresupuestoFinOps b JOIN Proyecto p ON p.id_proyecto = b.id_proyecto
     WHERE b.activo = 1 AND b.id_proyecto IN (' . implode(',', array_map('intval', $visibleProjectIds ?: [0])) . ')
     ORDER BY actual / NULLIF(b.presupuesto_mensual,0) DESC'
);
$budgetTotal = array_sum(array_map(fn($b) => (float)$b['presupuesto_mensual'], $budgets));
$alerts = fetch_all(
    'SELECT a.*, p.codigo_unico, p.nombre_proyecto FROM AlertaFinOps a JOIN Proyecto p ON p.id_proyecto = a.id_proyecto
     WHERE a.id_proyecto IN (' . implode(',', array_map('intval', $visibleProjectIds ?: [0])) . ')
     ORDER BY a.leida ASC, FIELD(a.nivel,"Crítica","Advertencia","Info"), a.fecha DESC LIMIT 50'
);
$unreadAlerts = array_values(array_filter($alerts, fn($a) => !(int)$a['leida']));
$providers = fetch_all('SELECT DISTINCT proveedor FROM ConsumoFinOps ORDER BY proveedor');
$hitos = fetch_all('SELECT h.*, p.codigo_unico FROM Hito h JOIN Proyecto p ON p.id_proyecto = h.id_proyecto WHERE h.id_proyecto IS NOT NULL ORDER BY p.codigo_unico, h.id_hito');

$trendKeys = [];
for ($i = 5; $i >= 0; $i--) { $trendKeys[] = date('Y-m', strtotime("first day of -$i months")); }
$trendCloud = array_fill_keys($trendKeys, 0.0);
$trendApi = array_fill_keys($trendKeys, 0.0);
$trendRows = fetch_all(
    "SELECT DATE_FORMAT(fecha_consumo,'%Y-%m') k, categoria, SUM(monto_cargado) v FROM ConsumoFinOps
     WHERE id_proyecto IN (" . implode(',', array_map('intval', $visibleProjectIds ?: [0])) . ") AND fecha_consumo >= ?
     GROUP BY k, categoria",
    [date('Y-m-01', strtotime('first day of -5 months'))]
);
foreach ($trendRows as $row) {
    if ($row['categoria'] === 'Cloud') { $trendCloud[$row['k']] = (float)$row['v']; }
    elseif ($row['categoria'] === 'API') { $trendApi[$row['k']] = (float)$row['v']; }
}
$byProvider = [];
foreach ($consumptions as $cost) { $byProvider[$cost['proveedor']] = ($byProvider[$cost['proveedor']] ?? 0) + (float)$cost['monto_cargado']; }
arsort($byProvider);
$topProvider = array_key_first($byProvider);

render_header('FinOps', $user, 'finops');
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
        <h2 class="section-title">Control de infraestructura cloud y APIs</h2>
        <div class="section-sub">Imputación directa por proyecto, etiquetado y alertas de desviación OpEx</div>
    </div>
    <div class="d-flex gap-2">
        <form method="post" data-confirm="Se importarán los costos mock de AWS y Azure. ¿Continuar?" data-loading-text="Sincronizando…">
            <?= csrf_field() ?><input type="hidden" name="action" value="sync">
            <button class="btn btn-ghost btn-sm" type="submit"><i class="bi bi-cloud-arrow-down"></i> Sincronizar cloud</button>
        </form>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newCostModal"><i class="bi bi-plus-lg"></i> Registrar consumo</button>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="kpi-card" style="--accent:#4f46e5;--accent-soft:#eef2ff"><div class="kpi-label">Consumo del mes</div><div class="kpi-value"><?= fmt_money_short($monthTotal) ?></div><div class="kpi-foot"><i class="bi bi-calendar"></i> <?= e(month_label($month)) ?></div><div class="kpi-icon"><i class="bi bi-cloud"></i></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card" style="--accent:#0891b2;--accent-soft:#cffafe"><div class="kpi-label">Infraestructura cloud</div><div class="kpi-value"><?= fmt_money_short($cloudTotal) ?></div><div class="kpi-foot"><i class="bi bi-hdd-network"></i> <?= fmt_ratio_pct($monthTotal > 0 ? $cloudTotal / $monthTotal : 0) ?> del total</div><div class="kpi-icon"><i class="bi bi-hdd-network"></i></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card" style="--accent:#d97706;--accent-soft:#fef3c7"><div class="kpi-label">APIs de terceros</div><div class="kpi-value"><?= fmt_money_short($apiTotal) ?></div><div class="kpi-foot"><i class="bi bi-braces"></i> OpenAI, Twilio, Stripe...</div><div class="kpi-icon"><i class="bi bi-braces"></i></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card" style="--accent:<?= $unreadAlerts ? '#e11d48' : '#16a34a' ?>;--accent-soft:<?= $unreadAlerts ? '#ffe4e6' : '#dcfce7' ?>"><div class="kpi-label">Alertas activas</div><div class="kpi-value"><?= count($unreadAlerts) ?></div><div class="kpi-foot"><i class="bi bi-bell"></i> Presupuesto cloud del mes</div><div class="kpi-icon"><i class="bi bi-bell"></i></div></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header"><span>Evolución del gasto cloud y APIs</span><span class="section-sub">Últimos 6 meses</span></div>
            <div class="card-body"><div class="chart-box"><canvas id="finopsTrend"></canvas></div></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header"><span>Distribución por proveedor</span><span class="section-sub"><?= e(month_label($month)) ?></span></div>
            <div class="card-body"><div class="chart-box"><canvas id="providerChart"></canvas></div></div>
        </div>
    </div>
</div>

<form class="filter-bar mb-4" method="get">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label">Mes</label><input type="month" class="form-control form-control-sm" name="mes" value="<?= e($month) ?>"></div>
        <div class="col-md-3"><label class="form-label">Proyecto</label><select class="form-select form-select-sm" name="proyecto"><option value="">Todos</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id_proyecto'] ?>" <?= $projectFilter === (int)$p['id_proyecto'] ? 'selected' : '' ?>><?= e($p['codigo_unico']) ?> · <?= e($p['nombre_proyecto']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Proveedor</label><select class="form-select form-select-sm" name="proveedor"><option value="">Todos</option><?php foreach ($providers as $p): ?><option <?= $providerFilter === $p['proveedor'] ? 'selected' : '' ?>><?= e($p['proveedor']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Categoría</label><select class="form-select form-select-sm" name="categoria"><option value="">Todas</option><?php foreach (['Cloud','API','Licencia','Otro'] as $c): ?><option <?= $categoryFilter === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary btn-sm flex-grow-1" type="submit">Filtrar</button><a href="finops.php" class="btn btn-ghost btn-sm"><i class="bi bi-arrow-counterclockwise"></i></a></div>
    </div>
</form>

<div class="row g-3 mb-4">
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header"><span>Detalle de consumos</span><span class="section-sub"><?= count($consumptions) ?> registros · <?= fmt_money($monthTotal) ?></span></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead><tr><th>Fecha</th><th>Proyecto</th><th>Proveedor / Servicio</th><th>Categoría</th><th class="num">Monto</th></tr></thead>
                    <tbody>
                    <?php foreach ($consumptions as $cost): ?>
                        <tr>
                            <td class="text-muted small"><?= fmt_date($cost['fecha_consumo']) ?></td>
                            <td><div class="fw-semibold"><?= e($cost['codigo_unico']) ?></div><div class="text-muted small"><?= e($cost['nombre_hito'] ?: $cost['nombre_proyecto']) ?></div></td>
                            <td><div class="fw-semibold"><?= e($cost['proveedor']) ?></div><div class="text-muted small"><?= e($cost['servicio']) ?></div></td>
                            <td><span class="tag"><?= e($cost['categoria']) ?></span></td>
                            <td class="num fw-semibold"><?= fmt_money($cost['monto_cargado'], $cost['moneda']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$consumptions): ?><tr><td colspan="5"><div class="empty-state"><i class="bi bi-cloud-slash"></i>Sin consumos para el filtro.</div></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card mb-3" id="alertas">
            <div class="card-header">
                <span>Alertas de desviación OpEx</span>
                <?php if ($unreadAlerts): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="leer_todas"><button class="btn btn-sm btn-ghost" type="submit">Marcar todas</button></form>
                <?php endif; ?>
            </div>
            <div class="card-body" style="max-height:470px;overflow-y:auto">
                <?php if (!$alerts): ?><div class="empty-state"><i class="bi bi-shield-check"></i>Sin alertas registradas.</div><?php endif; ?>
                <?php foreach ($alerts as $alert): ?>
                    <div class="alert-card <?= $alert['nivel'] === 'Crítica' ? 'critical' : '' ?> mb-3 ps-3 <?= (int)$alert['leida'] ? 'opacity-50' : '' ?>">
                        <div class="d-flex justify-content-between gap-2">
                            <span class="fw-semibold small"><?= e($alert['codigo_unico']) ?> · <?= e($alert['tipo']) ?></span>
                            <?= status_badge($alert['nivel']) ?>
                        </div>
                        <div class="alert-message mt-1"><?= e($alert['mensaje']) ?></div>
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <span class="alert-time"><i class="bi bi-clock"></i> <?= fmt_date($alert['fecha']) ?></span>
                            <?php if (!(int)$alert['leida']): ?>
                                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="leer_alerta"><input type="hidden" name="id_alerta" value="<?= (int)$alert['id_alerta'] ?>"><button class="btn btn-sm btn-ghost py-0">Leída</button></form>
                            <?php else: ?>
                                <span class="tag">Leída</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div class="card" id="presupuestos">
    <div class="card-header">
        <span>Presupuestos y consumos del mes</span>
        <button class="btn btn-sm btn-ghost" data-bs-toggle="modal" data-bs-target="#newBudgetModal"><i class="bi bi-plus-lg"></i> Nuevo presupuesto</button>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead><tr><th>Proyecto</th><th>Proveedor</th><th>Servicio</th><th class="num">Presupuesto</th><th class="num">Consumido</th><th>Estado</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($budgets as $budget): ?>
                <?php $actual = (float)$budget['actual']; $limit = (float)$budget['presupuesto_mensual']; $pct = $limit > 0 ? $actual / $limit : 0; ?>
                <tr>
                    <td><div class="fw-semibold"><?= e($budget['codigo_unico']) ?></div><div class="text-muted small"><?= e($budget['nombre_proyecto']) ?></div></td>
                    <td><?= e($budget['proveedor'] ?: 'Todos') ?></td>
                    <td class="text-muted"><?= e($budget['servicio'] ?: 'Todos') ?></td>
                    <td class="num"><?= fmt_money($limit) ?></td>
                    <td class="num fw-semibold"><?= fmt_money($actual) ?></td>
                    <td style="min-width:140px">
                        <div class="progress-label"><span class="<?= $pct >= 1 ? 'text-negative' : '' ?>"><?= fmt_ratio_pct($pct, 0) ?></span><span><?= $pct >= 1 ? 'Excedido' : ($pct >= $budget['umbral_alerta'] / 100 ? 'Alerta' : 'OK') ?></span></div>
                        <div class="progress"><div class="progress-bar <?= $pct >= 1 ? 'bg-danger' : ($pct >= $budget['umbral_alerta'] / 100 ? 'bg-warning' : 'bg-success') ?>" style="width:<?= min(100, $pct * 100) ?>%"></div></div>
                    </td>
                    <td class="num text-muted small"><?= fmt_money_short($limit - $actual) ?> disp.</td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$budgets): ?><tr><td colspan="7"><div class="empty-state"><i class="bi bi-sliders"></i>No hay presupuestos configurados.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="newCostModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="add_consumo">
            <div class="modal-header"><h5 class="modal-title">Registrar consumo cloud / API</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12"><label class="form-label">Proyecto</label><select class="form-select" name="id_proyecto" required><option value="">Selecciona...</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id_proyecto'] ?>"><?= e($p['codigo_unico']) ?> · <?= e($p['nombre_proyecto']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-12"><label class="form-label">Hito (opcional)</label><select class="form-select" name="id_hito"><option value="">Sin asignar</option><?php foreach ($hitos as $h): ?><option value="<?= (int)$h['id_hito'] ?>"><?= e($h['codigo_unico']) ?> · <?= e($h['nombre_hito']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-6"><label class="form-label">Proveedor</label><input class="form-control" name="proveedor" placeholder="AWS, Azure, OpenAI..." required></div>
                    <div class="col-6"><label class="form-label">Categoría</label><select class="form-select" name="categoria"><option>Cloud</option><option>API</option><option>Licencia</option><option>Otro</option></select></div>
                    <div class="col-12"><label class="form-label">Servicio</label><input class="form-control" name="servicio" placeholder="Amazon EC2, API tokens..." required></div>
                    <div class="col-4"><label class="form-label">Monto</label><input type="number" step="0.01" min="0.01" class="form-control" name="monto_cargado" required></div>
                    <div class="col-4"><label class="form-label">Moneda</label><select class="form-select" name="moneda"><option>USD</option><option>EUR</option></select></div>
                    <div class="col-4"><label class="form-label">Fecha</label><input type="date" class="form-control" name="fecha_consumo" value="<?= date('Y-m-d') ?>" required></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar consumo</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="newBudgetModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="add_presupuesto">
            <div class="modal-header"><h5 class="modal-title">Nuevo presupuesto FinOps</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12"><label class="form-label">Proyecto</label><select class="form-select" name="id_proyecto" required><option value="">Selecciona...</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id_proyecto'] ?>"><?= e($p['codigo_unico']) ?> · <?= e($p['nombre_proyecto']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-6"><label class="form-label">Proveedor</label><input class="form-control" name="proveedor" placeholder="Todos si se deja vacío"></div>
                    <div class="col-6"><label class="form-label">Servicio</label><input class="form-control" name="servicio" placeholder="Todos si se deja vacío"></div>
                    <div class="col-6"><label class="form-label">Presupuesto mensual (USD)</label><input type="number" step="0.01" min="1" class="form-control" name="presupuesto_mensual" required></div>
                    <div class="col-6"><label class="form-label">Umbral de alerta (%)</label><input type="number" min="1" max="100" class="form-control" name="umbral_alerta" value="80"></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar presupuesto</button></div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const trend = document.getElementById('finopsTrend');
    if (trend) {
        new Chart(trend, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(fn($k) => month_label($k), $trendKeys)) ?>,
                datasets: [
                    { label: 'Cloud', data: <?= json_encode(array_values($trendCloud)) ?>, backgroundColor: 'rgba(79,70,229,.8)', borderRadius: 5 },
                    { label: 'APIs', data: <?= json_encode(array_values($trendApi)) ?>, backgroundColor: 'rgba(217,119,6,.75)', borderRadius: 5 }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true, ticks: { callback: v => 'USD ' + v } } } }
        });
    }
    const provider = document.getElementById('providerChart');
    if (provider) {
        const labels = <?= json_encode(array_keys($byProvider)) ?>;
        const values = <?= json_encode(array_values($byProvider)) ?>;
        if (labels.length) {
            new Chart(provider, {
                type: 'doughnut',
                data: { labels, datasets: [{ data: values, backgroundColor: ['#4f46e5','#0d9488','#d97706','#e11d48','#0891b2','#7c3aed','#64748b'], borderWidth: 0 }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'bottom' } } }
            });
        }
    }
});
</script>
<?php
render_footer();

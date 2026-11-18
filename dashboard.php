<?php
require_once 'includes/bootstrap.php';
require_once 'includes/layout.php';

$user = require_login();
$isDev = is_dev($user);

if ($isDev) {
    $uid = (int)$user['id_usuario'];
    $monthHours = (float)scalar(
        "SELECT COALESCE(SUM(r.horas_incurridas),0) FROM RegistroTiempo r WHERE r.id_usuario = ? AND r.estado_aprobacion IN ('Aprobado','Facturado') AND DATE_FORMAT(r.fecha_registro,'%Y-%m') = ?",
        [$uid, date('Y-m')]
    );
    $monthBillable = (float)scalar(
        "SELECT COALESCE(SUM(r.horas_incurridas),0) FROM RegistroTiempo r JOIN Tarea t ON t.id_tarea = r.id_tarea WHERE r.id_usuario = ? AND r.estado_aprobacion IN ('Aprobado','Facturado') AND t.categoria_contable = 'Billable' AND DATE_FORMAT(r.fecha_registro,'%Y-%m') = ?",
        [$uid, date('Y-m')]
    );
    $pendingHours = (float)scalar("SELECT COALESCE(SUM(horas_incurridas),0) FROM RegistroTiempo WHERE id_usuario = ? AND estado_aprobacion = 'Pendiente'", [$uid]);
    $pendingCount = (int)scalar("SELECT COUNT(*) FROM RegistroTiempo WHERE id_usuario = ? AND estado_aprobacion = 'Pendiente'", [$uid]);
    $projectsCount = (int)scalar(
        "SELECT COUNT(DISTINCT h.id_proyecto) FROM RegistroTiempo r JOIN Tarea t ON t.id_tarea = r.id_tarea JOIN Hito h ON h.id_hito = t.id_hito WHERE r.id_usuario = ? AND DATE_FORMAT(r.fecha_registro,'%Y-%m') = ?",
        [$uid, date('Y-m')]
    );
    $utilization = $monthHours > 0 ? $monthBillable / $monthHours : 0;
    $recent = fetch_all(
        "SELECT r.*, t.titulo, t.categoria_contable, h.nombre_hito, p.codigo_unico, p.nombre_proyecto
         FROM RegistroTiempo r JOIN Tarea t ON t.id_tarea = r.id_tarea JOIN Hito h ON h.id_hito = t.id_hito JOIN Proyecto p ON p.id_proyecto = h.id_proyecto
         WHERE r.id_usuario = ? ORDER BY r.fecha_registro DESC, r.id_registro DESC LIMIT 8",
        [$uid]
    );
    $openTasks = fetch_all(
        "SELECT t.*, h.nombre_hito, p.codigo_unico, p.nombre_proyecto
         FROM Tarea t JOIN Hito h ON h.id_hito = t.id_hito JOIN Proyecto p ON p.id_proyecto = h.id_proyecto
         WHERE t.estado_tarea = 'Open' AND p.activo = 1
           AND EXISTS (SELECT 1 FROM RegistroTiempo r WHERE r.id_tarea = t.id_tarea AND r.id_usuario = ?)
         ORDER BY t.peso_horas_estimadas DESC LIMIT 8",
        [$uid]
    );
    render_header('Mi espacio', $user, 'dashboard');
    ?>
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="kpi-card" style="--accent:#4f46e5;--accent-soft:#eef2ff">
                <div class="kpi-label">Horas del mes</div>
                <div class="kpi-value"><?= fmt_number($monthHours, 1) ?> h</div>
                <div class="kpi-foot"><i class="bi bi-check2-circle"></i> Aprobadas y facturadas</div>
                <div class="kpi-icon"><i class="bi bi-clock-history"></i></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="kpi-card" style="--accent:#0d9488;--accent-soft:#ccfbf1">
                <div class="kpi-label">Horas facturables</div>
                <div class="kpi-value"><?= fmt_number($monthBillable, 1) ?> h</div>
                <div class="kpi-foot"><i class="bi bi-graph-up"></i> <?= fmt_pct($utilization * 100) ?> de utilización</div>
                <div class="kpi-icon"><i class="bi bi-cash-coin"></i></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="kpi-card" style="--accent:#d97706;--accent-soft:#fef3c7">
                <div class="kpi-label">Pendientes de aprobación</div>
                <div class="kpi-value"><?= fmt_number($pendingHours, 1) ?> h</div>
                <div class="kpi-foot"><i class="bi bi-hourglass-split"></i> <?= $pendingCount ?> registro<?= $pendingCount === 1 ? '' : 's' ?></div>
                <div class="kpi-icon"><i class="bi bi-inbox"></i></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="kpi-card" style="--accent:#0891b2;--accent-soft:#cffafe">
                <div class="kpi-label">Proyectos activos</div>
                <div class="kpi-value"><?= $projectsCount ?></div>
                <div class="kpi-foot"><i class="bi bi-kanban"></i> Con horas este mes</div>
                <div class="kpi-icon"><i class="bi bi-folder2-open"></i></div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header">
                    <span>Mis últimos registros de tiempo</span>
                    <a href="timesheets.php" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Registrar horas</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead><tr><th>Fecha</th><th>Proyecto / Tarea</th><th>Categoría</th><th class="num">Horas</th><th>Estado</th></tr></thead>
                        <tbody>
                        <?php foreach ($recent as $row): ?>
                            <tr>
                                <td class="text-muted small"><?= fmt_date($row['fecha_registro']) ?></td>
                                <td>
                                    <div class="fw-semibold"><?= e($row['titulo']) ?></div>
                                    <div class="text-muted small"><?= e($row['codigo_unico']) ?> · <?= e($row['nombre_hito']) ?></div>
                                </td>
                                <td><span class="tag"><?= e($row['categoria_contable']) ?></span></td>
                                <td class="num fw-semibold"><?= fmt_number($row['horas_incurridas'], 1) ?></td>
                                <td><?= status_badge($row['estado_aprobacion']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$recent): ?>
                            <tr><td colspan="5"><div class="empty-state"><i class="bi bi-clock"></i>Aún no tienes registros de tiempo.</div></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><span>Tareas abiertas en mis proyectos</span><a href="projects.php" class="small">Ver todo</a></div>
                <div class="card-body py-2">
                    <?php if (!$openTasks): ?>
                        <div class="empty-state"><i class="bi bi-check2-all"></i>No hay tareas abiertas en tus proyectos.</div>
                    <?php else: ?>
                        <ul class="list-clean">
                            <?php foreach ($openTasks as $task): ?>
                                <li class="d-flex justify-content-between gap-3">
                                    <div>
                                        <div class="fw-semibold small"><?= e($task['titulo']) ?></div>
                                        <div class="text-muted" style="font-size:.7rem"><?= e($task['codigo_unico']) ?> · <?= e($task['historia_usuario']) ?></div>
                                    </div>
                                    <div class="text-end">
                                        <div class="tag"><?= fmt_number($task['peso_horas_estimadas'], 0) ?> h</div>
                                        <div class="text-muted mt-1" style="font-size:.65rem"><?= e($task['categoria_contable']) ?></div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
    render_footer();
    exit;
}

// ============================================================
// Dashboard CFO / PM
// ============================================================
$isCfo = is_cfo($user);
$projectWhere = '';
$projectParams = [];
if (is_pm($user)) {
    $projectWhere = ' AND p.id_manager = ?';
    $projectParams[] = (int)$user['id_usuario'];
}
$projects = fetch_all(
    "SELECT p.* FROM Proyecto p WHERE p.activo = 1 $projectWhere ORDER BY p.created_at DESC",
    $projectParams
);
$metrics = [];
foreach ($projects as $project) {
    $metric = project_metrics((int)$project['id_proyecto']);
    if ($metric) {
        $metrics[] = $metric;
    }
}
$sum = fn(string $key) => array_sum(array_map(fn($m) => (float)$m[$key], $metrics));
$revenue = $sum('ingresos_facturados');
$cost = $sum('costo_directo');
$margin = $revenue - $cost;
$marginPct = $revenue > 0 ? $margin / $revenue : 0;
$bac = $sum('bac');
$ev = $sum('ev');
$ac = $sum('ac');
$cpi = $ac > 0 ? $ev / $ac : 0;
$ar = $sum('cuentas_por_cobrar');
$alerts = (int)scalar('SELECT COUNT(*) FROM AlertaFinOps a JOIN Proyecto p ON p.id_proyecto = a.id_proyecto WHERE a.leida = 0' . (is_pm($user) ? ' AND p.id_manager = ' . (int)$user['id_usuario'] : ''));
$cash = (float)get_config('saldo_caja', '0');
$pendingQuery = "SELECT r.*, u.nombre_completo, t.titulo, t.categoria_contable, p.id_proyecto, p.codigo_unico, p.nombre_proyecto
    FROM RegistroTiempo r JOIN Usuario u ON u.id_usuario = r.id_usuario
    JOIN Tarea t ON t.id_tarea = r.id_tarea JOIN Hito h ON h.id_hito = t.id_hito JOIN Proyecto p ON p.id_proyecto = h.id_proyecto
    WHERE r.estado_aprobacion = 'Pendiente'";
$pendingParams = [];
if (is_pm($user)) {
    $pendingQuery .= ' AND p.id_manager = ?';
    $pendingParams[] = (int)$user['id_usuario'];
}
$pendingQuery .= ' ORDER BY r.fecha_registro DESC, r.id_registro DESC LIMIT 6';
$pending = fetch_all($pendingQuery, $pendingParams);

$alertQuery = "SELECT a.*, p.codigo_unico FROM AlertaFinOps a JOIN Proyecto p ON p.id_proyecto = a.id_proyecto WHERE a.leida = 0";
$alertParams = [];
if (is_pm($user)) {
    $alertQuery .= ' AND p.id_manager = ?';
    $alertParams[] = (int)$user['id_usuario'];
}
$alertQuery .= ' ORDER BY FIELD(a.nivel, "Crítica","Advertencia","Info"), a.fecha DESC LIMIT 5';
$alertsList = fetch_all($alertQuery, $alertParams);

$series = monthly_series(6);
$projectChart = [
    'labels' => array_map(fn($m) => $m['codigo_unico'], $metrics),
    'bac' => array_map(fn($m) => round((float)$m['bac'] / 1000, 1), $metrics),
    'ac' => array_map(fn($m) => round((float)$m['ac'] / 1000, 1), $metrics),
];

render_header('Dashboard financiero', $user, 'dashboard');
?>
<?php
// Solo presentación: reutiliza variables ya calculadas arriba (sin consultas nuevas).
$heroName = explode(' ', trim((string)$user['nombre_completo']))[0] ?: 'equipo';
?>
<section class="dash-hero">
    <div class="dash-hero-top">
        <div>
            <span class="dash-eyebrow"><?= $isCfo ? 'Vista consolidada de la operación' : 'Proyectos bajo tu gestión' ?></span>
            <h2>Hola, <?= e($heroName) ?></h2>
            <div class="dash-sub">Datos al <?= fmt_date(date('Y-m-d')) ?> · Moneda de consolidación USD</div>
        </div>
        <div class="dash-hero-actions">
            <a href="billing.php" class="btn btn-hero btn-sm"><i class="bi bi-receipt"></i> Facturación</a>
            <a href="reports.php" class="btn btn-hero-solid btn-sm"><i class="bi bi-graph-up-arrow"></i> Ver reportes</a>
        </div>
    </div>
    <div class="hero-pills">
        <div class="hero-pill <?= $margin >= 0 ? 'ok' : 'bad' ?>"><i class="bi bi-graph-up-arrow"></i> Margen <b><?= fmt_ratio_pct($marginPct) ?></b></div>
        <div class="hero-pill <?= $cpi >= 1 ? 'ok' : 'warn' ?>"><i class="bi bi-activity"></i> CPI <b><?= number_format($cpi, 2, ',', '.') ?></b></div>
        <div class="hero-pill <?= $alerts > 0 ? 'warn' : 'ok' ?>"><i class="bi bi-bell"></i> Alertas <b><?= $alerts ?></b></div>
        <div class="hero-pill info"><i class="bi bi-kanban"></i> Proyectos activos <b><?= count($metrics) ?></b></div>
    </div>
</section>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="kpi-card" style="--accent:#4f46e5;--accent-soft:#eef2ff">
            <div class="kpi-label">Ingresos facturados</div>
            <div class="kpi-value"><?= fmt_money_short($revenue) ?></div>
            <div class="kpi-foot"><i class="bi bi-receipt"></i> Facturas emitidas y cobradas</div>
            <div class="kpi-icon"><i class="bi bi-cash-stack"></i></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card" style="--accent:#e11d48;--accent-soft:#ffe4e6">
            <div class="kpi-label">Costo directo</div>
            <div class="kpi-value"><?= fmt_money_short($cost) ?></div>
            <div class="kpi-foot"><i class="bi bi-people"></i> Mano de obra + insumos</div>
            <div class="kpi-icon"><i class="bi bi-wallet2"></i></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card" style="--accent:#16a34a;--accent-soft:#dcfce7">
            <div class="kpi-label">Margen bruto</div>
            <div class="kpi-value <?= $margin >= 0 ? 'text-positive' : 'text-negative' ?>"><?= fmt_money_short($margin) ?></div>
            <div class="kpi-foot"><i class="bi bi-percent"></i> <?= fmt_ratio_pct($marginPct) ?> sobre ingresos</div>
            <div class="kpi-icon"><i class="bi bi-graph-up"></i></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card" style="--accent:#0891b2;--accent-soft:#cffafe">
            <div class="kpi-label"><?= $isCfo ? 'Caja disponible' : 'Cuentas por cobrar' ?></div>
            <div class="kpi-value"><?= fmt_money_short($isCfo ? $cash : $ar) ?></div>
            <div class="kpi-foot"><i class="bi bi-bank"></i> <?= $isCfo ? 'Runway y flujo proyectado' : 'Facturas emitidas pendientes' ?></div>
            <div class="kpi-icon"><i class="bi bi-bank2"></i></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="kpi-card" style="--accent:#7c3aed;--accent-soft:#ede9fe">
            <div class="kpi-label">Presupuesto gestionado</div>
            <div class="kpi-value"><?= fmt_money_short($bac) ?></div>
            <div class="kpi-foot"><i class="bi bi-bullseye"></i> <?= count($metrics) ?> proyectos activos</div>
            <div class="kpi-icon"><i class="bi bi-bullseye"></i></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card" style="--accent:#d97706;--accent-soft:#fef3c7">
            <div class="kpi-label">Costo acumulado (AC)</div>
            <div class="kpi-value"><?= fmt_money_short($ac) ?></div>
            <div class="kpi-foot"><i class="bi bi-speedometer"></i> <?= fmt_ratio_pct($bac > 0 ? $ac / $bac : 0) ?> del BAC</div>
            <div class="kpi-icon"><i class="bi bi-speedometer2"></i></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card" style="--accent:#0d9488;--accent-soft:#ccfbf1">
            <div class="kpi-label">CPI consolidado</div>
            <div class="kpi-value <?= $cpi >= 1 ? 'text-positive' : 'text-negative' ?>"><?= number_format($cpi, 2, ',', '.') ?></div>
            <div class="kpi-foot"><i class="bi bi-activity"></i> <?= $cpi >= 1 ? 'Dentro del presupuesto' : 'Sobre costo' ?></div>
            <div class="kpi-icon"><i class="bi bi-activity"></i></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card" style="--accent:#e11d48;--accent-soft:#ffe4e6">
            <div class="kpi-label">Alertas FinOps</div>
            <div class="kpi-value"><?= $alerts ?></div>
            <div class="kpi-foot"><i class="bi bi-bell"></i> Desviaciones OpEx sin leer</div>
            <div class="kpi-icon"><i class="bi bi-bell"></i></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header"><span><?= $isCfo ? 'Ingresos vs. costo operativo' : 'Presupuesto vs. costo real por proyecto' ?></span><span class="section-sub"><?= $isCfo ? 'Últimos 6 meses' : 'USD miles' ?></span></div>
            <div class="card-body">
                <div class="chart-box"><canvas id="mainChart"></canvas></div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100" id="alertas">
            <div class="card-header"><span>Alertas de desviación</span><a href="finops.php#alertas" class="small">Ver todas</a></div>
            <div class="card-body">
                <?php if (!$alertsList): ?>
                    <div class="empty-state"><i class="bi bi-shield-check"></i>Sin alertas activas. Todo bajo control.</div>
                <?php else: ?>
                    <?php foreach ($alertsList as $alert): ?>
                        <div class="alert-card <?= $alert['nivel'] === 'Crítica' ? 'critical' : ($alert['nivel'] === 'Info' ? 'info' : '') ?> mb-3 ps-3">
                            <div class="d-flex justify-content-between gap-2">
                                <span class="fw-semibold small"><?= e($alert['codigo_unico']) ?> · <?= e($alert['tipo']) ?></span>
                                <?= status_badge($alert['nivel']) ?>
                            </div>
                            <div class="alert-message mt-1"><?= e($alert['mensaje']) ?></div>
                            <div class="alert-time mt-1"><i class="bi bi-clock"></i> <?= fmt_date($alert['fecha']) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header"><span>Rentabilidad por proyecto</span><a href="projects.php" class="small">Ver proyectos</a></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead><tr><th>Proyecto</th><th>Modelo</th><th class="num">BAC</th><th class="num">Costo real</th><th>Avance</th><th class="num">CPI</th><th class="num">Margen</th></tr></thead>
                    <tbody>
                    <?php foreach ($metrics as $m): ?>
                        <tr>
                            <td>
                                <a href="project.php?id=<?= (int)$m['id_proyecto'] ?>" class="fw-semibold text-dark"><?= e($m['nombre_proyecto']) ?></a>
                                <div class="text-muted small"><?= e($m['codigo_unico']) ?> · <?= e($m['cliente']) ?></div>
                            </td>
                            <td><span class="tag"><?= e($m['modelo_contratacion']) ?></span></td>
                            <td class="num"><?= fmt_money_short($m['bac']) ?></td>
                            <td class="num"><?= fmt_money_short($m['ac']) ?></td>
                            <td style="min-width:110px">
                                <div class="progress-label"><span><?= fmt_ratio_pct($m['avance'], 0) ?></span></div>
                                <div class="progress"><div class="progress-bar bg-primary" style="width:<?= min(100, $m['avance'] * 100) ?>%"></div></div>
                            </td>
                            <td class="num <?= $m['cpi'] >= 1 ? 'text-positive' : 'text-negative' ?> fw-semibold"><?= number_format($m['cpi'], 2, ',', '.') ?></td>
                            <td class="num <?= $m['margen_bruto'] >= 0 ? 'text-positive' : 'text-negative' ?> fw-semibold"><?= fmt_money_short($m['margen_bruto']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$metrics): ?>
                        <tr><td colspan="7"><div class="empty-state"><i class="bi bi-kanban"></i>No hay proyectos visibles.</div></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header"><span>Horas pendientes de aprobación</span><a href="timesheets.php?estado=Pendiente" class="small">Aprobar</a></div>
            <div class="card-body py-2">
                <?php if (!$pending): ?>
                    <div class="empty-state"><i class="bi bi-check2-all"></i>No hay horas pendientes.</div>
                <?php else: ?>
                    <ul class="list-clean">
                    <?php foreach ($pending as $row): ?>
                        <li class="d-flex justify-content-between align-items-center gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <div class="avatar avatar-sm"><?= e(initials($row['nombre_completo'])) ?></div>
                                <div>
                                    <div class="fw-semibold" style="font-size:.78rem"><?= e($row['nombre_completo']) ?></div>
                                    <div class="text-muted" style="font-size:.68rem"><?= e($row['codigo_unico']) ?> · <?= fmt_date($row['fecha_registro']) ?></div>
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="fw-semibold num"><?= fmt_number($row['horas_incurridas'], 1) ?> h</div>
                                <div class="tag mt-1"><?= e($row['categoria_contable']) ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const ctx = document.getElementById('mainChart');
    if (!ctx) return;
    <?php if ($isCfo): ?>
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($series['labels']) ?>,
            datasets: [
                { label: 'Ingresos', data: <?= json_encode($series['revenue']) ?>, backgroundColor: c => CL.gradient(c, 'rgba(129,140,248,.95)', 'rgba(79,70,229,.88)'), borderRadius: 7, borderSkipped: false, barPercentage: .62 },
                { label: 'Costo operativo', data: <?= json_encode($series['cost']) ?>, backgroundColor: c => CL.gradient(c, 'rgba(251,113,133,.85)', 'rgba(225,29,72,.6)'), borderRadius: 7, borderSkipped: false, barPercentage: .62 },
                { label: 'Margen', data: <?= json_encode($series['margin']) ?>, type: 'line', borderColor: '#14b8a6', backgroundColor: '#14b8a6', tension: .35, pointRadius: 3.5, pointHoverRadius: 5, borderWidth: 2.5 }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'top', align: 'end' } }, scales: { x: { grid: { display: false } }, y: { ticks: { callback: v => 'USD ' + (v/1000).toFixed(0) + 'K' } } } }
    });
    <?php else: ?>
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($projectChart['labels']) ?>,
            datasets: [
                { label: 'BAC', data: <?= json_encode($projectChart['bac']) ?>, backgroundColor: c => CL.gradient(c, 'rgba(129,140,248,.6)', 'rgba(79,70,229,.3)'), borderRadius: 7, borderSkipped: false },
                { label: 'Costo real', data: <?= json_encode($projectChart['ac']) ?>, backgroundColor: c => CL.gradient(c, 'rgba(251,113,133,.95)', 'rgba(225,29,72,.75)'), borderRadius: 7, borderSkipped: false }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'top', align: 'end' } }, scales: { x: { grid: { display: false } }, y: { ticks: { callback: v => 'USD ' + v + 'K' } } } }
    });
    <?php endif; ?>
});
</script>
<?php
render_footer();

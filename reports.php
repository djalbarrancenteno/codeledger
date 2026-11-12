<?php
require_once 'includes/bootstrap.php';
require_once 'includes/layout.php';

$user = require_login(['CFO', 'PM']);
$isCfo = is_cfo($user);
$managerId = is_pm($user) ? (int)$user['id_usuario'] : null;

$from = (string)($_GET['desde'] ?? date('Y-01-01'));
$to = (string)($_GET['hasta'] ?? date('Y-m-d'));
if (!strtotime($from)) {
    $from = date('Y-01-01');
}
if (!strtotime($to)) {
    $to = date('Y-m-d');
}
if (strtotime($from) > strtotime($to)) {
    [$from, $to] = [$to, $from];
}

$financials = project_financials_between($from, $to, $managerId);

// Historias cerradas por proyecto (para costo por historia de usuario)
$storyRows = fetch_all(
    "SELECT h.id_proyecto,
            COALESCE(SUM(CASE WHEN t.estado_tarea = 'Closed' THEN 1 ELSE 0 END),0) cerradas,
            COUNT(t.id_tarea) total
     FROM Hito h LEFT JOIN Tarea t ON t.id_hito = h.id_hito
     GROUP BY h.id_proyecto"
);
$storiesByProject = [];
foreach ($storyRows as $row) {
    $storiesByProject[(int)$row['id_proyecto']] = ['cerradas' => (int)$row['cerradas'], 'total' => (int)$row['total']];
}

$totals = ['ingresos' => 0.0, 'costo' => 0.0, 'mano_obra' => 0.0, 'cloud' => 0.0, 'margen' => 0.0, 'horas' => 0.0, 'billable' => 0.0, 'cerradas' => 0];
$byClient = [];
$byTech = [];
$byMethod = [];
$projectRows = [];
foreach ($financials as $row) {
    $pid = (int)$row['id_proyecto'];
    $ingresos = (float)$row['ingresos'];
    $costo = (float)$row['costo_mano_obra'] + (float)$row['costo_cloud'];
    $margen = $ingresos - $costo;
    $cerradas = $storiesByProject[$pid]['cerradas'] ?? 0;
    $costoHistoria = $cerradas > 0 ? $costo / $cerradas : 0.0;
    $projectRows[] = array_merge($row, [
        'costo_total' => $costo,
        'margen' => $margen,
        'margen_pct' => $ingresos > 0 ? $margen / $ingresos : 0,
        'cerradas' => $cerradas,
        'costo_historia' => $costoHistoria,
    ]);
    $totals['ingresos'] += $ingresos;
    $totals['costo'] += $costo;
    $totals['mano_obra'] += (float)$row['costo_mano_obra'];
    $totals['cloud'] += (float)$row['costo_cloud'];
    $totals['margen'] += $margen;
    $totals['horas'] += (float)$row['horas'];
    $totals['billable'] += (float)$row['horas_billable'];
    $totals['cerradas'] += $cerradas;

    $addToBucket = static function (array &$bucket, string $name, float $ingresos, float $costo, float $margen): void {
        $name = trim($name) ?: 'Sin clasificar';
        if (!isset($bucket[$name])) {
            $bucket[$name] = ['nombre' => $name, 'ingresos' => 0.0, 'costo' => 0.0, 'margen' => 0.0, 'proyectos' => 0];
        }
        $bucket[$name]['ingresos'] += $ingresos;
        $bucket[$name]['costo'] += $costo;
        $bucket[$name]['margen'] += $margen;
        $bucket[$name]['proyectos']++;
    };
    $addToBucket($byClient, (string)$row['cliente'], $ingresos, $costo, $margen);
    $addToBucket($byTech, (string)$row['tecnologia'], $ingresos, $costo, $margen);
    $addToBucket($byMethod, (string)$row['metodologia'], $ingresos, $costo, $margen);
}
$totals['margen_pct'] = $totals['ingresos'] > 0 ? $totals['margen'] / $totals['ingresos'] : 0;
$totals['costo_historia'] = $totals['cerradas'] > 0 ? $totals['costo'] / $totals['cerradas'] : 0;

$sortByMargin = static function (array $a, array $b): int {
    return $b['margen'] <=> $a['margen'];
};
uasort($byClient, $sortByMargin);
uasort($byTech, $sortByMargin);
uasort($byMethod, $sortByMargin);

$utilization = utilization_rows($from, $to, $managerId);
$evmRows = [];
foreach ($financials as $row) {
    $metrics = project_metrics((int)$row['id_proyecto']);
    if ($metrics) {
        $evmRows[] = $metrics;
    }
}

$series = monthly_series(6, $managerId);

$burnRate = 0.0;
$netBurn = 0.0;
$runway = 0.0;
$cash = 0.0;
$forecast = [];
$company = null;
if ($isCfo) {
    $company = company_metrics();
    $cash = (float)$company['caja'];
    $short = monthly_series(3);
    $burnRate = count($short['cost']) ? array_sum($short['cost']) / count($short['cost']) : 0.0;
    $netBurn = count($short['margin']) ? (array_sum($short['cost']) - array_sum($short['revenue'])) / count($short['margin']) : 0.0;
    $netBurn = max(0.0, $netBurn);
    $runway = $burnRate > 0 ? $cash / $burnRate : ($cash > 0 ? 99 : 0);
    $forecast = cashflow_forecast(3);
}

$chartClients = array_slice(array_values($byClient), 0, 6);
render_header('Reportes de rentabilidad', $user, 'reports');
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
        <h2 class="section-title">Rentabilidad multidimensional y flujo de caja</h2>
        <div class="section-sub"><?= e(fecha_larga($from)) ?> → <?= e(fecha_larga($to)) ?> · <?= $isCfo ? 'Consolidado de toda la operación' : 'Solo proyectos que gestionas' ?></div>
    </div>
    <form method="get" class="d-flex flex-wrap align-items-end gap-2">
        <div>
            <label class="form-label mb-1">Desde</label>
            <input type="date" class="form-control form-control-sm" name="desde" value="<?= e($from) ?>">
        </div>
        <div>
            <label class="form-label mb-1">Hasta</label>
            <input type="date" class="form-control form-control-sm" name="hasta" value="<?= e($to) ?>">
        </div>
        <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel"></i> Aplicar</button>
        <a class="btn btn-ghost btn-sm" href="reports.php"><i class="bi bi-arrow-counterclockwise"></i></a>
    </form>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="kpi-card" style="--accent:#4f46e5;--accent-soft:#eef2ff">
            <div class="kpi-label">Ingresos del período</div>
            <div class="kpi-value"><?= fmt_money_short($totals['ingresos']) ?></div>
            <div class="kpi-foot"><i class="bi bi-receipt"></i> Facturado y cobrado</div>
            <div class="kpi-icon"><i class="bi bi-receipt"></i></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card" style="--accent:#d97706;--accent-soft:#fef3c7">
            <div class="kpi-label">Costo directo</div>
            <div class="kpi-value"><?= fmt_money_short($totals['costo']) ?></div>
            <div class="kpi-foot"><i class="bi bi-diagram-3"></i> Mano de obra + cloud</div>
            <div class="kpi-icon"><i class="bi bi-diagram-3"></i></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card" style="--accent:<?= $totals['margen'] >= 0 ? '#16a34a' : '#e11d48' ?>;--accent-soft:<?= $totals['margen'] >= 0 ? '#dcfce7' : '#ffe4e6' ?>">
            <div class="kpi-label">Margen bruto</div>
            <div class="kpi-value"><?= fmt_money_short($totals['margen']) ?></div>
            <div class="kpi-foot"><i class="bi bi-percent"></i> <?= fmt_ratio_pct($totals['margen_pct']) ?> sobre ingresos</div>
            <div class="kpi-icon"><i class="bi bi-graph-up"></i></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card" style="--accent:#0891b2;--accent-soft:#cffafe">
            <div class="kpi-label">Costo por historia</div>
            <div class="kpi-value"><?= fmt_money_short($totals['costo_historia']) ?></div>
            <div class="kpi-foot"><i class="bi bi-list-check"></i> <?= $totals['cerradas'] ?> historia(s) cerrada(s)</div>
            <div class="kpi-icon"><i class="bi bi-list-check"></i></div>
        </div>
    </div>
</div>

<?php if ($isCfo): ?>
<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Burn rate y runway</div>
            <div class="card-body">
                <div class="stat-row"><span>Caja disponible</span><strong><?= fmt_money($cash) ?></strong></div>
                <div class="stat-row"><span>Burn mensual promedio</span><strong class="text-negative"><?= fmt_money($burnRate) ?></strong></div>
                <div class="stat-row"><span>Burn neto (costo − ingreso)</span><strong><?= fmt_money($netBurn) ?></strong></div>
                <div class="stat-row"><span>Runway estimado</span><strong class="<?= $runway < 3 ? 'text-negative' : 'text-positive' ?>"><?= $runway >= 99 ? '∞' : fmt_number($runway, 1) . ' meses' ?></strong></div>
                <div class="progress mt-2"><div class="progress-bar <?= $runway < 3 ? 'bg-danger' : ($runway < 6 ? 'bg-warning' : 'bg-success') ?>" style="width:<?= min(100, ($runway / 12) * 100) ?>%"></div></div>
                <div class="text-muted small mt-2"><i class="bi bi-info-circle"></i> Calculado con la caja configurada y el promedio de costos de los últimos 3 meses.</div>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header"><span>Proyección de flujo de caja</span><span class="section-sub">Próximos 3 meses · USD</span></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Período</th><th class="num">Ingresos</th><th class="num">Egresos</th><th class="num">Neto</th><th class="num">Acumulado</th></tr></thead>
                        <tbody>
                        <?php foreach ($forecast as $item): ?>
                            <tr>
                                <td class="fw-semibold"><?= e($item['label']) ?></td>
                                <td class="num text-positive"><?= fmt_money($item['ingresos']) ?></td>
                                <td class="num text-negative"><?= fmt_money($item['egresos']) ?></td>
                                <td class="num <?= $item['neto'] >= 0 ? 'text-positive' : 'text-negative' ?>"><?= fmt_money($item['neto']) ?></td>
                                <td class="num fw-semibold"><?= fmt_money($item['acumulado']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header"><span>Ingresos, costo y margen mensual</span><span class="section-sub">Últimos 6 meses · USD</span></div>
            <div class="card-body"><div class="chart-box"><canvas id="marginSeriesChart"></canvas></div></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header"><span>Mix de ingresos por cliente</span><span class="section-sub">Período</span></div>
            <div class="card-body"><div class="chart-box"><canvas id="clientMixChart"></canvas></div></div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><span>Rentabilidad por proyecto</span><span class="section-sub"><?= count($projectRows) ?> proyecto(s)</span></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Proyecto</th><th>Cliente</th><th>Modelo</th><th class="num">Horas</th><th class="num">% Billable</th>
                    <th class="num">Ingresos</th><th class="num">Costo directo</th><th class="num">Margen</th><th class="num">Margen %</th>
                    <th class="num">Historias</th><th class="num">Costo / historia</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($projectRows as $row): ?>
                <tr>
                    <td>
                        <a class="fw-semibold text-decoration-none" href="project.php?id=<?= (int)$row['id_proyecto'] ?>"><?= e($row['codigo_unico']) ?></a>
                        <div class="text-muted small"><?= e($row['nombre_proyecto']) ?></div>
                    </td>
                    <td><?= e($row['cliente']) ?></td>
                    <td><span class="tag"><?= e($row['modelo_contratacion']) ?></span></td>
                    <td class="num"><?= fmt_hours($row['horas']) ?></td>
                    <td class="num"><?= fmt_ratio_pct((float)$row['horas'] > 0 ? (float)$row['horas_billable'] / (float)$row['horas'] : 0) ?></td>
                    <td class="num"><?= fmt_money($row['ingresos']) ?></td>
                    <td class="num"><?= fmt_money($row['costo_total']) ?></td>
                    <td class="num fw-semibold <?= (float)$row['margen'] >= 0 ? 'text-positive' : 'text-negative' ?>"><?= fmt_money($row['margen']) ?></td>
                    <td class="num"><?= fmt_ratio_pct((float)$row['margen_pct']) ?></td>
                    <td class="num"><?= (int)$row['cerradas'] ?></td>
                    <td class="num"><?= $row['costo_historia'] > 0 ? fmt_money($row['costo_historia']) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$projectRows): ?>
                <tr><td colspan="11"><div class="empty-state"><i class="bi bi-graph-down"></i>No hay información financiera en el período seleccionado.</div></td></tr>
            <?php endif; ?>
            </tbody>
            <?php if ($projectRows): ?>
            <tfoot>
                <tr class="fw-semibold">
                    <td colspan="3">Total</td>
                    <td class="num"><?= fmt_hours($totals['horas']) ?></td>
                    <td class="num"><?= fmt_ratio_pct($totals['horas'] > 0 ? $totals['billable'] / $totals['horas'] : 0) ?></td>
                    <td class="num"><?= fmt_money($totals['ingresos']) ?></td>
                    <td class="num"><?= fmt_money($totals['costo']) ?></td>
                    <td class="num"><?= fmt_money($totals['margen']) ?></td>
                    <td class="num"><?= fmt_ratio_pct($totals['margen_pct']) ?></td>
                    <td class="num"><?= $totals['cerradas'] ?></td>
                    <td class="num"><?= $totals['costo_historia'] > 0 ? fmt_money($totals['costo_historia']) : '—' ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ([['Rentabilidad por cliente', $byClient], ['Rentabilidad por tecnología', $byTech], ['Rentabilidad por metodología', $byMethod]] as [$title, $bucket]): ?>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header"><?= e($title) ?></div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Dimensión</th><th class="num">Ingresos</th><th class="num">Margen</th><th class="num">%</th></tr></thead>
                    <tbody>
                    <?php foreach ($bucket as $item): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= e($item['nombre']) ?></div>
                                <div class="text-muted small"><?= (int)$item['proyectos'] ?> proyecto(s)</div>
                            </td>
                            <td class="num"><?= fmt_money($item['ingresos']) ?></td>
                            <td class="num <?= $item['margen'] >= 0 ? 'text-positive' : 'text-negative' ?>"><?= fmt_money($item['margen']) ?></td>
                            <td class="num"><?= fmt_ratio_pct($item['ingresos'] > 0 ? $item['margen'] / $item['ingresos'] : 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$bucket): ?><tr><td colspan="4" class="text-muted text-center py-3">Sin datos</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card mb-4">
    <div class="card-header"><span>Matriz de utilización</span><span class="section-sub"><?= $isCfo ? 'Equipo de desarrollo · horas y valor' : 'Equipo de tus proyectos' ?></span></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr><th>Colaborador</th><th>Nivel</th><th class="num">Horas</th><th class="num">Billable</th><th class="num">Operativa</th><th class="num">I+D</th><th class="num">% Billable</th><?php if ($isCfo): ?><th class="num">Costo</th><th class="num">Valor facturable</th><th class="num">Margen</th><?php endif; ?></tr>
            </thead>
            <tbody>
            <?php foreach ($utilization as $row): ?>
                <?php
                $hours = (float)$row['horas'];
                $ratio = $hours > 0 ? (float)$row['billable'] / $hours : 0;
                ?>
                <tr>
                    <td class="fw-semibold"><?= e($row['nombre_completo']) ?></td>
                    <td><span class="tag"><?= e($row['nivel']) ?></span></td>
                    <td class="num"><?= fmt_hours($hours) ?></td>
                    <td class="num"><?= fmt_hours($row['billable']) ?></td>
                    <td class="num"><?= fmt_hours($row['operativa']) ?></td>
                    <td class="num"><?= fmt_hours($row['id']) ?></td>
                    <td class="num" style="min-width:130px">
                        <?= fmt_ratio_pct($ratio, 0) ?>
                        <div class="progress mt-1"><div class="progress-bar <?= $ratio >= 0.7 ? 'bg-success' : ($ratio >= 0.5 ? 'bg-warning' : 'bg-danger') ?>" style="width:<?= min(100, $ratio * 100) ?>%"></div></div>
                    </td>
                    <?php if ($isCfo): ?>
                        <td class="num"><?= fmt_money($row['costo']) ?></td>
                        <td class="num"><?= fmt_money($row['valor']) ?></td>
                        <td class="num <?= ((float)$row['valor'] - (float)$row['costo']) >= 0 ? 'text-positive' : 'text-negative' ?>"><?= fmt_money((float)$row['valor'] - (float)$row['costo']) ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$utilization): ?><tr><td colspan="<?= $isCfo ? 10 : 7 ?>"><div class="empty-state"><i class="bi bi-people"></i>No hay horas registradas en el período.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><span>EVM · valor ganado por proyecto</span><span class="section-sub">CPI, EAC y desviación</span></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr><th>Proyecto</th><th class="num">Avance</th><th class="num">BAC</th><th class="num">EV</th><th class="num">AC</th><th class="num">CPI</th><th class="num">EAC</th><th class="num">VAC</th><th class="num">Margen</th></tr>
            </thead>
            <tbody>
            <?php foreach ($evmRows as $row): ?>
                <tr>
                    <td>
                        <a class="fw-semibold text-decoration-none" href="project.php?id=<?= (int)$row['id_proyecto'] ?>"><?= e($row['codigo_unico']) ?></a>
                        <div class="text-muted small"><?= e($row['nombre_proyecto']) ?></div>
                    </td>
                    <td class="num" style="min-width:120px">
                        <?= fmt_ratio_pct((float)$row['avance'], 0) ?>
                        <div class="progress mt-1"><div class="progress-bar bg-primary" style="width:<?= min(100, (float)$row['avance'] * 100) ?>%"></div></div>
                    </td>
                    <td class="num"><?= fmt_money($row['bac']) ?></td>
                    <td class="num"><?= fmt_money($row['ev']) ?></td>
                    <td class="num"><?= fmt_money($row['ac']) ?></td>
                    <td class="num fw-semibold <?= (float)$row['cpi'] >= 1 ? 'text-positive' : 'text-negative' ?>"><?= fmt_number($row['cpi'], 2) ?></td>
                    <td class="num"><?= fmt_money($row['eac']) ?></td>
                    <td class="num <?= (float)$row['vac'] >= 0 ? 'text-positive' : 'text-negative' ?>"><?= fmt_money($row['vac']) ?></td>
                    <td class="num <?= (float)$row['margen_bruto'] >= 0 ? 'text-positive' : 'text-negative' ?>"><?= fmt_money($row['margen_bruto']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$evmRows): ?><tr><td colspan="9"><div class="empty-state"><i class="bi bi-clipboard-data"></i>No hay proyectos visibles con datos EVM.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const series = document.getElementById('marginSeriesChart');
    if (series) {
        new Chart(series, {
            type: 'bar',
            data: {
                labels: <?= json_encode($series['labels']) ?>,
                datasets: [
                    { label: 'Ingresos', data: <?= json_encode($series['revenue']) ?>, backgroundColor: 'rgba(79,70,229,.82)', borderRadius: 5 },
                    { label: 'Costo directo', data: <?= json_encode($series['cost']) ?>, backgroundColor: 'rgba(217,119,6,.72)', borderRadius: 5 },
                    { label: 'Margen', data: <?= json_encode($series['margin']) ?>, type: 'line', borderColor: '#16a34a', backgroundColor: '#16a34a', tension: .35, borderWidth: 2, pointRadius: 3 }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { ticks: { callback: v => 'USD ' + v } } } }
        });
    }
    const mix = document.getElementById('clientMixChart');
    if (mix) {
        new Chart(mix, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_column($chartClients, 'nombre')) ?>,
                datasets: [{ data: <?= json_encode(array_map(fn($c) => round($c['ingresos'], 2), $chartClients)) ?>, backgroundColor: ['#4f46e5','#0891b2','#16a34a','#d97706','#e11d48','#7c3aed'], borderWidth: 0 }]
            },
            options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'bottom' } } }
        });
    }
});
</script>
<?php
render_footer();

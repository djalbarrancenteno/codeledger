<?php
require_once 'includes/bootstrap.php';
require_once 'includes/layout.php';

$user = require_login();
$projectId = (int)($_GET['id'] ?? 0);
$project = fetch_one(
    'SELECT p.*, c.nombre AS cliente, c.industria, u.nombre_completo AS manager
     FROM Proyecto p JOIN Cliente c ON c.id_cliente = p.id_cliente
     LEFT JOIN Usuario u ON u.id_usuario = p.id_manager WHERE p.id_proyecto = ?',
    [$projectId]
);
if (!$project) {
    flash('danger', 'Proyecto no encontrado.');
    redirect('projects.php');
}
if (is_pm($user) && (int)$project['id_manager'] !== (int)$user['id_usuario']) {
    flash('danger', 'Solo puedes ver los proyectos que gestionas.');
    redirect('projects.php');
}

$canManage = can_manage_project($user, $project);
$showFinance = can_view_financials($user);
$showCosts = is_cfo($user);
$tab = (string)($_GET['tab'] ?? 'resumen');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'add_hito') {
        $name = trim((string)($_POST['nombre_hito'] ?? ''));
        $phase = (string)($_POST['fase_contable'] ?? 'Desarrollo');
        $opex = (float)($_POST['presupuesto_opex'] ?? 0);
        $capex = (float)($_POST['presupuesto_capex'] ?? 0);
        $value = (float)($_POST['valor_facturable'] ?? 0);
        $state = (string)($_POST['estado'] ?? 'Pendiente');
        if ($name === '') {
            flash('danger', 'El nombre del hito es obligatorio.');
        } else {
            q(
                'INSERT INTO Hito (id_proyecto, nombre_hito, fase_contable, presupuesto_opex, presupuesto_capex, valor_facturable, estado, fecha_inicio, fecha_entrega) VALUES (?,?,?,?,?,?,?,?,?)',
                [$projectId, $name, in_array($phase, ['Investigacion', 'Desarrollo'], true) ? $phase : 'Desarrollo', $opex, $capex, $value, in_array($state, ['Pendiente', 'En Curso', 'Entregado'], true) ? $state : 'Pendiente', ($_POST['fecha_inicio'] ?? null) ?: null, ($_POST['fecha_entrega'] ?? null) ?: null]
            );
            audit('crear', 'Hito', (int)db()->lastInsertId(), "Hito $name en {$project['codigo_unico']}");
            flash('success', 'Hito agregado.');
        }
        redirect('project.php?id=' . $projectId . '&tab=hitos');
    }

    if ($action === 'add_tarea') {
        $hitoId = (int)($_POST['id_hito'] ?? 0);
        $hito = fetch_one('SELECT * FROM Hito WHERE id_hito = ? AND id_proyecto = ?', [$hitoId, $projectId]);
        $title = trim((string)($_POST['titulo'] ?? ''));
        if (!$hito || $title === '') {
            flash('danger', 'Selecciona un hito válido e ingresa el título de la tarea.');
        } else {
            q(
                'INSERT INTO Tarea (id_hito, issue_id_github, titulo, historia_usuario, peso_horas_estimadas, estado_tarea, categoria_contable, fecha_creacion) VALUES (?,?,?,?,?,?,?,?)',
                [
                    $hitoId, trim((string)($_POST['issue_id_github'] ?? '')) ?: null, $title,
                    trim((string)($_POST['historia_usuario'] ?? '')) ?: null,
                    (float)($_POST['peso_horas_estimadas'] ?? 0),
                    'Open',
                    in_array($_POST['categoria_contable'] ?? '', ['Billable', 'Operativa', 'I+D'], true) ? $_POST['categoria_contable'] : 'Billable',
                    date('Y-m-d'),
                ]
            );
            audit('crear', 'Tarea', (int)db()->lastInsertId(), "Tarea $title en {$project['codigo_unico']}");
            flash('success', 'Tarea agregada.');
        }
        redirect('project.php?id=' . $projectId . '&tab=hitos');
    }

    if ($action === 'toggle_tarea') {
        $taskId = (int)($_POST['id_tarea'] ?? 0);
        $task = fetch_one('SELECT t.* FROM Tarea t JOIN Hito h ON h.id_hito = t.id_hito WHERE t.id_tarea = ? AND h.id_proyecto = ?', [$taskId, $projectId]);
        if ($task) {
            $new = $task['estado_tarea'] === 'Closed' ? 'Open' : 'Closed';
            q('UPDATE Tarea SET estado_tarea = ? WHERE id_tarea = ?', [$new, $taskId]);
            audit('actualizar', 'Tarea', $taskId, "Estado de tarea cambiado a $new");
            flash('success', 'Estado de la tarea actualizado.');
        }
        redirect('project.php?id=' . $projectId . '&tab=hitos');
    }

    if ($action === 'entregar_hito') {
        $hitoId = (int)($_POST['id_hito'] ?? 0);
        $hito = fetch_one('SELECT * FROM Hito WHERE id_hito = ? AND id_proyecto = ?', [$hitoId, $projectId]);
        if ($hito) {
            q("UPDATE Hito SET estado = 'Entregado', fecha_entrega = COALESCE(fecha_entrega, CURDATE()) WHERE id_hito = ?", [$hitoId]);
            audit('actualizar', 'Hito', $hitoId, 'Hito marcado como entregado');
            flash('success', 'Hito marcado como entregado. Ya puede facturarse si tiene valor asignado.');
        }
        redirect('project.php?id=' . $projectId . '&tab=hitos');
    }

    if ($action === 'estado_proyecto') {
        $state = (string)($_POST['estado'] ?? '');
        if (in_array($state, ['En Investigacion', 'En Desarrollo', 'En Produccion', 'Cerrado'], true)) {
            q('UPDATE Proyecto SET estado = ? WHERE id_proyecto = ?', [$state, $projectId]);
            audit('actualizar', 'Proyecto', $projectId, "Estado cambiado a $state");
            flash('success', 'Estado del proyecto actualizado.');
        }
        redirect('project.php?id=' . $projectId);
    }
}

$metrics = project_metrics($projectId);
$phaseCosts = project_phase_costs($projectId);
$accounting = project_accounting($projectId);
$asset = fetch_one('SELECT * FROM ActivoIntangible WHERE id_proyecto = ?', [$projectId]);
$hitos = fetch_all('SELECT * FROM Hito WHERE id_proyecto = ? ORDER BY id_hito', [$projectId]);
$tasks = fetch_all(
    'SELECT t.*, h.nombre_hito, h.fase_contable, h.id_hito,
            COALESCE(SUM(r.horas_incurridas),0) horas, COALESCE(SUM(CASE WHEN r.estado_aprobacion IN ("Aprobado","Facturado") THEN r.horas_incurridas * r.fully_loaded_rate_hist ELSE 0 END),0) costo
     FROM Tarea t JOIN Hito h ON h.id_hito = t.id_hito
     LEFT JOIN RegistroTiempo r ON r.id_tarea = t.id_tarea
     WHERE h.id_proyecto = ?
     GROUP BY t.id_tarea, h.id_hito, h.nombre_hito, h.fase_contable
     ORDER BY h.id_hito, t.id_tarea',
    [$projectId]
);
$tasksByHito = [];
foreach ($tasks as $task) { $tasksByHito[(int)$task['id_hito']][] = $task; }
$cloudByHito = [];
foreach (fetch_all('SELECT id_hito, SUM(monto_cargado) total FROM ConsumoFinOps WHERE id_proyecto = ? GROUP BY id_hito', [$projectId]) as $row) {
    $cloudByHito[(int)$row['id_hito']] = (float)$row['total'];
}
$timesheets = fetch_all(
    'SELECT r.*, u.nombre_completo, u.nivel, t.titulo, t.categoria_contable, h.nombre_hito
     FROM RegistroTiempo r JOIN Usuario u ON u.id_usuario = r.id_usuario JOIN Tarea t ON t.id_tarea = r.id_tarea JOIN Hito h ON h.id_hito = t.id_hito
     WHERE h.id_proyecto = ? ' . (is_dev($user) ? 'AND r.id_usuario = ' . (int)$user['id_usuario'] : '') . '
     ORDER BY r.fecha_registro DESC, r.id_registro DESC LIMIT 120',
    [$projectId]
);
$clouds = fetch_all('SELECT c.*, h.nombre_hito FROM ConsumoFinOps c LEFT JOIN Hito h ON h.id_hito = c.id_hito WHERE c.id_proyecto = ? ORDER BY c.fecha_consumo DESC LIMIT 100', [$projectId]);
$invoices = fetch_all('SELECT * FROM ComprobanteFacturacion WHERE id_proyecto = ? ORDER BY created_at DESC', [$projectId]);
$alerts = fetch_all('SELECT * FROM AlertaFinOps WHERE id_proyecto = ? ORDER BY FIELD(nivel,"Crítica","Advertencia","Info"), fecha DESC', [$projectId]);
$retainer = fetch_one('SELECT * FROM ContratoRetainer WHERE id_proyecto = ?', [$projectId]);
$retainerSummary = $retainer ? retainer_summary((int)$retainer['id_retainer']) : null;

render_header($project['codigo_unico'] . ' · ' . $project['nombre_proyecto'], $user, 'projects');
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="project-code"><?= e($project['codigo_unico']) ?></span>
            <?= status_badge($project['estado']) ?>
            <span class="tag"><?= e($project['modelo_contratacion']) ?></span>
        </div>
        <h2 class="section-title" style="font-size:1.35rem"><?= e($project['nombre_proyecto']) ?></h2>
        <div class="section-sub mt-1"><?= e($project['cliente']) ?> · <?= e($project['tecnologia']) ?> · <?= e($project['metodologia']) ?> · PM: <?= e($project['manager'] ?: 'Sin asignar') ?></div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($canManage): ?>
            <form method="post" class="d-flex gap-2">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="estado_proyecto">
                <select class="form-select form-select-sm" name="estado" style="width:auto">
                    <?php foreach (['En Investigacion', 'En Desarrollo', 'En Produccion', 'Cerrado'] as $state): ?>
                        <option <?= $project['estado'] === $state ? 'selected' : '' ?>><?= e($state) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-ghost" type="submit">Actualizar</button>
            </form>
        <?php endif; ?>
        <a href="timesheets.php?proyecto=<?= $projectId ?>" class="btn btn-sm btn-ghost"><i class="bi bi-clock-history"></i> Timesheets</a>
        <?php if ($showFinance): ?><a href="billing.php?proyecto=<?= $projectId ?>" class="btn btn-sm btn-primary"><i class="bi bi-receipt"></i> Facturación</a><?php endif; ?>
    </div>
</div>

<?php if ($showFinance): ?>
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="kpi-card" style="--accent:#4f46e5;--accent-soft:#eef2ff"><div class="kpi-label">Presupuesto BAC</div><div class="kpi-value"><?= fmt_money_short($metrics['bac']) ?></div><div class="kpi-foot"><i class="bi bi-bullseye"></i> <?= fmt_ratio_pct($metrics['consumo_presupuesto']) ?> consumido</div><div class="kpi-icon"><i class="bi bi-bullseye"></i></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card" style="--accent:#e11d48;--accent-soft:#ffe4e6"><div class="kpi-label">Costo real (AC)</div><div class="kpi-value"><?= fmt_money_short($metrics['ac']) ?></div><div class="kpi-foot"><i class="bi bi-people"></i> <?= fmt_money_short($metrics['costo_mano_obra']) ?> mano de obra</div><div class="kpi-icon"><i class="bi bi-wallet2"></i></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card" style="--accent:#0d9488;--accent-soft:#ccfbf1"><div class="kpi-label">CPI / EAC</div><div class="kpi-value <?= $metrics['cpi'] >= 1 ? 'text-positive' : 'text-negative' ?>"><?= number_format($metrics['cpi'], 2, ',', '.') ?></div><div class="kpi-foot"><i class="bi bi-arrow-right-circle"></i> EAC <?= fmt_money_short($metrics['eac']) ?></div><div class="kpi-icon"><i class="bi bi-activity"></i></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card" style="--accent:<?= $metrics['margen_bruto'] >= 0 ? '#16a34a' : '#e11d48' ?>;--accent-soft:<?= $metrics['margen_bruto'] >= 0 ? '#dcfce7' : '#ffe4e6' ?>"><div class="kpi-label">Margen bruto</div><div class="kpi-value <?= $metrics['margen_bruto'] >= 0 ? 'text-positive' : 'text-negative' ?>"><?= fmt_money_short($metrics['margen_bruto']) ?></div><div class="kpi-foot"><i class="bi bi-percent"></i> <?= fmt_ratio_pct($metrics['margen_pct']) ?> sobre ingresos</div><div class="kpi-icon"><i class="bi bi-graph-up"></i></div></div></div>
</div>
<?php else: ?>
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="kpi-card" style="--accent:#4f46e5;--accent-soft:#eef2ff"><div class="kpi-label">Avance del proyecto</div><div class="kpi-value"><?= fmt_ratio_pct($metrics['avance'], 0) ?></div><div class="kpi-foot"><i class="bi bi-list-check"></i> <?= $metrics['tareas_cerradas'] ?>/<?= $metrics['tareas_total'] ?> tareas</div><div class="kpi-icon"><i class="bi bi-graph-up-arrow"></i></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card" style="--accent:#0d9488;--accent-soft:#ccfbf1"><div class="kpi-label">Mis horas</div><div class="kpi-value"><?= fmt_number($metrics['horas'], 1) ?></div><div class="kpi-foot"><i class="bi bi-clock"></i> Registradas en el proyecto</div><div class="kpi-icon"><i class="bi bi-clock-history"></i></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card" style="--accent:#d97706;--accent-soft:#fef3c7"><div class="kpi-label">Hitos entregados</div><div class="kpi-value"><?= $metrics['hitos_entregados'] ?>/<?= $metrics['hitos_total'] ?></div><div class="kpi-foot"><i class="bi bi-flag"></i> Fases completadas</div><div class="kpi-icon"><i class="bi bi-flag"></i></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card" style="--accent:#0891b2;--accent-soft:#cffafe"><div class="kpi-label">Tareas cerradas</div><div class="kpi-value"><?= $metrics['tareas_cerradas'] ?></div><div class="kpi-foot"><i class="bi bi-check2-square"></i> De <?= $metrics['tareas_total'] ?> totales</div><div class="kpi-icon"><i class="bi bi-check2-square"></i></div></div></div>
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <div>
                <span class="fw-semibold">Avance ponderado por historias de usuario</span>
                <span class="text-muted small ms-2"><?= fmt_number($metrics['horas'], 1) ?> de <?= fmt_number($project['horas_estimadas'], 0) ?> horas estimadas</span>
            </div>
            <div class="fw-bold"><?= fmt_ratio_pct($metrics['avance']) ?></div>
        </div>
        <div class="progress" style="height:10px"><div class="progress-bar bg-primary" style="width:<?= min(100, $metrics['avance'] * 100) ?>%"></div></div>
        <div class="d-flex flex-wrap gap-4 mt-3 text-muted small">
            <span><i class="bi bi-calendar3"></i> Inicio <?= fmt_date($project['fecha_inicio']) ?></span>
            <span><i class="bi bi-calendar-check"></i> Fin estimado <?= fmt_date($project['fecha_fin_estimada']) ?></span>
            <span><i class="bi bi-clock"></i> <?= fmt_number($metrics['horas_billable'], 1) ?> h facturables · <?= fmt_number($metrics['horas_operativas'], 1) ?> h operativas · <?= fmt_number($metrics['horas_id'], 1) ?> h I+D</span>
        </div>
    </div>
</div>

<ul class="nav nav-pills tabs-nav" id="projectTabs" role="tablist">
    <li class="nav-item"><button class="nav-link <?= $tab === 'resumen' ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#tab-resumen" type="button">Resumen</button></li>
    <li class="nav-item"><button class="nav-link <?= $tab === 'hitos' ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#tab-hitos" type="button">Hitos y tareas</button></li>
    <li class="nav-item"><button class="nav-link <?= $tab === 'timesheets' ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#tab-timesheets" type="button">Timesheets</button></li>
    <?php if ($showFinance): ?>
        <li class="nav-item"><button class="nav-link <?= $tab === 'finops' ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#tab-finops" type="button">Costos FinOps</button></li>
        <li class="nav-item"><button class="nav-link <?= $tab === 'facturacion' ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#tab-facturacion" type="button">Facturación</button></li>
        <li class="nav-item"><button class="nav-link <?= $tab === 'contabilidad' ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#tab-contabilidad" type="button">Contabilidad</button></li>
    <?php endif; ?>
</ul>

<div class="tab-content">
    <div class="tab-pane fade <?= $tab === 'resumen' ? 'show active' : '' ?>" id="tab-resumen">
        <div class="row g-3">
            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-header">Ficha del proyecto</div>
                    <div class="card-body">
                        <div class="stat-row"><span>Cliente</span><strong><?= e($project['cliente']) ?></strong></div>
                        <div class="stat-row"><span>Industria</span><strong><?= e($project['industria']) ?></strong></div>
                        <div class="stat-row"><span>Modelo</span><strong><?= e($project['modelo_contratacion']) ?></strong></div>
                        <div class="stat-row"><span>Tecnología</span><strong><?= e($project['tecnologia']) ?></strong></div>
                        <div class="stat-row"><span>Metodología</span><strong><?= e($project['metodologia']) ?></strong></div>
                        <div class="stat-row"><span>Project Manager</span><strong><?= e($project['manager'] ?: 'Sin asignar') ?></strong></div>
                        <div class="stat-row"><span>Moneda del contrato</span><strong><?= e($project['moneda_contrato']) ?></strong></div>
                        <?php if ($showFinance): ?><div class="stat-row"><span>Tarifa fija total</span><strong><?= $project['tarifa_fija_total'] > 0 ? fmt_money($project['tarifa_fija_total']) : 'No aplica' ?></strong></div><?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if ($showFinance): ?>
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header">P&L y valor ganado</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <div class="stat-row"><span>Ingresos facturados</span><strong><?= fmt_money($metrics['ingresos_facturados']) ?></strong></div>
                                <div class="stat-row"><span>Ingresos reconocidos</span><strong><?= fmt_money($metrics['ingresos_reconocidos']) ?></strong></div>
                                <div class="stat-row"><span>Mano de obra</span><strong><?= fmt_money($metrics['costo_mano_obra']) ?></strong></div>
                                <div class="stat-row"><span>Cloud / APIs</span><strong><?= fmt_money($metrics['costo_insumos']) ?></strong></div>
                                <div class="stat-row"><span>Margen bruto</span><strong class="<?= $metrics['margen_bruto'] >= 0 ? 'text-positive' : 'text-negative' ?>"><?= fmt_money($metrics['margen_bruto']) ?></strong></div>
                            </div>
                            <div class="col-sm-6">
                                <div class="stat-row"><span>Valor ganado (EV)</span><strong><?= fmt_money($metrics['ev']) ?></strong></div>
                                <div class="stat-row"><span>Costo actual (AC)</span><strong><?= fmt_money($metrics['ac']) ?></strong></div>
                                <div class="stat-row"><span>CPI</span><strong class="<?= $metrics['cpi'] >= 1 ? 'text-positive' : 'text-negative' ?>"><?= number_format($metrics['cpi'], 3, ',', '.') ?></strong></div>
                                <div class="stat-row"><span>EAC</span><strong><?= fmt_money($metrics['eac']) ?></strong></div>
                                <div class="stat-row"><span>VAC</span><strong class="<?= $metrics['vac'] >= 0 ? 'text-positive' : 'text-negative' ?>"><?= fmt_money($metrics['vac']) ?></strong></div>
                            </div>
                        </div>
                        <div class="mt-3 p-3 rounded" style="background:#f8fafc;border:1px solid var(--cl-line)">
                            <div class="d-flex justify-content-between"><span class="text-muted small">Valor de horas aprobadas sin facturar</span><strong><?= fmt_money($metrics['valor_no_facturado']) ?></strong></div>
                            <div class="d-flex justify-content-between mt-1"><span class="text-muted small">Cuentas por cobrar del proyecto</span><strong><?= fmt_money($metrics['cuentas_por_cobrar']) ?></strong></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($showFinance && $alerts): ?>
        <div class="card mt-3">
            <div class="card-header">Alertas del proyecto</div>
            <div class="card-body">
                <?php foreach (array_slice($alerts, 0, 4) as $alert): ?>
                    <div class="d-flex justify-content-between align-items-start gap-2 py-2 border-bottom">
                        <div><div class="fw-semibold small"><?= e($alert['tipo']) ?></div><div class="text-muted small"><?= e($alert['mensaje']) ?></div></div>
                        <?= status_badge($alert['nivel']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="tab-pane fade <?= $tab === 'hitos' ? 'show active' : '' ?>" id="tab-hitos">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div><h3 class="section-title">Hitos y tareas</h3><div class="section-sub">Presupuesto por fase, avance y categorización contable de horas</div></div>
            <?php if ($canManage): ?>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-ghost" data-bs-toggle="modal" data-bs-target="#newHitoModal"><i class="bi bi-flag"></i> Nuevo hito</button>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newTaskModal"><i class="bi bi-plus-lg"></i> Nueva tarea</button>
                </div>
            <?php endif; ?>
        </div>

        <?php foreach ($hitos as $hito): ?>
            <?php
            $hitoTasks = $tasksByHito[(int)$hito['id_hito']] ?? [];
            $estimated = array_sum(array_map(fn($t) => (float)$t['peso_horas_estimadas'], $hitoTasks));
            $closed = array_sum(array_map(fn($t) => $t['estado_tarea'] === 'Closed' ? (float)$t['peso_horas_estimadas'] : 0, $hitoTasks));
            $actualHours = array_sum(array_map(fn($t) => (float)$t['horas'], $hitoTasks));
            $laborCost = array_sum(array_map(fn($t) => (float)$t['costo'], $hitoTasks));
            $hitoCloud = $cloudByHito[(int)$hito['id_hito']] ?? 0;
            $hitoProgress = $estimated > 0 ? $closed / $estimated : 0;
            $hitoBudget = (float)$hito['presupuesto_opex'] + (float)$hito['presupuesto_capex'];
            ?>
            <div class="card mb-3">
                <div class="card-header">
                    <div>
                        <span class="fw-bold"><?= e($hito['nombre_hito']) ?></span>
                        <span class="tag ms-2"><?= e($hito['fase_contable']) ?></span>
                        <span class="ms-2"><?= status_badge($hito['estado']) ?></span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($showFinance): ?><span class="text-muted small"><?= fmt_money_short($hitoBudget) ?></span><?php endif; ?>
                        <?php if ($canManage && $hito['estado'] !== 'Entregado'): ?>
                            <form method="post" class="d-inline" data-confirm="¿Marcar este hito como entregado?">
                                <?= csrf_field() ?><input type="hidden" name="action" value="entregar_hito"><input type="hidden" name="id_hito" value="<?= (int)$hito['id_hito'] ?>">
                                <button class="btn btn-sm btn-outline-success" type="submit"><i class="bi bi-check2"></i> Entregar</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body pb-2">
                    <div class="d-flex flex-wrap gap-4 mb-2 small text-muted">
                        <span><i class="bi bi-list-check"></i> <?= fmt_ratio_pct($hitoProgress, 0) ?> completado</span>
                        <span><i class="bi bi-clock"></i> <?= fmt_number($actualHours, 1) ?> / <?= fmt_number($estimated, 0) ?> h</span>
                        <?php if ($showCosts): ?><span><i class="bi bi-cash"></i> <?= fmt_money_short($laborCost + $hitoCloud) ?> costo</span><?php endif; ?>
                        <span><i class="bi bi-calendar"></i> <?= fmt_date($hito['fecha_entrega']) ?></span>
                    </div>
                    <div class="progress mb-3"><div class="progress-bar bg-success" style="width:<?= min(100, $hitoProgress * 100) ?>%"></div></div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead><tr><th>Tarea / Historia</th><th>Categoría</th><th class="num">Estimado</th><th class="num">Real</th><?php if ($showCosts): ?><th class="num">Costo</th><?php endif; ?><th>Estado</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php foreach ($hitoTasks as $task): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= e($task['titulo']) ?></div>
                                        <div class="text-muted small"><?= e($task['issue_id_github']) ?> · <?= e($task['historia_usuario']) ?></div>
                                    </td>
                                    <td><span class="tag"><?= e($task['categoria_contable']) ?></span></td>
                                    <td class="num"><?= fmt_number($task['peso_horas_estimadas'], 0) ?> h</td>
                                    <td class="num"><?= fmt_number($task['horas'], 1) ?> h</td>
                                    <?php if ($showCosts): ?><td class="num"><?= fmt_money_short($task['costo']) ?></td><?php endif; ?>
                                    <td><?= status_badge($task['estado_tarea']) ?></td>
                                    <?php if ($canManage): ?>
                                        <td class="text-end">
                                            <form method="post">
                                                <?= csrf_field() ?><input type="hidden" name="action" value="toggle_tarea"><input type="hidden" name="id_tarea" value="<?= (int)$task['id_tarea'] ?>">
                                                <button class="btn btn-sm btn-ghost" type="submit" title="Cambiar estado"><i class="bi bi-arrow-repeat"></i></button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$hitoTasks): ?><tr><td colspan="<?= $showCosts ? 7 : 6 ?>" class="text-center text-muted py-3">Sin tareas registradas.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$hitos): ?><div class="card"><div class="empty-state"><i class="bi bi-flag"></i>Este proyecto aún no tiene hitos.</div></div><?php endif; ?>
    </div>

    <div class="tab-pane fade <?= $tab === 'timesheets' ? 'show active' : '' ?>" id="tab-timesheets">
        <div class="card">
            <div class="card-header"><span>Registros de tiempo del proyecto</span><a href="timesheets.php?proyecto=<?= $projectId ?>" class="small">Registrar / aprobar horas</a></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead><tr><th>Fecha</th><th>Colaborador</th><th>Tarea</th><th>Categoría</th><th class="num">Horas</th><?php if ($showCosts): ?><th class="num">Costo</th><?php endif; ?><th>Estado</th></tr></thead>
                    <tbody>
                    <?php foreach ($timesheets as $row): ?>
                        <tr>
                            <td class="text-muted small"><?= fmt_date($row['fecha_registro']) ?></td>
                            <td><div class="fw-semibold"><?= e($row['nombre_completo']) ?></div><div class="text-muted small"><?= e($row['nivel']) ?></div></td>
                            <td><div class="fw-semibold"><?= e($row['titulo']) ?></div><div class="text-muted small"><?= e($row['nombre_hito']) ?></div></td>
                            <td><span class="tag"><?= e($row['categoria_contable']) ?></span></td>
                            <td class="num fw-semibold"><?= fmt_number($row['horas_incurridas'], 1) ?></td>
                            <?php if ($showCosts): ?><td class="num"><?= fmt_money_short($row['horas_incurridas'] * $row['fully_loaded_rate_hist']) ?></td><?php endif; ?>
                            <td><?= status_badge($row['estado_aprobacion']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$timesheets): ?><tr><td colspan="<?= $showCosts ? 7 : 6 ?>" class="text-center text-muted py-4">Sin registros de tiempo.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($showFinance): ?>
    <div class="tab-pane fade <?= $tab === 'finops' ? 'show active' : '' ?>" id="tab-finops">
        <div class="card">
            <div class="card-header"><span>Consumos cloud y APIs imputados</span><a href="finops.php?proyecto=<?= $projectId ?>" class="small">Gestionar FinOps</a></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead><tr><th>Fecha</th><th>Proveedor</th><th>Servicio</th><th>Categoría</th><th>Hito</th><th class="num">Monto</th></tr></thead>
                    <tbody>
                    <?php foreach ($clouds as $row): ?>
                        <tr>
                            <td class="text-muted small"><?= fmt_date($row['fecha_consumo']) ?></td>
                            <td class="fw-semibold"><?= e($row['proveedor']) ?></td>
                            <td><?= e($row['servicio']) ?></td>
                            <td><span class="tag"><?= e($row['categoria']) ?></span></td>
                            <td class="text-muted small"><?= e($row['nombre_hito'] ?: 'Sin asignar') ?></td>
                            <td class="num fw-semibold"><?= fmt_money($row['monto_cargado'], $row['moneda']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$clouds): ?><tr><td colspan="6" class="text-center text-muted py-4">Sin consumos registrados.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="tab-pane fade <?= $tab === 'facturacion' ? 'show active' : '' ?>" id="tab-facturacion">
        <div class="card">
            <div class="card-header"><span>Facturas y pre-facturas</span><a href="billing.php?proyecto=<?= $projectId ?>" class="small">Ir a facturación</a></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead><tr><th>Número</th><th>Tipo</th><th>Estado</th><th>Emisión</th><th>Vencimiento</th><th class="num">Monto</th><th class="num">USD</th></tr></thead>
                    <tbody>
                    <?php foreach ($invoices as $invoice): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($invoice['numero_correlativo']) ?></td>
                            <td><?= e($invoice['tipo_comprobante']) ?></td>
                            <td><?= status_badge($invoice['estado']) ?></td>
                            <td class="text-muted small"><?= fmt_date($invoice['fecha_emision']) ?></td>
                            <td class="text-muted small"><?= fmt_date($invoice['fecha_vencimiento']) ?></td>
                            <td class="num"><?= fmt_money($invoice['monto_total'], $invoice['moneda']) ?></td>
                            <td class="num fw-semibold"><?= fmt_money($invoice['monto_total_usd']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$invoices): ?><tr><td colspan="7" class="text-center text-muted py-4">Sin comprobantes.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="tab-pane fade <?= $tab === 'contabilidad' ? 'show active' : '' ?>" id="tab-contabilidad">
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">Tratamiento IAS 38</div>
                    <div class="card-body">
                        <div class="row g-2 mb-3">
                            <div class="col-6"><div class="mini-stat"><span>OpEx</span><strong><?= fmt_money_short($accounting['opex']) ?></strong></div></div>
                            <div class="col-6"><div class="mini-stat"><span>CapEx</span><strong><?= fmt_money_short($accounting['capex']) ?></strong></div></div>
                        </div>
                        <?php foreach ($accounting['fases'] as $phase => $detail): ?>
                            <div class="stat-row"><span><?= e($phase) ?> · <?= e($detail['tratamiento']) ?></span><strong><?= fmt_money($detail['total']) ?></strong></div>
                            <div class="text-muted small mb-2">Mano de obra <?= fmt_money($detail['labor']) ?> · Cloud <?= fmt_money($detail['cloud']) ?><?= $detail['vida_util_meses'] ? ' · Vida útil ' . (int)$detail['vida_util_meses'] . ' meses' : '' ?></div>
                        <?php endforeach; ?>
                        <a href="accounting.php?proyecto=<?= $projectId ?>" class="btn btn-sm btn-ghost mt-2">Configurar reglas</a>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">Activo intangible</div>
                    <div class="card-body">
                        <?php if ($asset): ?>
                            <div class="stat-row"><span>Monto capitalizado</span><strong><?= fmt_money($asset['monto_capitalizado']) ?></strong></div>
                            <div class="stat-row"><span>Amortización mensual</span><strong><?= fmt_money($asset['amortizacion_mensual']) ?></strong></div>
                            <div class="stat-row"><span>Amortización acumulada</span><strong><?= fmt_money($asset['amortizacion_acumulada']) ?></strong></div>
                            <div class="stat-row"><span>Valor neto</span><strong><?= fmt_money($asset['monto_capitalizado'] - $asset['amortizacion_acumulada']) ?></strong></div>
                            <div class="stat-row"><span>Vida útil</span><strong><?= (int)$asset['vida_util_meses'] ?> meses · <?= e($asset['estado']) ?></strong></div>
                        <?php else: ?>
                            <div class="empty-state"><i class="bi bi-journal-x"></i>No hay activo capitalizado. Configura la fase de Desarrollo como CapEx en Contabilidad.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if ($retainerSummary): ?>
            <div class="col-12">
                <div class="card">
                    <div class="card-header">Contrato Retainer · <?= e($retainerSummary['periodo']) ?></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-6 col-md-3"><div class="mini-stat"><span>Horas incluidas</span><strong><?= fmt_number($retainerSummary['horas_incluidas_mes'], 0) ?> h</strong></div></div>
                            <div class="col-6 col-md-3"><div class="mini-stat"><span>Saldo inicial</span><strong><?= fmt_number($retainerSummary['saldo_inicial'], 1) ?> h</strong></div></div>
                            <div class="col-6 col-md-3"><div class="mini-stat"><span>Consumidas</span><strong><?= fmt_number($retainerSummary['horas_consumidas'], 1) ?> h</strong></div></div>
                            <div class="col-6 col-md-3"><div class="mini-stat"><span>Saldo final</span><strong><?= fmt_number($retainerSummary['saldo_final'], 1) ?> h</strong></div></div>
                        </div>
                        <div class="progress mt-3"><div class="progress-bar <?= $retainerSummary['consumo_pct'] > 1 ? 'bg-danger' : 'bg-info' ?>" style="width:<?= min(100, $retainerSummary['consumo_pct'] * 100) ?>%"></div></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($canManage): ?>
<div class="modal fade" id="newHitoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="add_hito">
            <div class="modal-header"><h5 class="modal-title">Nuevo hito</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-8"><label class="form-label">Nombre del hito</label><input class="form-control" name="nombre_hito" required></div>
                    <div class="col-md-4"><label class="form-label">Fase contable</label><select class="form-select" name="fase_contable"><option>Desarrollo</option><option>Investigacion</option></select></div>
                    <div class="col-md-4"><label class="form-label">Presupuesto OpEx</label><input type="number" step="0.01" min="0" class="form-control" name="presupuesto_opex" value="0"></div>
                    <div class="col-md-4"><label class="form-label">Presupuesto CapEx</label><input type="number" step="0.01" min="0" class="form-control" name="presupuesto_capex" value="0"></div>
                    <div class="col-md-4"><label class="form-label">Valor facturable</label><input type="number" step="0.01" min="0" class="form-control" name="valor_facturable" value="0"></div>
                    <div class="col-md-4"><label class="form-label">Estado</label><select class="form-select" name="estado"><option>Pendiente</option><option>En Curso</option><option>Entregado</option></select></div>
                    <div class="col-md-4"><label class="form-label">Inicio</label><input type="date" class="form-control" name="fecha_inicio"></div>
                    <div class="col-md-4"><label class="form-label">Entrega</label><input type="date" class="form-control" name="fecha_entrega"></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar hito</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="newTaskModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="add_tarea">
            <div class="modal-header"><h5 class="modal-title">Nueva tarea / historia</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12"><label class="form-label">Hito</label><select class="form-select" name="id_hito" required><?php foreach ($hitos as $hito): ?><option value="<?= (int)$hito['id_hito'] ?>"><?= e($hito['nombre_hito']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-12"><label class="form-label">Título</label><input class="form-control" name="titulo" required></div>
                    <div class="col-md-6"><label class="form-label">Historia de usuario</label><input class="form-control" name="historia_usuario" placeholder="US-XX Descripción"></div>
                    <div class="col-md-6"><label class="form-label">Issue GitHub</label><input class="form-control" name="issue_id_github" placeholder="GH-123"></div>
                    <div class="col-md-4"><label class="form-label">Estimación (h)</label><input type="number" step="0.5" min="0" class="form-control" name="peso_horas_estimadas" value="8"></div>
                    <div class="col-md-4"><label class="form-label">Categoría</label><select class="form-select" name="categoria_contable"><option>Billable</option><option>Operativa</option><option>I+D</option></select></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar tarea</button></div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php
render_footer();

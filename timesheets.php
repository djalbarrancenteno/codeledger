<?php
require_once 'includes/bootstrap.php';
require_once 'includes/layout.php';

$user = require_login();
$canApprove = can_view_financials($user);
$isCfo = is_cfo($user);
$dev = is_dev($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $taskId = (int)($_POST['id_tarea'] ?? 0);
        $date = (string)($_POST['fecha_registro'] ?? date('Y-m-d'));
        $hours = (float)($_POST['horas_incurridas'] ?? 0);
        $description = trim((string)($_POST['descripcion'] ?? ''));
        $task = fetch_one(
            'SELECT t.*, h.id_proyecto FROM Tarea t JOIN Hito h ON h.id_hito = t.id_hito WHERE t.id_tarea = ?',
            [$taskId]
        );
        if (!$task || $hours <= 0 || $hours > 24 || $date > date('Y-m-d')) {
            flash('danger', 'Registro inválido. Verifica la tarea, la fecha y que las horas estén entre 0 y 24.');
        } else {
            $rate = fully_loaded_rate((int)$user['id_usuario']);
            $billable = $task['categoria_contable'] === 'Billable' ? user_billable_rate((int)$user['id_usuario'], $date) : 0;
            q(
                'INSERT INTO RegistroTiempo (id_tarea, id_usuario, horas_incurridas, estado_aprobacion, fully_loaded_rate_hist, billable_rate_hist, fecha_registro, descripcion) VALUES (?,?,?,?,?,?,?,?)',
                [$taskId, (int)$user['id_usuario'], $hours, 'Pendiente', $rate['rate'], $billable, $date, $description ?: null]
            );
            audit('crear', 'RegistroTiempo', (int)db()->lastInsertId(), "Registro de $hours h en tarea $taskId");
            flash('success', 'Horas registradas. Quedan pendientes de aprobación.');
        }
        redirect('timesheets.php');
    }

    if (in_array($action, ['approve', 'delete'], true)) {
        $id = (int)($_POST['id_registro'] ?? 0);
        $row = fetch_one(
            'SELECT r.*, p.id_manager, p.codigo_unico FROM RegistroTiempo r JOIN Tarea t ON t.id_tarea = r.id_tarea JOIN Hito h ON h.id_hito = t.id_hito JOIN Proyecto p ON p.id_proyecto = h.id_proyecto WHERE r.id_registro = ?',
            [$id]
        );
        $allowed = $row && (
            $isCfo ||
            ($canApprove && (int)$row['id_manager'] === (int)$user['id_usuario']) ||
            ($dev && (int)$row['id_usuario'] === (int)$user['id_usuario'] && $row['estado_aprobacion'] === 'Pendiente')
        );
        if (!$allowed) {
            flash('danger', 'No tienes permisos sobre ese registro.');
        } elseif ($action === 'approve' && $row['estado_aprobacion'] === 'Pendiente') {
            q("UPDATE RegistroTiempo SET estado_aprobacion = 'Aprobado' WHERE id_registro = ?", [$id]);
            audit('aprobar', 'RegistroTiempo', $id, "Aprobación de horas en {$row['codigo_unico']}");
            flash('success', 'Registro aprobado. Ya forma parte del costo real del proyecto.');
        } elseif ($action === 'delete' && $row['estado_aprobacion'] === 'Pendiente') {
            q('DELETE FROM RegistroTiempo WHERE id_registro = ?', [$id]);
            audit('eliminar', 'RegistroTiempo', $id, "Eliminación de registro pendiente");
            flash('success', 'Registro eliminado.');
        } else {
            flash('warning', 'El registro ya fue aprobado o facturado y no puede modificarse.');
        }
        redirect('timesheets.php?' . http_build_query(array_filter([
            'desde' => $_POST['return_desde'] ?? null,
            'hasta' => $_POST['return_hasta'] ?? null,
            'proyecto' => $_POST['return_proyecto'] ?? null,
            'estado' => $_POST['return_estado'] ?? null,
        ])));
    }
}

$from = (string)($_GET['desde'] ?? date('Y-m-01'));
$to = (string)($_GET['hasta'] ?? date('Y-m-d'));
$projectFilter = (int)($_GET['proyecto'] ?? 0);
$statusFilter = (string)($_GET['estado'] ?? '');
$categoryFilter = (string)($_GET['categoria'] ?? '');
$userFilter = (int)($_GET['usuario'] ?? 0);

$where = ['r.fecha_registro BETWEEN ? AND ?'];
$params = [$from, $to];
if ($dev) {
    $where[] = 'r.id_usuario = ?';
    $params[] = (int)$user['id_usuario'];
} elseif (is_pm($user)) {
    $where[] = 'p.id_manager = ?';
    $params[] = (int)$user['id_usuario'];
}
if ($projectFilter > 0) {
    $where[] = 'p.id_proyecto = ?';
    $params[] = $projectFilter;
}
if ($statusFilter !== '') {
    $where[] = 'r.estado_aprobacion = ?';
    $params[] = $statusFilter;
}
if ($categoryFilter !== '') {
    $where[] = 't.categoria_contable = ?';
    $params[] = $categoryFilter;
}
if ($userFilter > 0 && !$dev) {
    $where[] = 'r.id_usuario = ?';
    $params[] = $userFilter;
}
$sqlWhere = implode(' AND ', $where);
$rows = fetch_all(
    "SELECT r.*, u.nombre_completo, u.nivel, t.titulo, t.categoria_contable, h.nombre_hito, p.id_proyecto, p.codigo_unico, p.nombre_proyecto, p.id_manager
     FROM RegistroTiempo r JOIN Usuario u ON u.id_usuario = r.id_usuario JOIN Tarea t ON t.id_tarea = r.id_tarea JOIN Hito h ON h.id_hito = t.id_hito JOIN Proyecto p ON p.id_proyecto = h.id_proyecto
     WHERE $sqlWhere ORDER BY r.fecha_registro DESC, r.id_registro DESC LIMIT 400",
    $params
);
$summary = [
    'horas' => array_sum(array_map(fn($r) => (float)$r['horas_incurridas'], $rows)),
    'billable' => array_sum(array_map(fn($r) => $r['categoria_contable'] === 'Billable' ? (float)$r['horas_incurridas'] : 0, $rows)),
    'pendientes' => array_sum(array_map(fn($r) => $r['estado_aprobacion'] === 'Pendiente' ? (float)$r['horas_incurridas'] : 0, $rows)),
    'costo' => array_sum(array_map(fn($r) => $r['estado_aprobacion'] !== 'Pendiente' ? (float)$r['horas_incurridas'] * (float)$r['fully_loaded_rate_hist'] : 0, $rows)),
    'valor' => array_sum(array_map(fn($r) => $r['estado_aprobacion'] !== 'Pendiente' ? (float)$r['horas_incurridas'] * (float)$r['billable_rate_hist'] : 0, $rows)),
];

$projectWhere = 'p.activo = 1';
$projectParams = [];
if (is_pm($user)) {
    $projectWhere .= ' AND p.id_manager = ?';
    $projectParams[] = (int)$user['id_usuario'];
}
$projects = fetch_all("SELECT p.* FROM Proyecto p WHERE $projectWhere ORDER BY p.nombre_proyecto", $projectParams);
$tasks = fetch_all(
    'SELECT t.*, h.nombre_hito, p.id_proyecto, p.codigo_unico, p.nombre_proyecto
     FROM Tarea t JOIN Hito h ON h.id_hito = t.id_hito JOIN Proyecto p ON p.id_proyecto = h.id_proyecto
     WHERE p.activo = 1 AND t.estado_tarea = "Open" ' . (is_pm($user) ? 'AND p.id_manager = ' . (int)$user['id_usuario'] : '') . '
     ORDER BY p.nombre_proyecto, h.id_hito, t.titulo'
);
$users = $dev ? [] : fetch_all("SELECT * FROM Usuario WHERE estado_operativo = 1 ORDER BY nombre_completo");

render_header('Timesheets', $user, 'timesheets');
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
        <h2 class="section-title">Registro y aprobación de horas</h2>
        <div class="section-sub">Clasificación contable automática: Billable, Operativa e I+D</div>
    </div>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newTimeModal"><i class="bi bi-plus-lg"></i> Registrar horas</button>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="kpi-card" style="--accent:#4f46e5;--accent-soft:#eef2ff"><div class="kpi-label">Horas filtradas</div><div class="kpi-value"><?= fmt_number($summary['horas'], 1) ?> h</div><div class="kpi-foot"><i class="bi bi-funnel"></i> <?= count($rows) ?> registros</div><div class="kpi-icon"><i class="bi bi-clock"></i></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card" style="--accent:#0d9488;--accent-soft:#ccfbf1"><div class="kpi-label">Horas facturables</div><div class="kpi-value"><?= fmt_number($summary['billable'], 1) ?> h</div><div class="kpi-foot"><i class="bi bi-percent"></i> <?= fmt_ratio_pct($summary['horas'] > 0 ? $summary['billable'] / $summary['horas'] : 0) ?> del total</div><div class="kpi-icon"><i class="bi bi-cash-coin"></i></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card" style="--accent:#d97706;--accent-soft:#fef3c7"><div class="kpi-label">Pendientes</div><div class="kpi-value"><?= fmt_number($summary['pendientes'], 1) ?> h</div><div class="kpi-foot"><i class="bi bi-hourglass"></i> Requieren aprobación</div><div class="kpi-icon"><i class="bi bi-inbox"></i></div></div></div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card" style="--accent:#0891b2;--accent-soft:#cffafe">
            <div class="kpi-label"><?= $isCfo ? 'Costo aprobado' : 'Valor facturable' ?></div>
            <div class="kpi-value"><?= fmt_money_short($isCfo ? $summary['costo'] : $summary['valor']) ?></div>
            <div class="kpi-foot"><i class="bi bi-shield-check"></i> Excluye horas pendientes</div>
            <div class="kpi-icon"><i class="bi bi-wallet2"></i></div>
        </div>
    </div>
</div>

<form class="filter-bar mb-4" method="get">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label">Desde</label><input type="date" class="form-control form-control-sm" name="desde" value="<?= e($from) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label">Hasta</label><input type="date" class="form-control form-control-sm" name="hasta" value="<?= e($to) ?>"></div>
        <div class="col-md-2"><label class="form-label">Proyecto</label><select class="form-select form-select-sm" name="proyecto"><option value="">Todos</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id_proyecto'] ?>" <?= $projectFilter === (int)$p['id_proyecto'] ? 'selected' : '' ?>><?= e($p['codigo_unico']) ?> · <?= e($p['nombre_proyecto']) ?></option><?php endforeach; ?></select></div>
        <?php if (!$dev): ?>
        <div class="col-md-2"><label class="form-label">Colaborador</label><select class="form-select form-select-sm" name="usuario"><option value="">Todos</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id_usuario'] ?>" <?= $userFilter === (int)$u['id_usuario'] ? 'selected' : '' ?>><?= e($u['nombre_completo']) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <div class="col-md-2"><label class="form-label">Estado</label><select class="form-select form-select-sm" name="estado"><option value="">Todos</option><?php foreach (['Pendiente','Aprobado','Facturado'] as $s): ?><option <?= $statusFilter === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary btn-sm flex-grow-1" type="submit">Filtrar</button><a href="timesheets.php" class="btn btn-ghost btn-sm"><i class="bi bi-arrow-counterclockwise"></i></a></div>
    </div>
</form>

<div class="card">
    <div class="card-header"><span>Registros entre <?= fmt_date($from) ?> y <?= fmt_date($to) ?></span><span class="section-sub">Máximo 400 registros</span></div>
    <div class="table-responsive" data-paginate data-paginate-controls="#tsPager" data-paginate-label="registros de horas">
        <table class="table table-hover align-middle">
            <thead><tr><th>Fecha</th><?php if (!$dev): ?><th>Colaborador</th><?php endif; ?><th>Proyecto / Tarea</th><th>Categoría</th><th class="num">Horas</th><?php if ($isCfo): ?><th class="num">Costo</th><?php endif; ?><th class="num">Valor</th><th>Estado</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="text-muted small"><?= fmt_date($row['fecha_registro']) ?></td>
                    <?php if (!$dev): ?><td><div class="fw-semibold"><?= e($row['nombre_completo']) ?></div><div class="text-muted small"><?= e($row['nivel']) ?></div></td><?php endif; ?>
                    <td><div class="fw-semibold"><?= e($row['titulo']) ?></div><div class="text-muted small"><?= e($row['codigo_unico']) ?> · <?= e($row['nombre_hito']) ?></div></td>
                    <td><span class="tag"><?= e($row['categoria_contable']) ?></span></td>
                    <td class="num fw-semibold"><?= fmt_number($row['horas_incurridas'], 1) ?></td>
                    <?php if ($isCfo): ?><td class="num"><?= fmt_money_short($row['horas_incurridas'] * $row['fully_loaded_rate_hist']) ?></td><?php endif; ?>
                    <td class="num"><?= $row['billable_rate_hist'] > 0 ? fmt_money_short($row['horas_incurridas'] * $row['billable_rate_hist']) : '—' ?></td>
                    <td><?= status_badge($row['estado_aprobacion']) ?></td>
                    <td class="text-end">
                        <div class="d-flex gap-1 justify-content-end">
                        <?php if ($canApprove && $row['estado_aprobacion'] === 'Pendiente'): ?>
                            <form method="post">
                                <?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id_registro" value="<?= (int)$row['id_registro'] ?>">
                                <input type="hidden" name="return_desde" value="<?= e($from) ?>"><input type="hidden" name="return_hasta" value="<?= e($to) ?>"><input type="hidden" name="return_proyecto" value="<?= $projectFilter ?: '' ?>"><input type="hidden" name="return_estado" value="<?= e($statusFilter) ?>">
                                <button class="btn btn-sm btn-outline-success" type="submit" title="Aprobar"><i class="bi bi-check2"></i></button>
                            </form>
                        <?php endif; ?>
                        <?php if (($canApprove || ($dev && $row['estado_aprobacion'] === 'Pendiente')) && $row['estado_aprobacion'] === 'Pendiente'): ?>
                            <form method="post" data-confirm="¿Eliminar este registro pendiente?">
                                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id_registro" value="<?= (int)$row['id_registro'] ?>">
                                <button class="btn btn-sm btn-ghost" type="submit" title="Eliminar"><i class="bi bi-trash"></i></button>
                            </form>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="<?= $isCfo ? 10 : 9 ?>"><div class="empty-state"><i class="bi bi-inbox"></i>No hay registros para el filtro seleccionado.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="cl-pager-host" id="tsPager"></div>
</div>

<div class="modal fade" id="newTimeModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="create">
            <div class="modal-header"><h5 class="modal-title">Registrar horas</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Proyecto</label><select class="form-select" id="projectSelect"><option value="">Todos los proyectos</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id_proyecto'] ?>"><?= e($p['codigo_unico']) ?> · <?= e($p['nombre_proyecto']) ?></option><?php endforeach; ?></select></div>
                <div class="mb-3"><label class="form-label">Tarea</label><select class="form-select" id="taskSelect" name="id_tarea" required><option value="">Selecciona una tarea...</option><?php foreach ($tasks as $task): ?><option value="<?= (int)$task['id_tarea'] ?>" data-project="<?= (int)$task['id_proyecto'] ?>"><?= e($task['codigo_unico']) ?> · <?= e($task['titulo']) ?> [<?= e($task['categoria_contable']) ?>]</option><?php endforeach; ?></select></div>
                <div class="row g-3">
                    <div class="col-6"><label class="form-label">Fecha</label><input type="date" class="form-control" name="fecha_registro" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
                    <div class="col-6"><label class="form-label">Horas</label><input type="number" step="0.25" min="0.25" max="24" class="form-control" name="horas_incurridas" value="8" required></div>
                </div>
                <div class="mt-3"><label class="form-label">Descripción (opcional)</label><textarea class="form-control" name="descripcion" rows="2" placeholder="¿Qué se trabajó?"></textarea></div>
                <div class="alert alert-info mt-3 mb-0 py-2 small"><i class="bi bi-info-circle"></i> La tarifa de costo y venta se congela al momento del registro para preservar la trazabilidad histórica.</div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Registrar</button></div>
        </form>
    </div>
</div>
<?php
render_footer();

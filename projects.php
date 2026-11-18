<?php
require_once 'includes/bootstrap.php';
require_once 'includes/layout.php';

$user = require_login();
$canManage = can_view_financials($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create' && $canManage) {
    verify_csrf();
    $code = strtoupper(trim((string)($_POST['codigo_unico'] ?? '')));
    $name = trim((string)($_POST['nombre_proyecto'] ?? ''));
    $client = (int)($_POST['id_cliente'] ?? 0);
    $model = (string)($_POST['modelo_contratacion'] ?? 'Time & Materials');
    $bac = (float)($_POST['bac_presupuesto_inicial'] ?? 0);
    $tech = trim((string)($_POST['tecnologia'] ?? ''));
    $method = trim((string)($_POST['metodologia'] ?? ''));
    $start = $_POST['fecha_inicio'] ?? null;
    $end = $_POST['fecha_fin_estimada'] ?? null;
    $hours = (float)($_POST['horas_estimadas'] ?? 0);
    $manager = is_pm($user) ? (int)$user['id_usuario'] : (int)($_POST['id_manager'] ?? 0);
    $allowedModels = ['Time & Materials', 'Fixed Price', 'Retainer'];
    if ($code === '' || $name === '' || $client <= 0 || $bac <= 0) {
        flash('danger', 'Código, nombre, cliente y presupuesto son obligatorios.');
        redirect('projects.php');
    }
    if (!in_array($model, $allowedModels, true)) {
        $model = 'Time & Materials';
    }
    if (fetch_one('SELECT id_proyecto FROM Proyecto WHERE codigo_unico = ?', [$code])) {
        flash('danger', 'El código de proyecto ya existe.');
        redirect('projects.php');
    }
    q(
        'INSERT INTO Proyecto (id_cliente, id_manager, codigo_unico, nombre_proyecto, modelo_contratacion, bac_presupuesto_inicial, estado, moneda_contrato, tecnologia, metodologia, fecha_inicio, fecha_fin_estimada, tarifa_fija_total, horas_estimadas)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$client, $manager ?: null, $code, $name, $model, $bac, 'En Desarrollo', 'USD', $tech ?: null, $method ?: null, $start ?: null, $end ?: null, $model === 'Fixed Price' ? $bac : 0, $hours]
    );
    $projectId = (int)db()->lastInsertId();
    q('INSERT INTO ReglaContable (id_proyecto, fase, tratamiento, vida_util_meses) VALUES (?,?,36), (?,?,36)', [
        $projectId, 'Investigacion', $projectId, 'Desarrollo'
    ]);
    audit('crear', 'Proyecto', $projectId, "Proyecto $code creado");
    flash('success', 'Proyecto creado correctamente. Agrega hitos y tareas para comenzar.');
    redirect('project.php?id=' . $projectId);
}

$status = trim((string)($_GET['estado'] ?? ''));
$clientFilter = (int)($_GET['cliente'] ?? 0);
$search = trim((string)($_GET['q'] ?? ''));
$where = ['p.activo = 1'];
$params = [];
if (is_pm($user)) {
    $where[] = 'p.id_manager = ?';
    $params[] = (int)$user['id_usuario'];
}
if ($status !== '') {
    $where[] = 'p.estado = ?';
    $params[] = $status;
}
if ($clientFilter > 0) {
    $where[] = 'p.id_cliente = ?';
    $params[] = $clientFilter;
}
if ($search !== '') {
    $where[] = '(p.nombre_proyecto LIKE ? OR p.codigo_unico LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$projects = fetch_all('SELECT p.* FROM Proyecto p WHERE ' . implode(' AND ', $where) . ' ORDER BY p.created_at DESC', $params);
$metrics = [];
foreach ($projects as $project) {
    $m = project_metrics((int)$project['id_proyecto']);
    if ($m) { $metrics[] = $m; }
}
$clients = fetch_all('SELECT * FROM Cliente WHERE activo = 1 ORDER BY nombre');
$managers = fetch_all("SELECT * FROM Usuario WHERE rol IN ('CFO','PM') AND estado_operativo = 1 ORDER BY nombre_completo");
$statuses = ['En Investigacion', 'En Desarrollo', 'En Produccion', 'Cerrado'];

render_header('Proyectos', $user, 'projects');
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
        <h2 class="section-title">Portafolio de proyectos</h2>
        <div class="section-sub"><?= count($metrics) ?> proyectos visibles · Presupuesto, avance y rentabilidad</div>
    </div>
    <?php if ($canManage): ?>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newProjectModal"><i class="bi bi-plus-lg"></i> Nuevo proyecto</button>
    <?php endif; ?>
</div>

<form class="filter-bar mb-4" method="get">
    <div class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label">Buscar</label>
            <input type="text" class="form-control form-control-sm" name="q" value="<?= e($search) ?>" placeholder="Nombre o código...">
        </div>
        <div class="col-md-3">
            <label class="form-label">Estado</label>
            <select class="form-select form-select-sm" name="estado">
                <option value="">Todos</option>
                <?php foreach ($statuses as $s): ?><option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Cliente</label>
            <select class="form-select form-select-sm" name="cliente">
                <option value="">Todos</option>
                <?php foreach ($clients as $client): ?><option value="<?= (int)$client['id_cliente'] ?>" <?= $clientFilter === (int)$client['id_cliente'] ? 'selected' : '' ?>><?= e($client['nombre']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button class="btn btn-primary btn-sm flex-grow-1" type="submit"><i class="bi bi-funnel"></i> Filtrar</button>
            <a href="projects.php" class="btn btn-ghost btn-sm"><i class="bi bi-arrow-counterclockwise"></i></a>
        </div>
    </div>
</form>

<div class="row g-3" data-paginate data-paginate-controls="#projectsPager" data-paginate-label="proyectos">
<?php foreach ($metrics as $m): ?>
    <div class="col-md-6 col-xxl-4">
        <div class="card project-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <div class="project-code"><?= e($m['codigo_unico']) ?></div>
                        <a href="project.php?id=<?= (int)$m['id_proyecto'] ?>" class="project-name d-block"><?= e($m['nombre_proyecto']) ?></a>
                    </div>
                    <?= status_badge($m['estado']) ?>
                </div>
                <div class="project-meta mb-3">
                    <span><i class="bi bi-building"></i> <?= e($m['cliente']) ?></span>
                    <span><i class="bi bi-diagram-3"></i> <?= e($m['tecnologia']) ?></span>
                    <span><i class="bi bi-bezier2"></i> <?= e($m['metodologia']) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="tag"><?= e($m['modelo_contratacion']) ?></span>
                    <span class="text-muted" style="font-size:.7rem"><?= fmt_ratio_pct($m['avance'], 0) ?> de avance</span>
                </div>
                <div class="progress mb-3"><div class="progress-bar <?= $m['avance'] >= .8 ? 'bg-success' : 'bg-primary' ?>" style="width:<?= min(100, $m['avance'] * 100) ?>%"></div></div>

                <?php if ($canManage): ?>
                    <div class="row g-2 mb-3">
                        <div class="col-4"><div class="mini-stat"><span>BAC</span><strong><?= fmt_money_short($m['bac']) ?></strong></div></div>
                        <div class="col-4"><div class="mini-stat"><span>Consumido</span><strong><?= fmt_ratio_pct($m['consumo_presupuesto'], 0) ?></strong></div></div>
                        <div class="col-4"><div class="mini-stat"><span>CPI</span><strong class="<?= $m['cpi'] >= 1 ? 'text-positive' : 'text-negative' ?>"><?= number_format($m['cpi'], 2, ',', '.') ?></strong></div></div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted" style="font-size:.68rem">Margen bruto</span>
                            <div class="fw-bold <?= $m['margen_bruto'] >= 0 ? 'text-positive' : 'text-negative' ?>"><?= fmt_money_short($m['margen_bruto']) ?> <span class="fw-normal text-muted" style="font-size:.68rem">(<?= fmt_ratio_pct($m['margen_pct'], 0) ?>)</span></div>
                        </div>
                        <a href="project.php?id=<?= (int)$m['id_proyecto'] ?>" class="btn btn-sm btn-outline-primary">Abrir <i class="bi bi-arrow-right"></i></a>
                    </div>
                <?php else: ?>
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="text-muted small"><i class="bi bi-clock-history"></i> <?= fmt_number($m['horas'], 1) ?> horas registradas</div>
                        <a href="project.php?id=<?= (int)$m['id_proyecto'] ?>" class="btn btn-sm btn-outline-primary">Ver tareas <i class="bi bi-arrow-right"></i></a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<?php if (!$metrics): ?>
    <div class="col-12"><div class="card"><div class="empty-state"><i class="bi bi-kanban"></i>No hay proyectos que coincidan con el filtro.</div></div></div>
<?php endif; ?>
</div>
<div class="cl-pager-host cl-pager-host--plain" id="projectsPager"></div>

<?php if ($canManage): ?>
<div class="modal fade" id="newProjectModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form class="modal-content" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-header">
                <h5 class="modal-title">Nuevo proyecto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Código único</label>
                        <input class="form-control" name="codigo_unico" placeholder="PRJ-006" required>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Nombre del proyecto</label>
                        <input class="form-control" name="nombre_proyecto" placeholder="Nombre descriptivo" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Cliente</label>
                        <select class="form-select" name="id_cliente" required>
                            <option value="">Selecciona...</option>
                            <?php foreach ($clients as $client): ?><option value="<?= (int)$client['id_cliente'] ?>"><?= e($client['nombre']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Project Manager</label>
                        <?php if (is_pm($user)): ?>
                            <input class="form-control" value="<?= e($user['nombre_completo']) ?>" disabled>
                        <?php else: ?>
                            <select class="form-select" name="id_manager">
                                <?php foreach ($managers as $manager): ?><option value="<?= (int)$manager['id_usuario'] ?>"><?= e($manager['nombre_completo']) ?> (<?= e($manager['rol']) ?>)</option><?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Modelo de contratación</label>
                        <select class="form-select" name="modelo_contratacion">
                            <option>Time & Materials</option>
                            <option>Fixed Price</option>
                            <option>Retainer</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">BAC / Presupuesto inicial (USD)</label>
                        <input type="number" step="0.01" min="1" class="form-control" name="bac_presupuesto_inicial" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tecnología</label>
                        <input class="form-control" name="tecnologia" placeholder="React, Flutter, Java...">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Metodología</label>
                        <input class="form-control" name="metodologia" placeholder="Scrum, Kanban, Shape Up...">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Inicio</label>
                        <input type="date" class="form-control" name="fecha_inicio" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Fin estimado</label>
                        <input type="date" class="form-control" name="fecha_fin_estimada">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Horas estimadas</label>
                        <input type="number" step="0.5" min="0" class="form-control" name="horas_estimadas" value="0">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i> Crear proyecto</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php
render_footer();

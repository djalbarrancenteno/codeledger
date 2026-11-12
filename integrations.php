<?php
require_once 'includes/bootstrap.php';
require_once 'includes/layout.php';

$user = require_login(['CFO', 'PM']);
$isCfo = is_cfo($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$isCfo) {
        flash('danger', 'Solo el CFO puede administrar integraciones y ejecutar sincronizaciones.');
        redirect('integrations.php');
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'toggle_integration') {
        $id = (int)($_POST['id_integracion'] ?? 0);
        $integration = fetch_one('SELECT * FROM Integracion WHERE id_integracion = ?', [$id]);
        if (!$integration) {
            flash('danger', 'Integración no encontrada.');
        } else {
            $newState = $integration['estado'] === 'Conectado' ? 'Pendiente' : 'Conectado';
            q('UPDATE Integracion SET estado = ? WHERE id_integracion = ?', [$newState, $id]);
            audit('actualizar', 'Integracion', $id, "Estado cambiado a $newState");
            flash('success', $newState === 'Conectado'
                ? 'Integración conectada. Ya puedes ejecutar la sincronización.'
                : 'Integración desconectada. No se ejecutarán sincronizaciones automáticas.');
        }
        redirect('integrations.php');
    }

    if ($action === 'run_sync') {
        $id = (int)($_POST['id_integracion'] ?? 0);
        $integration = fetch_one('SELECT * FROM Integracion WHERE id_integracion = ?', [$id]);
        if (!$integration) {
            flash('danger', 'Integración no encontrada.');
            redirect('integrations.php');
        }
        if ($integration['estado'] !== 'Conectado') {
            flash('warning', 'La integración está pendiente. Conéctala antes de sincronizar.');
            redirect('integrations.php');
        }
        $registros = 0;
        $detalle = '';
        $estado = 'OK';
        try {
            switch ($integration['tipo']) {
                case 'Tareas':
                    if (stripos($integration['proveedor'], 'GitHub') !== false) {
                        $result = sync_github_issues();
                        $registros = (int)$result['inserted'] + (int)$result['updated'];
                        $detalle = "Issues importados: {$result['inserted']} nuevos, {$result['updated']} actualizados";
                        if (!empty($result['error'])) {
                            $estado = 'Error';
                            $detalle = (string)$result['error'];
                        }
                    } else {
                        $registros = (int)scalar('SELECT COUNT(*) FROM Tarea');
                        $detalle = 'Simulación de sincronización de tareas del proveedor';
                    }
                    break;
                case 'Cloud':
                    $result = sync_local_cloud_costs();
                    $alerts = evaluar_alertas_finops();
                    $registros = (int)$result['inserted'];
                    $detalle = "Consumos importados: {$result['inserted']}, omitidos: {$result['skipped']}, alertas generadas: $alerts";
                    break;
                case 'ERP':
                    $registros = (int)scalar("SELECT COUNT(*) FROM ComprobanteFacturacion WHERE estado IN ('Emitida','Cobrada')");
                    $detalle = 'Simulación de exportación de comprobantes al ERP';
                    break;
                case 'Pagos':
                    $registros = (int)scalar("SELECT COUNT(*) FROM ComprobanteFacturacion WHERE estado = 'Cobrada'");
                    $detalle = 'Simulación de conciliación de cobros con la pasarela';
                    break;
                default:
                    $detalle = 'Tipo de integración no soportado';
                    $estado = 'Error';
            }
        } catch (Throwable $e) {
            $estado = 'Error';
            $detalle = 'Error de sincronización: ' . $e->getMessage();
        }
        q('UPDATE Integracion SET ultima_sincronizacion = NOW(), estado = ? WHERE id_integracion = ?', [$estado === 'Error' ? 'Error' : 'Conectado', $id]);
        q(
            'INSERT INTO SyncLog (id_integracion, tipo, referencia, mensaje, registros, estado) VALUES (?,?,?,?,?,?)',
            [$id, $integration['tipo'] . ' · ' . $integration['proveedor'], $integration['nombre'], $detalle, $registros, $estado]
        );
        audit('sincronizar', 'Integracion', $id, $detalle);
        flash($estado === 'Error' ? 'danger' : 'success', $detalle);
        redirect('integrations.php');
    }

    if ($action === 'add_integration') {
        $name = trim((string)($_POST['nombre'] ?? ''));
        $type = in_array($_POST['tipo'] ?? '', ['Tareas', 'Cloud', 'ERP', 'Pagos'], true) ? $_POST['tipo'] : 'Tareas';
        $provider = trim((string)($_POST['proveedor'] ?? ''));
        $config = trim((string)($_POST['configuracion'] ?? ''));
        if ($name === '' || $provider === '') {
            flash('danger', 'Nombre y proveedor son obligatorios.');
        } else {
            q(
                'INSERT INTO Integracion (nombre, tipo, proveedor, estado, configuracion) VALUES (?,?,?,"Pendiente",?)',
                [$name, $type, $provider, $config]
            );
            $id = (int)db()->lastInsertId();
            audit('crear', 'Integracion', $id, "Integración $name ($provider) creada");
            flash('success', 'Integración agregada en estado pendiente. Conéctala para sincronizar.');
        }
        redirect('integrations.php');
    }

    if ($action === 'delete_integration') {
        $id = (int)($_POST['id_integracion'] ?? 0);
        $integration = fetch_one('SELECT * FROM Integracion WHERE id_integracion = ?', [$id]);
        if ($integration) {
            q('DELETE FROM Integracion WHERE id_integracion = ?', [$id]);
            audit('eliminar', 'Integracion', $id, 'Integración eliminada: ' . $integration['nombre']);
            flash('success', 'Integración y sus logs eliminados.');
        }
        redirect('integrations.php');
    }
}

$integrations = fetch_all('SELECT * FROM Integracion ORDER BY FIELD(tipo, "Tareas", "Cloud", "ERP", "Pagos"), nombre');
$byType = ['Tareas' => [], 'Cloud' => [], 'ERP' => [], 'Pagos' => []];
foreach ($integrations as $integration) {
    $byType[$integration['tipo']][] = $integration;
}
$lastSync = [];
foreach (fetch_all('SELECT id_integracion, MAX(fecha) ultima FROM SyncLog GROUP BY id_integracion') as $row) {
    $lastSync[(int)$row['id_integracion']] = $row['ultima'];
}
$logs = fetch_all(
    'SELECT l.*, i.nombre, i.proveedor FROM SyncLog l JOIN Integracion i ON i.id_integracion = l.id_integracion ORDER BY l.fecha DESC, l.id_log DESC LIMIT 60'
);
$totalConnected = (int)scalar("SELECT COUNT(*) FROM Integracion WHERE estado = 'Conectado'");
$totalPending = (int)scalar("SELECT COUNT(*) FROM Integracion WHERE estado = 'Pendiente'");
$totalError = (int)scalar("SELECT COUNT(*) FROM Integracion WHERE estado = 'Error'");
$logsToday = (int)scalar('SELECT COUNT(*) FROM SyncLog WHERE DATE(fecha) = CURDATE()');
$typeIcons = ['Tareas' => 'bi-kanban', 'Cloud' => 'bi-cloud', 'ERP' => 'bi-building', 'Pagos' => 'bi-credit-card'];
$typeLabels = ['Tareas' => 'Gestión de tareas', 'Cloud' => 'Costos cloud', 'ERP' => 'ERP / contabilidad', 'Pagos' => 'Pasarelas de pago'];

render_header('Integraciones', $user, 'integrations');
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
        <h2 class="section-title">Integraciones y sincronización</h2>
        <div class="section-sub">Jira, GitHub, ClickUp, AWS, Azure, GCP, ERPs y pasarelas de pago</div>
    </div>
    <?php if ($isCfo): ?>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#integrationModal"><i class="bi bi-plus-lg"></i> Agregar integración</button>
    <?php endif; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="kpi-card" style="--accent:#16a34a;--accent-soft:#dcfce7">
            <div class="kpi-label">Conectadas</div>
            <div class="kpi-value"><?= $totalConnected ?></div>
            <div class="kpi-foot"><i class="bi bi-plug"></i> Listas para sincronizar</div>
            <div class="kpi-icon"><i class="bi bi-plug"></i></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card" style="--accent:#d97706;--accent-soft:#fef3c7">
            <div class="kpi-label">Pendientes</div>
            <div class="kpi-value"><?= $totalPending ?></div>
            <div class="kpi-foot"><i class="bi bi-hourglass-split"></i> Requieren credenciales</div>
            <div class="kpi-icon"><i class="bi bi-hourglass-split"></i></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card" style="--accent:#e11d48;--accent-soft:#ffe4e6">
            <div class="kpi-label">Con error</div>
            <div class="kpi-value"><?= $totalError ?></div>
            <div class="kpi-foot"><i class="bi bi-exclamation-triangle"></i> Revisar configuración</div>
            <div class="kpi-icon"><i class="bi bi-exclamation-triangle"></i></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card" style="--accent:#4f46e5;--accent-soft:#eef2ff">
            <div class="kpi-label">Syncs hoy</div>
            <div class="kpi-value"><?= $logsToday ?></div>
            <div class="kpi-foot"><i class="bi bi-arrow-repeat"></i> Ejecuciones registradas</div>
            <div class="kpi-icon"><i class="bi bi-arrow-repeat"></i></div>
        </div>
    </div>
</div>

<?php foreach ($byType as $type => $items): ?>
<div class="card mb-4">
    <div class="card-header">
        <span><i class="bi <?= e($typeIcons[$type]) ?> me-1"></i> <?= e($typeLabels[$type]) ?></span>
        <span class="section-sub"><?= count($items) ?> integración(es)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr><th>Integración</th><th>Proveedor</th><th>Estado</th><th>Última sincronización</th><th>Configuración</th><th class="text-end">Acciones</th></tr>
            </thead>
            <tbody>
            <?php foreach ($items as $integration): ?>
                <?php $last = $lastSync[(int)$integration['id_integracion']] ?? $integration['ultima_sincronizacion']; ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= e($integration['nombre']) ?></div>
                        <div class="text-muted small">ID <?= (int)$integration['id_integracion'] ?></div>
                    </td>
                    <td><?= e($integration['proveedor']) ?></td>
                    <td><?= status_badge($integration['estado']) ?></td>
                    <td class="text-muted small">
                        <?= $last ? fecha_larga(substr((string)$last, 0, 10)) . ' · ' . e(date('H:i', strtotime((string)$last))) : 'Nunca' ?>
                    </td>
                    <td class="text-muted small" style="max-width:280px"><?= e($integration['configuracion'] ?: '—') ?></td>
                    <td class="text-end">
                        <?php if ($isCfo): ?>
                            <div class="d-flex flex-wrap gap-1 justify-content-end">
                                <form method="post" data-confirm="<?= $integration['estado'] === 'Conectado' ? '¿Desconectar esta integración?' : '¿Marcar esta integración como conectada?' ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_integration">
                                    <input type="hidden" name="id_integracion" value="<?= (int)$integration['id_integracion'] ?>">
                                    <button class="btn btn-sm <?= $integration['estado'] === 'Conectado' ? 'btn-ghost' : 'btn-outline-success' ?>" type="submit">
                                        <i class="bi <?= $integration['estado'] === 'Conectado' ? 'bi-plug-fill' : 'bi-plug' ?>"></i>
                                        <?= $integration['estado'] === 'Conectado' ? 'Desconectar' : 'Conectar' ?>
                                    </button>
                                </form>
                                <?php if ($integration['estado'] === 'Conectado'): ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="run_sync">
                                        <input type="hidden" name="id_integracion" value="<?= (int)$integration['id_integracion'] ?>">
                                        <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-arrow-repeat"></i> Sincronizar</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" data-confirm="¿Eliminar esta integración y todos sus logs?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_integration">
                                    <input type="hidden" name="id_integracion" value="<?= (int)$integration['id_integracion'] ?>">
                                    <button class="btn btn-sm btn-ghost" type="submit" title="Eliminar"><i class="bi bi-trash"></i></button>
                                </form>
                            </div>
                        <?php else: ?>
                            <span class="text-muted small">Solo lectura</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$items): ?><tr><td colspan="6"><div class="empty-state"><i class="bi bi-plug"></i>No hay integraciones de este tipo.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<div class="card">
    <div class="card-header"><span>Bitácora de sincronizaciones</span><span class="section-sub">Últimos 60 eventos</span></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead><tr><th>Fecha</th><th>Integración</th><th>Tipo</th><th>Mensaje</th><th class="num">Registros</th><th>Estado</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td class="text-muted small"><?= fecha_larga(substr((string)$log['fecha'], 0, 10)) ?> · <?= e(date('H:i', strtotime((string)$log['fecha']))) ?></td>
                    <td><div class="fw-semibold"><?= e($log['nombre']) ?></div><div class="text-muted small"><?= e($log['proveedor']) ?></div></td>
                    <td><span class="tag"><?= e($log['tipo']) ?></span></td>
                    <td class="small"><?= e($log['mensaje']) ?><?= $log['referencia'] ? '<div class="text-muted">' . e($log['referencia']) . '</div>' : '' ?></td>
                    <td class="num"><?= (int)$log['registros'] ?></td>
                    <td><?= status_badge($log['estado']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$logs): ?><tr><td colspan="6"><div class="empty-state"><i class="bi bi-journal-x"></i>Aún no se han ejecutado sincronizaciones.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($isCfo): ?>
<div class="modal fade" id="integrationModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_integration">
            <div class="modal-header"><h5 class="modal-title">Agregar integración</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Nombre</label><input class="form-control" name="nombre" placeholder="Ej. Jira Cloud" required></div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label">Tipo</label>
                        <select class="form-select" name="tipo">
                            <option value="Tareas">Tareas</option><option value="Cloud">Cloud</option><option value="ERP">ERP</option><option value="Pagos">Pagos</option>
                        </select>
                    </div>
                    <div class="col-6"><label class="form-label">Proveedor</label><input class="form-control" name="proveedor" placeholder="Atlassian" required></div>
                </div>
                <div class="mt-3"><label class="form-label">Configuración / notas</label><textarea class="form-control" name="configuracion" rows="2" placeholder="Dominio, repositorio, cuenta..."></textarea></div>
                <div class="alert alert-info mt-3 mb-0 py-2 small"><i class="bi bi-info-circle"></i> La integración se crea en estado pendiente. Puedes usar datos mock locales para demostrar la sincronización sin credenciales reales.</div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Agregar</button></div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php
render_footer();

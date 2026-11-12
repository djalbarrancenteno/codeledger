<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!can_view_financials($user)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sin permisos para sincronizar'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido. Usa POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
if (!hash_equals(csrf_token(), (string)$token)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = sync_github_issues();
$status = empty($result['error']) ? 200 : 500;

$integration = fetch_one("SELECT id_integracion FROM Integracion WHERE proveedor = 'GitHub' LIMIT 1");
if ($integration) {
    q('UPDATE Integracion SET ultima_sincronizacion = NOW(), estado = ? WHERE id_integracion = ?', [$status === 200 ? 'Conectado' : 'Error', $integration['id_integracion']]);
    q(
        'INSERT INTO SyncLog (id_integracion, tipo, referencia, mensaje, registros, estado) VALUES (?,?,?,?,?,?)',
        [
            $integration['id_integracion'],
            'Tareas · GitHub',
            'API sync_github',
            $result['error'] ?: "Issues nuevos: {$result['inserted']}, actualizados: {$result['updated']}",
            (int)$result['inserted'] + (int)$result['updated'],
            $status === 200 ? 'OK' : 'Error',
        ]
    );
}

http_response_code($status);
echo json_encode(array_merge(['ok' => $status === 200], $result), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

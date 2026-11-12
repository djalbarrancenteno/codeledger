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

$result = sync_local_cloud_costs();
$alerts = evaluar_alertas_finops();

$integrations = fetch_all("SELECT id_integracion FROM Integracion WHERE tipo = 'Cloud' AND estado = 'Conectado'");
foreach ($integrations as $integration) {
    q('UPDATE Integracion SET ultima_sincronizacion = NOW() WHERE id_integracion = ?', [$integration['id_integracion']]);
    q(
        'INSERT INTO SyncLog (id_integracion, tipo, referencia, mensaje, registros, estado) VALUES (?,?,?,?,?,?)',
        [
            $integration['id_integracion'],
            'Cloud · API sync_finops',
            'mocks cloud',
            "Consumos importados: {$result['inserted']}, omitidos: {$result['skipped']}, alertas: $alerts",
            (int)$result['inserted'],
            'OK',
        ]
    );
}

echo json_encode([
    'ok' => true,
    'inserted' => $result['inserted'],
    'skipped' => $result['skipped'],
    'alerts' => $alerts,
    'integrations' => count($integrations),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

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

$where = 'p.activo = 1';
$params = [];
if (is_pm($user)) {
    $where .= ' AND p.id_manager = ?';
    $params[] = (int)$user['id_usuario'];
} elseif (is_dev($user)) {
    $where .= ' AND p.id_proyecto IN (
        SELECT DISTINCT h.id_proyecto FROM RegistroTiempo r
        JOIN Tarea t ON t.id_tarea = r.id_tarea
        JOIN Hito h ON h.id_hito = t.id_hito
        WHERE r.id_usuario = ?
    )';
    $params[] = (int)$user['id_usuario'];
}

$projects = fetch_all(
    "SELECT p.id_proyecto, p.codigo_unico, p.nombre_proyecto, p.estado, p.modelo_contratacion,
            p.tecnologia, p.metodologia, c.nombre AS cliente
     FROM Proyecto p JOIN Cliente c ON c.id_cliente = p.id_cliente
     WHERE $where ORDER BY p.codigo_unico",
    $params
);

echo json_encode([
    'ok' => true,
    'total' => count($projects),
    'proyectos' => array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id_proyecto'],
            'codigo' => $row['codigo_unico'],
            'nombre' => $row['nombre_proyecto'],
            'cliente' => $row['cliente'],
            'estado' => $row['estado'],
            'modelo' => $row['modelo_contratacion'],
            'tecnologia' => $row['tecnologia'],
            'metodologia' => $row['metodologia'],
        ];
    }, $projects),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

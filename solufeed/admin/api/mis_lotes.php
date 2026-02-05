<?php
/**
 * API - Mis lotes (CAMPO)
 * Devuelve la lista de lotes asignados al usuario CAMPO.
 * Se usa para prefetch offline de pantallas lote-específicas.
 */

require_once '../../config/database.php';
require_once '../../includes/functions.php';

verificarCampo();

header('Content-Type: application/json; charset=utf-8');

try {
    $db = getConnection();
    $idUsuario = (int)($_SESSION['usuario_id'] ?? 0);

    if ($idUsuario <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'unauthorized']);
        exit;
    }

    $stmt = $db->prepare(
        "SELECT t.id_tropa, t.nombre
         FROM tropa t
         INNER JOIN usuario_tropa ut ON ut.id_tropa = t.id_tropa
         WHERE t.activo = 1 AND ut.id_usuario = ?
         ORDER BY t.nombre ASC"
    );
    $stmt->execute([$idUsuario]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $lotes = [];
    foreach ($rows as $r) {
        $lotes[] = [
            'id_tropa' => (int)$r['id_tropa'],
            'nombre' => (string)$r['nombre'],
        ];
    }

    echo json_encode(['ok' => true, 'lotes' => $lotes]);
} catch (Throwable $e) {
    log_event('ERROR', 'API_MIS_LOTES_FAIL', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}

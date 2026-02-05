<?php
require_once '../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

iniciarSesion();

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'not_authenticated',
    ]);
    exit;
}

// Permitir ADMIN o CAMPO (necesario para sincronizar y refrescar CSRF)
$tipo = isset($_SESSION['tipo']) ? (string)$_SESSION['tipo'] : '';
if ($tipo !== 'ADMIN' && $tipo !== 'CAMPO') {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'forbidden',
    ]);
    exit;
}

http_response_code(200);
echo json_encode([
    'ok' => true,
    'usuario_id' => (int)$_SESSION['usuario_id'],
    'tipo' => $tipo,
    'ts' => time(),
]);

<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

iniciarSesion();

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

$tipo_sesion = isset($_SESSION['tipo']) ? (string)$_SESSION['tipo'] : '';
if ($tipo_sesion !== 'CAMPO') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

// CSRF
if (!csrf_verify_post()) {
    http_response_code(419);
    echo json_encode([
        'ok' => false,
        'error' => 'csrf',
        'message' => csrf_fail_response('Token expirado o inválido. Iniciá sesión nuevamente y reintentá la sincronización.'),
    ]);
    exit;
}

$db = getConnection();

$id_usuario = (int)$_SESSION['usuario_id'];
$id_tropa = (int)($_POST['id_tropa'] ?? 0);
$fecha = trim((string)($_POST['fecha'] ?? ''));
$peso_promedio = (float)($_POST['peso_promedio'] ?? 0);
$animales_esperados = (int)($_POST['animales_esperados'] ?? 0);
$animales_vistos = (int)($_POST['animales_vistos'] ?? 0);
$motivo_operario = isset($_POST['motivo_operario']) ? trim((string)$_POST['motivo_operario']) : '';
$client_uuid = isset($_POST['client_uuid']) ? trim((string)$_POST['client_uuid']) : '';

// Normalizar uuid para usarlo como clave idempotente
$client_uuid = preg_replace('/[^a-zA-Z0-9\-_:]/', '', $client_uuid);
if (strlen($client_uuid) > 128) $client_uuid = substr($client_uuid, 0, 128);

$origen_registro = $client_uuid !== '' ? ('OFFLINE:' . $client_uuid) : 'OFFLINE';

$hay_diferencia = ($animales_vistos !== $animales_esperados) ? 1 : 0;
$diferencia_animales = $hay_diferencia ? ($animales_vistos - $animales_esperados) : 0;

$errores = [];

// Seguridad: acceso al lote
if ($id_tropa <= 0) {
    $errores[] = 'Debés seleccionar un lote.';
} else {
    if (!usuarioCampoPuedeAccederLote($id_tropa)) {
        $errores[] = 'No tenés permisos para registrar en ese lote.';
    }
}

// Validar fecha YYYY-MM-DD
if ($fecha === '') {
    $errores[] = 'La fecha es obligatoria.';
} else {
    $dt = DateTime::createFromFormat('Y-m-d', $fecha);
    $is_valid = $dt && $dt->format('Y-m-d') === $fecha;
    if (!$is_valid) {
        $errores[] = 'Fecha inválida.';
    }
}

if ($peso_promedio <= 0) {
    $errores[] = 'El peso promedio debe ser mayor a 0.';
}

if ($animales_vistos <= 0) {
    $errores[] = 'La cantidad de animales vistos debe ser mayor a 0.';
}

if ($hay_diferencia && $motivo_operario === '') {
    $errores[] = 'Indicá un motivo si hay diferencia de animales.';
}

// Idempotencia: si ya se sincronizó este registro, responder OK sin duplicar
if (empty($errores) && $client_uuid !== '') {
    $stmt_dup = $db->prepare('SELECT id_pesada FROM pesada WHERE id_usuario = ? AND origen_registro = ? LIMIT 1');
    $stmt_dup->execute([$id_usuario, $origen_registro]);
    $existing = (int)($stmt_dup->fetchColumn() ?: 0);
    if ($existing > 0) {
        http_response_code(200);
        echo json_encode([
            'ok' => true,
            'duplicated' => true,
            'id_pesada' => $existing,
        ]);
        exit;
    }
}

if (!empty($errores)) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => 'validation',
        'errors' => $errores,
    ]);
    exit;
}

try {
    $db->beginTransaction();

    $stmt_i = $db->prepare("\n        INSERT INTO pesada
        (id_tropa, id_usuario, fecha, peso_promedio, animales_esperados, animales_vistos, hay_diferencia, origen_registro, fecha_creacion)
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $stmt_i->execute([
        $id_tropa,
        $id_usuario,
        $fecha,
        $peso_promedio,
        $animales_esperados,
        $animales_vistos,
        $hay_diferencia,
        $origen_registro,
    ]);

    $id_pesada = (int)$db->lastInsertId();

    if ($hay_diferencia) {
        $stmt_a = $db->prepare("\n            INSERT INTO ajuste_animales_pendiente
            (id_pesada, id_tropa, diferencia_animales, motivo_operario, estado, fecha_creacion)
            VALUES
            (?, ?, ?, ?, 'PENDIENTE', NOW())
        ");

        $stmt_a->execute([
            $id_pesada,
            $id_tropa,
            $diferencia_animales,
            $motivo_operario,
        ]);
    }

    $db->commit();

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'id_pesada' => $id_pesada,
        'hay_diferencia' => (bool)$hay_diferencia,
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();

    // Intento final de idempotencia (por carrera): si el origen ya existe, devolver ok
    if ($client_uuid !== '') {
        try {
            $stmt_dup = $db->prepare('SELECT id_pesada FROM pesada WHERE id_usuario = ? AND origen_registro = ? LIMIT 1');
            $stmt_dup->execute([$id_usuario, $origen_registro]);
            $existing = (int)($stmt_dup->fetchColumn() ?: 0);
            if ($existing > 0) {
                http_response_code(200);
                echo json_encode([
                    'ok' => true,
                    'duplicated' => true,
                    'id_pesada' => $existing,
                ]);
                exit;
            }
        } catch (Throwable $e2) {
            // ignore
        }
    }

    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'server_error',
    ]);
}

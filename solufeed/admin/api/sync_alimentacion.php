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
$hora = trim((string)($_POST['hora'] ?? ''));
$sobrante_nivel = trim((string)($_POST['sobrante_nivel'] ?? 'NORMAL'));
$kg_totales = (float)($_POST['kg_totales'] ?? 0);

// Puede venir como array (kg_real[ID] => valor)
$kg_reales = [];
if (isset($_POST['kg_real']) && is_array($_POST['kg_real'])) {
    $kg_reales = $_POST['kg_real'];
}

$client_uuid = isset($_POST['client_uuid']) ? trim((string)$_POST['client_uuid']) : '';

// Normalizar uuid para usarlo como clave idempotente
$client_uuid = preg_replace('/[^a-zA-Z0-9\-_:]/', '', $client_uuid);
if (strlen($client_uuid) > 128) $client_uuid = substr($client_uuid, 0, 128);

$origen_registro = $client_uuid !== '' ? ('OFFLINE:' . $client_uuid) : 'OFFLINE';

$allowed_sobrante = ['SIN_SOBRAS','POCAS_SOBRAS','NORMAL','MUCHAS_SOBRAS'];
if (!in_array($sobrante_nivel, $allowed_sobrante, true)) {
    $sobrante_nivel = 'NORMAL';
}

$errores = [];

// Seguridad: acceso al lote (usuario CAMPO solo a lotes asignados)
if ($id_tropa <= 0) {
    $errores[] = 'Debés seleccionar un lote.';
} else {
    if (!usuarioCampoPuedeAccederLote($id_tropa)) {
        $errores[] = 'No tenés permisos para registrar en ese lote.';
    }
}

if ($fecha === '' || $hora === '') {
    $errores[] = 'La fecha y hora son obligatorias.';
}

if ($kg_totales <= 0) {
    $errores[] = 'Los kg totales deben ser mayores a 0.';
}

// Verificar que se hayan ingresado kg para al menos un insumo
$hay_insumos = false;
$suma_kg_reales = 0.0;
foreach ($kg_reales as $kg) {
    $kg_val = (float)$kg;
    if ($kg_val > 0) {
        $hay_insumos = true;
        $suma_kg_reales += $kg_val;
    }
}

if (!$hay_insumos) {
    $errores[] = 'Debés ingresar kg reales para al menos un insumo.';
}

// Verificar que la suma de kg reales esté dentro de un margen razonable (+- 5%)
$margen_permitido = $kg_totales * 0.05;
if ($kg_totales > 0 && abs($suma_kg_reales - $kg_totales) > $margen_permitido) {
    $errores[] = 'La diferencia entre el total y la suma de insumos supera el 5% permitido.';
}

if (!empty($errores)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'errors' => $errores]);
    exit;
}

// Idempotencia: si ya existe un registro con el mismo origen, devolver OK
try {
    $stmt_dup = $db->prepare('SELECT id_consumo FROM consumo_lote WHERE id_usuario = ? AND origen_registro = ? LIMIT 1');
    $stmt_dup->execute([$id_usuario, $origen_registro]);
    $existing = (int)($stmt_dup->fetchColumn() ?: 0);
    if ($existing > 0) {
        echo json_encode(['ok' => true, 'id_consumo' => $existing, 'idempotent' => true]);
        exit;
    }
} catch (Throwable $e) {
    // Si falla el check, seguimos a intentar insertar (se deduplicará por app si repite)
}

try {
    // Obtener animales presentes
    $animales = obtenerAnimalesPresentes($id_tropa);

    // Calcular número de alimentación del día
    $stmt_num = $db->prepare("SELECT COALESCE(MAX(numero_alimentacion_dia), 0) + 1 AS siguiente FROM consumo_lote WHERE id_tropa = ? AND fecha = ?");
    $stmt_num->execute([$id_tropa, $fecha]);
    $row_num = $stmt_num->fetch(PDO::FETCH_ASSOC);
    $numero_alimentacion = $row_num ? (int)$row_num['siguiente'] : 1;

    $db->beginTransaction();

    // Insertar cabezal de consumo
    $stmt = $db->prepare(
        "INSERT INTO consumo_lote
        (id_tropa, id_usuario, fecha, hora, numero_alimentacion_dia, sobrante_nivel, kg_totales_tirados, animales_presentes, origen_registro, fecha_creacion)
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );

    $stmt->execute([
        $id_tropa,
        $id_usuario,
        $fecha,
        $hora,
        $numero_alimentacion,
        $sobrante_nivel,
        $kg_totales,
        $animales,
        $origen_registro
    ]);

    $id_consumo = (int)$db->lastInsertId();

    // Obtener dieta vigente para calcular porcentajes
    $dieta = obtenerDietaVigente($id_tropa, $fecha);
    if (!$dieta || !isset($dieta['id_dieta'])) {
        throw new RuntimeException('Este lote no tiene una dieta asignada para la fecha seleccionada.');
    }

    // Obtener insumos de la dieta
    $stmt_ins = $db->prepare("SELECT i.id_insumo, i.porcentaje_ms, dd.porcentaje_teorico
        FROM dieta_detalle dd
        INNER JOIN insumo i ON dd.id_insumo = i.id_insumo
        WHERE dd.id_dieta = ?");
    $stmt_ins->execute([(int)$dieta['id_dieta']]);
    $insumos_dieta_rows = $stmt_ins->fetchAll(PDO::FETCH_ASSOC);

    // Preparar insert detalle
    $stmt_det = $db->prepare(
        "INSERT INTO consumo_lote_detalle (id_consumo, id_insumo, kg_sugeridos, kg_reales, porcentaje_real, kg_ms)
        VALUES (?, ?, ?, ?, ?, ?)"
    );

    foreach ($insumos_dieta_rows as $insumo) {
        $id_insumo = (int)$insumo['id_insumo'];

        if (isset($kg_reales[$id_insumo]) && (float)$kg_reales[$id_insumo] > 0) {
            $kg_real = (float)$kg_reales[$id_insumo];

            // Calcular kg sugeridos según dieta teórica
            $kg_sugeridos = ((float)$insumo['porcentaje_teorico'] * $kg_totales) / 100;

            // Calcular porcentaje real
            $porcentaje_real = ($kg_totales > 0) ? (($kg_real / $kg_totales) * 100) : 0;

            // kg de Materia Seca
            $kg_ms = ($kg_real * (float)$insumo['porcentaje_ms']) / 100;

            $stmt_det->execute([
                $id_consumo,
                $id_insumo,
                $kg_sugeridos,
                $kg_real,
                $porcentaje_real,
                $kg_ms
            ]);
        }
    }

    $db->commit();

    echo json_encode([
        'ok' => true,
        'id_consumo' => $id_consumo,
        'origen_registro' => $origen_registro
    ]);
    exit;

} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    log_event('ERROR', 'SYNC_ALIMENTACION_FAILED', [
        'error' => $e->getMessage(),
        'id_tropa' => $id_tropa,
        'fecha' => $fecha,
        'origen_registro' => $origen_registro,
    ]);

    // Re-check idempotencia in case it got inserted concurrently
    try {
        $stmt_dup = $db->prepare('SELECT id_consumo FROM consumo_lote WHERE id_usuario = ? AND origen_registro = ? LIMIT 1');
        $stmt_dup->execute([$id_usuario, $origen_registro]);
        $existing = (int)($stmt_dup->fetchColumn() ?: 0);
        if ($existing > 0) {
            echo json_encode(['ok' => true, 'id_consumo' => $existing, 'idempotent' => true]);
            exit;
        }
    } catch (Throwable $e2) {
        // ignore
    }

    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'server',
        'message' => 'Error al sincronizar la alimentación.',
    ]);
    exit;
}

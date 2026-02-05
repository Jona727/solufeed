<?php
/**
 * SOLUFEED - Cambiar dieta vigente de un lote (acción rápida desde ver.php)
 * Seguridad:
 * - Solo ADMIN
 * - Solo POST
 * - CSRF obligatorio
 */

require_once '../../config/database.php';
require_once '../../includes/functions.php';

verificarAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Location: listar.php');
    exit();
}

if (!csrf_verify_post()) {
    flash_set('error', csrf_fail_response());
    header('Location: listar.php');
    exit();
}

$id_tropa = isset($_POST['id_tropa']) ? (int)$_POST['id_tropa'] : 0;
$return_to = isset($_POST['return_to']) ? (string)$_POST['return_to'] : '';

$fallback = ($id_tropa > 0)
    ? (BASE_URL . '/admin/lotes/ver.php?id=' . $id_tropa)
    : (BASE_URL . '/admin/lotes/listar.php');

$back_url = safe_return_to($return_to, $fallback);

// Fecha del cambio
$fecha_cambio = isset($_POST['fecha_cambio_dieta']) ? (string)$_POST['fecha_cambio_dieta'] : date('Y-m-d');
$dt = DateTime::createFromFormat('Y-m-d', $fecha_cambio);
if (!$dt || $dt->format('Y-m-d') !== $fecha_cambio) {
    $fecha_cambio = date('Y-m-d');
}

// Dieta nueva (vacío => quitar dieta)
$id_dieta_raw = isset($_POST['id_dieta']) ? trim((string)$_POST['id_dieta']) : '';
$id_dieta_nueva = ($id_dieta_raw === '' || $id_dieta_raw === '0') ? null : (int)$id_dieta_raw;

if ($id_tropa <= 0) {
    flash_set('error', 'Lote inválido.');
    header('Location: ' . $back_url);
    exit();
}

$db = getConnection();

// Verificar lote
$stmt_lote = $db->prepare('SELECT id_tropa, nombre FROM tropa WHERE id_tropa = ?');
$stmt_lote->execute([$id_tropa]);
$lote = $stmt_lote->fetch(PDO::FETCH_ASSOC);

if (!$lote) {
    flash_set('error', 'Lote no encontrado.');
    header('Location: ' . $back_url);
    exit();
}

// Traer asignación abierta (vigente actual)
$stmt_open = $db->prepare("
    SELECT id_dieta, fecha_desde
    FROM tropa_dieta_asignada
    WHERE id_tropa = ?
      AND fecha_hasta IS NULL
    ORDER BY fecha_desde DESC
    LIMIT 1
");
$stmt_open->execute([$id_tropa]);
$open = $stmt_open->fetch(PDO::FETCH_ASSOC);

$id_dieta_actual = $open ? (int)$open['id_dieta'] : null;
$fecha_desde_actual = $open ? (string)$open['fecha_desde'] : null;

// Validar fecha del cambio (no puede ser anterior al inicio de la dieta actual abierta)
if ($fecha_desde_actual && $fecha_cambio < $fecha_desde_actual) {
    flash_set('error', 'La fecha del cambio no puede ser anterior al inicio de la dieta vigente (' . date('d/m/Y', strtotime($fecha_desde_actual)) . ').');
    header('Location: ' . $back_url);
    exit();
}

// Validar dieta nueva si corresponde
$dieta_nombre = null;
if ($id_dieta_nueva !== null && $id_dieta_nueva > 0) {
    $stmt_d = $db->prepare('SELECT id_dieta, nombre FROM dieta WHERE id_dieta = ? AND activo = 1');
    $stmt_d->execute([$id_dieta_nueva]);
    $dieta = $stmt_d->fetch(PDO::FETCH_ASSOC);

    if (!$dieta) {
        flash_set('error', 'La dieta seleccionada no existe o está inactiva.');
        header('Location: ' . $back_url);
        exit();
    }

    $dieta_nombre = $dieta['nombre'];
}

// Si no hay cambios reales
if ($id_dieta_actual !== null && $id_dieta_nueva !== null && $id_dieta_nueva === $id_dieta_actual) {
    flash_set('info', 'Esa dieta ya es la vigente para este lote.');
    header('Location: ' . $back_url);
    exit();
}

if ($id_dieta_actual === null && $id_dieta_nueva === null) {
    flash_set('info', 'El lote ya está sin dieta asignada.');
    header('Location: ' . $back_url);
    exit();
}

try {
    $db->beginTransaction();

    // Cerrar dieta actual (si hay una abierta)
    if ($id_dieta_actual !== null) {
        $stmt_close = $db->prepare("
            UPDATE tropa_dieta_asignada
            SET fecha_hasta = ?
            WHERE id_tropa = ?
              AND fecha_hasta IS NULL
        ");
        $stmt_close->execute([$fecha_cambio, $id_tropa]);
    }

    // Insertar nueva asignación (si se seleccionó una dieta)
    if ($id_dieta_nueva !== null && $id_dieta_nueva > 0) {
        $stmt_ins = $db->prepare("
            INSERT INTO tropa_dieta_asignada (id_tropa, id_dieta, fecha_desde, fecha_hasta)
            VALUES (?, ?, ?, NULL)
        ");
        $stmt_ins->execute([$id_tropa, $id_dieta_nueva, $fecha_cambio]);
    }

    $db->commit();

    if ($id_dieta_nueva !== null && $id_dieta_nueva > 0) {
        flash_set('success', "Dieta vigente cambiada a '{$dieta_nombre}' desde " . date('d/m/Y', strtotime($fecha_cambio)) . '.');
    } else {
        flash_set('success', 'Dieta quitada desde ' . date('d/m/Y', strtotime($fecha_cambio)) . '.');
    }

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    log_event('ERROR', 'TROPA_DIETA_CHANGE_FAILED', ['error' => $e->getMessage(), 'id_tropa' => $id_tropa]);
    flash_set('error', 'Error al cambiar la dieta del lote.');
}

header('Location: ' . $back_url);
exit();
?>

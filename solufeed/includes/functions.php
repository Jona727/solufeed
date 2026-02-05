<?php
// Cargar configuración de entorno si no está cargada
if (!defined('BASE_URL')) {
    $envPath = __DIR__ . '/../config/env.php';
    if (file_exists($envPath)) {
        require_once $envPath;
    }
}

if (!defined('BASE_URL')) {
    define('BASE_URL', '/solufeed');
}


/**
 * SOLUFEED - Funciones Auxiliares
 * 
 * Funciones reutilizables para toda la aplicación
 */

/**
 * Inicia una sesión si no está iniciada
 */
function iniciarSesion() {
    if (session_status() === PHP_SESSION_NONE) {
        // Endurecer cookies de sesión
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $path = (defined('BASE_URL') && BASE_URL) ? BASE_URL : '/';

        // Solo configurar params si no hay sesión iniciada
        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => $path,
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            // Fallback compatible
            session_set_cookie_params(0, $path . '; samesite=Lax', '', $secure, true);
        }

        session_start();
    }
}

/**
 * Logging simple a archivo (logs/app.log).
 * No expone detalles al usuario en producción.
 */
function log_event($level, $message, $context = []) {
    iniciarSesion();

    $level = strtoupper((string)$level);
    $allowed = ['DEBUG','INFO','WARN','ERROR'];
    if (!in_array($level, $allowed, true)) $level = 'INFO';

    $line = [
        'ts' => date('Y-m-d H:i:s'),
        'level' => $level,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'user' => $_SESSION['usuario_id'] ?? null,
        'msg' => (string)$message,
        'ctx' => $context,
    ];

    $logDir = __DIR__ . '/../logs';
    $logFile = $logDir . '/app.log';

    try {
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0750, true);
        }
        @file_put_contents($logFile, json_encode($line, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        error_log('[Solufeed][' . $level . '] ' . $message);
    }
}

function send_security_headers() {
    if (headers_sent()) return;

    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    // Evitar cache en páginas autenticadas (assets quedan fuera de esto)
    if (!empty($_SESSION['usuario_id'])) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
}

function register_error_handlers() {
    static $registered = false;
    if ($registered) return;
    $registered = true;

    if (defined('APP_DEBUG') && !APP_DEBUG) {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
    }

    set_error_handler(function ($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) return false;
        log_event('ERROR', 'PHP_ERROR: ' . $message, ['file' => $file, 'line' => $line, 'severity' => $severity]);
        return false; // dejar que PHP maneje según config
    });

    set_exception_handler(function ($e) {
        log_event('ERROR', 'UNCAUGHT_EXCEPTION: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => (defined('APP_DEBUG') && APP_DEBUG) ? $e->getTraceAsString() : null
        ]);

        if (!headers_sent()) {
            http_response_code(500);
        }

        if (defined('APP_DEBUG') && APP_DEBUG) {
            echo '<pre style="white-space:pre-wrap">' . htmlspecialchars((string)$e) . '</pre>';
        } else {
            echo 'Ocurrió un error inesperado. Intentá nuevamente.';
        }
        exit;
    });
}

/**
 * CSRF (Cross-Site Request Forgery)
 * 
 * - Token por sesión.
 * - Rotación automática por antigüedad.
 * - Tolerancia: se acepta 1 token anterior por un corto período para evitar fallos
 *   por carreras (refresh en paralelo / sincronización offline).
 */
function csrf_rotate_token() {
    iniciarSesion();

    $new = bin2hex(random_bytes(32));

    // Guardar token anterior para tolerancia breve
    if (!empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token_prev'] = $_SESSION['csrf_token'];
        // 10 minutos de tolerancia
        $_SESSION['csrf_token_prev_until'] = time() + 600;
    }

    $_SESSION['csrf_token'] = $new;
    $_SESSION['csrf_token_issued_at'] = time();

    return $new;
}

/**
 * Obtiene el token CSRF vigente. Rota automáticamente si es muy viejo.
 */
function csrf_token() {
    iniciarSesion();

    $ttl = 1800; // 30 minutos
    $issuedAt = isset($_SESSION['csrf_token_issued_at']) ? (int)$_SESSION['csrf_token_issued_at'] : 0;

    if (empty($_SESSION['csrf_token'])) {
        return csrf_rotate_token();
    }

    if ($issuedAt > 0 && (time() - $issuedAt) > $ttl) {
        return csrf_rotate_token();
    }

    return $_SESSION['csrf_token'];
}

/**
 * HTML input hidden para CSRF.
 */
function csrf_input() {
    $t = csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Verifica token CSRF en un POST.
 * Devuelve true si es válido.
 */
function csrf_verify_post($field = 'csrf_token') {
    iniciarSesion();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return true;

    $provided = $_POST[$field] ?? '';
    if (!is_string($provided) || $provided === '') {
        return false;
    }

    $current = $_SESSION['csrf_token'] ?? '';
    if (is_string($current) && $current !== '' && hash_equals($current, $provided)) {
        return true;
    }

    // Tolerancia a token anterior durante ventana breve
    $prev = $_SESSION['csrf_token_prev'] ?? '';
    $prevUntil = isset($_SESSION['csrf_token_prev_until']) ? (int)$_SESSION['csrf_token_prev_until'] : 0;
    if (is_string($prev) && $prev !== '' && $prevUntil > time() && hash_equals($prev, $provided)) {
        return true;
    }

    return false;
}

/**
 * Respuesta estándar cuando falla CSRF.
 */
function csrf_fail_response($message = 'Acción no autorizada. Actualizá la página e intentá nuevamente.') {
    if (!headers_sent()) {
        http_response_code(419);
    }
    return $message;
}

/**
 * Sanitiza un return_to / destino de redirección para evitar open-redirect.
 * - Bloquea esquemas (http:, https:, javascript:, data:, etc.) y URLs tipo //example.com
 * - Acepta únicamente rutas internas.
 */
function safe_return_to($dest, $fallback = null) {
    $fallback = is_string($fallback) && $fallback !== '' ? $fallback : (BASE_URL . '/admin/campo/historial.php');

    if (!is_string($dest)) return $fallback;

    $dest = trim($dest);
    // Evitar header injection
    $dest = str_replace(["\r", "\n"], '', $dest);

    if ($dest === '') return $fallback;

    // Bloquear cualquier esquema: http:, https:, javascript:, data:, etc
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $dest)) return $fallback;

    // Bloquear scheme-relative URLs: //evil.com
    if (strpos($dest, '//') === 0) return $fallback;

    // Aceptar rutas absolutas internas (/admin/...)
    if (strpos($dest, '/') === 0) return $dest;

    // Aceptar BASE_URL prefijado (/miapp/...)
    if (defined('BASE_URL') && BASE_URL !== '' && strpos($dest, BASE_URL . '/') === 0) return $dest;

    return $fallback;
}


// ======================
// Flash messages (toasts)
// ======================
// Uso:
//   flash_set('success', 'Guardado OK');
//   flash_set('error', 'Ocurrió un error');
// Luego en header.php se consumen con flash_pull_all() y se muestran como toasts.

/**
 * Agrega un mensaje flash a la sesión.
 *
 * @param string $type    success|error|warning|info
 * @param string $message Texto a mostrar.
 */
function flash_set($type, $message) {
    iniciarSesion();

    $type = strtolower(trim((string)$type));
    $allowed = ['success', 'error', 'warning', 'info'];
    if (!in_array($type, $allowed, true)) {
        $type = 'info';
    }

    $msg = trim((string)$message);
    if ($msg === '') return;

    if (!isset($_SESSION['flash_messages']) || !is_array($_SESSION['flash_messages'])) {
        $_SESSION['flash_messages'] = [];
    }

    $_SESSION['flash_messages'][] = [
        'type' => $type,
        'message' => $msg,
    ];
}

/**
 * Devuelve y limpia todos los mensajes flash pendientes.
 *
 * @return array<int, array{type:string, message:string}>
 */
function flash_pull_all() {
    iniciarSesion();
    $msgs = $_SESSION['flash_messages'] ?? [];
    if (!is_array($msgs)) $msgs = [];
    unset($_SESSION['flash_messages']);
    return $msgs;
}

/**
 * Migra mensajes legacy (mensaje_exito/mensaje_error) a flash_messages.
 * Mantiene compatibilidad con pantallas viejas.
 */
function flash_migrate_legacy() {
    iniciarSesion();

    // Patrones legacy usados en algunos módulos
    $legacyMap = [
        'mensaje_exito' => 'success',
        'mensaje_error' => 'error',
        'success' => 'success',
        'error' => 'error',
        'warning' => 'warning',
        'info' => 'info',
    ];

    foreach ($legacyMap as $key => $type) {
        if (!empty($_SESSION[$key]) && is_string($_SESSION[$key])) {
            flash_set($type, $_SESSION[$key]);
            unset($_SESSION[$key]);
        }
    }
}


/**
 * Verifica si el usuario está logueado
 * Si no lo está, redirige al login
 */
function verificarSesion() {
    iniciarSesion();
    
    if (!isset($_SESSION['usuario_id'])) {
        header('Location: ' . BASE_URL . '/admin/login.php');
        exit();
    }
}

/**
 * Verifica si el usuario es ADMIN
 */
function verificarAdmin() {
    iniciarSesion();
    
    // Si no hay sesión, al login
    if (!isset($_SESSION['tipo'])) {
        header('Location: ' . BASE_URL . '/admin/login.php');
        exit();
    }
    
    // Si es de campo, al Hub de Campo
    if ($_SESSION['tipo'] === 'CAMPO') {
        header('Location: ' . BASE_URL . '/admin/campo/index.php');
        exit();
    }
    
    // Si no es ADMIN (y no es CAMPO, por descarte), al login
    if ($_SESSION['tipo'] !== 'ADMIN') {
        header('Location: ' . BASE_URL . '/admin/login.php');
        exit();
    }
}

/**
 * Verifica si el usuario es de CAMPO (Operario)
 */
function verificarCampo() {
    iniciarSesion();
    
    if (!isset($_SESSION['tipo']) || $_SESSION['tipo'] !== 'CAMPO') {
        // Si es Admin, mandarlo al dashboard, si es nada, al login
        if (isset($_SESSION['tipo']) && $_SESSION['tipo'] === 'ADMIN') {
            header('Location: ' . BASE_URL . '/admin/dashboard.php');
        } else {
            header('Location: ' . BASE_URL . '/admin/login.php');
        }
        exit();
    }
}

/**
 * Verifica si el usuario de tipo CAMPO tiene acceso a un lote (tropa) específico.
 * Devuelve true si:
 *  - el usuario NO es CAMPO (no aplica), o
 *  - el lote está asignado en usuario_tropa para ese usuario.
 *
 * @param int $id_tropa
 * @return bool
 */
function usuarioCampoPuedeAccederLote($id_tropa) {
    iniciarSesion();

    if (!isset($_SESSION['tipo']) || $_SESSION['tipo'] !== 'CAMPO') {
        return true; // Solo aplica a CAMPO
    }

    $id_usuario = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : 0;
    $id_tropa = (int)$id_tropa;

    if ($id_usuario <= 0 || $id_tropa <= 0) {
        return false;
    }

    // Usar PDO para evitar concatenación y mantener consistencia
    require_once __DIR__ . '/../config/database.php';
    $db = getConnection();
    $stmt = $db->prepare("SELECT 1 FROM usuario_tropa WHERE id_usuario = ? AND id_tropa = ? LIMIT 1");
    $stmt->execute([$id_usuario, $id_tropa]);

    return (bool)$stmt->fetchColumn();
}

/**
 * Devuelve el registro de alimentación (consumo_lote) si pertenece al usuario indicado.
 * Útil para edición/borrado por parte de CAMPO.
 */
function obtenerConsumoDeUsuarioCampo($id_consumo, $id_usuario = null) {
    iniciarSesion();

    if ($id_usuario === null) {
        $id_usuario = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : 0;
    }

    $id_consumo = (int)$id_consumo;
    $id_usuario = (int)$id_usuario;

    if ($id_consumo <= 0 || $id_usuario <= 0) {
        return null;
    }

    require_once __DIR__ . '/../config/database.php';
    $db = getConnection();

    $stmt = $db->prepare("SELECT * FROM consumo_lote WHERE id_consumo = ? AND id_usuario = ? LIMIT 1");
    $stmt->execute([$id_consumo, $id_usuario]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Devuelve el registro de pesada si pertenece al usuario indicado.
 */
function obtenerPesadaDeUsuarioCampo($id_pesada, $id_usuario = null) {
    iniciarSesion();

    if ($id_usuario === null) {
        $id_usuario = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : 0;
    }

    $id_pesada = (int)$id_pesada;
    $id_usuario = (int)$id_usuario;

    if ($id_pesada <= 0 || $id_usuario <= 0) {
        return null;
    }

    require_once __DIR__ . '/../config/database.php';
    $db = getConnection();

    $stmt = $db->prepare("SELECT * FROM pesada WHERE id_pesada = ? AND id_usuario = ? LIMIT 1");
    $stmt->execute([$id_pesada, $id_usuario]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}


/**
 * Formatea una fecha en formato argentino
 * 
 * @param string $fecha - Fecha en formato Y-m-d
 * @return string - Fecha en formato d/m/Y
 */
function formatearFecha($fecha) {
    if (empty($fecha)) return '-';
    
    $timestamp = strtotime($fecha);
    return date('d/m/Y', $timestamp);
}

/**
 * Formatea un número decimal con separador de miles
 * 
 * @param float $numero - Número a formatear
 * @param int $decimales - Cantidad de decimales
 * @return string - Número formateado
 */
function formatearNumero($numero, $decimales = 2) {
    return number_format($numero, $decimales, ',', '.');
}

/**
 * Calcula el porcentaje de Materia Seca (MS) de un consumo
 * 
 * @param float $kg_insumo - Kg del insumo
 * @param float $porcentaje_ms_insumo - % MS del insumo
 * @return float - Kg de MS
 */
function calcularKgMS($kg_insumo, $porcentaje_ms_insumo) {
    return ($kg_insumo * $porcentaje_ms_insumo) / 100;
}

/**
 * Obtiene los animales presentes actuales de un lote
 * Basado en: cantidad_inicial + movimientos
 * 
 * @param int $id_tropa - ID del lote
 * @return int - Cantidad de animales presentes
 */
function obtenerAnimalesPresentes($id_tropa) {
    try {
        $db = getConnection();
        
        // Obtener cantidad inicial
        $stmt = $db->prepare("SELECT cantidad_inicial FROM tropa WHERE id_tropa = ?");
        $stmt->execute([$id_tropa]);
        $cantidad = $stmt->fetchColumn() ?: 0;
        
        // Sumar/restar movimientos
        $stmt = $db->prepare("
            SELECT 
                SUM(CASE 
                    WHEN tipo_movimiento IN ('ENTRADA', 'AJUSTE_POSITIVO') THEN cantidad
                    WHEN tipo_movimiento IN ('SALIDA', 'BAJA', 'AJUSTE_NEGATIVO') THEN -cantidad
                    ELSE 0
                END) as ajuste_total
            FROM movimiento_animal
            WHERE id_tropa = ?
        ");
        $stmt->execute([$id_tropa]);
        $ajuste = $stmt->fetchColumn();
        
        if ($ajuste) {
            $cantidad += $ajuste;
        }
        
        return max(0, $cantidad);
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Obtiene la dieta vigente de un lote en una fecha específica
 * 
 * @param int $id_tropa - ID del lote
 * @param string $fecha - Fecha en formato Y-m-d (opcional, por defecto hoy)
 * @return array|null - Datos de la dieta o null si no hay
 */
function obtenerDietaVigente($id_tropa, $fecha = null) {
    try {
        $db = getConnection();
        
        if ($fecha === null) {
            $fecha = date('Y-m-d');
        }
        
        $stmt = $db->prepare("
            SELECT tda.*, d.nombre as dieta_nombre, d.id_dieta
            FROM tropa_dieta_asignada tda
            INNER JOIN dieta d ON tda.id_dieta = d.id_dieta
            WHERE tda.id_tropa = ?
            AND tda.fecha_desde <= ?
            AND (tda.fecha_hasta IS NULL OR tda.fecha_hasta >= ?)
            ORDER BY tda.fecha_desde DESC
            LIMIT 1
        ");
        
        $stmt->execute([$id_tropa, $fecha, $fecha]);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $resultado ?: null;
        
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Muestra un mensaje de éxito o error
 * 
 * @param string $mensaje - Texto del mensaje
 * @param string $tipo - Tipo: 'success' o 'error'
 */
function mostrarMensaje($mensaje, $tipo = 'success') {
    $clase = $tipo === 'success' ? 'mensaje-exito' : 'mensaje-error';
    $icono = $tipo === 'success' ? '✓' : '✕';
    
    echo "<div class='mensaje {$clase}'>{$icono} {$mensaje}</div>";
}

/**
 * Redirige a una página después de un tiempo
 * 
 * @param string $url - URL de destino
 * @param int $segundos - Segundos de espera
 */
function redirigir($url, $segundos = 2) {
    header("refresh:{$segundos};url={$url}");
}




/**
 * Formatea una fecha en español sin depender de locales del sistema.
 * Ej: "martes, 3 de febrero"
 *
 * @param string|null $dateYmd Fecha en formato Y-m-d (opcional)
 * @return string
 */
function format_fecha_es($dateYmd = null) {
    $dateYmd = $dateYmd ?: date('Y-m-d');
    $ts = strtotime($dateYmd);
    if (!$ts) return (string)$dateYmd;

    $dias = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
    $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];

    $dia_semana = $dias[(int)date('w', $ts)] ?? '';
    $dia = (int)date('j', $ts);
    $mes = $meses[(int)date('n', $ts) - 1] ?? '';

    if ($dia_semana && $mes) {
        return $dia_semana . ', ' . $dia . ' de ' . $mes;
    }

    return date('d/m/Y', $ts);
}

// AUTO_REGISTER_ERROR_HANDLERS: garantizar logs/500 amigable aun si una página no incluye header.php a tiempo.
if (php_sapi_name() !== 'cli') {
    register_error_handlers();
}

?>

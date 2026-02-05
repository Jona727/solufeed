<?php
// config/env.php
// ⚠️ Importante: NO guardar credenciales reales en el repositorio.
// En producción, configurar variables de entorno (DB_HOST, DB_NAME, DB_USER, DB_PASS, BASE_URL, APP_ENV, APP_DEBUG).
// Alternativamente, podés crear un archivo NO versionado: config/env.local.php con las constantes.
// ==============================
// CARGA DE SECRETOS FUERA DEL WEB ROOT
// Si querés guardar credenciales fuera de public_html:
//   /home/TUUSUARIO/secret/solufeed.php
//
// Este bloque intenta cargar ese archivo automáticamente.
// También podés forzar la ruta con:
// - variable de entorno SOLUFEED_SECRET_PATH
// - o define('SOLUFEED_SECRET_PATH', '/ruta/absoluta/secret/solufeed.php');
// ==============================
$secretPath = null;

// 1) Constante / variable de entorno
if (defined('SOLUFEED_SECRET_PATH')) {
    $secretPath = SOLUFEED_SECRET_PATH;
} else {
    $envSecret = getenv('SOLUFEED_SECRET_PATH');
    if ($envSecret !== false && $envSecret !== null && $envSecret !== '') {
        $secretPath = $envSecret;
    }
}

// 2) cPanel típico: /home/user/public_html -> /home/user/secret/solufeed.php
if ($secretPath === null) {
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if ($docRoot !== '') {
        $candidate = realpath($docRoot . '/../secret/solufeed.php');
        if ($candidate && is_file($candidate)) {
            $secretPath = $candidate;
        }
    }
}

// 3) Fallback relativo (si el proyecto está dentro de public_html/solufeed/)
if ($secretPath === null) {
    $candidate = realpath(__DIR__ . '/../../../../secret/solufeed.php');
    if ($candidate && is_file($candidate)) {
        $secretPath = $candidate;
    }
}

if ($secretPath !== null && is_file($secretPath)) {
    require_once $secretPath;
}

$envLocal = __DIR__ . '/env.local.php';
if (file_exists($envLocal)) {
    require_once $envLocal;
}

$is_local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);

// BASE_URL
if (!defined('BASE_URL')) {
    $base = getenv('BASE_URL');
    if ($base === false || $base === null) {
        $base = $is_local ? '/solufeed' : '';
    }
    define('BASE_URL', $base);
}

// Entorno
if (!defined('APP_ENV')) {
    $appEnv = getenv('APP_ENV');
    if ($appEnv === false || $appEnv === null) {
        $appEnv = $is_local ? 'development' : 'production';
    }
    define('APP_ENV', $appEnv);
}

if (!defined('APP_DEBUG')) {
    $debug = getenv('APP_DEBUG');
    if ($debug === false || $debug === null) {
        $debug = $is_local ? 'true' : 'false';
    }
    define('APP_DEBUG', filter_var($debug, FILTER_VALIDATE_BOOLEAN));
}

// Credenciales DB
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: ($is_local ? 'solufeed_el_choli' : ''));
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: ($is_local ? 'root' : ''));
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: ($is_local ? '' : ''));



// Login Offline (opcional)
// Por seguridad, está deshabilitado por defecto. Si querés habilitarlo:
// - Definí OFFLINE_LOGIN_ENABLED=true en env.local.php o variable de entorno.
// - OFFLINE_LOGIN_TTL_HOURS define cuántas horas dura la sesión local (default 24).
if (!defined('OFFLINE_LOGIN_ENABLED')) {
    $v = getenv('OFFLINE_LOGIN_ENABLED');
    if ($v === false || $v === null) $v = 'false';
    define('OFFLINE_LOGIN_ENABLED', filter_var($v, FILTER_VALIDATE_BOOLEAN));
}
if (!defined('OFFLINE_LOGIN_TTL_HOURS')) {
    $ttl = getenv('OFFLINE_LOGIN_TTL_HOURS');
    if ($ttl === false || $ttl === null) $ttl = 24;
    define('OFFLINE_LOGIN_TTL_HOURS', max(1, (int)$ttl));
}


?>

<?php
/**
 * SoluFeed - Secret config (FUERA del web root)
 *
 * Ubicación sugerida (cPanel):
 *   /home/TUUSUARIO/secret/solufeed.php
 *
 * Importante:
 * - No subir este archivo al repo.
 * - No debe estar dentro de public_html.
 */
declare(strict_types=1);

// ==============================
// URL base de tu app
// - Si tu app está en: https://tudominio.com/solufeed   => BASE_URL = '/solufeed'
// - Si tu app está en: https://tudominio.com/           => BASE_URL = ''
// ==============================
if (!defined('BASE_URL')) define('BASE_URL', '/solufeed');

// ==============================
// Entorno
// ==============================
if (!defined('APP_ENV')) define('APP_ENV', 'production'); // 'development' o 'production'
if (!defined('APP_DEBUG')) define('APP_DEBUG', false);    // true solo para dev

// ==============================
// Credenciales de Base de Datos
// ==============================
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', ''); // <-- tu DB (o solufeed_test)
if (!defined('DB_USER')) define('DB_USER', '');  // <-- tu usuario DB
if (!defined('DB_PASS')) define('DB_PASS', '');    // <-- tu password DB

// ==============================
// Offline login (opcional)
// Por seguridad lo dejo apagado.
// ==============================
if (!defined('OFFLINE_LOGIN_ENABLED')) define('OFFLINE_LOGIN_ENABLED', false);
if (!defined('OFFLINE_LOGIN_TTL_HOURS')) define('OFFLINE_LOGIN_TTL_HOURS', 24);
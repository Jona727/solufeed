<?php
// config/database.php
// Configuración de conexión a la base de datos MySQL con PDO

/**
 * Función principal para obtener conexión PDO
 */
function getConnection() {
    // Cargar credenciales si no están definidas
    if (!defined('DB_HOST')) {
        $envPath = __DIR__ . '/env.php';
        if (file_exists($envPath)) {
            require_once $envPath;
        } else {
            die("Error: No se encuentra el archivo de configuración de entorno (config/env.php)");
        }
    }

    $host = DB_HOST;
    $dbname = DB_NAME;
    $username = DB_USER;
    $password = DB_PASS;
    
    try {
        $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        
        $db = new PDO($dsn, $username, $password, $options);
        return $db;
        
    } catch (PDOException $e) {
        // No exponer detalles sensibles en producción
        $msg = $e->getMessage();
        error_log("[Solufeed][DB] " . $msg);

        if (defined('APP_DEBUG') && APP_DEBUG) {
            die("Error al conectar con la base de datos: " . $msg);
        }

        die("Error al conectar con la base de datos. Contacte soporte.");
    }
}

// ===========================================
// FUNCIONES COMPATIBILIDAD MYSQLI (DEPRECATED)
// Mantener solo para soporte de código legado
// ===========================================

// Conexión mysqli global
$conn = null;

function getMysqliConnection() {
    global $conn;
    // Cargar credenciales si no están definidas (para redundancia)
    if (!defined('DB_HOST')) {
        $envPath = __DIR__ . '/env.php';
        if (file_exists($envPath)) {
            require_once $envPath;
        }
    }

    if ($conn === null) {
        $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if (!$conn) {
            die("Error de conexión mysqli: " . mysqli_connect_error());
        }
        mysqli_set_charset($conn, "utf8mb4");
    }
    return $conn;
}

// Inicializar conexión
// $conn = getMysqliConnection(); // DEPRECATED: No inicializar globalmente. Usar getConnection() (PDO) preferiblemente.

function ejecutarConsulta($query) {
    $conn = getMysqliConnection();
    $result = mysqli_query($conn, $query);
    if (!$result) {
        die("Error en la consulta: " . mysqli_error($conn));
    }
    return $result;
}

function limpiarDato($dato) {
    $conn = getMysqliConnection();
    return mysqli_real_escape_string($conn, trim($dato));
}

// ===========================================
// FUNCIONES AUXILIARES PDO
// ===========================================

function executeQuery($sql, $params = []) {
    $db = getConnection();
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function getOne($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt->fetch();
}

function getAll($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt->fetchAll();
}
?>

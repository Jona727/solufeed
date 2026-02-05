<?php
// Seguridad: este script solo debe ejecutarse por CLI (no por navegador)
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

// Script temporal para exportar la base de datos a un archivo .sql
require_once 'config/env.php';

$host = DB_HOST;
$user = DB_USER;
$pass = DB_PASS;
$name = DB_NAME;

$filename = 'solufeed_backup_' . date('Y-m-d_H-i-s') . '.sql';

// Intentar usar mysqldump si está disponible en el PATH (común en XAMPP)
$command = "mysqldump --opt -h $host -u $user " . ($pass ? "-p$pass " : "") . "$name > $filename";

system($command, $output);

if ($output === 0) {
    echo "✅ Base de datos exportada con éxito a: <b>$filename</b><br>";
    echo "Descarga este archivo y súbelo a phpMyAdmin en InfinityFree.";
} else {
    echo "❌ Error al exportar con mysqldump. Intentando respaldo manual...<br>";
    // Si falla mysqldump, podríamos implementar un exportador PHP básico aquí, 
    // pero usualmente en XAMPP mysqldump funciona.
    echo "Por favor, exporta la base de datos manualmente desde phpMyAdmin local (http://localhost/phpmyadmin).";
}
?>

<?php
// Seguridad: este script solo debe ejecutarse por CLI (no por navegador)
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

$conn = mysqli_connect('localhost', 'root', '', 'solufeed_el_choli');
if ($conn) {
    echo "Conectado a solufeed_el_choli.\n";
    $res = mysqli_query($conn, "SHOW TABLES");
    $tables = [];
    while ($row = mysqli_fetch_row($res)) {
        $tables[] = $row[0];
    }
    
    if (in_array('usuario', $tables)) {
        echo "Tabla 'usuario' ENCONTRADA.\n";
        // Check columns
        $res = mysqli_query($conn, "DESCRIBE usuario");
        while ($row = mysqli_fetch_assoc($res)) {
            echo $row['Field'] . "\n";
        }
    } else {
        echo "Tabla 'usuario' NO encontrada.\nTables: " . implode(', ', $tables);
    }
} else {
    echo "Error conectando: " . mysqli_connect_error();
}
?>

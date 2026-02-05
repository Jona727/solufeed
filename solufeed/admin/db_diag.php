<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

iniciarSesion();
register_error_handlers();
send_security_headers();

// Solo ADMIN
verificarAdmin();

$page_title = "Diagnóstico DB";
require_once __DIR__ . '/../includes/header.php';

echo '<div class="card" style="max-width:1000px;margin:24px auto;padding:18px">';
echo '<h2 style="margin:0 0 10px">🛠️ Diagnóstico de base de datos</h2>';

try {
    $db = getConnection();
    $dbName = $db->query("SELECT DATABASE()")->fetchColumn();
    echo '<p><b>DB actual:</b> ' . htmlspecialchars((string)$dbName, ENT_QUOTES, 'UTF-8') . '</p>';

    // La tabla de usuarios en el dump es `usuario` (singular)
    $required = ['usuario','tropa','campo','consumo_lote','consumo_lote_detalle','pesada','dieta','tropa_dieta_asignada','insumo'];
    $missing = [];
    foreach ($required as $t) {
        // MariaDB no permite placeholders en SHOW TABLES; usamos information_schema
        $stmt = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t LIMIT 1");
        $stmt->execute([':t' => $t]);
        if (!$stmt->fetchColumn()) $missing[] = $t;
    }

    if ($missing) {
        echo '<p style="color:#b45309"><b>Faltan tablas:</b> ' . htmlspecialchars(implode(', ', $missing), ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<p>Importá el dump <code>solufeed_test_db-3.sql</code> en esta misma base.</p>';
    } else {
        echo '<p style="color:#047857"><b>OK:</b> Tablas principales presentes.</p>';
    }

    $counts = [
        'usuario' => (int)$db->query("SELECT COUNT(*) FROM usuario")->fetchColumn(),
        'tropa' => (int)$db->query("SELECT COUNT(*) FROM tropa")->fetchColumn(),
        'campo' => (int)$db->query("SELECT COUNT(*) FROM campo")->fetchColumn(),
    ];
    echo '<h3 style="margin:16px 0 8px">Conteos</h3><ul>';
    foreach ($counts as $k => $v) {
        echo '<li><b>' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . ':</b> ' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    echo '</ul>';

} catch (Throwable $e) {
    echo '<p style="color:#b91c1c"><b>Error:</b> ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
}

echo '<p style="margin-top:16px">Si el dashboard sigue fallando, revisá <code>logs/app.log</code> y el error log del hosting.</p>';
echo '</div>';

require_once __DIR__ . '/../includes/footer.php';

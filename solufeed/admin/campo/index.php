<?php
// admin/campo/index.php
// Hub principal para Usuario de Campo
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Verificar sesión y rol de campo
verificarCampo();

$page_title = "Usuario de Campo";
$db = getConnection();

// Obtener información del día actual
$hoy = date('Y-m-d');

// Contar alimentaciones registradas hoy por este usuario
$stmt = $db->prepare("
    SELECT COUNT(*) as total 
    FROM consumo_lote
    WHERE DATE(fecha) = ? AND id_usuario = ?
");
$stmt->execute([$hoy, $_SESSION['usuario_id']]);
$alimentaciones_hoy = $stmt->fetch()['total'];

// Contar pesadas registradas hoy
$stmt = $db->prepare("
    SELECT COUNT(*) as total 
    FROM pesada
    WHERE DATE(fecha) = ? AND id_usuario = ?
");
$stmt->execute([$hoy, $_SESSION['usuario_id']]);
$pesadas_hoy = $stmt->fetch()['total'];

// Obtener lotes activos
// Obtener lotes activos
if ($_SESSION['tipo'] === 'CAMPO') {
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT t.id_tropa) as total 
        FROM tropa t
        INNER JOIN usuario_tropa ut ON t.id_tropa = ut.id_tropa
        WHERE t.activo = 1 AND ut.id_usuario = ?
    ");
    $stmt->execute([$_SESSION['usuario_id']]);
} else {
    $stmt = $db->query("
        SELECT COUNT(*) as total 
        FROM tropa 
        WHERE activo = 1
    ");
}
$lotes_activos = $stmt->fetch()['total'];

// Obtener lotes pendientes de alimentar hoy
if ($_SESSION['tipo'] === 'CAMPO') {
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT t.id_tropa) as total
        FROM tropa t
        INNER JOIN usuario_tropa ut ON t.id_tropa = ut.id_tropa
        LEFT JOIN consumo_lote cl ON t.id_tropa = cl.id_tropa 
            AND DATE(cl.fecha) = ?
        WHERE t.activo = 1 
        AND ut.id_usuario = ?
        AND cl.id_consumo IS NULL
    ");
    $stmt->execute([$hoy, $_SESSION['usuario_id']]);
} else {
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT t.id_tropa) as total
        FROM tropa t
        LEFT JOIN consumo_lote cl ON t.id_tropa = cl.id_tropa 
            AND DATE(cl.fecha) = ?
        WHERE t.activo = 1 
        AND cl.id_consumo IS NULL
    ");
    $stmt->execute([$hoy]);
}
$lotes_pendientes = $stmt->fetch()['total'];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="campo-hub">
    <!-- Indicador de estado para PWA -->
    <div id="connection-status" style="display:none; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: bold;"></div>
    
    <!-- Header principal -->
    <div class="card" style="background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%); color: white; border: none; padding: 2.5rem; text-align: center;">
        <div style="font-size: 4rem; margin-bottom: 1rem;">👷</div>
        <h1 style="font-weight: 800; margin-bottom: 0.5rem; letter-spacing: -1px;">Área de Campo</h1>
        <p style="opacity: 0.9; font-weight: 500;">Hola, <?php echo htmlspecialchars($_SESSION['nombre']); ?>. ¡Buen trabajo hoy!</p>
        <div style="margin-top: 1.5rem; font-size: 0.9rem; background: rgba(0,0,0,0.1); display: inline-block; padding: 0.5rem 1rem; border-radius: 50px;">
            📅 <?php echo htmlspecialchars(format_fecha_es($hoy)); ?>
        </div>
    </div>

    <!-- Alerta de lotes pendientes -->
        <div class="card" style="background: #fff3cd; border-left: 5px solid var(--warning); padding: 1.25rem; display: flex; align-items: center; gap: 15px; margin-bottom: 2rem;">
            <div style="font-size: 2rem;">⚠️</div>
            <div style="flex: 1;">
                <strong style="color: #856404; display: block;">Lotes pendientes</strong>
                <span style="color: #856404; font-size: 0.9rem;">Faltan <?php echo $lotes_pendientes; ?> lotes por alimentar hoy.</span>
            </div>
            <a href="consultar_lotes.php?filtro=sin_alimentar" class="btn btn-secondary" style="font-size: 0.8rem; padding: 0.5rem 1rem;">Gestionar</a>
        </div>

    <!-- Estadísticas rápidas -->
    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 2rem;">
        <div class="card" style="text-align: center; padding: 1rem; margin-bottom: 0;">
            <div style="font-size: 1.75rem; font-weight: 800; color: var(--primary);"><?php echo $alimentaciones_hoy; ?></div>
            <div style="font-size: 0.75rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase;">Mixers</div>
        </div>
        <div class="card" style="text-align: center; padding: 1rem; margin-bottom: 0;">
            <div style="font-size: 1.75rem; font-weight: 800; color: var(--secondary);"><?php echo $pesadas_hoy; ?></div>
            <div style="font-size: 0.75rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase;">Pesadas</div>
        </div>
    </div>

    <!-- Acciones principales (Hub táctil) -->
    <div class="hub-grid" style="margin-bottom: 2.5rem;">
        <a href="../alimentaciones/registrar.php" class="hub-btn primary">
            <span class="icon">🍽️</span>
            <span class="title">Cargar Mixer</span>
            <span style="font-size: 0.85rem; opacity: 0.8; font-weight: 500;">Registrar ración hoy</span>
        </a>

        <a href="../pesadas/registrar.php" class="hub-btn" style="border: 2px solid var(--secondary);">
            <span class="icon" style="color: var(--secondary);">⚖️</span>
            <span class="title" style="color: var(--secondary);">Registrar Pesada</span>
            <span style="font-size: 0.85rem; color: var(--text-muted); font-weight: 500;">Aumento de peso</span>
        </a>
    </div>

    <!-- Otras acciones -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
        <a href="historial.php" class="card" style="text-align: center; text-decoration: none; padding: 1.25rem;">
            <div style="font-size: 1.5rem; margin-bottom: 0.5rem;">📋</div>
            <div style="font-weight: 700; color: var(--primary); font-size: 0.9rem;">Mi Historial</div>
        </a>
        <a href="consultar_lotes.php" class="card" style="text-align: center; text-decoration: none; padding: 1.25rem;">
            <div style="font-size: 1.5rem; margin-bottom: 0.5rem;">🐮</div>
            <div style="font-weight: 700; color: var(--primary); font-size: 0.9rem;">Ver Lotes</div>
        </a>
        <a href="../../admin/logout.php" class="card" style="text-align: center; text-decoration: none; padding: 1.25rem; border-color: #fee2e2;">
            <div style="font-size: 1.5rem; margin-bottom: 0.5rem;">🚪</div>
            <div style="font-weight: 700; color: var(--danger); font-size: 0.9rem;">Salir</div>
        </a>
    </div>

    <!-- Info -->
    <div class="card" style="background: #f1f5f9; border: none;">
        <h3 style="color: var(--primary); font-weight: 800; margin-bottom: 1rem; display: flex; align-items: center; gap: 10px;">
            <span>💡</span> Consejos
        </h3>
        <ul style="padding-left: 1.25rem; font-size: 0.95rem; color: var(--text-main); display: grid; gap: 0.5rem;">
            <li>No necesitas internet para cargar datos, el sistema guarda todo solo.</li>
            <li>Revisá que el número de animales en el corral coincida con el sistema.</li>
            <li>Si tenés dudas, contactá al Administrador.</li>
        </ul>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
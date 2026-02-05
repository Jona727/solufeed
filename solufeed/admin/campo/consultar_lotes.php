<?php
// admin/campo/consultar_lotes.php
// Consultar información de lotes disponibles
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

verificarCampo();

$page_title = "Consultar Lotes";
$db = getConnection();

// Filtros dinámicos
$filtro = isset($_GET['filtro']) ? $_GET['filtro'] : '';
$where_clause = "WHERE t.activo = 1";
$join_usuario = "";

// Filtro de seguridad para CAMPO
if (isset($_SESSION['tipo']) && $_SESSION['tipo'] === 'CAMPO') {
    $join_usuario = "INNER JOIN usuario_tropa ut ON t.id_tropa = ut.id_tropa";
    $where_clause .= " AND ut.id_usuario = " . intval($_SESSION['usuario_id']);
}

if ($filtro === 'sin_alimentar') {
    $where_clause .= " AND NOT EXISTS (
        SELECT 1 FROM consumo_lote cl 
        WHERE cl.id_tropa = t.id_tropa AND DATE(cl.fecha) = CURDATE()
    )";
}

// Obtener lotes activos con información completa
$stmt = $db->query("
    SELECT 
        t.*,
        c.nombre as nombre_campo,
        d.nombre as nombre_dieta,
        tda.fecha_desde as fecha_asignacion_dieta,
        (
            SELECT COUNT(*) 
            FROM consumo_lote cl 
            WHERE cl.id_tropa = t.id_tropa 
            AND DATE(cl.fecha) = CURDATE()
        ) as alimentado_hoy,
        (
            SELECT peso_promedio 
            FROM pesada p 
            WHERE p.id_tropa = t.id_tropa 
            ORDER BY p.fecha DESC 
            LIMIT 1
        ) as ultimo_peso,
        (
            SELECT DATE(fecha) 
            FROM pesada p 
            WHERE p.id_tropa = t.id_tropa 
            ORDER BY p.fecha DESC 
            LIMIT 1
        ) as fecha_ultima_pesada
    FROM tropa t
    LEFT JOIN campo c ON t.id_campo = c.id_campo
    LEFT JOIN tropa_dieta_asignada tda ON t.id_tropa = tda.id_tropa 
        AND tda.fecha_hasta IS NULL
    LEFT JOIN dieta d ON tda.id_dieta = d.id_dieta
    $join_usuario
    $where_clause
    ORDER BY t.nombre ASC
");
$lotes = $stmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<style>
.consulta-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.header-consulta {
    background: white;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.lotes-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
}

.lote-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    transition: all 0.3s ease;
    border-left: 5px solid #2c5530;
}

.lote-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.lote-card.alimentado {
    border-left-color: #28a745;
}

.lote-card.sin-alimentar {
    border-left-color: #ffc107;
}

.lote-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 15px;
    padding-bottom: 15px;
    border-bottom: 2px solid #f0f0f0;
}

.lote-nombre {
    font-size: 1.3em;
    font-weight: bold;
    color: #2c5530;
    margin: 0;
}

.lote-badge {
    padding: 5px 12px;
    border-radius: 15px;
    font-size: 0.85em;
    font-weight: bold;
}

.badge-alimentado {
    background: #d4edda;
    color: #155724;
}

.badge-pendiente {
    background: #fff3cd;
    color: #856404;
}

.lote-info {
    display: grid;
    gap: 12px;
}

.info-row {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 0.95em;
}

.info-row .icono {
    font-size: 1.2em;
    width: 25px;
    text-align: center;
}

.info-row .etiqueta {
    color: #666;
    min-width: 120px;
}

.info-row .valor {
    font-weight: bold;
    color: #333;
}

.lote-acciones {
    display: flex;
    gap: 10px;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 2px solid #f0f0f0;
}

.btn-accion {
    flex: 1;
    padding: 10px;
    border: none;
    border-radius: 5px;
    font-size: 0.9em;
    font-weight: bold;
    cursor: pointer;
    text-decoration: none;
    text-align: center;
    transition: all 0.3s;
}

.btn-alimentar {
    background: #2c5530;
    color: white;
}

.btn-alimentar:hover {
    background: #3d7043;
}

.btn-ver-dieta {
    background: #f8f9fa;
    color: #2c5530;
    border: 1px solid #2c5530;
}

.btn-ver-dieta:hover {
    background: #2c5530;
    color: white;
}

.btn-volver {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: #2c5530;
    color: white;
    text-decoration: none;
    border-radius: 5px;
    transition: all 0.3s;
}

.btn-volver:hover {
    background: #3d7043;
    transform: translateX(-3px);
}

.mensaje-vacio {
    background: white;
    padding: 60px 20px;
    border-radius: 10px;
    text-align: center;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.mensaje-vacio .icono {
    font-size: 5em;
    margin-bottom: 20px;
    opacity: 0.5;
}

@media (max-width: 768px) {
    .lotes-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="consulta-container">
    <!-- Header -->
    <div class="header-consulta">
        <div>
            <h1 style="margin: 0 0 5px 0;">
                <?php echo ($filtro === 'sin_alimentar') ? "⚠️ Lotes Pendientes de Ración" : "🐮 Consultar Lotes"; ?>
            </h1>
            <p style="margin: 0; color: #666;">
                <?php echo ($filtro === 'sin_alimentar') ? "Mostrando lotes que aún no han recibido alimento hoy." : "Información de todos los lotes activos"; ?>
            </p>
        </div>
        <div style="display: flex; gap: 10px;">
            <?php if ($filtro): ?>
                <a href="consultar_lotes.php" class="btn-volver" style="background: var(--secondary);">
                    ✕ Ver todos los lotes
                </a>
            <?php endif; ?>
            <a href="index.php" class="btn-volver">
                ← Volver al Hub
            </a>
        </div>
    </div>

    <!-- Grid de lotes -->
    <?php if (count($lotes) > 0): ?>
        <div class="lotes-grid">
            <?php foreach ($lotes as $lote): ?>
                <?php
                $alimentado_hoy = $lote['alimentado_hoy'] > 0;
                $dias_feedlot = (time() - strtotime($lote['fecha_inicio'])) / 86400;
                ?>
                
                <div class="lote-card <?php echo $alimentado_hoy ? 'alimentado' : 'sin-alimentar'; ?>">
                    <!-- Header del lote -->
                    <div class="lote-header">
                        <h3 class="lote-nombre"><?php echo htmlspecialchars($lote['nombre']); ?></h3>
                        <span class="lote-badge <?php echo $alimentado_hoy ? 'badge-alimentado' : 'badge-pendiente'; ?>">
                            <?php echo $alimentado_hoy ? '✓ Alimentado' : '⏳ Pendiente'; ?>
                        </span>
                    </div>

                    <!-- Información del lote -->
                    <div class="lote-info">
                        <div class="info-row">
                            <span class="icono">📍</span>
                            <span class="etiqueta">Campo:</span>
                            <span class="valor"><?php echo htmlspecialchars($lote['nombre_campo'] ?? 'Sin asignar'); ?></span>
                        </div>

                        <div class="info-row">
                            <span class="icono">🐄</span>
                            <span class="etiqueta">Animales:</span>
                            <span class="valor"><?php echo $lote['cantidad_inicial']; ?> cabezas</span>
                        </div>

                        <div class="info-row">
                            <span class="icono">🏷️</span>
                            <span class="etiqueta">Categoría:</span>
                            <span class="valor"><?php echo htmlspecialchars($lote['categoria']); ?></span>
                        </div>

                        <div class="info-row">
                            <span class="icono">📋</span>
                            <span class="etiqueta">Dieta:</span>
                            <span class="valor">
                                <?php if ($lote['nombre_dieta']): ?>
                                    <?php echo htmlspecialchars($lote['nombre_dieta']); ?>
                                <?php else: ?>
                                    <span style="color: #dc3545;">Sin dieta asignada</span>
                                <?php endif; ?>
                            </span>
                        </div>

                        <?php if ($lote['ultimo_peso']): ?>
                            <div class="info-row">
                                <span class="icono">⚖️</span>
                                <span class="etiqueta">Último peso:</span>
                                <span class="valor">
                                    <?php echo number_format($lote['ultimo_peso'], 0); ?> kg
                                    <span style="color: #666; font-weight: normal; font-size: 0.85em;">
                                        (<?php echo date('d/m/Y', strtotime($lote['fecha_ultima_pesada'])); ?>)
                                    </span>
                                </span>
                            </div>
                        <?php endif; ?>

                        <div class="info-row">
                            <span class="icono">📅</span>
                            <span class="etiqueta">Días en feedlot:</span>
                            <span class="valor"><?php echo floor($dias_feedlot); ?> días</span>
                        </div>
                    </div>

                    <!-- Acciones -->
                    <div class="lote-acciones">
                        <?php if ($lote['nombre_dieta']): ?>
                            <a href="../alimentaciones/registrar.php?lote=<?php echo $lote['id_tropa']; ?>" 
                               class="btn-accion btn-alimentar">
                                🍽️ Alimentar
                            </a>
                            <a href="ver_dieta.php?lote=<?php echo $lote['id_tropa']; ?>" 
                               class="btn-accion btn-ver-dieta">
                                📊 Ver Dieta
                            </a>
                        <?php else: ?>
                            <div style="text-align: center; padding: 10px; background: #fff3cd; border-radius: 5px; font-size: 0.9em; color: #856404;">
                                ⚠️ Este lote no tiene dieta asignada
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="mensaje-vacio">
            <?php if ($filtro === 'sin_alimentar'): ?>
                <div class="icono">✅</div>
                <h2 style="color: #2c5530;">¡Todo al día!</h2>
                <p style="color: #666;">Todos los lotes activos han sido alimentados hoy.</p>
                <a href="consultar_lotes.php" class="btn-volver" style="background: var(--secondary); margin-top: 20px;">
                    Ver todos los lotes
                </a>
            <?php else: ?>
                <div class="icono">🐮</div>
                <h2 style="color: #666;">No hay lotes activos</h2>
                <p style="color: #999;">Actualmente no hay lotes registrados en el sistema.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Botón para volver -->
    <div style="text-align: center; margin-top: 30px;">
        <a href="index.php" class="btn-volver" style="font-size: 1.1em;">
            ← Volver al Hub de Campo
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
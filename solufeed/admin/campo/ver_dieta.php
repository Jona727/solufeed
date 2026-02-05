<?php
// admin/campo/ver_dieta.php
// Ver dieta vigente de un lote específico
require_once '../../config/database.php';
require_once '../../includes/functions.php';

verificarCampo();

$page_title = "Ver Dieta del Lote";
$db = getConnection();

// Obtener lote seleccionado
$lote_id = isset($_GET['lote']) ? (int)$_GET['lote'] : 0;

if ($lote_id > 0) {
    // Obtener información del lote
    $stmt = $db->prepare("
        SELECT 
            t.*,
            c.nombre as nombre_campo,
            d.id_dieta,
            d.nombre as nombre_dieta,
            d.descripcion as descripcion_dieta,
            tda.fecha_desde as fecha_asignacion
        FROM tropa t
        INNER JOIN usuario_tropa ut ON t.id_tropa = ut.id_tropa
        LEFT JOIN campo c ON t.id_campo = c.id_campo
        LEFT JOIN tropa_dieta_asignada tda ON t.id_tropa = tda.id_tropa 
            AND tda.fecha_hasta IS NULL
        LEFT JOIN dieta d ON tda.id_dieta = d.id_dieta
        WHERE t.id_tropa = ? AND t.activo = 1 AND ut.id_usuario = ?
    ");
    $stmt->execute([$lote_id, (int)$_SESSION['usuario_id']]);
    $lote = $stmt->fetch();

    if ($lote && $lote['id_dieta']) {
        // Obtener composición de la dieta
        $stmt = $db->prepare("
            SELECT 
                dd.*,
                i.nombre as nombre_insumo,
                i.tipo,
                i.porcentaje_ms
            FROM dieta_detalle dd
            INNER JOIN insumo i ON dd.id_insumo = i.id_insumo
            WHERE dd.id_dieta = ?
            ORDER BY dd.porcentaje_teorico DESC
        ");
        $stmt->execute([$lote['id_dieta']]);
        $composicion = $stmt->fetchAll();

        // Calcular MS total de la dieta
        $ms_total = 0;
        foreach ($composicion as $item) {
            $ms_total += ($item['porcentaje_teorico'] * $item['porcentaje_ms']) / 100;
        }
    }
}

// Si no hay lote seleccionado, obtener lista de lotes con dieta (solo los asignados al usuario)
$stmt = $db->prepare("
    SELECT 
        t.id_tropa,
        t.nombre,
        c.nombre as nombre_campo,
        d.nombre as nombre_dieta
    FROM tropa t
    INNER JOIN usuario_tropa ut ON t.id_tropa = ut.id_tropa
    LEFT JOIN campo c ON t.id_campo = c.id_campo
    LEFT JOIN tropa_dieta_asignada tda ON t.id_tropa = tda.id_tropa 
        AND tda.fecha_hasta IS NULL
    LEFT JOIN dieta d ON tda.id_dieta = d.id_dieta
    WHERE t.activo = 1 
      AND d.id_dieta IS NOT NULL
      AND ut.id_usuario = ?
    ORDER BY t.nombre ASC
");
$stmt->execute([(int)$_SESSION['usuario_id']]);
$lotes_disponibles = $stmt->fetchAll();

require_once '../../includes/header.php';
?>

<style>
.ver-dieta-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.header-dieta {
    background: white;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}

.selector-lote {
    background: white;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.info-lote {
    background: linear-gradient(135deg, #2c5530 0%, #3d7043 100%);
    color: white;
    padding: 25px;
    border-radius: 10px;
    margin-bottom: 20px;
}

.info-lote h2 {
    margin: 0 0 15px 0;
    font-size: 1.8em;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.info-item {
    display: flex;
    align-items: center;
    gap: 10px;
}

.info-item .icono {
    font-size: 1.5em;
}

.dieta-info {
    background: white;
    padding: 25px;
    border-radius: 10px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.dieta-nombre {
    font-size: 1.8em;
    color: #2c5530;
    margin: 0 0 10px 0;
}

.dieta-descripcion {
    color: #666;
    font-style: italic;
    margin: 0 0 20px 0;
}

.composicion-tabla {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

.composicion-tabla thead {
    background: #2c5530;
    color: white;
}

.composicion-tabla th,
.composicion-tabla td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}

.composicion-tabla tbody tr:hover {
    background: #f8f9fa;
}

.composicion-tabla .porcentaje-bar {
    height: 25px;
    background: #e9ecef;
    border-radius: 5px;
    overflow: hidden;
    position: relative;
}

.composicion-tabla .porcentaje-fill {
    height: 100%;
    background: linear-gradient(90deg, #2c5530 0%, #3d7043 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: bold;
    font-size: 0.85em;
}

.tipo-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 0.85em;
    font-weight: bold;
}

.tipo-grano { background: #fff3cd; color: #856404; }
.tipo-forraje { background: #d4edda; color: #155724; }
.tipo-concentrado { background: #d1ecf1; color: #0c5460; }
.tipo-suplemento { background: #f8d7da; color: #721c24; }

.resumen-ms {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 10px;
    margin-top: 20px;
    border-left: 4px solid #2c5530;
}

.resumen-ms h3 {
    margin: 0 0 15px 0;
    color: #2c5530;
}

.ms-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.ms-item {
    background: white;
    padding: 15px;
    border-radius: 8px;
    text-align: center;
}

.ms-item .valor {
    font-size: 2em;
    font-weight: bold;
    color: #2c5530;
    margin: 0;
}

.ms-item .etiqueta {
    font-size: 0.9em;
    color: #666;
    margin: 5px 0 0 0;
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

.btn-alimentar {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 25px;
    background: #2c5530;
    color: white;
    text-decoration: none;
    border-radius: 5px;
    font-weight: bold;
    transition: all 0.3s;
}

.btn-alimentar:hover {
    background: #3d7043;
    transform: scale(1.05);
}

.mensaje-sin-dieta {
    background: white;
    padding: 60px 20px;
    border-radius: 10px;
    text-align: center;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.mensaje-sin-dieta .icono {
    font-size: 5em;
    margin-bottom: 20px;
    opacity: 0.5;
}

select {
    width: 100%;
    padding: 12px;
    border: 2px solid #ddd;
    border-radius: 5px;
    font-size: 1em;
    margin-bottom: 15px;
}

select:focus {
    border-color: #2c5530;
    outline: none;
}
</style>

<div class="ver-dieta-container">
    <!-- Header -->
    <div class="header-dieta">
        <div>
            <h1 style="margin: 0 0 5px 0;">📊 Ver Dieta del Lote</h1>
            <p style="margin: 0; color: #666;">
                Consultar la composición de la dieta asignada
            </p>
        </div>
        <a href="index.php" class="btn-volver">
            ← Volver al Hub
        </a>
    </div>

    <!-- Selector de lote -->
    <?php if (!$lote_id || !$lote): ?>
        <div class="selector-lote">
            <h3 style="margin: 0 0 15px 0; color: #2c5530;">Seleccionar Lote</h3>
            <form method="GET" action="">
                <select name="lote" onchange="this.form.submit()" required data-searchable="true" data-search-placeholder="Buscar lote...">
                    <option value="">-- Seleccionar un lote --</option>
                    <?php foreach ($lotes_disponibles as $l): ?>
                        <option value="<?php echo $l['id_tropa']; ?>">
                            <?php echo htmlspecialchars($l['nombre']); ?> 
                            - <?php echo htmlspecialchars($l['nombre_dieta']); ?>
                            (<?php echo htmlspecialchars($l['nombre_campo']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <p style="color: #666; margin: 10px 0 0 0;">
                💡 Seleccioná un lote para ver su dieta vigente
            </p>
        </div>
    <?php endif; ?>

    <!-- Mostrar dieta si hay lote seleccionado -->
    <?php if ($lote_id && $lote): ?>
        <?php if ($lote['id_dieta']): ?>
            <!-- Información del lote -->
            <div class="info-lote">
                <h2>🐮 <?php echo htmlspecialchars($lote['nombre']); ?></h2>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="icono">📍</span>
                        <span><?php echo htmlspecialchars($lote['nombre_campo']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="icono">🐄</span>
                        <span><?php echo $lote['cantidad_inicial']; ?> animales</span>
                    </div>
                    <div class="info-item">
                        <span class="icono">🏷️</span>
                        <span><?php echo htmlspecialchars($lote['categoria']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="icono">📅</span>
                        <span>Dieta desde: <?php echo date('d/m/Y', strtotime($lote['fecha_asignacion'])); ?></span>
                    </div>
                </div>
            </div>

            <!-- Información de la dieta -->
            <div class="dieta-info">
                <h2 class="dieta-nombre">📋 <?php echo htmlspecialchars($lote['nombre_dieta']); ?></h2>
                <?php if ($lote['descripcion_dieta']): ?>
                    <p class="dieta-descripcion"><?php echo htmlspecialchars($lote['descripcion_dieta']); ?></p>
                <?php endif; ?>

                <!-- Tabla de composición -->
                <h3 style="margin: 20px 0 10px 0; color: #2c5530;">🌾 Composición de la Dieta</h3>
                <table class="composicion-tabla responsive-table">
                    <thead>
                        <tr>
                            <th>Insumo</th>
                            <th>Tipo</th>
                            <th>% MS Insumo</th>
                            <th>% en Dieta</th>
                            <th>Proporción Visual</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($composicion as $item): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($item['nombre_insumo']); ?></strong></td>
                                <td>
                                    <span class="tipo-badge tipo-<?php echo strtolower($item['tipo']); ?>">
                                        <?php echo htmlspecialchars($item['tipo']); ?>
                                    </span>
                                </td>
                                <td><?php echo number_format($item['porcentaje_ms'], 1); ?>%</td>
                                <td><strong><?php echo number_format($item['porcentaje_teorico'], 1); ?>%</strong></td>
                                <td>
                                    <div class="porcentaje-bar">
                                        <div class="porcentaje-fill" style="width: <?php echo $item['porcentaje_teorico']; ?>%">
                                            <?php echo number_format($item['porcentaje_teorico'], 1); ?>%
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Resumen de MS -->
                <div class="resumen-ms">
                    <h3>📊 Materia Seca de la Dieta</h3>
                    <div class="ms-grid">
                        <div class="ms-item">
                            <p class="valor"><?php echo number_format($ms_total, 2); ?>%</p>
                            <p class="etiqueta">% MS Total de la Dieta</p>
                        </div>
                        <div class="ms-item">
                            <p class="valor"><?php echo count($composicion); ?></p>
                            <p class="etiqueta">Insumos en la Mezcla</p>
                        </div>
                    </div>
                    
                    <div style="margin-top: 20px; padding: 15px; background: white; border-radius: 8px;">
                        <p style="margin: 0; color: #666; font-size: 0.95em;">
                            <strong>💡 Nota:</strong> El % MS total indica cuánta materia seca contiene esta dieta. 
                            Para calcular el consumo de MS de los animales, se multiplicarán los kg totales entregados 
                            por este porcentaje.
                        </p>
                    </div>
                </div>

                <!-- Acción rápida -->
                <div style="text-align: center; margin-top: 30px;">
                    <a href="../alimentaciones/registrar.php?lote=<?php echo $lote_id; ?>" class="btn-alimentar">
                        🍽️ Registrar Alimentación para este Lote
                    </a>
                </div>
            </div>

        <?php else: ?>
            <!-- Lote sin dieta asignada -->
            <div class="mensaje-sin-dieta">
                <div class="icono">⚠️</div>
                <h2 style="color: #856404;">Este lote no tiene dieta asignada</h2>
                <p style="color: #666;">
                    El lote <strong><?php echo htmlspecialchars($lote['nombre']); ?></strong> 
                    no tiene una dieta asignada actualmente.
                </p>
                <p style="color: #999; margin-top: 15px;">
                    Contactá al administrador para que asigne una dieta a este lote.
                </p>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Botones de navegación -->
    <div style="text-align: center; margin-top: 30px; display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
        <a href="consultar_lotes.php" class="btn-volver">
            ← Ver Todos los Lotes
        </a>
        <a href="index.php" class="btn-volver">
            🏠 Volver al Hub
        </a>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
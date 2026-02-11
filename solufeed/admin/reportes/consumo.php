<?php
/**
 * SOLUFEED - Reporte de Consumo y Métricas
 * Muestra indicadores técnicos calculados automáticamente
 */

require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Verificar permisos de administrador
verificarAdmin();

$db = getConnection();

// Helper: validar fecha YYYY-MM-DD
$esFechaValida = function($s) {
    if (!is_string($s) || $s === '') return false;
    $dt = DateTime::createFromFormat('Y-m-d', $s);
    return $dt && $dt->format('Y-m-d') === $s;
};

// Filtros
$lote_filtro = isset($_GET['lote']) ? (int) $_GET['lote'] : 0;
$fecha_desde = isset($_GET['fecha_desde']) ? $_GET['fecha_desde'] : date('Y-m-d', strtotime('-30 days'));
$fecha_hasta = isset($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : date('Y-m-d');

if (!$esFechaValida($fecha_desde)) {
    $fecha_desde = date('Y-m-d', strtotime('-30 days'));
}
if (!$esFechaValida($fecha_hasta)) {
    $fecha_hasta = date('Y-m-d');
}
if ($fecha_desde > $fecha_hasta) {
    // swap
    $tmp = $fecha_desde;
    $fecha_desde = $fecha_hasta;
    $fecha_hasta = $tmp;
}

// Obtener lotes activos para el filtro
$stmt_l = $db->query("SELECT id_tropa, nombre FROM tropa WHERE activo = 1 ORDER BY nombre ASC");
$lotes_disponibles = $stmt_l->fetchAll(PDO::FETCH_ASSOC);

// Si no se seleccionó lote específico, tomar el primero
if ($lote_filtro === 0 && !empty($lotes_disponibles)) {
    $lote_filtro = (int)$lotes_disponibles[0]['id_tropa'];
}

// Datos del lote
$lote_seleccionado = null;
$animales_presentes = 0;

// Variables del reporte
$pesadas_array = [];
$alimentaciones_array = [];

$peso_inicial = null;
$peso_final = null;
$peso_medio = null;
$dias_periodo = 0;

$total_kg_ms = 0;
$dias_con_alimentacion = 0;

$adpv = null;
$cms_diario = null;
$cms_porcentaje_pv = null;
$ec = null;
$kg_producidos = null;

if ($lote_filtro > 0) {
    $stmt_lote = $db->prepare("
        SELECT t.*, c.nombre AS campo_nombre
        FROM tropa t
        INNER JOIN campo c ON t.id_campo = c.id_campo
        WHERE t.id_tropa = ?
    ");
    $stmt_lote->execute([$lote_filtro]);
    $lote_seleccionado = $stmt_lote->fetch(PDO::FETCH_ASSOC);

    if ($lote_seleccionado) {
        $animales_presentes = obtenerAnimalesPresentes($lote_filtro);

        // 1. Pesadas en el rango
        $stmt_p = $db->prepare("
            SELECT peso_promedio, fecha
            FROM pesada
            WHERE id_tropa = ?
              AND fecha BETWEEN ? AND ?
            ORDER BY fecha ASC
        ");
        $stmt_p->execute([$lote_filtro, $fecha_desde, $fecha_hasta]);
        $pesadas_array = $stmt_p->fetchAll(PDO::FETCH_ASSOC);

        if (count($pesadas_array) > 0) {
            $peso_inicial = (float)$pesadas_array[0]['peso_promedio'];
            $peso_final = (float)$pesadas_array[count($pesadas_array) - 1]['peso_promedio'];
            $peso_medio = ($peso_inicial + $peso_final) / 2;

            $fecha_ini = new DateTime($pesadas_array[0]['fecha']);
            $fecha_fin = new DateTime($pesadas_array[count($pesadas_array) - 1]['fecha']);
            $dias_periodo = max(1, $fecha_ini->diff($fecha_fin)->days);
        }

        // 2. Consumo total de MS en el período
        $stmt_ms = $db->prepare("
            SELECT
                COALESCE(SUM(cld.kg_ms), 0) AS total_kg_ms,
                COUNT(DISTINCT cl.fecha) AS dias_con_alimentacion
            FROM consumo_lote cl
            INNER JOIN consumo_lote_detalle cld ON cl.id_consumo = cld.id_consumo
            WHERE cl.id_tropa = ?
              AND cl.fecha BETWEEN ? AND ?
        ");
        $stmt_ms->execute([$lote_filtro, $fecha_desde, $fecha_hasta]);
        $datos_ms = $stmt_ms->fetch(PDO::FETCH_ASSOC);
        if (!$datos_ms) {
            $datos_ms = ['total_kg_ms' => 0, 'dias_con_alimentacion' => 0];
        }
        $total_kg_ms = (float)($datos_ms['total_kg_ms'] ?? 0);
        $dias_con_alimentacion = (int)($datos_ms['dias_con_alimentacion'] ?? 0);

        // 3. Indicadores
        if ($peso_inicial !== null && $peso_final !== null && $dias_periodo > 0) {
            $adpv = ($peso_final - $peso_inicial) / $dias_periodo;
            $kg_producidos = ($peso_final - $peso_inicial) * $animales_presentes;
        }

        if ($total_kg_ms > 0 && $dias_con_alimentacion > 0 && $animales_presentes > 0) {
            $cms_diario = $total_kg_ms / ($dias_con_alimentacion * $animales_presentes);
            if ($peso_medio) {
                $cms_porcentaje_pv = ($cms_diario / $peso_medio) * 100;
            }
            if ($kg_producidos && $kg_producidos > 0) {
                $ec = $total_kg_ms / $kg_producidos;
            }
        }

        // 4. Detalle de alimentaciones
        $stmt_a = $db->prepare("
            SELECT
                cl.fecha,
                cl.hora,
                cl.kg_totales_tirados,
                cl.animales_presentes,
                cl.sobrante_nivel,
                SUM(cld.kg_ms) AS total_kg_ms
            FROM consumo_lote cl
            LEFT JOIN consumo_lote_detalle cld ON cl.id_consumo = cld.id_consumo
            WHERE cl.id_tropa = ?
              AND cl.fecha BETWEEN ? AND ?
            GROUP BY cl.id_consumo
            ORDER BY cl.fecha DESC, cl.hora DESC
        ");
        $stmt_a->execute([$lote_filtro, $fecha_desde, $fecha_hasta]);
        $alimentaciones_array = $stmt_a->fetchAll(PDO::FETCH_ASSOC);
    }
}

require_once '../../includes/header.php';
?>

<div class="insumos-container">
    <div class="page-header">
        <div>
            <h1 style="font-weight: 800; color: var(--primary); margin: 0; letter-spacing: -1px;">📈 Reportes</h1>
            <p style="margin: 0.25rem 0 0 0; color: var(--text-muted); font-size: 0.95rem; font-weight: 500;">
                Consumo y métricas por lote
            </p>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card">
        <h3 class="card-title"><span>🔍</span> Filtros</h3>

        <div class="filters-bar">
            <form method="GET" action="consumo.php" class="filters-row">
                <div class="filters-left" style="flex-wrap: wrap;">
                    <div style="min-width: 240px;">
                        <label for="lote" style="display:block; font-weight:700; margin-bottom: .35rem;">Lote</label>
                        <select id="lote" name="lote" class="filter-select" onchange="this.form.submit()">
                            <?php foreach ($lotes_disponibles as $lote): ?>
                                <option value="<?php echo (int)$lote['id_tropa']; ?>" <?php echo ($lote_filtro == $lote['id_tropa']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($lote['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="min-width: 200px;">
                        <label for="fecha_desde" style="display:block; font-weight:700; margin-bottom: .35rem;">Desde</label>
                        <input class="filter-input" type="date" id="fecha_desde" name="fecha_desde" value="<?php echo htmlspecialchars($fecha_desde); ?>">
                    </div>

                    <div style="min-width: 200px;">
                        <label for="fecha_hasta" style="display:block; font-weight:700; margin-bottom: .35rem;">Hasta</label>
                        <input class="filter-input" type="date" id="fecha_hasta" name="fecha_hasta" value="<?php echo htmlspecialchars($fecha_hasta); ?>">
                    </div>

                    <div style="display:flex; align-items:flex-end; gap:.5rem;">
                        <button type="submit" class="btn btn-primary btn-sm" style="height: 42px;">Filtrar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

<?php if ($lote_seleccionado): ?>

<!-- Información del lote -->
<div class="card" style="background: linear-gradient(135deg, var(--bg-main) 0%, #e2e8f0 100%); border: none;">
    <h3 style="color: var(--primary); margin-bottom: 1.5rem; font-weight: 800; letter-spacing: -0.5px;">
        <span>🐮</span> <?php echo htmlspecialchars($lote_seleccionado['nombre']); ?>
    </h3>
    
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
        <div>
            <small style="color: #666;">Campo:</small><br>
            <strong><?php echo htmlspecialchars($lote_seleccionado['campo_nombre']); ?></strong>
        </div>
        <div>
            <small style="color: #666;">Categoría:</small><br>
            <strong><?php echo htmlspecialchars($lote_seleccionado['categoria']); ?></strong>
        </div>
        <div>
            <small style="color: #666;">Animales actuales:</small><br>
            <strong style="font-size: 1.3rem; color: #2c5530;"><?php echo $animales_presentes; ?></strong>
        </div>
        <div>
            <small style="color: #666;">Fecha inicio:</small><br>
            <strong><?php echo formatearFecha($lote_seleccionado['fecha_inicio']); ?></strong>
        </div>
    </div>
</div>



<!-- Indicadores Principales -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
    
    <?php if ($peso_inicial): ?>
    <div class="indicador">
        <div class="indicador-icono">⚖️</div>
        <div class="indicador-valor"><?php echo formatearNumero($peso_inicial, 0); ?> kg</div>
        <div class="indicador-label">Peso Inicial Promedio</div>
    </div>
    <?php endif; ?>
    
    <?php if ($peso_final): ?>
    <div class="indicador">
        <div class="indicador-icono">📊</div>
        <div class="indicador-valor"><?php echo formatearNumero($peso_final, 0); ?> kg</div>
        <div class="indicador-label">Peso Final Promedio</div>
    </div>
    <?php endif; ?>
    
    <?php if ($adpv !== null): ?>
    <div class="indicador">
        <div class="indicador-icono">📈</div>
        <div class="indicador-valor" style="color: <?php echo $adpv > 0 ? '#28a745' : '#dc3545'; ?>">
            <?php echo formatearNumero($adpv, 3); ?>
        </div>
        <div class="indicador-label">ADPV (kg/día)</div>
    </div>
    <?php endif; ?>
    
    <?php if ($cms_diario !== null): ?>
    <div class="indicador">
        <div class="indicador-icono">🌾</div>
        <div class="indicador-valor"><?php echo formatearNumero($cms_diario, 2); ?></div>
        <div class="indicador-label">CMS Diario (kg MS/día)</div>
    </div>
    <?php endif; ?>
    
    <?php if ($cms_porcentaje_pv !== null): ?>
    <div class="indicador">
        <div class="indicador-icono">📊</div>
        <div class="indicador-valor"><?php echo formatearNumero($cms_porcentaje_pv, 2); ?>%</div>
        <div class="indicador-label">CMS % PV</div>
    </div>
    <?php endif; ?>
    
    <?php if ($ec !== null): ?>
    <div class="indicador">
        <div class="indicador-icono">⚡</div>
        <div class="indicador-valor"><?php echo formatearNumero($ec, 2); ?></div>
        <div class="indicador-label">Efic. Conversión (EC)</div>
        <small style="font-size: 0.75rem; color: #999;">kg MS / kg producido</small>
    </div>
    <?php endif; ?>
    
    <?php if ($kg_producidos !== null): ?>
    <div class="indicador">
        <div class="indicador-icono">🥩</div>
        <div class="indicador-valor"><?php echo formatearNumero($kg_producidos, 0); ?> kg</div>
        <div class="indicador-label">Kilos Producidos</div>
    </div>
    <?php endif; ?>
    
    <div class="indicador">
        <div class="indicador-icono">📅</div>
        <div class="indicador-valor"><?php echo $dias_periodo; ?></div>
        <div class="indicador-label">Días del Período</div>
    </div>
    
</div>

<!-- Explicación de Indicadores -->
<details class="accordion">
    <summary>
        <div style="display: flex; align-items: center; gap: 10px;">
            <span>📖</span> Glosario de Indicadores y Fórmulas
        </div>
    </summary>
    <div class="accordion-content">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem;">
            <div>
                <strong style="color: var(--primary); display: block; margin-bottom: 0.25rem;">ADPV (Aumento Diario de Peso Vivo)</strong>
                <p style="color: var(--text-muted); font-size: 0.9rem; line-height: 1.4;">
                    Kg que aumenta cada animal por día. Indica la velocidad de engorde.
                    <br><small style="font-weight: 700; color: var(--text-main);">Fórmula:</small> <code style="background: var(--bg-main); padding: 2px 4px; border-radius: 4px;">(Peso Final - Peso Inicial) / Días</code>
                </p>
            </div>
            
            <div>
                <strong style="color: var(--primary); display: block; margin-bottom: 0.25rem;">CMS (Consumo de Materia Seca)</strong>
                <p style="color: var(--text-muted); font-size: 0.9rem; line-height: 1.4;">
                    Kg de materia seca que consume cada animal por día.
                    <br><small style="font-weight: 700; color: var(--text-main);">Fórmula:</small> <code style="background: var(--bg-main); padding: 2px 4px; border-radius: 4px;">Total MS / (Días × Animales)</code>
                </p>
            </div>
            
            <div>
                <strong style="color: var(--primary); display: block; margin-bottom: 0.25rem;">CMS % PV (CMS como % del Peso Vivo)</strong>
                <p style="color: var(--text-muted); font-size: 0.9rem; line-height: 1.4;">
                    Consumo de MS expresado como porcentaje del peso del animal.
                    <br><small style="font-weight: 700; color: var(--text-main);">Fórmula:</small> <code style="background: var(--bg-main); padding: 2px 4px; border-radius: 4px;">(CMS / Peso Medio) × 100</code>
                </p>
            </div>
            
            <div>
                <strong style="color: var(--primary); display: block; margin-bottom: 0.25rem;">EC (Eficiencia de Conversión)</strong>
                <p style="color: var(--text-muted); font-size: 0.9rem; line-height: 1.4;">
                    Kg de MS necesarios para producir 1 kg de carne. Menor es mejor.
                    <br><small style="font-weight: 700; color: var(--text-main);">Fórmula:</small> <code style="background: var(--bg-main); padding: 2px 4px; border-radius: 4px;">Total MS / Kg Producidos</code>
                </p>
            </div>
        </div>
    </div>
</details>

<!-- Evolución de Peso -->
<?php if (count($pesadas_array) > 0): ?>
<div class="card">
    <h3 class="card-title"><span>📊</span> Evolución de Peso</h3>
    
    <div class="table-container">
        <table class="responsive-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Peso Promedio (kg)</th>
                    <th>Variación desde anterior</th>
                    <th>ADPV desde anterior</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Mantener el cálculo de variaciones en orden cronológico, pero mostrar lo más reciente primero.
                $pesadas_rows = [];
                $peso_anterior_tabla = null;
                $fecha_anterior_tabla = null;

                foreach ($pesadas_array as $pesada) {
                    $variacion = null;
                    $adpv_parcial = null;

                    if ($peso_anterior_tabla !== null && $fecha_anterior_tabla !== null) {
                        $variacion = $pesada['peso_promedio'] - $peso_anterior_tabla;

                        $fecha_ant = new DateTime($fecha_anterior_tabla);
                        $fecha_act = new DateTime($pesada['fecha']);
                        $dias_dif = max(1, $fecha_ant->diff($fecha_act)->days);

                        $adpv_parcial = $variacion / $dias_dif;
                    }

                    $pesadas_rows[] = [
                        'fecha' => $pesada['fecha'],
                        'peso_promedio' => $pesada['peso_promedio'],
                        'variacion' => $variacion,
                        'adpv_parcial' => $adpv_parcial,
                    ];

                    $peso_anterior_tabla = $pesada['peso_promedio'];
                    $fecha_anterior_tabla = $pesada['fecha'];
                }

                $pesadas_rows = array_reverse($pesadas_rows);

                foreach ($pesadas_rows as $row):
                    $variacion = $row['variacion'];
                    $adpv_parcial = $row['adpv_parcial'];
                ?>
                    <tr>
                        <td><?php echo formatearFecha($row['fecha']); ?></td>
                        <td><strong><?php echo formatearNumero($row['peso_promedio'], 2); ?> kg</strong></td>
                        <td>
                            <?php if ($variacion !== null): ?>
                                <span style="color: <?php echo $variacion >= 0 ? '#28a745' : '#dc3545'; ?>; font-weight: 600;">
                                    <?php echo $variacion >= 0 ? '+' : ''; ?><?php echo formatearNumero($variacion, 2); ?> kg
                                </span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($adpv_parcial !== null): ?>
                                <span style="color: <?php echo $adpv_parcial >= 0 ? '#28a745' : '#dc3545'; ?>; font-weight: 600;">
                                    <?php echo formatearNumero($adpv_parcial, 3); ?> kg/día
                                </span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Detalle de Alimentaciones -->
<?php if (count($alimentaciones_array) > 0): ?>
<div class="card">
    <h3 class="card-title"><span>🍽️</span> Detalle de Alimentaciones</h3>
    
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Hora</th>
                    <th>Animales</th>
                    <th>Kg Totales</th>
                    <th>Kg/Animal</th>
                    <th>Kg MS Total</th>
                    <th>Kg MS/Animal</th>
                    <th>Sobras</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($alimentaciones_array as $alim): ?>
                    <?php 
                    $kg_por_animal = $alim['animales_presentes'] > 0 
                        ? $alim['kg_totales_tirados'] / $alim['animales_presentes'] 
                        : 0;
                    $kg_ms_por_animal = $alim['animales_presentes'] > 0
                        ? $alim['total_kg_ms'] / $alim['animales_presentes']
                        : 0;
                    ?>
                    <tr>
                        <td><?php echo formatearFecha($alim['fecha']); ?></td>
                        <td><?php echo date('H:i', strtotime($alim['hora'])); ?></td>
                        <td><?php echo $alim['animales_presentes']; ?></td>
                        <td><?php echo formatearNumero($alim['kg_totales_tirados'], 1); ?> kg</td>
                        <td><strong><?php echo formatearNumero($kg_por_animal, 2); ?> kg</strong></td>
                        <td style="color: #2c5530; font-weight: 600;">
                            <?php echo formatearNumero($alim['total_kg_ms'], 1); ?> kg MS
                        </td>
                        <td style="color: #2c5530; font-weight: 600;">
                            <strong><?php echo formatearNumero($kg_ms_por_animal, 2); ?> kg</strong>
                        </td>
                        <td>
                            <?php
                            $color_sobra = '';
                            switch($alim['sobrante_nivel']) {
                                case 'SIN_SOBRAS': $color_sobra = '#28a745'; break;
                                case 'POCAS_SOBRAS': $color_sobra = '#ffc107'; break;
                                case 'NORMAL': $color_sobra = '#17a2b8'; break;
                                case 'MUCHAS_SOBRAS': $color_sobra = '#dc3545'; break;
                            }
                            ?>
                            <span style="color: <?php echo $color_sobra; ?>; font-weight: 600; font-size: 0.85rem;">
                                <?php echo str_replace('_', ' ', $alim['sobrante_nivel']); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php else: ?>
<div class="card">
    <h3 class="card-title"><span>🍽️</span> Alimentaciones</h3>
    <p style="color: var(--text-muted);">No hay alimentaciones registradas en el período seleccionado.</p>
    <a href="../alimentaciones/registrar.php?lote=<?php echo $lote_filtro; ?>" class="btn btn-primary btn-sm">
        Registrar Primera Alimentación
    </a>
</div>
<?php endif; ?>

<?php else: ?>

<div class="card">
    <h3 class="card-title"><span>ℹ️</span> Sin datos</h3>
    <p style="color: var(--text-muted);">No hay lotes disponibles para mostrar reportes.</p>
    <a href="../lotes/crear.php" class="btn btn-primary btn-sm">Crear Primer Lote</a>
</div>

<?php endif; ?>

</div>

<?php include '../../includes/footer.php'; ?>

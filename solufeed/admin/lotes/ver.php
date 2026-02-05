<?php
/**
 * SOLUFEED - Ver Detalle de Lote
 */

require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Verificar sesión
// Verificar permisos de administrador
verificarAdmin();

// Verificar que se recibió el ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: listar.php');
    exit();
}

$id_tropa = (int) $_GET['id'];

// Navegación: volver al origen si viene return_to (evita perder filtros/listados)
$return_to = isset($_GET['return_to']) ? (string)$_GET['return_to'] : '';
$back_url = safe_return_to($return_to, BASE_URL . '/admin/lotes/listar.php');

$db = getConnection();

// Obtener dietas activas para selector rápido (cambio de dieta desde esta vista)
$dietas_disponibles = [];
try {
    $stmt_dietas = $db->query("SELECT id_dieta, nombre FROM dieta WHERE activo = 1 ORDER BY nombre ASC");
    $dietas_disponibles = $stmt_dietas->fetchAll();
} catch (Exception $e) {
    $dietas_disponibles = [];
}


// Obtener datos del lote (PDO)
$stmt_lote = $db->prepare("
    SELECT 
        t.*,
        c.nombre as campo_nombre,
        c.ubicacion as campo_ubicacion
    FROM tropa t
    INNER JOIN campo c ON t.id_campo = c.id_campo
    WHERE t.id_tropa = ?
");
$stmt_lote->execute([$id_tropa]);
$lote = $stmt_lote->fetch();

if (!$lote) {
    header('Location: listar.php');
    exit();
}

// Obtener dieta vigente
$dieta_vigente = obtenerDietaVigente($id_tropa);

// Calcular animales presentes
$animales_presentes = obtenerAnimalesPresentes($id_tropa);

// Calcular días de engorde
$fecha_inicio = new DateTime($lote['fecha_inicio']);
$fecha_hoy = new DateTime();
$dias_engorde = $fecha_inicio->diff($fecha_hoy)->days;

// Obtener pesadas del lote (PDO)
$stmt_pesadas = $db->prepare("
    SELECT fecha, peso_promedio, animales_vistos
    FROM pesada
    WHERE id_tropa = ?
    ORDER BY fecha DESC
    LIMIT 10
");
$stmt_pesadas->execute([$id_tropa]);
$pesadas = $stmt_pesadas->fetchAll();

// Calcular peso promedio inicial y actual
$peso_inicial = null;
$peso_actual = null;
$adpv = null;

$stmt_peso_inicial = $db->prepare("SELECT peso_promedio FROM pesada WHERE id_tropa = ? ORDER BY fecha ASC LIMIT 1");
$stmt_peso_inicial->execute([$id_tropa]);
$dato_peso_inicial = $stmt_peso_inicial->fetch();
if ($dato_peso_inicial) {
    $peso_inicial = $dato_peso_inicial['peso_promedio'];
}

$stmt_peso_actual = $db->prepare("SELECT peso_promedio, fecha FROM pesada WHERE id_tropa = ? ORDER BY fecha DESC LIMIT 1");
$stmt_peso_actual->execute([$id_tropa]);
$dato_peso_actual = $stmt_peso_actual->fetch();
if ($dato_peso_actual) {
    $peso_actual = $dato_peso_actual['peso_promedio'];
    
    // Calcular ADPV si hay peso inicial y actual
    if ($peso_inicial && $peso_actual && $dias_engorde > 0) {
        $adpv = ($peso_actual - $peso_inicial) / $dias_engorde;
    }
}

// Obtener últimas alimentaciones (PDO)
$stmt_alimentaciones = $db->prepare("
    SELECT 
        cl.fecha,
        cl.hora,
        cl.kg_totales_tirados,
        cl.sobrante_nivel,
        cl.animales_presentes
    FROM consumo_lote cl
    WHERE cl.id_tropa = ?
    ORDER BY cl.fecha DESC, cl.hora DESC
    LIMIT 10
");
$stmt_alimentaciones->execute([$id_tropa]);
$alimentaciones = $stmt_alimentaciones->fetchAll();

// Obtener movimientos de animales (PDO)
$stmt_movimientos = $db->prepare("
    SELECT 
        fecha,
        tipo_movimiento,
        cantidad,
        motivo
    FROM movimiento_animal
    WHERE id_tropa = ?
    ORDER BY fecha DESC
    LIMIT 10
");
$stmt_movimientos->execute([$id_tropa]);
$movimientos = $stmt_movimientos->fetchAll();

// Calcular consumo total de MS (PDO)
$stmt_ms_total = $db->prepare("
    SELECT IFNULL(SUM(kg_ms), 0) as total_kg_ms
    FROM consumo_lote_detalle cld
    INNER JOIN consumo_lote cl ON cld.id_consumo = cl.id_consumo
    WHERE cl.id_tropa = ?
");
$stmt_ms_total->execute([$id_tropa]);
$total_kg_ms = $stmt_ms_total->fetch()['total_kg_ms'];

// Calcular CMS promedio
$cms_promedio = null;
if ($dias_engorde > 0 && $animales_presentes > 0) {
    $cms_promedio = $total_kg_ms / ($dias_engorde * $animales_presentes);
}

include '../../includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; background: var(--surface); padding: 1.5rem; border-radius: var(--radius); box-shadow: var(--shadow-sm); border: 1px solid var(--border);">
    <div>
        <h1 style="font-weight: 800; color: var(--primary); margin: 0; letter-spacing: -1px; display: flex; align-items: center; gap: 0.75rem;">
            <span>🐮</span> <?php echo htmlspecialchars($lote['nombre']); ?>
        </h1>
        <p style="margin: 0.25rem 0 0 0; color: var(--text-muted); font-size: 0.95rem; font-weight: 500;">
            <?php echo htmlspecialchars($lote['campo_nombre']); ?> | <?php echo htmlspecialchars($lote['categoria']); ?>
        </p>
    </div>
    <div style="display: flex; gap: 0.75rem;">
        <a href="editar.php?id=<?php echo $id_tropa; ?>&return_to=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="btn btn-secondary">✏️ Editar</a>
        <form method="POST" action="toggle_estado.php" style="display:inline;" onsubmit="return confirm('<?php echo $lote['activo'] ? '¿Desactivar este lote?' : '¿Activar este lote?'; ?>')">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="id_tropa" value="<?php echo (int)$id_tropa; ?>">
            <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" class="btn <?php echo $lote['activo'] ? 'btn-secondary' : 'btn-primary'; ?>">
                <span><?php echo $lote['activo'] ? '⏸️' : '▶️'; ?></span> <?php echo $lote['activo'] ? 'Desactivar' : 'Activar'; ?>
            </button>
        </form>
        <a href="<?php echo htmlspecialchars($back_url, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary"><span>←</span> Volver</a>
    </div>
</div>

<div class="card" style="border-top: 5px solid var(--primary);">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2rem;">
        <div>
            <h3 class="card-title" style="margin-bottom: 0.25rem;"><span>📍</span> Ubicación y Estado</h3>
            <p style="color: var(--text-muted); font-size: 0.9rem;"><?php echo !empty($lote['campo_ubicacion']) ? htmlspecialchars($lote['campo_ubicacion']) : 'Ubicación no especificada'; ?></p>
        </div>
        <?php if ($lote['activo']): ?>
            <span class="badge" style="background: #dcfce7; color: #166534; font-size: 0.9rem; padding: 0.6rem 1.25rem; border: 1px solid #bbf7d0;">ACTIVO</span>
        <?php else: ?>
            <span class="badge" style="background: #f1f5f9; color: #475569; font-size: 0.9rem; padding: 0.6rem 1.25rem; border: 1px solid #e2e8f0;">CERRADO</span>
        <?php endif; ?>
    </div>
    
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem;">
        <div style="background: var(--bg-main); padding: 1.25rem; border-radius: var(--radius);">
            <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.5rem; letter-spacing: 0.5px;">Fecha Inicio</div>
            <div style="font-size: 1.15rem; font-weight: 800; color: var(--primary);"><?php echo date('d/m/Y', strtotime($lote['fecha_inicio'])); ?></div>
            <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;"><?php echo $dias_engorde; ?> días en feedlot</div>
        </div>
        
        <div style="background: var(--bg-main); padding: 1.25rem; border-radius: var(--radius);">
            <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.5rem; letter-spacing: 0.5px;">Animales</div>
            <div style="font-size: 1.15rem; font-weight: 800; color: var(--primary);"><?php echo $animales_presentes; ?> cabezas</div>
            <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">Stock actual</div>
        </div>
    </div>
    
    <div style="margin-top: 2rem; display: flex; gap: 1rem; border-top: 1px solid var(--border); padding-top: 1.5rem;">
        <?php if (isset($_SESSION['tipo']) && $_SESSION['tipo'] === 'CAMPO'): ?>
<a href="../alimentaciones/registrar.php?lote=<?php echo $id_tropa; ?>" class="btn btn-primary" style="flex: 1;"><span>🍽️</span> Cargar Dieta Diaria</a>
        <a href="../pesadas/registrar.php?lote=<?php echo $id_tropa; ?>" class="btn btn-secondary" style="flex: 1;"><span>⚖️</span> Registrar Pesada</a>
<?php endif; ?>
    </div>
</div>

<!-- Indicadores clave -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
    
    <div class="card" style="text-align: center; margin-bottom: 0; padding: 1.25rem;">
        <div style="font-size: 1.75rem; margin-bottom: 0.5rem;">⚖️</div>
        <div style="font-size: 1.25rem; font-weight: 800; color: var(--primary);"><?php echo formatearNumero($peso_actual ?: $peso_inicial, 1); ?> kg</div>
        <div style="font-size: 0.7rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Peso Promedio</div>
    </div>
    
    <?php if ($adpv): ?>
    <div class="card" style="text-align: center; margin-bottom: 0; padding: 1.25rem; background: var(--primary); color: white;">
        <div style="font-size: 1.75rem; margin-bottom: 0.5rem;">📈</div>
        <div style="font-size: 1.25rem; font-weight: 800;"><?php echo formatearNumero($adpv, 2); ?> kg</div>
        <div style="font-size: 0.7rem; font-weight: 700; opacity: 0.8; text-transform: uppercase;">ADPV (Garancia)</div>
    </div>
    <?php endif; ?>
    
    <?php if ($cms_promedio): ?>
    <div class="card" style="text-align: center; margin-bottom: 0; padding: 1.25rem;">
        <div style="font-size: 1.75rem; margin-bottom: 0.5rem;">🌾</div>
        <div style="font-size: 1.25rem; font-weight: 800; color: var(--secondary);"><?php echo formatearNumero($cms_promedio, 2); ?></div>
        <div style="font-size: 0.7rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">CMS (Eficiencia)</div>
    </div>
    <?php endif; ?>

    <div class="card" style="text-align: center; margin-bottom: 0; padding: 1.25rem;">
        <div style="font-size: 1.75rem; margin-bottom: 0.5rem;">🐂</div>
        <div style="font-size: 1.25rem; font-weight: 800; color: var(--primary);"><?php echo $animales_presentes; ?></div>
        <div style="font-size: 0.7rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Animales</div>
    </div>
</div>

<!-- Dieta vigente -->
<div class="card">
    <h3 class="card-title"><span>📋</span> Dieta Vigente</h3>

    <?php if ($dieta_vigente): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; background: var(--bg-main); padding: 1.5rem; border-radius: var(--radius); border: 1px solid var(--border); gap: 1rem; flex-wrap: wrap;">
            <div>
                <div style="font-size: 1.2rem; font-weight: 800; color: var(--primary); margin-bottom: 0.25rem;">
                    <?php echo htmlspecialchars($dieta_vigente['dieta_nombre']); ?>
                </div>
                <div style="font-size: 0.85rem; color: var(--text-muted); font-weight: 500;">
                    Asignada el: <?php echo date('d/m/Y', strtotime($dieta_vigente['fecha_desde'])); ?>
                </div>
            </div>

            <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                <a href="../dietas/ver.php?id=<?php echo $dieta_vigente['id_dieta']; ?>&return_to=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="btn btn-secondary">Composición Detallada</a>

                <?php if (!empty($dietas_disponibles)): ?>
                    <details style="position: relative;">
                        <summary class="btn btn-primary" style="list-style: none; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem;">
                            <span>🔄</span> Cambiar Dieta Vigente
                        </summary>
                        <div style="margin-top: 1rem; background: var(--surface); padding: 1rem; border-radius: var(--radius); border: 1px solid var(--border); box-shadow: var(--shadow-sm); min-width: 320px;">
                            <form method="POST" action="cambiar_dieta.php" style="display: grid; gap: 0.75rem;">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="id_tropa" value="<?php echo (int)$id_tropa; ?>">
                                <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8'); ?>">

                                <div>
                                    <label for="id_dieta" style="font-weight: 700; font-size: 0.85rem;">Nueva dieta</label>
                                    <select id="id_dieta" name="id_dieta" style="width: 100%; margin-top: 0.25rem;">
                                        <option value="">— Quitar dieta (sin asignar) —</option>
                                        <?php foreach ($dietas_disponibles as $d): ?>
                                            <option value="<?php echo (int)$d['id_dieta']; ?>" <?php echo ((int)$dieta_vigente['id_dieta'] === (int)$d['id_dieta']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($d['nombre']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label for="fecha_cambio_dieta" style="font-weight: 700; font-size: 0.85rem;">Fecha del cambio</label>
                                    <input type="date" id="fecha_cambio_dieta" name="fecha_cambio_dieta" value="<?php echo date('Y-m-d'); ?>" style="width: 100%; margin-top: 0.25rem;">
                                    <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">Se cierra la dieta actual y se asigna la nueva desde esa fecha.</div>
                                </div>

                                <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                                    <button type="submit" class="btn btn-primary">Guardar</button>
                                </div>
                            </form>
                        </div>
                    </details>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div style="text-align: center; padding: 2rem; border: 1px dashed var(--border); border-radius: var(--radius);">
            <p style="color: var(--text-muted); margin-bottom: 1rem;">Este lote no tiene dieta asignada actualmente.</p>

            <?php if (!empty($dietas_disponibles)): ?>
                <details style="display: inline-block; text-align: left;">
                    <summary class="btn btn-primary" style="list-style: none; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem;">
                        <span>➕</span> Asignar Dieta
                    </summary>
                    <div style="margin-top: 1rem; background: var(--surface); padding: 1rem; border-radius: var(--radius); border: 1px solid var(--border); box-shadow: var(--shadow-sm); min-width: 320px;">
                        <form method="POST" action="cambiar_dieta.php" style="display: grid; gap: 0.75rem;">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="id_tropa" value="<?php echo (int)$id_tropa; ?>">
                            <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8'); ?>">

                            <div>
                                <label for="id_dieta" style="font-weight: 700; font-size: 0.85rem;">Dieta</label>
                                <select id="id_dieta" name="id_dieta" style="width: 100%; margin-top: 0.25rem;">
                                    <option value="">— Seleccioná una dieta —</option>
                                    <?php foreach ($dietas_disponibles as $d): ?>
                                        <option value="<?php echo (int)$d['id_dieta']; ?>">
                                            <?php echo htmlspecialchars($d['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label for="fecha_cambio_dieta" style="font-weight: 700; font-size: 0.85rem;">Fecha de asignación</label>
                                <input type="date" id="fecha_cambio_dieta" name="fecha_cambio_dieta" value="<?php echo date('Y-m-d'); ?>" style="width: 100%; margin-top: 0.25rem;">
                            </div>

                            <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                                <button type="submit" class="btn btn-primary">Asignar</button>
                            </div>
                        </form>
                    </div>
                </details>
            <?php else: ?>
                <div style="color: var(--text-muted); font-style: italic;">No hay dietas activas disponibles para asignar.</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Últimas Pesadas -->

<div class="card">
    <h3 class="card-title"><span>⚖️</span> Últimas Pesadas</h3>
    
    <?php if (count($pesadas) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th style="text-align: center;">Peso Promedio</th>
                        <th style="text-align: center;">Animales Vistos</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pesadas as $pesada): ?>
                        <tr>
                            <td style="font-weight: 500;"><?php echo date('d/m/Y', strtotime($pesada['fecha'])); ?></td>
                            <td style="text-align: center;"><strong style="color: var(--primary); font-size: 1.1rem;"><?php echo number_format($pesada['peso_promedio'], 1); ?> kg</strong></td>
                            <td style="text-align: center;"><?php echo $pesada['animales_vistos']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div style="text-align: center; padding: 2rem; color: var(--text-muted); font-style: italic;">
            No hay pesadas registradas para este lote todavía.
        </div>
    <?php endif; ?>
</div>

<!-- Últimas Alimentaciones -->
<div class="card">
    <h3 class="card-title"><span>🍽️</span> Últimas Alimentaciones</h3>
    
    <?php if (count($alimentaciones) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Fecha y Hora</th>
                        <th style="text-align: center;">Kg Tirados</th>
                        <th style="text-align: center;">Kg/Animal</th>
                        <th style="text-align: center;">Sobras</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($alimentaciones as $alim): ?>
                        <?php 
                        $kg_por_animal = $alim['animales_presentes'] > 0 
                            ? $alim['kg_totales_tirados'] / $alim['animales_presentes'] 
                            : 0;
                        
                        $sobra_class = 'badge';
                        $sobra_style = '';
                        switch($alim['sobrante_nivel']) {
                            case 'SIN_SOBRAS': $sobra_style = 'background: #dcfce7; color: #166534;'; break;
                            case 'POCAS_SOBRAS': $sobra_style = 'background: #fef9c3; color: #854d0e;'; break;
                            case 'NORMAL': $sobra_style = 'background: #e0f2fe; color: #075985;'; break;
                            case 'MUCHAS_SOBRAS': $sobra_style = 'background: #fee2e2; color: #991b1b;'; break;
                        }
                        ?>
                        <tr>
                            <td>
                                <div style="font-weight: 500;"><?php echo date('d/m', strtotime($alim['fecha'])); ?> - <?php echo date('H:i', strtotime($alim['hora'])); ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo $alim['animales_presentes']; ?> animales</div>
                            </td>
                            <td style="text-align: center;"><strong><?php echo number_format($alim['kg_totales_tirados'], 0); ?> kg</strong></td>
                            <td style="text-align: center;"><strong style="color: var(--primary);"><?php echo number_format($kg_por_animal, 2); ?></strong></td>
                            <td style="text-align: center;">
                                <span class="badge" style="<?php echo $sobra_style; ?> font-size: 0.7rem;">
                                    <?php echo str_replace('_', ' ', $alim['sobrante_nivel']); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div style="text-align: center; padding: 2rem; color: var(--text-muted); font-style: italic;">
            No hay mediciones de alimentación registradas.
        </div>
    <?php endif; ?>
</div>

<!-- Movimientos de Animales -->
<?php if (count($movimientos) > 0): ?>
<div class="card">
    <h3 class="card-title"><span>📝</span> Historial de Movimientos</h3>
    
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Tipo</th>
                    <th style="text-align: center;">Cantidad</th>
                    <th>Motivo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($movimientos as $mov): ?>
                    <?php
                    $mov_style = '';
                    switch($mov['tipo_movimiento']) {
                        case 'ENTRADA':
                        case 'AJUSTE_POSITIVO':
                            $mov_style = 'background: #dcfce7; color: #166534;';
                            break;
                        case 'SALIDA':
                        case 'BAJA':
                        case 'AJUSTE_NEGATIVO':
                            $mov_style = 'background: #fee2e2; color: #991b1b;';
                            break;
                        default:
                            $mov_style = 'background: #e0f2fe; color: #075985;';
                    }
                    ?>
                    <tr>
                        <td><?php echo date('d/m/Y', strtotime($mov['fecha'])); ?></td>
                        <td>
                            <span class="badge" style="<?php echo $mov_style; ?> font-size: 0.7rem;">
                                <?php echo str_replace('_', ' ', $mov['tipo_movimiento']); ?>
                            </span>
                        </td>
                        <td style="text-align: center;"><strong><?php echo $mov['cantidad']; ?></strong></td>
                        <td style="font-size: 0.85rem; color: var(--text-muted);"><?php echo htmlspecialchars($mov['motivo']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
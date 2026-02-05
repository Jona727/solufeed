<?php
/**
 * SOLUFEED - Crear Nueva Dieta - Refactored to PDO
 */

require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Verificar permisos de administrador
verificarAdmin();

$db = getConnection();

// Obtener todos los insumos activos
try {
    $stmt = $db->query("SELECT id_insumo, nombre, tipo, porcentaje_ms FROM insumo WHERE activo = 1 ORDER BY nombre ASC");
    $insumos_disponibles = $stmt->fetchAll();
} catch (PDOException $e) {
    log_event('ERROR', 'DIETA_GET_INSUMOS_FAILED', ['error' => $e->getMessage()]);
    die('Error al obtener insumos. Contacte soporte.');
}

// Procesar formulario si se envió
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = limpiarDato($_POST['nombre']);
    $descripcion = limpiarDato($_POST['descripcion']);
    $activo = isset($_POST['activo']) ? 1 : 0;
    
    $insumos_seleccionados = isset($_POST['insumos']) ? $_POST['insumos'] : [];
    $porcentajes = isset($_POST['porcentajes']) ? $_POST['porcentajes'] : [];
    
    $errores = [];

    if (!csrf_verify_post()) {
        $errores[] = csrf_fail_response('Token expirado o inválido. Actualizá la página y reintentá.');
    }
    
    if (empty($nombre)) {
        $errores[] = "El nombre de la dieta es obligatorio.";
    }

    // Evitar nombres duplicados entre dietas ACTIVAS
    if (empty($errores) && $activo == 1) {
        $stmt_dup = $db->prepare("SELECT COUNT(*) FROM dieta WHERE activo = 1 AND nombre = ?");
        $stmt_dup->execute([$nombre]);
        if ((int)$stmt_dup->fetchColumn() > 0) {
            $errores[] = "Ya existe una dieta activa con ese nombre. Usá otro nombre o desactivá la anterior.";
        }
    }
    
    if (empty($insumos_seleccionados)) {
        $errores[] = "Debés seleccionar al menos un insumo.";
    }
    
    // Validar porcentajes
    $total_porcentaje = 0;
    foreach ($insumos_seleccionados as $id_insumo) {
        $val = isset($porcentajes[$id_insumo]) ? (float)$porcentajes[$id_insumo] : 0;
        
        if ($val <= 0) {
            $errores[] = "Todos los insumos seleccionados deben tener un porcentaje mayor a 0%.";
            break; // Solo necesitamos un error de este tipo
        }
        $total_porcentaje += $val;
    }
    
    if (empty($errores) && abs($total_porcentaje - 100) > 0.01) {
        $errores[] = "Los porcentajes deben sumar exactamente 100%. Actualmente suman " . number_format($total_porcentaje, 2) . "%.";
    }
    
    if (empty($errores)) {
        try {
            $db->beginTransaction();
            
            // Insertar dieta
            $stmt = $db->prepare("INSERT INTO dieta (nombre, descripcion, activo, fecha_creacion) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$nombre, $descripcion, $activo]);
            $id_dieta_nueva = $db->lastInsertId();
            
            // Insertar detalles
            $stmt_detalle = $db->prepare("INSERT INTO dieta_detalle (id_dieta, id_insumo, porcentaje_teorico) VALUES (?, ?, ?)");
            foreach ($insumos_seleccionados as $id_insumo) {
                $stmt_detalle->execute([$id_dieta_nueva, $id_insumo, (float)$porcentajes[$id_insumo]]);
            }
            
            $db->commit();
            $exito = "✓ Dieta creada exitosamente.";
            header("refresh:2;url=ver.php?id=$id_dieta_nueva");
            
        } catch (PDOException $e) {
            $db->rollBack();
            log_event('ERROR', 'DIETA_SAVE_FAILED', ['error' => $e->getMessage()]);
            $errores[] = 'Error al guardar la dieta. Reintentá o contactá soporte.';
        }
    }
}

include '../../includes/header.php';
?>

<div class="page-header">
    <h1 style="font-weight: 800; color: var(--primary); margin: 0; letter-spacing: -1px;">📋 Crear Nueva Dieta</h1>
    <div class="header-actions">
        <a href="listar.php" class="btn btn-secondary"><span>←</span> Cancelar</a>
    </div>
</div>

<div class="card">
    <?php if (isset($exito)): ?>
        <div style="background: #dcfce7; border-left: 5px solid var(--success); color: #166534; padding: 1rem; margin-bottom: 1.5rem; border-radius: var(--radius);">
            <?php echo $exito; ?>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($errores)): ?>
        <div style="background: #fee2e2; border-left: 5px solid var(--danger); color: #991b1b; padding: 1rem; margin-bottom: 1.5rem; border-radius: var(--radius);">
            <strong style="display: block; margin-bottom: 0.5rem;">Se encontraron los siguientes errores:</strong>
            <ul style="margin: 0; padding-left: 1.5rem; font-size: 0.9rem;">
                <?php foreach ($errores as $error): ?>
                    <li><?php echo $error; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <form method="POST" class="formulario" id="formDieta">
                <?php echo csrf_input(); ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
            <div class="form-grupo">
                <label for="nombre" style="font-weight: 700; color: var(--text-main); display: block; margin-bottom: 0.5rem;">Nombre de la Dieta *</label>
                <input type="text" id="nombre" name="nombre" required placeholder="Ej: Engorde..." value="<?php echo isset($_POST['nombre']) ? htmlspecialchars($_POST['nombre']) : ''; ?>" style="width: 100%;">
            </div>
            
            <div class="form-grupo" style="display: flex; align-items: center; gap: 0.75rem; padding-top: 1.5rem;">
                <input type="checkbox" id="activo" name="activo" value="1" style="width: 20px; height: 20px;" <?php echo (!isset($_POST['activo']) || $_POST['activo']) ? 'checked' : ''; ?>>
                <label for="activo" style="font-weight: 700; color: var(--text-main);">Dieta activa</label>
            </div>
        </div>
        
        <div class="form-grupo">
            <label for="descripcion" style="font-weight: 700; color: var(--text-main); display: block; margin-bottom: 0.5rem;">Descripción (opcional)</label>
            <textarea id="descripcion" name="descripcion" placeholder="Notas sobre esta dieta..." style="width: 100%; min-height: 80px;"><?php echo isset($_POST['descripcion']) ? htmlspecialchars($_POST['descripcion']) : ''; ?></textarea>
        </div>
        
        <div style="margin: 2.5rem 0; height: 1px; background: var(--border);"></div>
        
        <h3 style="font-weight: 800; color: var(--primary); margin-bottom: 1.5rem; display: flex; align-items: center; gap: 10px;">
            <span>🌾</span> Composición de la Dieta
        </h3>
        
        <?php if (count($insumos_disponibles) > 0): ?>
            <div class="table-container">
                <table style="width: 100%;">
                    <thead>
                        <tr>
                            <th style="width: 60px; text-align: center;">Usar</th>
                            <th>Insumo</th>
                            <th class="hide-mobile">Tipo</th>
                            <th style="width: 180px; text-align: center;">% en la Dieta</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($insumos_disponibles as $insumo): 
                            $checked = isset($_POST['insumos']) && in_array($insumo['id_insumo'], $_POST['insumos']) ? 'checked' : '';
                            $valor_porcentaje = isset($_POST['porcentajes'][$insumo['id_insumo']]) ? $_POST['porcentajes'][$insumo['id_insumo']] : '';
                        ?>
                            <tr>
                                <td style="text-align: center;">
                                    <input type="checkbox" name="insumos[]" value="<?php echo $insumo['id_insumo']; ?>" class="insumo-checkbox" data-insumo-id="<?php echo $insumo['id_insumo']; ?>" style="width: 20px; height: 20px;" <?php echo $checked; ?>>
                                </td>
                                <td>
                                    <strong style="color: var(--primary);"><?php echo htmlspecialchars($insumo['nombre']); ?></strong>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);">MS: <?php echo number_format($insumo['porcentaje_ms'], 1); ?>%</div>
                                </td>
                                <td class="hide-mobile">
                                    <span class="badge" style="background: var(--bg-main); color: var(--text-main);"><?php echo htmlspecialchars($insumo['tipo']); ?></span>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 0.5rem; justify-content: center;">
                                        <input type="number" name="porcentajes[<?php echo $insumo['id_insumo']; ?>]" id="porcentaje_<?php echo $insumo['id_insumo']; ?>" step="0.01" min="0" max="100" placeholder="0.00" class="porcentaje-input" style="width: 100px; text-align: right; padding: 0.5rem; font-weight: 800;" value="<?php echo $valor_porcentaje; ?>" <?php echo $checked ? '' : 'disabled'; ?>>
                                        <span style="font-weight: 800; color: var(--text-muted);">%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background: var(--bg-main); font-weight: 800; border-top: 2px solid var(--border);">
                            <td colspan="3" style="text-align: right; padding: 1.25rem;">TOTAL:</td>
                            <td style="padding: 1.25rem; text-align: center;">
                                <div id="totalPorcentaje" style="font-size: 1.5rem;">0.00%</div>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            
            <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                <button type="submit" class="btn btn-primary btn-lg" style="flex: 1;"><span>💾</span> Guardar Dieta</button>
                <a href="listar.php" class="btn btn-secondary btn-lg" style="flex: 0.3;">Cancelar</a>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 2rem; background: #fee2e2; color: #991b1b; border-radius: var(--radius);">
                <p>⚠️ No hay insumos registrados para crear una dieta.</p>
                <a href="../insumos/crear.php" class="btn btn-primary" style="margin-top: 1rem;">Crear Insumo</a>
            </div>
        <?php endif; ?>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('.insumo-checkbox');
    const inputs = document.querySelectorAll('.porcentaje-input');
    const totalDiv = document.getElementById('totalPorcentaje');
    const form = document.getElementById('formDieta');

    function calcularTotal() {
        let total = 0;
        inputs.forEach(input => {
            if (input.value) {
                total += parseFloat(input.value) || 0;
            }
        });
        totalDiv.textContent = total.toFixed(2) + '%';
        totalDiv.style.color = (Math.abs(total - 100) < 0.01) ? '#28a745' : (total > 100 ? '#dc3545' : 'var(--text-main)');
    }

    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const input = document.getElementById('porcentaje_' + this.dataset.insumoId);
            input.disabled = !this.checked;
            if (this.checked) input.focus();
            else input.value = '';
            calcularTotal();
        });
    });

    inputs.forEach(input => input.addEventListener('input', calcularTotal));

    form.addEventListener('submit', function(e) {
        let error = false;
        checkboxes.forEach(cb => {
            if (cb.checked) {
                const input = document.getElementById('porcentaje_' + cb.dataset.insumoId);
                const val = parseFloat(input.value) || 0;
                if (val <= 0) {
                    showToast('Todos los insumos seleccionados deben tener un % mayor a 0', 'warning');
                    input.focus();
                    error = true;
                    return;
                }
            }
        });
        
        if (error) {
            e.preventDefault();
            return;
        }

        let total = 0;
        inputs.forEach(i => { total += parseFloat(i.value) || 0; });
        if (Math.abs(total - 100) > 0.01) {
            showToast('La suma total debe ser exactamente 100%', 'error');
            e.preventDefault();
        }
    });

    calcularTotal();
});
</script>

<?php include '../../includes/footer.php'; ?>
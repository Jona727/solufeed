<?php
/**
 * SOLUFEED - Editar Dieta - Refactored to PDO
 */

require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Verificar permisos de administrador
verificarAdmin();

$db = getConnection();
$return_to = isset($_POST['return_to']) ? (string)$_POST['return_to'] : (isset($_GET['return_to']) ? (string)$_GET['return_to'] : '');
$back_url = safe_return_to($return_to, BASE_URL . '/admin/dietas/listar.php');

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: listar.php');
    exit();
}

$id_dieta = (int) $_GET['id'];
// Back URL: por defecto volvemos al detalle de la dieta, salvo que nos pasen return_to válido
$back_url = safe_return_to($return_to, BASE_URL . '/admin/dietas/ver.php?id=' . $id_dieta);

// Obtener datos de la dieta
$stmt = $db->prepare("SELECT * FROM dieta WHERE id_dieta = ?");
$stmt->execute([$id_dieta]);
$dieta = $stmt->fetch();

if (!$dieta) {
    header('Location: listar.php');
    exit();
}

// Verificar si la dieta está en uso actualmente por algún lote activo
$stmt_check = $db->prepare("
    SELECT COUNT(*) 
    FROM tropa_dieta_asignada tda
    INNER JOIN tropa t ON tda.id_tropa = t.id_tropa
    WHERE tda.id_dieta = ? AND tda.fecha_hasta IS NULL AND t.activo = 1
");
$stmt_check->execute([$id_dieta]);
$esta_en_uso = $stmt_check->fetchColumn() > 0;

$es_activa = (int)$dieta['activo'] === 1;

// Inicializar errores
$errores = [];

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify_post()) {
        $errores[] = csrf_fail_response('Token expirado o inválido. Actualizá la página y reintentá.');
    }
    // Si la dieta es activa, solo permitimos cambiar el estado 'activo' (desactivarla)
    // Pero si está en uso, ni siquiera permitimos desactivarla.
    
    $nuevo_activo = isset($_POST['activo']) ? 1 : 0;
    
    if ($es_activa) {
        // La dieta estaba activa. Comprobamos si intentan desactivarla.
        if ($nuevo_activo === 0) {
            if ($esta_en_uso) {
                $errores[] = "No puedes desactivar una dieta que está siendo usada por lotes activos.";
            } else {
                // Proceder solo con la desactivación, manteniendo el resto igual
                $nombre = $dieta['nombre'];
                $descripcion = $dieta['descripcion'];
                $insumos_seleccionados = []; // No importa, no borraremos el detalle si solo desactivamos? 
                // Espera, según el flujo, si solo desactivamos, mantenemos los datos.
            }
        } else {
            // Sigue activa, no se permiten otros cambios
            $nombre = $dieta['nombre'];
            $descripcion = $dieta['descripcion'];
        }
    } else {
        // La dieta estaba inactiva, se permite edición completa
        $nombre = limpiarDato($_POST['nombre']);
        $descripcion = limpiarDato($_POST['descripcion']);    }
    
    if (!$es_activa || ($es_activa && $nuevo_activo === 0 && !$esta_en_uso)) {
        $insumos_seleccionados = isset($_POST['insumos']) ? $_POST['insumos'] : [];
        $porcentajes = isset($_POST['porcentajes']) ? $_POST['porcentajes'] : [];
        
        if (empty($nombre)) $errores[] = "El nombre es obligatorio.";

        // Evitar nombres duplicados entre dietas ACTIVAS
        if (empty($errores)) {
            $stmt_dup = $db->prepare("SELECT COUNT(*) FROM dieta WHERE activo = 1 AND nombre = ? AND id_dieta <> ?");
            $stmt_dup->execute([$nombre, $id_dieta]);
            if ((int)$stmt_dup->fetchColumn() > 0) {
                $errores[] = "Ya existe una dieta activa con ese nombre. Usá otro nombre o desactivá la anterior.";
            }
        }

        if (empty($insumos_seleccionados) && !$es_activa) $errores[] = "Debés seleccionar al menos un insumo.";
        
        if (empty($errores)) {
            $total_porcentaje = 0;
            foreach ($insumos_seleccionados as $id_ins) {
                $val = isset($porcentajes[$id_ins]) ? (float)$porcentajes[$id_ins] : 0;
                if ($val <= 0) {
                    $errores[] = "Todos los insumos seleccionados deben tener un % > 0.";
                    break;
                }
                $total_porcentaje += $val;
            }
            if (empty($errores) && !empty($insumos_seleccionados) && abs($total_porcentaje - 100) > 0.01) {
                $errores[] = "La suma debe ser exactamente 100%.";
            }
        }
    }

    if (empty($errores)) {
        try {
            $db->beginTransaction();
            
            // Si estaba activa y NO cambió a inactiva, solo validamos si intentaron cambiar nombre/desc
            // Pero para simplificar, si estaba activa solo actualizamos el campo 'activo'.
            if ($es_activa && $nuevo_activo === 1) {
                // No hay cambios permitidos si sigue activa
                $db->commit();
                // Respetar return_to para volver al origen (listar/ver/lote/insumo)
                header('Location: ' . $back_url);
                exit();
            }

            $stmt = $db->prepare("UPDATE dieta SET nombre = ?, descripcion = ?, activo = ?, fecha_actualizacion = NOW() WHERE id_dieta = ?");
            $stmt->execute([$nombre, $descripcion, $nuevo_activo, $id_dieta]);
            
            if (!$es_activa) {
                // Solo actualizamos insumos si la dieta estaba inactiva permitiendo edición
                $db->prepare("DELETE FROM dieta_detalle WHERE id_dieta = ?")->execute([$id_dieta]);
                $stmt_ins = $db->prepare("INSERT INTO dieta_detalle (id_dieta, id_insumo, porcentaje_teorico) VALUES (?, ?, ?)");
                foreach ($insumos_seleccionados as $id_ins) {
                    $stmt_ins->execute([$id_dieta, $id_ins, (float)$porcentajes[$id_ins]]);
                }
            }
            
            $db->commit();
            flash_set('success', 'Dieta actualizada correctamente.');
            header('Location: ' . $back_url);
            exit();

        } catch (PDOException $e) {
            $db->rollBack();
            log_event('ERROR', 'DIETA_UPDATE_FAILED', ['error' => $e->getMessage()]);
            $errores[] = 'Error al actualizar la dieta. Reintentá o contactá soporte.';
        }
    }
}

// Obtener insumos actuales
$stmt = $db->prepare("SELECT id_insumo, porcentaje_teorico FROM dieta_detalle WHERE id_dieta = ?");
$stmt->execute([$id_dieta]);
$insumos_actuales = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Obtener todos los insumos activos
$stmt = $db->query("SELECT id_insumo, nombre, tipo, porcentaje_ms FROM insumo WHERE activo = 1 ORDER BY nombre ASC");
$insumos_disponibles = $stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="page-header">
    <h1 style="font-weight: 800; color: var(--primary); margin: 0; letter-spacing: -1px;">✏️ Editar Dieta</h1>
    <div class="header-actions">
        <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-secondary"><span>←</span> Cancelar</a>
    </div>
</div>

<div class="card">
    <?php if (isset($_GET['exito'])): ?>
        <div style="background: #dcfce7; border-left: 5px solid var(--success); color: #166534; padding: 1rem; margin-bottom: 1.5rem; border-radius: var(--radius);">
            ✓ Cambios guardados correctamente.
        </div>
    <?php endif; ?>
    
    <?php if (!empty($errores)): ?>
        <div style="background: #fee2e2; border-left: 5px solid var(--danger); color: #991b1b; padding: 1rem; margin-bottom: 1.5rem; border-radius: var(--radius);">
            <ul style="margin: 0; padding-left: 1.5rem; font-size: 0.9rem;">
                <?php foreach ($errores as $error): ?>
                    <li><?php echo $error; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($es_activa): ?>
        <div style="background: #fff9db; border-left: 5px solid #fab005; color: #862e00; padding: 1rem; margin-bottom: 1.5rem; border-radius: var(--radius); font-size: 0.9rem;">
            <?php if ($esta_en_uso): ?>
                <strong>⚠️ Dieta en Uso:</strong> Esta dieta está asignada a lotes activos. No se puede editar ni desactivar hasta que se asigne otra dieta a dichos lotes.
            <?php else: ?>
                <strong>💡 Dieta Activa:</strong> Las dietas activas no se pueden modificar. Desactívala primero para realizar cambios en los insumos o el nombre.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="formDieta">
        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($back_url); ?>">
                <?php echo csrf_input(); ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
            <div class="form-grupo">
                <label for="nombre" style="font-weight: 700; color: var(--text-main); display: block; margin-bottom: 0.5rem;">Nombre *</label>
                <input type="text" id="nombre" name="nombre" value="<?php echo htmlspecialchars($dieta['nombre']); ?>" required style="width: 100%;" <?php echo $es_activa ? 'disabled' : ''; ?>>
            </div>
            <div class="form-grupo" style="display: flex; align-items: center; gap: 0.75rem; padding-top: 1.5rem;">
                <input type="checkbox" id="activo" name="activo" value="1" <?php echo $dieta['activo'] ? 'checked' : ''; ?> style="width: 20px; height: 20px;" <?php echo $esta_en_uso ? 'disabled' : ''; ?>>
                <label for="activo" style="font-weight: 700; color: var(--text-main);">Activa</label>
            </div>
        </div>
        
        <div class="form-grupo">
            <label for="descripcion" style="font-weight: 700; color: var(--text-main); display: block; margin-bottom: 0.5rem;">Descripción</label>
            <textarea id="descripcion" name="descripcion" style="width: 100%; min-height: 80px;" <?php echo $es_activa ? 'disabled' : ''; ?>><?php echo htmlspecialchars($dieta['descripcion']); ?></textarea>
        </div>

        <div style="margin: 2.5rem 0; height: 1px; background: var(--border);"></div>
        
        <h3 style="font-weight: 800; color: var(--primary); margin-bottom: 1.5rem;">🌾 Composición</h3>
        
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th style="width: 60px; text-align: center;">Usar</th>
                        <th>Insumo</th>
                        <th style="width: 180px; text-align: center;">% en Dieta</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($insumos_disponibles as $insumo): 
                        $esta_seleccionado = isset($insumos_actuales[$insumo['id_insumo']]);
                        $valor = $esta_seleccionado ? $insumos_actuales[$insumo['id_insumo']] : '';
                    ?>
                        <tr>
                            <td style="text-align: center;">
                                <input type="checkbox" name="insumos[]" value="<?php echo $insumo['id_insumo']; ?>" class="insumo-checkbox" data-insumo-id="<?php echo $insumo['id_insumo']; ?>" style="width: 20px; height: 20px;" <?php echo $esta_seleccionado ? 'checked' : ''; ?> <?php echo $es_activa ? 'disabled' : ''; ?>>
                            </td>
                            <td>
                                <strong style="color: <?php echo $es_activa ? 'var(--text-muted)' : 'var(--primary)'; ?>;"><?php echo htmlspecialchars($insumo['nombre']); ?></strong>
                                <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($insumo['tipo']); ?></div>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 0.5rem; justify-content: center;">
                                    <input type="number" name="porcentajes[<?php echo $insumo['id_insumo']; ?>]" id="porcentaje_<?php echo $insumo['id_insumo']; ?>" step="0.01" min="0" max="100" class="porcentaje-input" style="width: 100px; text-align: right; padding: 0.5rem; font-weight: 800;" value="<?php echo $valor; ?>" <?php echo ($esta_seleccionado && !$es_activa) ? '' : 'disabled'; ?>>
                                    <span style="font-weight: 800; color: var(--text-muted);">%</span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background: var(--bg-main); font-weight: 800;">
                        <td colspan="2" style="text-align: right; padding: 1.25rem;">TOTAL:</td>
                        <td style="text-align: center;"><div id="totalPorcentaje" style="font-size: 1.5rem;">0.00%</div></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        
        <div style="display: flex; gap: 1rem; margin-top: 2rem;">
            <?php if (!$es_activa || !$esta_en_uso): ?>
                <button type="submit" class="btn btn-primary btn-lg" style="flex: 1;"><span>💾</span> <?php echo $es_activa ? 'Guardar Cambios de Estado' : 'Guardar Todos los Cambios'; ?></button>
            <?php endif; ?>
            <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-secondary btn-lg" style="flex: 0.3;">Volver</a>
        </div>
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
            if (input.value) total += parseFloat(input.value) || 0;
        });
        totalDiv.textContent = total.toFixed(2) + '%';
        totalDiv.style.color = (Math.abs(total - 100) < 0.01) ? '#28a745' : (total > 100 ? '#dc3545' : 'var(--text-main)');
    }

    checkboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            const input = document.getElementById('porcentaje_' + this.dataset.insumoId);
            input.disabled = !this.checked;
            if (this.checked) input.focus();
            else input.value = '';
            calcularTotal();
        });
    });

    inputs.forEach(i => i.addEventListener('input', calcularTotal));

    form.addEventListener('submit', function(e) {
        let error = false;
        checkboxes.forEach(cb => {
            if (cb.checked) {
                const input = document.getElementById('porcentaje_' + cb.dataset.insumoId);
                if ((parseFloat(input.value) || 0) <= 0) {
                    showToast('Todos los insumos seleccionados deben tener un % mayor a 0', 'warning');
                    input.focus();
                    error = true;
                    return;
                }
            }
        });
        if (error) { e.preventDefault(); return; }
        
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

<?php include '../../includes/header.php'; ?>
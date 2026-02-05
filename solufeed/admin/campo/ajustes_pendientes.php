<?php
/**
 * SOLUFEED - Gestión de Ajustes de Stock Pendientes
 * Permite al administrador validar las diferencias de animales detectadas en las pesadas.
 */

require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Verificar permisos de administrador
verificarAdmin();

$db = getConnection();
$mensaje = '';
$error = '';

// Procesar acciones de Aprobación o Rechazo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    if (!csrf_verify_post()) {
        $error = csrf_fail_response('Token expirado o inválido. Actualizá la página y reintentá.');
    } else {
    $id_ajuste = (int)$_POST['id_ajuste'];
    $accion = $_POST['accion']; // 'APROBAR' o 'RECHAZAR'
    
    try {
        $db->beginTransaction();
        
        // Obtener datos del ajuste
        $stmt_datos = $db->prepare("SELECT * FROM ajuste_animales_pendiente WHERE id_ajuste = ? AND estado = 'PENDIENTE'");
        $stmt_datos->execute([$id_ajuste]);
        $ajuste = $stmt_datos->fetch();
        
        if ($ajuste) {
            if ($accion === 'APROBAR') {
                // 1. Cambiar estado a APROBADO
                $stmt_upd = $db->prepare("UPDATE ajuste_animales_pendiente SET estado = 'APROBADO' WHERE id_ajuste = ?");
                $stmt_upd->execute([$id_ajuste]);
                
                // 2. Determinar tipo de movimiento
                $tipo_mov = ($ajuste['diferencia_animales'] > 0) ? 'AJUSTE_POSITIVO' : 'AJUSTE_NEGATIVO';
                $cantidad = abs($ajuste['diferencia_animales']);
                $motivo = "Validación de Pesada: " . $ajuste['motivo_operario'];
                
                // 3. Insertar en movimiento_animal para actualizar stock real
                $stmt_mov = $db->prepare("
                    INSERT INTO movimiento_animal (id_tropa, tipo_movimiento, cantidad, fecha, motivo, fecha_creacion, id_usuario_admin)
                    VALUES (?, ?, ?, CURDATE(), ?, NOW(), ?)
                ");
                $stmt_mov->execute([$ajuste['id_tropa'], $tipo_mov, $cantidad, $motivo, $_SESSION['usuario_id']]);
                
                $mensaje = "✅ Ajuste aprobado y stock actualizado correctamente.";
            } else {
                // RECHAZAR
                $stmt_upd = $db->prepare("UPDATE ajuste_animales_pendiente SET estado = 'RECHAZADO' WHERE id_ajuste = ?");
                $stmt_upd->execute([$id_ajuste]);
                $mensaje = "❌ Ajuste rechazado. El stock no ha sido modificado.";
            }
        }
        
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        log_event('ERROR', 'AJUSTE_ANIMALES_FAILED', ['error' => $e->getMessage()]);
        $error = 'Error al procesar el ajuste. Reintentá o contactá soporte.';
    }
    }
}

// Obtener ajustes pendientes (PDO)
$stmt_pendientes = $db->query("
    SELECT 
        a.*, 
        t.nombre as lote_nombre,
        p.fecha as fecha_pesada,
        p.animales_esperados,
        p.animales_vistos
    FROM ajuste_animales_pendiente a
    INNER JOIN tropa t ON a.id_tropa = t.id_tropa
    INNER JOIN pesada p ON a.id_pesada = p.id_pesada
    WHERE a.estado = 'PENDIENTE'
    ORDER BY a.fecha_creacion DESC
");
$ajustes = $stmt_pendientes->fetchAll();

include '../../includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; background: var(--surface); padding: 1.5rem; border-radius: var(--radius); box-shadow: var(--shadow-sm); border: 1px solid var(--border);">
    <div>
        <h1 style="font-weight: 800; color: var(--primary); margin: 0; letter-spacing: -1px; display: flex; align-items: center; gap: 0.75rem;">
            <span>🚨</span> Ajustes de Stock Pendientes
        </h1>
        <p style="margin: 0.25rem 0 0 0; color: var(--text-muted); font-size: 0.95rem; font-weight: 500;">Validá las diferencias de animales reportadas por los operarios</p>
    </div>
    <a href="../dashboard.php" class="btn btn-secondary"><span>←</span> Volver al Panel</a>
</div>

<?php if ($mensaje): ?>
    <div class="card" style="background: #dcfce7; border-left: 5px solid var(--success); color: #166534; padding: 1rem; margin-bottom: 1.5rem; animation: slideIn 0.3s ease-out;">
        <?php echo $mensaje; ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="card" style="background: #fee2e2; border-left: 5px solid var(--danger); color: #991b1b; padding: 1rem; margin-bottom: 1.5rem;">
        <?php echo $error; ?>
    </div>
<?php endif; ?>

<div class="card">
    <h3 class="card-title"><span>📋</span> Solicitudes Pendientes de Validación</h3>
    
    <?php if (count($ajustes) > 0): ?>
        <div class="table-container">
            <table class="responsive-table">
                <thead>
                    <tr>
                        <th>Lote/Fecha</th>
                        <th style="text-align: center;">Diferencia</th>
                        <th>Motivo del Operario</th>
                        <th style="text-align: right;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ajustes as $ajuste): ?>
                        <tr>
                            <td>
                                <strong style="color: var(--primary); font-size: 1.05rem;"><?php echo htmlspecialchars($ajuste['lote_nombre']); ?></strong>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">Pesada del: <?php echo date('d/m/Y', strtotime($ajuste['fecha_pesada'])); ?></div>
                                <div style="font-size: 0.7rem; color: var(--text-muted);">Esperados: <?php echo $ajuste['animales_esperados']; ?> | Vistos: <?php echo $ajuste['animales_vistos']; ?></div>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($ajuste['diferencia_animales'] > 0): ?>
                                    <span class="badge" style="background: #dcfce7; color: #166534; padding: 0.5rem 0.75rem; font-weight: 800; font-size: 0.95rem;">
                                        +<?php echo $ajuste['diferencia_animales']; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background: #fee2e2; color: #991b1b; padding: 0.5rem 0.75rem; font-weight: 800; font-size: 0.95rem;">
                                        <?php echo $ajuste['diferencia_animales']; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-size: 0.9rem; font-style: italic; color: var(--text-main); border-left: 2px solid var(--border); padding-left: 10px; max-width: 300px;">
                                    "<?php echo !empty($ajuste['motivo_operario']) ? htmlspecialchars($ajuste['motivo_operario']) : 'Sin motivo especificado'; ?>"
                                </div>
                            </td>
                            <td style="text-align: right;">
                                <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                                    <form method="POST" style="margin:0;">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="id_ajuste" value="<?php echo $ajuste['id_ajuste']; ?>">
                                        <button type="submit" name="accion" value="APROBAR" class="btn btn-primary" style="padding: 0.5rem 0.75rem; font-size: 0.85rem;">
                                            ✅ Aprobar
                                        </button>
                                        <button type="submit" name="accion" value="RECHAZAR" class="btn btn-secondary" style="padding: 0.5rem 0.75rem; font-size: 0.85rem; border-color: var(--danger); color: var(--danger);">
                                            ✕ Rechazar
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div style="text-align: center; padding: 4rem 2rem; background: var(--bg-main); border-radius: var(--radius); border: 2px dashed var(--border);">
            <div style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;">✅</div>
            <h3 style="color: var(--text-muted); margin-bottom: 0.5rem;">No hay ajustes pendientes</h3>
            <p style="color: var(--text-muted); font-size: 0.9rem;">El stock de todos los lotes coincide con las últimas pesadas.</p>
        </div>
    <?php endif; ?>
</div>

<style>
@keyframes slideIn {
    from { transform: translateY(-10px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
.alerta-offline {
    animation: pulse 2s infinite;
}
@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.02); }
    100% { transform: scale(1); }
}
</style>

<?php include '../../includes/footer.php'; ?>

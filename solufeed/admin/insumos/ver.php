<?php
// admin/insumos/ver.php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Verificar permisos
verificarAdmin();

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: listar.php');
    exit();
}

$id_insumo = (int) $_GET['id'];
$db = getConnection();
$return_to = isset($_GET['return_to']) ? (string)$_GET['return_to'] : '';
$back_url = safe_return_to($return_to, BASE_URL . '/admin/insumos/listar.php');

// Obtener datos del insumo
$stmt = $db->prepare("SELECT * FROM insumo WHERE id_insumo = ?");
$stmt->execute([$id_insumo]);
$insumo = $stmt->fetch();

if (!$insumo) {
    header('Location: listar.php');
    exit();
}

$page_title = "Detalles del Insumo";
require_once '../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 style="font-weight: 800; color: var(--primary); margin: 0; letter-spacing: -1px;">📄 <?php echo htmlspecialchars($insumo['nombre']); ?></h1>
        <div style="display: flex; gap: 0.5rem; margin-top: 0.5rem;">
            <span class="badge" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-muted);">
                <?php echo htmlspecialchars($insumo['tipo']); ?>
            </span>
            <?php if ($insumo['activo']): ?>
                <span class="badge" style="background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;">Activo</span>
            <?php else: ?>
                <span class="badge" style="background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;">Inactivo</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="header-actions">
        <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-secondary"><span>←</span> Volver</a>
        <a href="editar.php?id=<?php echo $id_insumo; ?>&return_to=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="btn btn-primary"><span>✏️</span> Editar</a>
    </div>
</div>

<div class="card" style="margin-bottom: 2rem;">
    <h3 class="card-title"><span>ℹ️</span> Información General</h3>
    
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 2rem;">
        <div>
            <div style="font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700; margin-bottom: 0.25rem;">Porcentaje MS</div>
            <div style="font-size: 2rem; font-weight: 800; color: var(--primary);">
                <?php echo number_format($insumo['porcentaje_ms'], 1); ?><span style="font-size: 1rem; color: var(--text-muted);">%</span>
            </div>
            <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.5rem;">
                Materia Seca (Nutrientes sólidos)
            </div>
        </div>
        
        <div style="border-left: 1px solid var(--border); padding-left: 2rem;">
            <div style="font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700; margin-bottom: 0.5rem;">Fechas de Registro</div>
            <div style="margin-bottom: 0.5rem;">
                <span style="display: block; font-size: 0.8rem; color: var(--text-muted);">Creado:</span>
                <strong><?php echo date('d/m/Y H:i', strtotime($insumo['fecha_creacion'])); ?></strong>
            </div>
            <?php if ($insumo['fecha_actualizacion']): ?>
            <div>
                <span style="display: block; font-size: 0.8rem; color: var(--text-muted);">Última edición:</span>
                <strong><?php echo date('d/m/Y H:i', strtotime($insumo['fecha_actualizacion'])); ?></strong>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <h3 class="card-title"><span>📊</span> Uso en Recetas</h3>
    
    <?php
    // Obtener dietas asociadas
    $stmt_uso = $db->prepare("
        SELECT 
            d.id_dieta,
            d.nombre as dieta_nombre,
            dd.porcentaje_teorico,
            d.activo as dieta_activa
        FROM dieta_detalle dd
        INNER JOIN dieta d ON dd.id_dieta = d.id_dieta
        WHERE dd.id_insumo = ?
        ORDER BY d.activo DESC, d.nombre ASC
    ");
    $stmt_uso->execute([$id_insumo]);
    $dietas_uso = $stmt_uso->fetchAll();
    ?>
    
    <?php if (count($dietas_uso) > 0): ?>
        <p style="margin-bottom: 1.5rem; color: var(--text-muted);">
            Este insumo es parte de las siguientes fórmulas:
        </p>
        
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Dieta</th>
                        <th>Estado</th>
                        <th>% Teórico</th>
                        <th style="text-align: right;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dietas_uso as $dieta): ?>
                        <tr>
                            <td>
                                <strong style="color: var(--primary);"><?php echo htmlspecialchars($dieta['dieta_nombre']); ?></strong>
                            </td>
                            <td>
                                <?php if ($dieta['dieta_activa']): ?>
                                    <span style="font-size: 0.75rem; background: #dcfce7; color: #166534; padding: 2px 6px; border-radius: 4px; border: 1px solid #bbf7d0;">Activa</span>
                                <?php else: ?>
                                    <span style="font-size: 0.75rem; background: #f1f5f9; color: #64748b; padding: 2px 6px; border-radius: 4px; border: 1px solid #e2e8f0;">Archivada</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <span style="background: var(--bg-main); padding: 4px 10px; border-radius: 8px; font-weight: 700;">
                                    <?php echo number_format($dieta['porcentaje_teorico'], 1); ?>%
                                </span>
                            </td>
                            <td style="text-align: right;">
                                <a href="../dietas/ver.php?id=<?php echo $dieta['id_dieta']; ?>&return_to=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="btn btn-secondary" style="font-size: 0.8rem; padding: 0.4rem 0.8rem;">
                                    Ver Receta
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div style="text-align: center; padding: 3rem; background: var(--bg-main); border-radius: var(--radius); opacity: 0.7;">
            <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">📉</div>
            <strong>Sin uso registrado</strong>
            <p style="color: var(--text-muted); font-size: 0.9rem;">Este insumo aún no se ha utilizado en ninguna dieta.</p>
        </div>
    <?php endif; ?>
</div>

<?php require_once '../../includes/footer.php'; ?>

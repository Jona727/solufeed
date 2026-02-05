<?php
// admin/dietas/ver.php - Actualizado a PDO con CSS moderno
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Verificar permisos de administrador
verificarAdmin();

$page_title = "Detalle de Dieta";
$db = getConnection();
$return_to = isset($_GET['return_to']) ? (string)$_GET['return_to'] : '';
$back_url = safe_return_to($return_to, BASE_URL . '/admin/dietas/listar.php');

$id_dieta = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id_dieta <= 0) {
    header('Location: listar.php');
    exit;
}

// Obtener información de la dieta
$stmt = $db->prepare("SELECT * FROM dieta WHERE id_dieta = ?");
$stmt->execute([$id_dieta]);
$dieta = $stmt->fetch();

if (!$dieta) {
    header('Location: listar.php');
    exit;
}

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
$stmt->execute([$id_dieta]);
$composicion = $stmt->fetchAll();

// Calcular MS total de la dieta
$ms_total = 0;
foreach ($composicion as $item) {
    $ms_total += ($item['porcentaje_teorico'] * $item['porcentaje_ms']) / 100;
}

// Obtener lotes que usan esta dieta
$stmt = $db->prepare("
    SELECT 
        t.nombre as nombre_lote,
        t.cantidad_inicial,
        c.nombre as campo,
        tda.fecha_desde
    FROM tropa_dieta_asignada tda
    INNER JOIN tropa t ON tda.id_tropa = t.id_tropa
    LEFT JOIN campo c ON t.id_campo = c.id_campo
    WHERE tda.id_dieta = ? AND tda.fecha_hasta IS NULL AND t.activo = 1
    ORDER BY t.nombre
");
$stmt->execute([$id_dieta]);
$lotes_usando = $stmt->fetchAll();

require_once '../../includes/header.php';
?>



<div class="ver-dieta-container">
    <!-- Breadcrumb -->
    <nav class="breadcrumb" style="margin-bottom: 2rem; display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; font-weight: 600;">
        <a href="../dashboard.php" style="color: var(--primary); text-decoration: none; transition: opacity 0.2s;">Inicio</a>
        <span style="opacity: 0.3;">/</span>
        <a href="listar.php" style="color: var(--primary); text-decoration: none; transition: opacity 0.2s;">Dietas</a>
        <span style="opacity: 0.3;">/</span>
        <span style="color: var(--text-muted);"><?php echo htmlspecialchars($dieta['nombre']); ?></span>
    </nav>

    <?php if (isset($_GET['exito'])): ?>
        <div style="background: #dcfce7; border-left: 5px solid var(--success); color: #166534; padding: 1.25rem; margin-bottom: 2rem; border-radius: var(--radius); font-weight: 600;">
            ✓ Cambios guardados exitosamente.
        </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="page-header">
        <div>
            <h1 style="font-weight: 800; color: var(--primary); margin: 0; letter-spacing: -1px;">📋 <?php echo htmlspecialchars($dieta['nombre']); ?></h1>
            <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.5rem; flex-wrap: wrap;">
                <span class="badge" style="background: white; border: 1px solid var(--border); color: var(--primary); font-weight: 700;">
                    ID: #<?php echo $id_dieta; ?>
                </span>
                
                <?php if ($dieta['activo']): ?>
                    <span class="badge" style="background: #e7f5ff; color: #1971c2; border: 1px solid #a5d8ff;">
                        <?php 
                        // Verificar si está en uso
                        $stmt_check = $db->prepare("SELECT COUNT(*) FROM tropa_dieta_asignada tda INNER JOIN tropa t ON tda.id_tropa = t.id_tropa WHERE tda.id_dieta = ? AND tda.fecha_hasta IS NULL AND t.activo = 1");
                        $stmt_check->execute([$id_dieta]);
                        $en_uso = $stmt_check->fetchColumn() > 0;
                        echo $en_uso ? '🐄 En Uso' : '✓ Activa'; 
                        ?>
                    </span>
                <?php else: ?>
                    <span class="badge" style="background: #f1f3f5; color: #495057; border: 1px solid #dee2e6;">⚪ Inactiva</span>
                <?php endif; ?>
                
                <?php if ($dieta['descripcion']): ?>
                    <span style="color: var(--text-muted); font-size: 0.9rem; font-weight: 500; margin-left: 0.5rem; border-left: 2px solid var(--border); padding-left: 1rem; margin-top: 0.25rem;">
                        <?php echo htmlspecialchars($dieta['descripcion']); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="header-actions">
            <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-secondary"><span>←</span> Volver</a>
            <?php if (!(int)$dieta['activo']): ?>
                <a href="editar.php?id=<?php echo $id_dieta; ?>&return_to=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="btn btn-secondary">
                    <span>✏️</span> Editar Composición
                </a>
            <?php endif; ?>

            <form method="POST" action="toggle_estado.php" style="display:inline;" onsubmit="return confirm('<?php echo (int)$dieta['activo'] ? '¿Desactivar esta dieta?' : '¿Activar esta dieta?'; ?>')">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="id_dieta" value="<?php echo (int)$id_dieta; ?>">
                <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="btn <?php echo (int)$dieta['activo'] ? 'btn-secondary' : 'btn-primary'; ?>">
                    <span><?php echo (int)$dieta['activo'] ? '⏸️' : '▶️'; ?></span>
                    <?php echo (int)$dieta['activo'] ? 'Desactivar' : 'Activar'; ?>
                </button>
            </form>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 2rem; margin-bottom: 2rem;">
        <!-- Composición de la dieta -->
        <div class="card" style="margin: 0;">
            <h3 class="card-title"><span>🌾</span> Composición de la Dieta</h3>
            
            <?php if (count($composicion) > 0): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Insumo</th>
                                <th class="hide-mobile">Tipo</th>
                                <th>% MS Insumo</th>
                                <th>% en Dieta</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($composicion as $item): ?>
                                <tr>
                                    <td><strong style="color: var(--primary);"><?php echo htmlspecialchars($item['nombre_insumo']); ?></strong></td>
                                    <td class="hide-mobile">
                                        <span class="badge" style="background: var(--bg-main); color: var(--text-main);">
                                            <?php echo htmlspecialchars($item['tipo']); ?>
                                        </span>
                                    </td>
                                    <td style="text-align: center;"><?php echo number_format($item['porcentaje_ms'], 1); ?>%</td>
                                    <td style="text-align: center;"><strong style="font-size: 1.1rem; color: var(--primary);"><?php echo number_format($item['porcentaje_teorico'], 1); ?>%</strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 40px; color: #666;">
                    <div style="font-size: 3em; margin-bottom: 15px;">⚠️</div>
                    <p><strong>Esta dieta no tiene insumos asignados</strong></p>
                    <p>Editá la dieta para agregar insumos.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Columna de Gráficos y Resumen -->
        <div style="display: flex; flex-direction: column; gap: 1rem;">
            <!-- Gráfico de Torta -->
            <div class="card" style="margin: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 220px; padding: 1.25rem;">
                <h3 class="card-title" style="width: 100%; margin-bottom: 0.5rem; font-size: 1rem;"><span>📊</span> Visualización</h3>
                <div style="width: 100%; max-width: 150px; margin: auto; flex: 1; display: flex; align-items: center;">
                    <canvas id="dietaChart"></canvas>
                </div>
            </div>

            <!-- Resumen de MS -->
            <div class="card" style="margin: 0; background: var(--bg-main); border: 1px solid var(--border); padding: 1.25rem;">
                <h3 class="card-title" style="margin-bottom: 0.75rem; font-size: 1rem;"><span>📝</span> Resumen Materia Seca</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div style="background: white; padding: 0.75rem; border-radius: 12px; text-align: center; border: 1px solid var(--border);">
                        <div style="font-size: 1.4rem; font-weight: 800; color: var(--primary);"><?php echo number_format($ms_total, 2); ?>%</div>
                        <div style="font-size: 0.6rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">MS Total</div>
                    </div>
                    <div style="background: white; padding: 0.75rem; border-radius: 12px; text-align: center; border: 1px solid var(--border);">
                        <div style="font-size: 1.4rem; font-weight: 800; color: <?php 
                            $total_porcentaje = array_sum(array_column($composicion, 'porcentaje_teorico'));
                            echo (abs($total_porcentaje - 100) < 0.1) ? 'var(--success)' : 'var(--danger)';
                        ?>;">
                            <?php echo number_format($total_porcentaje, 1); ?>%
                        </div>
                        <div style="font-size: 0.6rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Mezcla</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Lotes que usan esta dieta -->
    <div class="card">
        <h3 class="card-title"><span>🐮</span> Lotes Usando Esta Dieta</h3>
        
        <?php if (count($lotes_usando) > 0): ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1rem;">
                <?php foreach ($lotes_usando as $lote): ?>
                    <div style="background: var(--bg-main); padding: 1.25rem; border-radius: var(--radius); border-left: 4px solid var(--primary);">
                        <strong style="color: var(--primary); font-size: 1.1rem; display: block; margin-bottom: 0.5rem;"><?php echo htmlspecialchars($lote['nombre_lote']); ?></strong>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; font-size: 0.85rem; color: var(--text-muted); font-weight: 500;">
                            <span>📍 <?php echo htmlspecialchars($lote['campo'] ?? 'Sin campo'); ?></span>
                            <span>🐄 <?php echo $lote['cantidad_inicial']; ?> animales</span>
                            <span style="grid-column: span 2;">📅 Desde: <?php echo date('d/m/Y', strtotime($lote['fecha_desde'])); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 30px; color: #666;">
                <p>📊 Esta dieta no está siendo usada por ningún lote actualmente.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    @media (max-width: 992px) {
        .ver-dieta-container > div[style*="grid-template-columns"] {
            grid-template-columns: 1fr !important;
        }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('dietaChart').getContext('2d');
    
    const data = {
        labels: [<?php echo "'" . implode("', '", array_map(function($i) { return htmlspecialchars($i['nombre_insumo']); }, $composicion)) . "'"; ?>],
        datasets: [{
            data: [<?php echo implode(", ", array_column($composicion, 'porcentaje_teorico')); ?>],
            backgroundColor: [
                '#2c5530', '#4a7c44', '#7dad6c', '#accf9b', '#d9ebce',
                '#1a3a1d', '#3e6341', '#669169', '#93bf96', '#c4e8c7'
            ],
            borderWidth: 2,
            borderColor: '#ffffff'
        }]
    };

    new Chart(ctx, {
        type: 'doughnut',
        data: data,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return ` ${context.label}: ${context.raw}%`;
                        }
                    }
                }
            },
            cutout: '65%'
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>

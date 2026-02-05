<?php
// admin/insumos/listar.php - Actualizado a PDO
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Verificar permisos de administrador
verificarAdmin();

$page_title = "Gestión de Insumos";
$db = getConnection();
$return_to = urlencode($_SERVER['REQUEST_URI']);

// Configuración de paginación
$registros_por_pagina = 10;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;

$offset = ($pagina_actual - 1) * $registros_por_pagina;

// Filtros (estado + orden) - default: recientes, activos
$estado = isset($_GET['estado']) ? (string)$_GET['estado'] : '';
$orden  = isset($_GET['orden']) ? (string)$_GET['orden'] : '';
$busqueda = isset($_GET['busqueda']) ? trim((string)$_GET['busqueda']) : '';

// Compatibilidad: ?inactivos=1 (legacy)
if (isset($_GET['inactivos']) && $_GET['inactivos'] == 1 && $estado === '') {
    $estado = 'inactivos';
}

$allowedEstado = ['activos','inactivos','todos'];
if ($estado === '' || !in_array($estado, $allowedEstado, true)) $estado = 'activos';
$allowedOrden = ['recientes','nombre'];
if ($orden === '' || !in_array($orden, $allowedOrden, true)) $orden = 'recientes';

// Construir consulta base
$sql_base = "FROM insumo WHERE 1=1";
$params = [];

if ($estado !== 'todos') {
    $sql_base .= " AND activo = :activo";
    $params[':activo'] = ($estado === 'activos') ? 1 : 0;
}
if (!empty($busqueda)) {
    // Usamos parámetros únicos para evitar problemas con PDO en algunos drivers
    $sql_base .= " AND (nombre LIKE :busqueda1 OR tipo LIKE :busqueda2)";
    $params[':busqueda1'] = "%$busqueda%";
    $params[':busqueda2'] = "%$busqueda%";
}

// Obtener total de registros para paginación
// Para el count, podemos usar el array params directamente en execute
$stmt_count = $db->prepare("SELECT COUNT(*) " . $sql_base);
$stmt_count->execute($params);
$total_registros = $stmt_count->fetchColumn();
$total_paginas = ceil($total_registros / $registros_por_pagina);

$query_extra = '&estado=' . urlencode($estado) . '&orden=' . urlencode($orden) . (!empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : '');

// Obtener insumos paginados
$orderSql = ($orden === "nombre") ? "nombre ASC" : "id_insumo DESC";
$sql_final = "SELECT * " . $sql_base . " ORDER BY {$orderSql} LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($sql_final);

// Vincular los parámetros de filtro
foreach ($params as $key => $val) {
    if (is_int($val)) {
        $stmt->bindValue($key, $val, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $val, PDO::PARAM_STR);
    }
}

// Vincular límite y offset
$stmt->bindValue(':limit', $registros_por_pagina, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$insumos = $stmt->fetchAll();

// La consulta de conteo por tipo se ha eliminado por solicitud del usuario (ahorro de espacio)

require_once '../../includes/header.php';
?>

<div class="insumos-container">
    <!-- Header -->
    <div class="page-header">
        <div>
            <h1 style="font-weight: 800; color: var(--primary); margin: 0; letter-spacing: -1px;">
                <?php echo ($estado === 'inactivos') ? '🗑️ Insumos Inactivos' : '🌾 Gestión de Insumos'; ?>
            </h1>
            <p style="margin: 0.25rem 0 0 0; color: var(--text-muted); font-size: 0.95rem; font-weight: 500;">
                <?php echo ($estado === 'inactivos') ? 'Visualizando insumos inactivos' : (($estado === 'todos') ? 'Visualizando todos los insumos' : 'Administrá los insumos activos disponibles para las dietas'); ?>
            </p>
        </div>
        <div class="header-actions">
            <div class="segmented" aria-label="Filtro de estado">
                <a href="listar.php?estado=activos&orden=<?php echo urlencode($orden); ?><?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?>" class="<?php echo $estado === 'activos' ? 'active' : ''; ?>">Activos</a>
                <a href="listar.php?estado=inactivos&orden=<?php echo urlencode($orden); ?><?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?>" class="<?php echo $estado === 'inactivos' ? 'active' : ''; ?>">Inactivos</a>
                <a href="listar.php?estado=todos&orden=<?php echo urlencode($orden); ?><?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?>" class="<?php echo $estado === 'todos' ? 'active' : ''; ?>">Todos</a>
            </div>

            <?php if ($estado !== 'inactivos'): ?>
                <a href="crear.php" class="btn btn-primary btn-sm">
                    <span>➕</span> Nuevo Insumo
                </a>
            <?php endif; ?>
        </div></div>
    </div>

    <!-- Filtros -->
    <div class="filters-bar">
        <form method="GET" action="listar.php" class="filters-row">
            <div class="filters-left">
                <input type="hidden" name="estado" value="<?php echo htmlspecialchars($estado); ?>">
                <input type="hidden" name="pagina" value="1">
                <div style="position: relative;">
                    <span style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); font-size: 1.1rem;">🔍</span>
                    <input class="filter-input" type="text" name="busqueda" value="<?php echo htmlspecialchars($busqueda); ?>" placeholder="Buscar por nombre o tipo..." style="padding-left: 2.6rem;">
                </div>
                <select class="filter-select" name="orden">
                    <option value="recientes" <?php echo $orden === 'recientes' ? 'selected' : ''; ?>>Más recientes primero</option>
                    <option value="nombre" <?php echo $orden === 'nombre' ? 'selected' : ''; ?>>Nombre (A → Z)</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">Aplicar</button>
                <?php if (!empty($busqueda)): ?>
                    <a class="btn btn-secondary btn-sm" href="listar.php?estado=<?php echo urlencode($estado); ?>&orden=<?php echo urlencode($orden); ?>">Limpiar</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

<!-- Tabla de insumos -->
    <!-- Tabla de insumos -->
    <div class="card">
        <h3 class="card-title"><span>📋</span> Lista de Insumos</h3>
        
        <?php if (count($insumos) > 0): ?>
            <div class="table-container">
                <table class="responsive-table">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Tipo</th>
                            <th>% MS</th>
                            <th class="th-actions">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($insumos as $insumo): ?>
                            <tr>
                                <td>
                                    <strong style="color: var(--primary); font-size: 1.05rem;"><?php echo htmlspecialchars($insumo['nombre']); ?></strong>
                                </td>
                                <td>
                                    <span class="badge" style="background: var(--bg-main); color: var(--text-main); border: 1px solid var(--border);">
                                        <?php echo htmlspecialchars($insumo['tipo']); ?>
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <span style="background: #e7f3e7; color: var(--primary); padding: 4px 10px; border-radius: 8px; font-weight: 800; font-size: 0.9rem;">
                                        <?php echo number_format($insumo['porcentaje_ms'], 1); ?>% MS
                                    </span>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <a href="ver.php?id=<?php echo $insumo['id_insumo']; ?>&return_to=<?php echo $return_to; ?>" class="btn btn-secondary btn-action">
                                        <span>👁️</span> <span class="btn-text">Ver</span>
                                    </a>
                                    <a href="editar.php?id=<?php echo $insumo['id_insumo']; ?>&return_to=<?php echo $return_to; ?>" class="btn btn-secondary btn-action">
                                        <span>✏️</span> <span class="btn-text">Editar</span>
                                    </a>
                                    <form method="POST" action="toggle_estado.php" style="display:inline;" onsubmit="return confirm('¿Confirmar cambio de estado del insumo?')">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="id_insumo" value="<?php echo (int)$insumo['id_insumo']; ?>">
                                        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="submit" class="btn-state <?php echo $insumo['activo'] ? 'on' : 'off'; ?>" title="<?php echo $insumo['activo'] ? 'Desactivar' : 'Activar'; ?>">
                                            <?php echo $insumo['activo'] ? '⏸️ Desactivar' : '▶️ Activar'; ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <?php if ($total_paginas > 1): ?>
                <div style="display: flex; justify-content: center; margin-top: 2rem; gap: 0.5rem;">
                    <?php 
                    $range = 2;
                    $initial_num = $pagina_actual - $range;
                    $condition_limit_num = ($pagina_actual + $range)  + 1;
                    ?>

                    <?php if ($pagina_actual > 1): ?>
                        <a href="?pagina=1<?php echo $query_extra; ?>" class="btn btn-secondary" style="padding: 0.5rem 1rem;">«</a>
                        <a href="?pagina=<?php echo $pagina_actual - 1; ?><?php echo $query_extra; ?>" class="btn btn-secondary" style="padding: 0.5rem 1rem;">‹</a>
                    <?php endif; ?>

                    <?php for ($x = $initial_num; $x < $condition_limit_num; $x++): ?>
                        <?php if (($x > 0) && ($x <= $total_paginas)): ?>
                            <?php if ($x == $pagina_actual): ?>
                                <span class="btn btn-primary" style="padding: 0.5rem 1rem; cursor: default;"><?php echo $x; ?></span>
                            <?php else: ?>
                                <a href="?pagina=<?php echo $x; ?><?php echo $query_extra; ?>" class="btn btn-secondary" style="padding: 0.5rem 1rem;"><?php echo $x; ?></a>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($pagina_actual < $total_paginas): ?>
                        <a href="?pagina=<?php echo $pagina_actual + 1; ?><?php echo $query_extra; ?>" class="btn btn-secondary" style="padding: 0.5rem 1rem;">›</a>
                        <a href="?pagina=<?php echo $total_paginas; ?><?php echo $query_extra; ?>" class="btn btn-secondary" style="padding: 0.5rem 1rem;">»</a>
                    <?php endif; ?>
                </div>
                <div style="text-align: center; margin-top: 1rem; color: var(--text-muted); font-size: 0.9rem;">
                    Mostrando <?php echo count($insumos); ?> de <?php echo $total_registros; ?> resultados
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 4rem 2rem; border: 2px dashed var(--border); border-radius: var(--radius); opacity: 0.6;">
                <div style="font-size: 4rem; margin-bottom: 1.5rem;">🌾</div>
                <h2 style="color: var(--text-muted); font-weight: 800;">No se encontraron resultados</h2>
                <p style="color: var(--text-muted); margin-bottom: 2rem;">
                    <?php if (!empty($busqueda)): ?>
                        No hay insumos que coincidan con "<strong><?php echo htmlspecialchars($busqueda); ?></strong>".
                        <br><a href="listar.php?estado=<?php echo urlencode($estado); ?>&orden=<?php echo urlencode($orden); ?>" style="color: var(--primary); font-weight: 700;">Limpiar búsqueda</a>
                    <?php else: ?>
                        Creá el primer insumo para comenzar.
                    <?php endif; ?>
                </p>
                <?php if (empty($busqueda)): ?>
                    <a href="crear.php" class="btn btn-primary btn-lg">
                        <span>➕</span> Crear Primer Insumo
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

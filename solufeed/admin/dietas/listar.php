<?php
// admin/dietas/listar.php - Actualizado con Busqueda, Paginación y Tabla Responsiva
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Verificar permisos de administrador
verificarAdmin();

$page_title = "Gestión de Dietas";
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
$sql_base = "FROM dieta d WHERE 1=1";
$params = [];

if ($estado !== 'todos') {
    $sql_base .= " AND d.activo = :activo";
    $params[':activo'] = ($estado === 'activos') ? 1 : 0;
}

if (!empty($busqueda)) {
    // Usamos parámetros únicos por seguridad
    $sql_base .= " AND d.nombre LIKE :busqueda";
    $params[':busqueda'] = "%$busqueda%";
}

// Obtener total de registros para paginación
$stmt_count = $db->prepare("SELECT COUNT(*) " . $sql_base);
$stmt_count->execute($params);
$total_registros = $stmt_count->fetchColumn();
$total_paginas = ceil($total_registros / $registros_por_pagina);

$orderSql = ($orden === 'nombre') ? 'd.nombre ASC' : 'd.id_dieta DESC';
$query_extra = '&estado=' . urlencode($estado) . '&orden=' . urlencode($orden) . (!empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : '');

// Obtener dietas paginadas con conteos
$sql_final = "
    SELECT 
        d.id_dieta,
        d.nombre,
        d.descripcion,
        d.activo,
        d.fecha_creacion,
        (SELECT COUNT(*) FROM dieta_detalle WHERE id_dieta = d.id_dieta) as cantidad_insumos,
        (SELECT COUNT(*) FROM tropa_dieta_asignada tda 
         WHERE tda.id_dieta = d.id_dieta AND tda.fecha_hasta IS NULL) as lotes_usando
    " . $sql_base . " 
    ORDER BY {$orderSql} 
    LIMIT :limit OFFSET :offset
";

$stmt = $db->prepare($sql_final);
foreach ($params as $key => $val) {
    if (is_int($val)) {
        $stmt->bindValue($key, $val, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $val, PDO::PARAM_STR);
    }
}
$stmt->bindValue(':limit', $registros_por_pagina, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$dietas = $stmt->fetchAll();

require_once '../../includes/header.php';
?>

<div class="dietas-container">
    <!-- Header -->
    <div class="page-header">
        <div>
            <h1 style="font-weight: 800; color: var(--primary); margin: 0; letter-spacing: -1px;">
                <?php echo ($estado === 'inactivos') ? '🗑️ Dietas Archivadas' : '📋 Gestión de Dietas'; ?>
            </h1>
            <p style="margin: 0.25rem 0 0 0; color: var(--text-muted); font-size: 0.95rem; font-weight: 500;">
                <?php echo ($estado === 'inactivos') ? 'Visualizando dietas que ya no están en uso' : (($estado === 'todos') ? 'Visualizando todas las dietas' : 'Administrá las dietas activas asignadas a los lotes'); ?>
            </p>
        </div>
        <div class="header-actions">
            <div class="segmented" aria-label="Filtro de estado">
                <a href="listar.php?estado=activos&orden=<?php echo urlencode($orden); ?><?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?>" class="<?php echo $estado === 'activos' ? 'active' : ''; ?>">Activas</a>
                <a href="listar.php?estado=inactivos&orden=<?php echo urlencode($orden); ?><?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?>" class="<?php echo $estado === 'inactivos' ? 'active' : ''; ?>">Inactivas</a>
                <a href="listar.php?estado=todos&orden=<?php echo urlencode($orden); ?><?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?>" class="<?php echo $estado === 'todos' ? 'active' : ''; ?>">Todas</a>
            </div>

            <?php if ($estado !== 'inactivos'): ?>
                <a href="crear.php" class="btn btn-primary btn-sm">
                    <span>➕</span> Nueva Dieta
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filtros -->
    <div class="filters-bar">
        <form method="GET" action="listar.php" class="filters-row">
            <div class="filters-left">
                <input type="hidden" name="estado" value="<?php echo htmlspecialchars($estado); ?>">
                <input type="hidden" name="pagina" value="1">
                <div style="position: relative;">
                    <span style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); font-size: 1.1rem;">🔍</span>
                    <input class="filter-input" type="text" name="busqueda" value="<?php echo htmlspecialchars($busqueda); ?>" placeholder="Buscar dieta por nombre..." style="padding-left: 2.6rem;">
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

    <!-- Lista de Dietas -->
    <div class="card">
        <h3 class="card-title"><span>📂</span> Listado de Fórmulas</h3>
        
        <?php if (count($dietas) > 0): ?>
            <div class="table-container">
                <table class="responsive-table">
                    <thead>
                        <tr>
                            <th>Nombre de Dieta</th>
                            <th>Insumos</th>
                            <th class="hide-mobile">Lotes en Uso</th>
                            <th class="hide-mobile">Creación</th>
                            <th class="th-actions">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dietas as $dieta): ?>
                            <tr>
                                <td>
                                    <strong style="color: var(--primary); font-size: 1.05rem;"><?php echo htmlspecialchars($dieta['nombre']); ?></strong>
                                    <?php if ($dieta['descripcion']): ?>
                                        <div style="font-size: 0.8rem; color: var(--text-muted); font-weight: 500; margin-top: 2px;">
                                            <?php echo htmlspecialchars(substr($dieta['descripcion'], 0, 80)) . (strlen($dieta['descripcion']) > 80 ? '...' : ''); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <span class="badge" style="background: var(--bg-main); color: var(--primary); font-weight: 700; font-size: 0.95rem;">
                                        <?php echo $dieta['cantidad_insumos']; ?>
                                    </span>
                                </td>
                                <td style="text-align: center;" class="hide-mobile">
                                    <?php $lotes_usando = (int)$dieta['lotes_usando']; ?>
                                    <span class="badge" style="font-weight: 700; <?php echo $lotes_usando > 0 ? 'background: rgba(40,167,69,.10); color: #166534;' : 'background: #fef3c7; color: #92400e;'; ?>">
                                        <?php echo $lotes_usando; ?> lotes
                                    </span>
                                </td>
                                <td style="text-align: center; color: var(--text-muted); font-size: 0.9rem;" class="hide-mobile">
                                    <?php echo date('d/m/Y', strtotime($dieta['fecha_creacion'])); ?>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <a href="ver.php?id=<?php echo $dieta['id_dieta']; ?>&return_to=<?php echo $return_to; ?>" class="btn btn-secondary btn-action">
                                        <span>👁️</span> <span class="btn-text">Ver</span>
                                    </a>
                                    <a href="editar.php?id=<?php echo $dieta['id_dieta']; ?>&return_to=<?php echo $return_to; ?>" class="btn btn-secondary btn-action">
                                        <span>✏️</span> <span class="btn-text">Editar</span>
                                    </a>
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
                    Mostrando <?php echo count($dietas); ?> de <?php echo $total_registros; ?> resultados
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 4rem 2rem; border: 2px dashed var(--border); border-radius: var(--radius); opacity: 0.6;">
                <div style="font-size: 4rem; margin-bottom: 1.5rem;">📋</div>
                <h2 style="color: var(--text-muted); font-weight: 800;">No se encontraron resultados</h2>
                <p style="color: var(--text-muted); margin-bottom: 2rem;">
                    <?php if (!empty($busqueda)): ?>
                        No hay dietas que coincidan con "<strong><?php echo htmlspecialchars($busqueda); ?></strong>".
                        <br><a href="listar.php?estado=<?php echo urlencode($estado); ?>&orden=<?php echo urlencode($orden); ?>" style="color: var(--primary); font-weight: 700;">Limpiar búsqueda</a>
                    <?php else: ?>
                        Creá la primera dieta para comenzar.
                    <?php endif; ?>
                </p>
                <?php if (empty($busqueda)): ?>
                    <a href="crear.php" class="btn btn-primary btn-lg">
                        <span>➕</span> Crear Primera Dieta
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

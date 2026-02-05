<?php
/**
 * SOLUFEED - Crear Nuevo Lote
 */

require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Verificar sesión
// Verificar permisos de administrador
verificarAdmin();

$db = getConnection();

// Obtener campos disponibles (PDO)
$stmt_campos = $db->query("SELECT id_campo, nombre FROM campo WHERE activo = 1 ORDER BY nombre ASC");
$campos_disponibles = $stmt_campos->fetchAll();

// Obtener dietas disponibles (PDO)
$stmt_dietas = $db->query("SELECT id_dieta, nombre FROM dieta WHERE activo = 1 ORDER BY nombre ASC");
$dietas_disponibles = $stmt_dietas->fetchAll();

$mensaje = '';
$error = '';
$errores = [];

// Procesar formulario si se envió
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify_post()) {
        $errores[] = csrf_fail_response('Token expirado o inválido. Actualizá la página y reintentá.');
    }
    
    // Recibir datos del formulario
    $nombre = trim($_POST['nombre']);
    $id_campo = (int) $_POST['id_campo'];
    $categoria = trim($_POST['categoria']);
    $fecha_inicio = $_POST['fecha_inicio'];
    $cantidad_inicial = (int) $_POST['cantidad_inicial'];
    $id_dieta = !empty($_POST['id_dieta']) ? (int) $_POST['id_dieta'] : null;
    $activo = isset($_POST['activo']) ? 1 : 0;
    
    // Validaciones
    if (empty($nombre)) $errores[] = "El nombre del lote es obligatorio.";
    if ($id_campo <= 0) $errores[] = "Debés seleccionar un campo.";
    if (empty($fecha_inicio)) $errores[] = "La fecha de inicio es obligatoria.";
    if ($cantidad_inicial <= 0) $errores[] = "La cantidad inicial de animales debe ser mayor a 0.";
    
    // Si no hay errores, crear el lote
    if (empty($errores)) {
        try {
            $db->beginTransaction();
            
            // Insertar lote
            $stmt_ins = $db->prepare("
                INSERT INTO tropa (nombre, id_campo, categoria, fecha_inicio, cantidad_inicial, activo, fecha_creacion)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt_ins->execute([$nombre, $id_campo, $categoria, $fecha_inicio, $cantidad_inicial, $activo]);
            $id_lote_nuevo = $db->lastInsertId();
            
            // Si se seleccionó una dieta, asignarla
            if ($id_dieta !== null && $id_dieta > 0) {
                $stmt_dieta = $db->prepare("
                    INSERT INTO tropa_dieta_asignada (id_tropa, id_dieta, fecha_desde, fecha_hasta)
                    VALUES (?, ?, ?, NULL)
                ");
                $stmt_dieta->execute([$id_lote_nuevo, $id_dieta, $fecha_inicio]);
            }
            
            $db->commit();
            $mensaje = "✅ Lote creado exitosamente.";
            header("refresh:2;url=ver.php?id=$id_lote_nuevo");
            
        } catch (Exception $e) {
            $db->rollBack();
            log_event('ERROR', 'LOTE_CREATE_FAILED', ['error' => $e->getMessage()]);
            $errores[] = 'Error al crear el lote. Reintentá o contactá soporte.';
        }
    }
}

include '../../includes/header.php';
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 2rem;">
    <h1 style="font-weight: 800; color: var(--primary); margin: 0; letter-spacing: -1px;">➕ Crear Nuevo Lote</h1>
    <a href="listar.php" class="btn btn-secondary"><span>←</span> Volver</a>
</div>

<div class="card">
    <?php if ($mensaje): ?>
        <div class="card" style="background: #dcfce7; border-left: 5px solid var(--success); color: #166534; padding: 1rem; margin-bottom: 1.5rem;">
            <p style="margin-bottom: 0.5rem;"><?php echo $mensaje; ?></p>
            <p style="font-size: 0.85rem;">Redirigiendo al detalle del lote...</p>
        </div>
    <?php endif; ?>

    <?php if (!empty($errores)): ?>
        <div class="card" style="background: #fee2e2; border-left: 5px solid var(--danger); color: #991b1b; padding: 1.5rem; margin-bottom: 1.5rem;">
            <p style="font-weight: 700; margin-bottom: 0.5rem;">⚠️ Se encontraron errores:</p>
            <ul style="margin-left: 1.25rem; font-size: 0.9rem;">
                <?php foreach ($errores as $err): ?>
                    <li><?php echo $err; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" action="" novalidate>
                <?php echo csrf_input(); ?>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
            <div class="form-group">
                <label for="nombre">Nombre del Lote *</label>
                <input type="text" id="nombre" name="nombre" required placeholder="Ej: Novillos Lote 1" value="<?php echo isset($_POST['nombre']) ? htmlspecialchars($_POST['nombre']) : ''; ?>">
                <small style="color: var(--text-muted); font-size: 0.8rem;">Nombre descriptivo para identificar el lote.</small>
            </div>
            
            <div class="form-group">
                <label for="id_campo">Campo / Ubicación *</label>
                <select id="id_campo" name="id_campo" required>
                    <option value="">-- Seleccioná un campo --</option>
                    <?php foreach ($campos_disponibles as $campo): ?>
                        <option value="<?php echo $campo['id_campo']; ?>" <?php echo (isset($_POST['id_campo']) && $_POST['id_campo'] == $campo['id_campo']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($campo['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style="color: var(--text-muted); font-size: 0.8rem;">¿En qué potrero o campo se encuentra?</small>
            </div>
        </div>
        
        <div class="form-group">
            <label for="categoria">Categoría o Descripción</label>
            <input type="text" id="categoria" name="categoria" placeholder="Ej: Novillos 350-400kg" value="<?php echo isset($_POST['categoria']) ? htmlspecialchars($_POST['categoria']) : ''; ?>">
            <small style="color: var(--text-muted); font-size: 0.8rem;">Detalles sobre el tipo de animal.</small>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-top: 1rem;">
            <div class="form-group">
                <label for="fecha_inicio">Fecha de Ingreso *</label>
                <input type="date" id="fecha_inicio" name="fecha_inicio" required value="<?php echo isset($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : date('Y-m-d'); ?>">
                <small style="color: var(--text-muted); font-size: 0.8rem;">¿Cuándo entraron al feedlot?</small>
            </div>
            
            <div class="form-group">
                <label for="cantidad_inicial">Cantidad de Cabezas *</label>
                <input type="number" id="cantidad_inicial" name="cantidad_inicial" required min="1" placeholder="Ej: 50" value="<?php echo isset($_POST['cantidad_inicial']) ? $_POST['cantidad_inicial'] : ''; ?>">
                <small style="color: var(--text-muted); font-size: 0.8rem;">Cantidad total inicial.</small>
            </div>
        </div>

        <div style="background: var(--bg-main); padding: 2rem; border-radius: var(--radius); border: 1px solid var(--border); margin: 2rem 0;">
            <h3 style="color: var(--primary); font-weight: 800; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
                <span>📋</span> Asignación de Dieta (Opcional)
            </h3>
            
            <div class="form-group">
                <label for="id_dieta">Dieta Sugerida</label>
                <select id="id_dieta" name="id_dieta">
                    <option value="">-- Sin dieta asignada por ahora --</option>
                    <?php foreach ($dietas_disponibles as $dieta): ?>
                        <option value="<?php echo $dieta['id_dieta']; ?>" <?php echo (isset($_POST['id_dieta']) && $_POST['id_dieta'] == $dieta['id_dieta']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dieta['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style="color: var(--text-muted); font-size: 0.8rem; display: block; margin-top: 0.5rem;">
                    Esta será la dieta por defecto para el registro de alimentación.
                </small>
            </div>
            
            <?php if (empty($dietas_disponibles)): ?>
                <div style="margin-top: 1rem; color: var(--danger); font-size: 0.85rem; font-weight: 600;">
                    ⚠️ No hay dietas activas. <a href="../dietas/crear.php" style="text-decoration: underline;">Crear una dieta</a>
                </div>
            <?php endif; ?>
        </div>
        
        <div style="margin: 2rem 0; padding: 1rem; background: var(--bg-main); border-radius: var(--radius); display: flex; align-items: center; gap: 1rem;">
            <input type="checkbox" name="activo" id="activo" value="1" <?php echo (!isset($_POST['activo']) || $_POST['activo']) ? 'checked' : ''; ?> style="width: 20px; height: 20px;">
            <label for="activo" style="margin-bottom: 0; cursor: pointer; font-weight: 600;">Lote activo (disponible para operaciones diarias)</label>
        </div>
        
        <!-- Botones -->
        <div style="display: flex; gap: 1rem; padding-top: 2rem; border-top: 1px solid var(--border);">
            <button type="submit" class="btn btn-primary btn-lg" style="flex: 1;">💾 Crear Lote</button>
            <a href="listar.php" class="btn btn-secondary btn-lg" style="flex: 0.3;">Cancelar</a>
        </div>
        
    </form>
    
</div>

<?php include '../../includes/footer.php'; ?>
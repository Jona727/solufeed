<?php
// admin/campo/pesada_editar.php
// Permite al usuario CAMPO editar una pesada propia.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

verificarCampo();

$db = getConnection();
$page_title = 'Editar Pesada';

$id_usuario = (int)($_SESSION['usuario_id'] ?? 0);
$id_pesada = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$return_to = isset($_GET['return_to']) ? $_GET['return_to'] : (BASE_URL . '/admin/campo/historial.php');
$return_to = safe_return_to($return_to, BASE_URL . '/admin/campo/historial.php');

if ($id_pesada <= 0) {
    header('Location: ' . BASE_URL . '/admin/campo/historial.php');
    exit();
}

// Obtener registro y validar ownership
$stmt = $db->prepare("
    SELECT p.*, t.nombre AS lote_nombre, c.nombre AS campo_nombre
    FROM pesada p
    INNER JOIN tropa t ON p.id_tropa = t.id_tropa
    LEFT JOIN campo c ON t.id_campo = c.id_campo
    WHERE p.id_pesada = ? AND p.id_usuario = ?
    LIMIT 1
");
$stmt->execute([$id_pesada, $id_usuario]);
$pesada = $stmt->fetch();

if (!$pesada) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="card"><h1 class="card-title">No encontrado</h1><p>Este registro no existe o no te pertenece.</p></div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit();
}

// Si ya existe un ajuste aprobado/rechazado, bloquear edición (evita inconsistencia)
$stmt = $db->prepare("SELECT estado, motivo_operario FROM ajuste_animales_pendiente WHERE id_pesada = ? ORDER BY fecha_creacion DESC LIMIT 1");
$stmt->execute([$id_pesada]);
$ajuste_row = $stmt->fetch();
$ajuste_estado = $ajuste_row['estado'] ?? null;
$ajuste_motivo = $ajuste_row['motivo_operario'] ?? '';
$bloqueado = ($ajuste_estado && $ajuste_estado !== 'PENDIENTE');

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_edicion']) && !$bloqueado) {
    if (!csrf_verify_post()) {
        $errores[] = csrf_fail_response('Token expirado o inválido. Actualizá la página y reintentá.');
    }

    // Revalidar ownership
    if (!obtenerPesadaDeUsuarioCampo($id_pesada, $id_usuario)) {
        $errores[] = 'No tenés permisos para editar este registro.';
    }

    $fecha = isset($_POST['fecha']) ? limpiarDato($_POST['fecha']) : '';
    $peso_promedio = isset($_POST['peso_promedio']) ? (float)$_POST['peso_promedio'] : 0;
    $animales_vistos = isset($_POST['animales_vistos']) ? (int)$_POST['animales_vistos'] : 0;
    $motivo_operario = isset($_POST['motivo_operario']) ? limpiarDato($_POST['motivo_operario']) : '';

    if ($fecha === '') $errores[] = 'La fecha es obligatoria.';
    if ($peso_promedio <= 0) $errores[] = 'El peso promedio debe ser mayor a 0.';
    if ($animales_vistos <= 0) $errores[] = 'La cantidad de animales vistos debe ser mayor a 0.';

    $animales_esperados = (int)$pesada['animales_esperados'];
    $hay_diferencia = ($animales_vistos !== $animales_esperados) ? 1 : 0;
    $diferencia = $animales_vistos - $animales_esperados;

    if ($hay_diferencia && trim($motivo_operario) === '') {
        $errores[] = 'Indicá un motivo cuando hay diferencia de animales.';
    }

    if (empty($errores)) {
        try {
            $db->beginTransaction();

            $stmt = $db->prepare("UPDATE pesada SET fecha = ?, peso_promedio = ?, animales_vistos = ?, hay_diferencia = ? WHERE id_pesada = ? AND id_usuario = ?");
            $stmt->execute([$fecha, $peso_promedio, $animales_vistos, $hay_diferencia, $id_pesada, $id_usuario]);

            // Ajustes pendientes
            $stmt = $db->prepare("SELECT id_ajuste FROM ajuste_animales_pendiente WHERE id_pesada = ? AND estado = 'PENDIENTE' ORDER BY fecha_creacion DESC LIMIT 1");
            $stmt->execute([$id_pesada]);
            $id_ajuste = $stmt->fetchColumn();

            if ($hay_diferencia) {
                if ($id_ajuste) {
                    $stmt = $db->prepare("UPDATE ajuste_animales_pendiente SET diferencia_animales = ?, motivo_operario = ? WHERE id_ajuste = ?");
                    $stmt->execute([$diferencia, $motivo_operario, (int)$id_ajuste]);
                } else {
                    $stmt = $db->prepare("INSERT INTO ajuste_animales_pendiente (id_pesada, id_tropa, diferencia_animales, motivo_operario, estado, fecha_creacion) VALUES (?, ?, ?, ?, 'PENDIENTE', NOW())");
                    $stmt->execute([$id_pesada, (int)$pesada['id_tropa'], $diferencia, $motivo_operario]);
                }
            } else {
                if ($id_ajuste) {
                    $stmt = $db->prepare("DELETE FROM ajuste_animales_pendiente WHERE id_ajuste = ?");
                    $stmt->execute([(int)$id_ajuste]);
                }
            }

            $db->commit();

            // Redirigir a donde estaba (solo rutas internas)
            $dest = safe_return_to($return_to, BASE_URL . '/admin/campo/historial.php');
            header('Location: ' . $dest);
            exit();

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $errores[] = 'No se pudo guardar la edición.';
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="card" style="margin-bottom: 1.5rem;">
    <div style="display:flex; align-items:center; justify-content:space-between; gap: 1rem; flex-wrap: wrap;">
        <div>
            <h1 class="card-title" style="margin:0;">✏️ Editar Pesada</h1>
            <p style="margin:.25rem 0 0 0; color: var(--text-muted);">
                Lote: <strong><?php echo htmlspecialchars($pesada['lote_nombre']); ?></strong>
            </p>
        </div>
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8'); ?>">← Volver</a>
    </div>
</div>

<?php if ($bloqueado): ?>
    <div class="card" style="background: #fff7ed; border-left: 5px solid #f59e0b; color: #92400e; padding: 1rem; margin-bottom: 1.5rem;">
        Este registro no puede editarse porque ya tiene un ajuste <strong><?php echo htmlspecialchars((string)$ajuste_estado); ?></strong>.
    </div>
<?php endif; ?>

<?php if (!empty($errores)): ?>
    <div class="card" style="background: #fee2e2; border-left: 5px solid var(--danger); color: #991b1b; padding: 1rem; margin-bottom: 1.5rem;">
        <strong style="display:block; margin-bottom:.5rem;">Se encontraron errores:</strong>
        <ul style="margin:0; padding-left:1.25rem;">
            <?php foreach ($errores as $e): ?>
                <li><?php echo htmlspecialchars($e); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card">
    <form method="POST" class="formulario">
        <?php echo csrf_input(); ?>
        <input type="hidden" name="guardar_edicion" value="1">

        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
            <div class="form-grupo">
                <label for="fecha">Fecha *</label>
                <input type="date" id="fecha" name="fecha" value="<?php echo htmlspecialchars((string)$pesada['fecha']); ?>" required <?php echo $bloqueado?'disabled':''; ?>>
            </div>
            <div class="form-grupo">
                <label for="peso_promedio">Peso promedio (kg) *</label>
                <input type="number" step="0.01" min="0" id="peso_promedio" name="peso_promedio" value="<?php echo htmlspecialchars((string)$pesada['peso_promedio']); ?>" required <?php echo $bloqueado?'disabled':''; ?>>
            </div>
            <div class="form-grupo">
                <label>Animales esperados</label>
                <input type="number" value="<?php echo (int)$pesada['animales_esperados']; ?>" disabled>
            </div>
            <div class="form-grupo">
                <label for="animales_vistos">Animales vistos *</label>
                <input type="number" min="0" id="animales_vistos" name="animales_vistos" value="<?php echo (int)$pesada['animales_vistos']; ?>" required <?php echo $bloqueado?'disabled':''; ?>>
            </div>
        </div>

        <div class="form-grupo" style="margin-top: 1rem;">
            <label for="motivo_operario">Motivo (solo si hay diferencia de animales)</label>
            <textarea id="motivo_operario" name="motivo_operario" rows="3" <?php echo $bloqueado?'disabled':''; ?>><?php echo htmlspecialchars((string)($ajuste_motivo ?? '')); ?></textarea>
            <small style="color: var(--text-muted);">Si la cantidad de animales vistos es distinta a la esperada, dejá un motivo.</small>
        </div>

        <div style="margin-top: 1.25rem; display:flex; gap: .75rem; flex-wrap: wrap;">
            <?php if (!$bloqueado): ?>
                <button type="submit" class="btn btn-primary">Guardar cambios</button>
            <?php endif; ?>
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8'); ?>">Cerrar</a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

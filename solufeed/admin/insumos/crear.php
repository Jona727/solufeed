<?php
// admin/insumos/crear.php - Actualizado a PDO
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Verificar permisos de administrador
verificarAdmin();

$page_title = "Crear Insumo";
$db = getConnection();

$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF obligatorio (corta el flujo si falla)
    if (!csrf_verify_post()) {
        $error = csrf_fail_response('Token expirado o inválido. Actualizá la página y reintentá.');
    }

    $nombre = trim($_POST['nombre'] ?? '');
    $tipo = $_POST['tipo'] ?? '';
    $porcentaje_ms = isset($_POST['porcentaje_ms']) ? floatval($_POST['porcentaje_ms']) : -1;

    // Validaciones (solo si no falló CSRF)
    if ($error === '') {
        if (empty($nombre)) {
            $error = "El nombre es obligatorio";
        } elseif ($porcentaje_ms < 0 || $porcentaje_ms > 100) {
            $error = "El porcentaje de MS debe estar entre 0 y 100";
        } elseif ($tipo === '') {
            $error = "El tipo de insumo es obligatorio";
        } else {
            // Verificar que no exista otro insumo ACTIVO con el mismo nombre
            $stmt = $db->prepare("SELECT COUNT(*) as total FROM insumo WHERE nombre = ? AND activo = 1");
            $stmt->execute([$nombre]);
            $existe = $stmt->fetch()['total'];

            if ($existe > 0) {
                $error = "Ya existe un insumo con ese nombre";
            } else {
                // Insertar el nuevo insumo
                $stmt = $db->prepare("
                    INSERT INTO insumo (nombre, tipo, porcentaje_ms, activo, fecha_creacion)
                    VALUES (?, ?, ?, 1, NOW())
                ");

                if ($stmt->execute([$nombre, $tipo, $porcentaje_ms])) {
                    $mensaje = "✅ Insumo creado exitosamente";
                    // Limpiar formulario
                    $_POST = [];
                } else {
                    $error = "Error al crear el insumo";
                }
            }
        }
    }
}

require_once '../../includes/header.php';
?>



<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 2rem;">
    <h1 style="font-weight: 800; color: var(--primary); margin: 0; letter-spacing: -1px;">🌾 Crear Nuevo Insumo</h1>
    <a href="listar.php" class="btn btn-secondary"><span>←</span> Cancelar</a>
</div>

<div class="card">
    <p style="color: var(--text-muted); margin: 0 0 2.5rem 0; font-weight: 500;">
        Ingresá los datos del nuevo insumo para el feedlot
    </p>

        <?php if ($mensaje): ?>
            <div class="card" style="background: #dcfce7; border-left: 5px solid var(--success); color: #166534; padding: 1rem; margin-bottom: 1.5rem;">
                <p style="margin-bottom: 0.5rem;"><?php echo $mensaje; ?></p>
                <a href="listar.php" style="color: #166534; font-weight: 700; text-decoration: underline;">Ver lista de insumos →</a>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="card" style="background: #fee2e2; border-left: 5px solid var(--danger); color: #991b1b; padding: 1rem; margin-bottom: 1.5rem;">
                ❌ <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <div style="background: var(--bg-main); padding: 1.5rem; border-radius: var(--radius); border-left: 4px solid var(--info); margin-bottom: 2rem;">
            <h4 style="color: var(--secondary); margin-bottom: 0.75rem; font-weight: 800;">💡 Información sobre Materia Seca (MS)</h4>
            <p style="font-size: 0.95rem; line-height: 1.6;">El % de MS es la proporción de nutrientes sólidos del insumo (excluyendo el agua).</p>
            <ul style="margin: 0.75rem 0 0 1.5rem; font-size: 0.9rem; color: var(--text-muted);">
                <li><strong>Granos secos:</strong> 85-90% MS</li>
                <li><strong>Forrajes conservados:</strong> 85-90% MS</li>
                <li><strong>Silajes:</strong> 30-40% MS</li>
                <li><strong>Forrajes verdes:</strong> 15-25% MS</li>
            </ul>
        </div>

        <form method="POST" action="">
            <?php echo csrf_input(); ?>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-top: 1.5rem;">
                <div class="form-group">
                    <label for="nombre">Nombre del Insumo *</label>
                    <input type="text" 
                           id="nombre" 
                           name="nombre" 
                           required
                           placeholder="Ej: Maíz grano"
                           value="<?php echo isset($_POST['nombre']) ? htmlspecialchars($_POST['nombre']) : ''; ?>">
                    <small style="color: var(--text-muted); font-size: 0.8rem;">Nombre descriptivo del insumo</small>
                </div>

                <div class="form-group">
                    <label for="tipo">Tipo de Insumo *</label>
                    <select id="tipo" name="tipo" required>
                        <option value="">-- Seleccionar tipo --</option>
                        <option value="GRANO" <?php echo (isset($_POST['tipo']) && $_POST['tipo'] == 'GRANO') ? 'selected' : ''; ?>>Grano</option>
                        <option value="FORRAJE" <?php echo (isset($_POST['tipo']) && $_POST['tipo'] == 'FORRAJE') ? 'selected' : ''; ?>>Forraje</option>
                        <option value="CONCENTRADO" <?php echo (isset($_POST['tipo']) && $_POST['tipo'] == 'CONCENTRADO') ? 'selected' : ''; ?>>Concentrado</option>
                        <option value="SUPLEMENTO" <?php echo (isset($_POST['tipo']) && $_POST['tipo'] == 'SUPLEMENTO') ? 'selected' : ''; ?>>Suplemento</option>
                    </select>
                    <small style="color: var(--text-muted); font-size: 0.8rem;">Categoría del insumo</small>
                </div>
            </div>

            <div class="form-group" style="max-width: 300px;">
                <label for="porcentaje_ms">Porcentaje de Materia Seca (%) *</label>
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <input type="number" 
                           id="porcentaje_ms" 
                           name="porcentaje_ms" 
                           step="0.1" 
                           min="0" 
                           max="100" 
                           required
                           placeholder="Ej: 86.5"
                           style="font-weight: 800; font-size: 1.2rem; text-align: center;"
                           value="<?php echo isset($_POST['porcentaje_ms']) ? $_POST['porcentaje_ms'] : ''; ?>">
                    <span style="font-weight: 800; color: var(--text-muted);">%</span>
                </div>
                <small style="color: var(--text-muted); font-size: 0.8rem;">Porcentaje de materia seca del insumo (0-100)</small>
            </div>

            <div style="display: flex; gap: 1rem; margin-top: 3rem; padding-top: 1.5rem; border-top: 1px solid var(--border);">
                <button type="submit" class="btn btn-primary btn-lg" style="flex: 1;">
                    <span>✅</span> Crear Nuevo Insumo
                </button>
                <a href="listar.php" class="btn btn-secondary btn-lg" style="flex: 0.3;">Cancelar</a>
            </div>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

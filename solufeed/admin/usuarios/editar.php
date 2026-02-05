<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

verificarAdmin();

$db = getConnection();

$me = (int)($_SESSION['usuario_id'] ?? 0);
$error = '';
$success = '';

// Obtener ID del usuario a editar
$id_usuario = (int)($_GET['id'] ?? 0);

if (!$id_usuario) {
    header('Location: listar.php');
    exit();
}

// Obtener datos del usuario
$stmt = $db->prepare("SELECT * FROM usuario WHERE id_usuario = ?");
$stmt->execute([$id_usuario]);
$usuario = $stmt->fetch();

// Un admin solo puede ver/editar su propia cuenta ADMIN y todas las cuentas CAMPO
if ($usuario && $usuario['tipo'] === 'ADMIN' && (int)$usuario['id_usuario'] !== (int)$me) {
    flash_set('error', 'No autorizado para acceder a otro administrador.');
    header('Location: listar.php');
    exit();
}

if (!$usuario) {
    header('Location: listar.php');
    exit();
}

// Obtener todos los lotes activos (REMOVIDO: Se hace en asignar_lotes.php)
// Obtener lotes asignados al usuario (REMOVIDO: Se hace en asignar_lotes.php)


// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $tipo = $_POST['tipo'] ?? 'CAMPO';
    $activo = isset($_POST['activo']) ? 1 : 0;
    $cambiar_password = isset($_POST['cambiar_password']);
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    
    // Validaciones
    if (!csrf_verify_post()) {
        $error = csrf_fail_response('Token expirado o inválido. Actualizá la página y reintentá.');
    } elseif (empty($nombre) || empty($email)) {
        $error = 'El nombre y email son obligatorios.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'El email no es válido.';
    } elseif ($cambiar_password && strlen($password) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres.';
    } elseif ($cambiar_password && $password !== $password_confirm) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        // Verificar si el email ya existe en otro usuario
        $stmt = $db->prepare("SELECT id_usuario FROM usuario WHERE email = ? AND id_usuario != ?");
        $stmt->execute([$email, $id_usuario]);
        
        if ($stmt->fetch()) {
            $error = 'Ya existe otro usuario con ese email.';
        } else {
            try {
                if ($cambiar_password) {
                    // Actualizar con nueva contraseña
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("
                        UPDATE usuario 
                        SET nombre = ?, email = ?, password_hash = ?, tipo = ?, activo = ?
                        WHERE id_usuario = ?
                    ");
                    $stmt->execute([$nombre, $email, $password_hash, $tipo, $activo, $id_usuario]);
                } else {
                    // Actualizar sin cambiar contraseña
                    $stmt = $db->prepare("
                        UPDATE usuario 
                        SET nombre = ?, email = ?, tipo = ?, activo = ?
                        WHERE id_usuario = ?
                    ");
                    $stmt->execute([$nombre, $email, $tipo, $activo, $id_usuario]);
                }

                // Limpieza de asignaciones si cambia de rol:
                // Si pasa de CAMPO a ADMIN, borrar asignaciones viejas por limpieza (opcional)
                if ($tipo === 'ADMIN') {
                    $stmt = $db->prepare("DELETE FROM usuario_tropa WHERE id_usuario = ?");
                    $stmt->execute([$id_usuario]);
                }
                
                $success = 'Usuario actualizado exitosamente.';
                
                // Recargar datos
                $stmt = $db->prepare("SELECT * FROM usuario WHERE id_usuario = ?");
                $stmt->execute([$id_usuario]);
                $usuario = $stmt->fetch();
                
            } catch (Exception $e) {
                log_event('ERROR', 'USUARIO_UPDATE_FAILED', ['error' => $e->getMessage()]);
                $error = 'Error al actualizar el usuario. Reintentá o contactá soporte.';
            }
        }
    }
}
?>
<?php
$page_title = 'Editar Usuario';
require_once '../../includes/header.php';
?>
<style>
        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .btn-back {
            padding: 0.75rem 1.5rem;
            background: #6b7280;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .btn-back:hover {
            background: #4b5563;
            transform: translateY(-1px);
        }

        .form-card {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            max-width: 600px;
            margin: 0 auto;
        }

        .info-banner {
            background: linear-gradient(135deg, #dbeafe 0%, #e0e7ff 100%);
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .info-banner .icon {
            font-size: 1.5rem;
        }

        .info-banner .text {
            flex: 1;
        }

        .info-banner .text strong {
            display: block;
            margin-bottom: 0.25rem;
        }

        .info-banner .text small {
            color: var(--text-muted);
        }

        .form-grid {
            display: grid;
            gap: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-group label {
            font-weight: 600;
            color: var(--text);
            font-size: 0.9rem;
        }

        .form-group label .required {
            color: var(--danger);
        }

        .form-group input,
        .form-group select {
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-family: inherit;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(44, 85, 48, 0.1);
        }

        .form-group small {
            color: var(--text-muted);
            font-size: 0.85rem;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 8px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }

        .checkbox-group label {
            margin: 0;
            cursor: pointer;
            font-weight: 500;
        }

        .tipo-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .tipo-option {
            position: relative;
        }

        .tipo-option input[type="radio"] {
            position: absolute;
            opacity: 0;
        }

        .tipo-option label {
            display: block;
            padding: 1.5rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .tipo-option input[type="radio"]:checked + label {
            border-color: var(--primary);
            background: rgba(44, 85, 48, 0.05);
        }

        .tipo-option label .icon {
            font-size: 2rem;
            display: block;
            margin-bottom: 0.5rem;
        }

        .tipo-option label .title {
            font-weight: 600;
            display: block;
            margin-bottom: 0.25rem;
        }

        .tipo-option label .description {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .password-section {
            border: 2px dashed #e2e8f0;
            padding: 1.5rem;
            border-radius: 8px;
            background: #fafafa;
        }

        .password-section.active {
            border-color: var(--primary);
            background: rgba(44, 85, 48, 0.02);
        }

        .password-fields {
            display: none;
            margin-top: 1rem;
            gap: 1rem;
        }

        .password-fields.active {
            display: grid;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 500;
        }

        .alert-error {
            background: #fee2e2;
            border-left: 4px solid var(--danger);
            color: #991b1b;
        }

        .alert-success {
            background: #d1fae5;
            border-left: 4px solid var(--success);
            color: #065f46;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .btn-submit {
            flex: 1;
            padding: 1rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-submit:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(44, 85, 48, 0.3);
        }

        .btn-cancel {
            padding: 1rem 2rem;
            background: #f1f5f9;
            color: var(--text);
            border: none;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s ease;
        }

        .btn-cancel:hover {
            background: #e2e8f0;
        }
    </style>
<div class="container">
        <div class="form-header">
            <div>
                <h1>✏️ Editar Usuario</h1>
                <p style="color: var(--text-muted); margin-top: 0.5rem;">
                    Modifica los datos del usuario
                </p>
            </div>
            <a href="listar.php" class="btn-back">
                <span>←</span>
                Volver
            </a>
        </div>

        <div class="form-card">
            <div class="info-banner">
                <div class="icon">ℹ️</div>
                <div class="text">
                    <strong>Editando Usuario</strong>
                    <small>Creado el <?php echo formatearFecha($usuario['fecha_creacion']); ?></small>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <strong>❌ Error:</strong> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <strong>✓ Éxito:</strong> <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="formUsuario">
                <?php echo csrf_input(); ?>
                <div class="form-grid">
                    <!-- Nombre -->
                    <div class="form-group">
                        <label>
                            Nombre Completo <span class="required">*</span>
                        </label>
                        <input type="text" name="nombre" required 
                               value="<?php echo htmlspecialchars($usuario['nombre']); ?>"
                               placeholder="Ej: Juan Pérez">
                    </div>

                    <!-- Email -->
                    <div class="form-group">
                        <label>
                            Correo Electrónico <span class="required">*</span>
                        </label>
                        <input type="email" name="email" required 
                               value="<?php echo htmlspecialchars($usuario['email']); ?>"
                               placeholder="usuario@ejemplo.com">
                        <small>Este será el usuario para iniciar sesión</small>
                    </div>

                    <!-- Tipo de Usuario -->
                    <div class="form-group">
                        <label>
                            Tipo de Usuario <span class="required">*</span>
                        </label>

                        <?php if ((int)$usuario['id_usuario'] === (int)$me): ?>
                            <div class="tipo-selector">
                                <div class="tipo-option">
                                    <input type="radio" name="tipo" value="ADMIN" id="tipo_admin"
                                           <?php echo $usuario['tipo'] === 'ADMIN' ? 'checked' : ''; ?>>
                                    <label for="tipo_admin">
                                        <span class="icon">👔</span>
                                        <span class="title">Administrador</span>
                                        <span class="description">Tu cuenta de administrador</span>
                                    </label>
                                </div>
                                <div class="tipo-option">
                                    <input type="radio" name="tipo" value="CAMPO" id="tipo_campo"
                                           <?php echo $usuario['tipo'] === 'CAMPO' ? 'checked' : ''; ?>>
                                    <label for="tipo_campo">
                                        <span class="icon">🧑‍🌾</span>
                                        <span class="title">Personal de Campo</span>
                                        <span class="description">Operaciones de campo</span>
                                    </label>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="card" style="padding: 0.9rem; background: var(--bg-main); border: 1px solid var(--border);">
                                <div style="display:flex; align-items:center; gap:.6rem; font-weight:800;">
                                    <span style="font-size:1.35rem;">🧑‍🌾</span>
                                    Personal de Campo
                                </div>
                                <div style="color: var(--text-muted); font-weight:600; margin-top:.25rem;">
                                    Por consistencia, no se permite promover usuarios a administrador desde esta pantalla.
                                </div>
                            </div>
                            <input type="hidden" name="tipo" value="CAMPO">
                        <?php endif; ?>
                    </div>

                    <!-- Asignación de Lotes: MOVIDO A VISTA DEDICADA asignar_lotes.php -->

                    <!-- Cambiar Contraseña -->
                    <div class="password-section" id="passwordSection">
                        <div class="checkbox-group" style="background: transparent; padding: 0;">
                            <input type="checkbox" name="cambiar_password" id="cambiar_password">
                            <label for="cambiar_password">
                                <strong>🔑 Cambiar Contraseña</strong>
                                <br>
                                <small style="color: var(--text-muted);">
                                    Marca esta opción solo si deseas establecer una nueva contraseña
                                </small>
                            </label>
                        </div>

                        <div class="password-fields" id="passwordFields">
                            <div class="form-group">
                                <label>Nueva Contraseña</label>
                                <input type="password" name="password" id="password" 
                                       minlength="6" placeholder="Mínimo 6 caracteres">
                            </div>

                            <div class="form-group">
                                <label>Confirmar Nueva Contraseña</label>
                                <input type="password" name="password_confirm" id="password_confirm" 
                                       minlength="6" placeholder="Repite la contraseña">
                            </div>
                        </div>
                    </div>

                    <!-- Estado -->
                    <div class="checkbox-group">
                        <input type="checkbox" name="activo" id="activo" value="1"
                               <?php echo $usuario['activo'] ? 'checked' : ''; ?>>
                        <label for="activo">
                            <strong>Usuario Activo</strong>
                            <br>
                            <small style="color: var(--text-muted);">
                                Si está desactivado, no podrá iniciar sesión
                            </small>
                        </label>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-submit">
                        ✓ Guardar Cambios
                    </button>
                    <a href="listar.php" class="btn-cancel">
                        ✕ Cancelar
                    </a>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Toggle password fields
        const cambiarPasswordCheckbox = document.getElementById('cambiar_password');
        const passwordSection = document.getElementById('passwordSection');
        const passwordFields = document.getElementById('passwordFields');
        const passwordInput = document.getElementById('password');
        const confirmInput = document.getElementById('password_confirm');

        cambiarPasswordCheckbox.addEventListener('change', function() {
            if (this.checked) {
                passwordSection.classList.add('active');
                passwordFields.classList.add('active');
                passwordInput.required = true;
                confirmInput.required = true;
            } else {
                passwordSection.classList.remove('active');
                passwordFields.classList.remove('active');
                passwordInput.required = false;
                confirmInput.required = false;
                passwordInput.value = '';
                confirmInput.value = '';
            }
        });

        // Validar que las contraseñas coincidan
        document.getElementById('formUsuario').addEventListener('submit', function(e) {
            if (cambiarPasswordCheckbox.checked) {
                if (passwordInput.value !== confirmInput.value) {
                    e.preventDefault();
                    alert('❌ Las contraseñas no coinciden');
                    confirmInput.focus();
                }
            }
        });
    </script>

<?php require_once '../../includes/footer.php'; ?>

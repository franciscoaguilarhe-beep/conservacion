<?php
/**
 * Módulo de Configuración Inicial (Setup)
 * Accesible para SuperAdmin (unidades y jefes) y Jefes de Conservación (áreas y personal)
 */
require_once __DIR__ . '/../../config/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userRol = $_SESSION['user_rol'];
if (!in_array($userRol, ['SuperAdmin', 'Jefe'])) {
    die('Acceso denegado: Esta sección es exclusiva para el SuperAdmin y Jefes de Conservación.');
}

$db = Database::getConnection();
$msg = '';
$error = '';
$userUnidadId = $_SESSION['user_unidad_id'];

// Obtener lista de unidades para selector (si es SuperAdmin)
$unidades = $db->query("SELECT * FROM unidades ORDER BY nombre_unidad ASC")->fetchAll();

// Si es Jefe, su unidad de trabajo es fija
$currentUnidadId = ($userRol === 'Jefe') ? $userUnidadId : (filter_input(INPUT_GET, 'unidad_id', FILTER_VALIDATE_INT) ?? ($unidades[0]['id'] ?? 1));

// Acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_SPECIAL_CHARS);

    // 1. Alta de Unidad Médica (SuperAdmin)
    if ($action === 'crear_unidad' && $userRol === 'SuperAdmin') {
        $nombre = strtoupper(trim(filter_input(INPUT_POST, 'nombre_unidad', FILTER_SANITIZE_SPECIAL_CHARS)));
        $clave = strtoupper(trim(filter_input(INPUT_POST, 'clave_unidad', FILTER_SANITIZE_SPECIAL_CHARS)));
        $ubicacion = trim(filter_input(INPUT_POST, 'ubicacion', FILTER_SANITIZE_SPECIAL_CHARS));

        if ($nombre && $clave) {
            try {
                $stmt = $db->prepare("INSERT INTO unidades (nombre_unidad, clave_unidad, ubicacion) VALUES (?, ?, ?)");
                $stmt->execute([$nombre, $clave, $ubicacion]);
                $msg = "Unidad médica {$clave} creada exitosamente.";
                $unidades = $db->query("SELECT * FROM unidades ORDER BY nombre_unidad ASC")->fetchAll();
            } catch (Exception $e) {
                $error = "Error al crear la unidad: " . $e->getMessage();
            }
        }
    }

    // 2. Alta de Área Global Maestro
    if ($action === 'crear_area') {
        $nombreArea = strtoupper(trim(filter_input(INPUT_POST, 'nombre_area', FILTER_SANITIZE_SPECIAL_CHARS)));
        if ($nombreArea) {
            try {
                $stmt = $db->prepare("INSERT INTO areas_maestro (nombre_area) VALUES (?) ON DUPLICATE KEY UPDATE nombre_area = VALUES(nombre_area)");
                $stmt->execute([$nombreArea]);
                $nuevoAreaId = $db->lastInsertId();
                if ($nuevoAreaId && $currentUnidadId) {
                    $stmtPiv = $db->prepare("INSERT IGNORE INTO unidades_areas (id_unidad, id_area, estatus) VALUES (?, ?, 'Habilitado')");
                    $stmtPiv->execute([$currentUnidadId, $nuevoAreaId]);
                }
                $msg = "Área '{$nombreArea}' agregada al catálogo global.";
            } catch (Exception $e) {
                $error = "Error al crear el área: " . $e->getMessage();
            }
        }
    }

    // 3. Encendido / Apagado de Áreas por Unidad Médica (Interruptores)
    if ($action === 'actualizar_areas_unidad') {
        $areasHabilitadas = $_POST['areas_habilitadas'] ?? [];
        try {
            $db->beginTransaction();
            // Primero deshabilitar todas para esta unidad
            $stmtReset = $db->prepare("UPDATE unidades_areas SET estatus = 'Deshabilitado' WHERE id_unidad = ?");
            $stmtReset->execute([$currentUnidadId]);

            // Habilitar las seleccionadas
            $stmtHabil = $db->prepare("
                INSERT INTO unidades_areas (id_unidad, id_area, estatus) 
                VALUES (?, ?, 'Habilitado') 
                ON DUPLICATE KEY UPDATE estatus = 'Habilitado'
            ");
            foreach ($areasHabilitadas as $areaId) {
                $stmtHabil->execute([$currentUnidadId, (int)$areaId]);
            }
            $db->commit();
            $msg = "Áreas de la unidad médica actualizadas correctamente.";
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error al actualizar áreas: " . $e->getMessage();
        }
    }

    // 4. Alta de Usuario Administrativo (Subjefe o AUO para Jefes, o Jefe para SuperAdmin)
    if ($action === 'crear_usuario') {
        $username = trim(filter_input(INPUT_POST, 'username', FILTER_SANITIZE_SPECIAL_CHARS));
        $nombreCompleto = trim(filter_input(INPUT_POST, 'nombre_completo', FILTER_SANITIZE_SPECIAL_CHARS));
        $password = trim(filter_input(INPUT_POST, 'password', FILTER_DEFAULT));
        $rol = filter_input(INPUT_POST, 'rol', FILTER_SANITIZE_SPECIAL_CHARS);
        $unidadAsignada = ($userRol === 'SuperAdmin') ? filter_input(INPUT_POST, 'unidad_usuario_id', FILTER_VALIDATE_INT) : $userUnidadId;

        // Validar permisos de rol
        if ($userRol === 'Jefe' && !in_array($rol, ['Subjefe', 'AUO'])) {
            $error = "Como Jefe de Conservación únicamente puede crear cuentas de Subjefe y AUO.";
        } elseif ($username && $password && $nombreCompleto) {
            try {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $db->prepare("
                    INSERT INTO usuarios_admin (username, password_hash, nombre_completo, rol, unidad_id) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$username, $hash, $nombreCompleto, $rol, $unidadAsignada]);
                $msg = "Usuario '{$username}' ({$rol}) creado exitosamente.";
            } catch (Exception $e) {
                $error = "Error al crear usuario: " . $e->getMessage();
            }
        }
    }
}

// Consultar todas las áreas globales y su estatus en la unidad actual
$stmtAreas = $db->prepare("
    SELECT am.id, am.nombre_area, 
           COALESCE(ua.estatus, 'Deshabilitado') AS estatus_unidad
    FROM areas_maestro am
    LEFT JOIN unidades_areas ua ON am.id = ua.id_area AND ua.id_unidad = ?
    ORDER BY am.nombre_area ASC
");
$stmtAreas->execute([$currentUnidadId]);
$areasList = $stmtAreas->fetchAll();

// Consultar usuarios de esta unidad
$stmtUsers = $db->prepare("
    SELECT u.*, un.clave_unidad 
    FROM usuarios_admin u
    LEFT JOIN unidades un ON u.unidad_id = un.id
    WHERE u.unidad_id = ? OR (? = 1 AND u.rol = 'SuperAdmin')
    ORDER BY u.rol ASC, u.nombre_completo ASC
");
$stmtUsers->execute([$currentUnidadId, ($userRol === 'SuperAdmin' ? 1 : 0)]);
$usuariosList = $stmtUsers->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración Inicial (Setup) - Conservación IMSS</title>
        <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="icon" type="image/png" sizes="32x32" href="../favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="../favicon-16.png">
    <link rel="icon" type="image/png" href="../conservacion.png">
    <link rel="apple-touch-icon" sizes="180x180" href="../apple-touch-icon.png">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#0C6839">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Conservación IMSS">
</head>
<body>
    <header class="navbar">
        <div class="nav-brand">
            <span style="font-size: 1.5rem;">⚙�</span>
            <div>
                <div style="font-size:0.75rem; color:var(--text-muted); font-weight:600; text-transform:uppercase;">Módulo de Configuración y Catálogos</div>
                <div>IMSS Conservación • Setup</div>
            </div>
        </div>
        <div>
            <a href="dashboard.php" class="btn btn-primary" style="font-size:0.85rem;">← Regresar al Dashboard</a>
        </div>
    </header>

    <main class="container" style="max-width: 1000px; margin-top: 1.5rem;">
        <?php if ($msg): ?>
            <div style="background: rgba(16, 185, 129, 0.2); border: 1px solid #10B981; color: #6EE7B7; padding: 0.875rem; border-radius: 0.5rem; margin-bottom: 1.5rem;">
                ✓ <?= h($msg) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div style="background: rgba(239, 68, 68, 0.2); border: 1px solid #EF4444; color: #FCA5A5; padding: 0.875rem; border-radius: 0.5rem; margin-bottom: 1.5rem;">
                ✖ <?= h($error) ?>
            </div>
        <?php endif; ?>

        <!-- Sección 1: Interruptores de Áreas Habilitadas por Unidad -->
        <div class="card" style="margin-bottom: 2rem;">
            <div class="card-header">
                <div>
                    <h3 style="font-size: 1.2rem; font-weight: 800; color: #FFFFFF;">
                        Encendido / Apagado de Departamentos por Unidad Médica
                    </h3>
                    <p style="font-size: 0.85rem; color: var(--text-muted);">
                        Habilite únicamente las áreas existentes en esta clínica para que aparezcan en el formulario público.
                    </p>
                </div>
            </div>

            <form method="POST" action="setup.php">
                <input type="hidden" name="action" value="actualizar_areas_unidad">
                <input type="hidden" name="unidad_id" value="<?= (int)$currentUnidadId ?>">

                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 0.75rem; margin-bottom: 1.5rem;">
                    <?php foreach ($areasList as $area): ?>
                        <label style="display: flex; align-items: center; gap: 0.6rem; background: var(--dark-card); padding: 0.75rem; border-radius: 0.5rem; cursor: pointer; border: 1px solid var(--dark-border);">
                            <input type="checkbox" name="areas_habilitadas[]" value="<?= (int)$area['id'] ?>" <?= $area['estatus_unidad'] === 'Habilitado' ? 'checked' : '' ?> style="width:1.2rem; height:1.2rem; accent-color: var(--imss-green-light);">
                            <span style="font-size: 0.85rem; font-weight: 600;"><?= h($area['nombre_area']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div style="text-align: right;">
                    <button type="submit" class="btn btn-primary">
                        💾 Guardar Configuración de Áreas
                    </button>
                </div>
            </form>
        </div>

        <!-- Sección 2: Alta de Nueva Área Global y Nuevos Usuarios -->
        <div class="grid grid-cols-2" style="margin-bottom: 2rem;">
            <!-- Agregar Área Global -->
            <div class="card">
                <h4 style="font-size: 1.05rem; font-weight: 700; color: #FFFFFF; margin-bottom: 0.5rem;">
                    ➕ Registrar Nueva Área Global
                </h4>
                <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1rem;">
                    Se agregará al catálogo maestro disponible para todas las unidades médicas.
                </p>
                <form method="POST" action="setup.php">
                    <input type="hidden" name="action" value="crear_area">
                    <div class="form-group">
                        <label for="nombre_area">Nombre del Área / Servicio</label>
                        <input type="text" id="nombre_area" name="nombre_area" placeholder="Ej. DIÁLISIS, TERAPIA INTENSIVA, COCINA" required>
                    </div>
                    <button type="submit" class="btn btn-secondary" style="width: 100%;">
                        Guardar Área en Catálogo
                    </button>
                </form>
            </div>

            <!-- Crear Cuenta de Subjefe o AUO -->
            <div class="card">
                <h4 style="font-size: 1.05rem; font-weight: 700; color: #FFFFFF; margin-bottom: 0.5rem;">
                    👤 Crear Cuenta Administrativa
                </h4>
                <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1rem;">
                    <?= $userRol === 'SuperAdmin' ? 'Crear Jefes de Conservación o Administradores' : 'Crear cuentas para Subjefe o AUO de su clínica' ?>
                </p>
                <form method="POST" action="setup.php">
                    <input type="hidden" name="action" value="crear_usuario">
                    <div class="form-group">
                        <label for="nombre_completo">Nombre Completo</label>
                        <input type="text" id="nombre_completo" name="nombre_completo" placeholder="Ej. Roberto Gómez" required>
                    </div>
                    <div class="grid grid-cols-2">
                        <div class="form-group">
                            <label for="username">Usuario (login)</label>
                            <input type="text" id="username" name="username" placeholder="roberto.gomez" required>
                        </div>
                        <div class="form-group">
                            <label for="password">Contraseña Inicial</label>
                            <input type="password" id="password" name="password" placeholder="••••••••" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="rol">Rol Asignado</label>
                        <select id="rol" name="rol" required>
                            <?php if ($userRol === 'SuperAdmin'): ?>
                                <option value="Jefe">Jefe de Conservación</option>
                                <option value="Subjefe">Subjefe</option>
                                <option value="AUO">AUO (Atención y Operación)</option>
                                <option value="SuperAdmin">SuperAdmin Global</option>
                            <?php else: ?>
                                <option value="Subjefe">Subjefe de Conservación</option>
                                <option value="AUO">AUO (Atención y Operación)</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-secondary" style="width: 100%;">
                        Crear Usuario
                    </button>
                </form>
            </div>
        </div>

        <?php if ($userRol === 'SuperAdmin'): ?>
            <!-- Sección Exclusiva SuperAdmin: Crear Unidad Médica -->
            <div class="card" style="margin-bottom: 2rem; border-color: var(--imss-gold);">
                <h4 style="font-size: 1.1rem; font-weight: 800; color: var(--imss-gold); margin-bottom: 0.5rem;">
                    ðŸ¥ Alta de Centro de Trabajo / Unidad Médica (SuperAdmin)
                </h4>
                <form method="POST" action="setup.php">
                    <input type="hidden" name="action" value="crear_unidad">
                    <div class="grid grid-cols-3">
                        <div class="form-group">
                            <label for="nombre_unidad">Nombre Oficial de la Unidad</label>
                            <input type="text" id="nombre_unidad" name="nombre_unidad" placeholder="Ej. UNIDAD DE MEDICINA FAMILIAR No.34" required>
                        </div>
                        <div class="form-group">
                            <label for="clave_unidad">Clave / Siglas (Prefijo de Folio)</label>
                            <input type="text" id="clave_unidad" name="clave_unidad" placeholder="Ej. UMF34" required>
                        </div>
                        <div class="form-group">
                            <label for="ubicacion">Ubicación (Ciudad, Estado)</label>
                            <input type="text" id="ubicacion" name="ubicacion" value="La Paz, B.C.S." required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        Registrar Nueva Unidad Médica
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>

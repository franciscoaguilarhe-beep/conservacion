<?php
/**
 * Portal Público Unificado: Reportes y Consulta de Estatus - Conservación IMSS
 */
require_once __DIR__ . '/../config/config.php';

$activeUnit = getActiveUnit();
$db = Database::getConnection();

$unidades = $db->query("SELECT id, clave_unidad, nombre_unidad FROM unidades WHERE estatus='Activo' ORDER BY clave_unidad")->fetchAll();
// --- Lógica de consulta de estatus (para tab "Consultar Estatus") ---
$folioParam = filter_input(INPUT_GET, 'folio', FILTER_SANITIZE_SPECIAL_CHARS);
$tabActivo = filter_input(INPUT_GET, 'tab', FILTER_SANITIZE_SPECIAL_CHARS);
$ticket = null;

if ($folioParam) {
    $tabActivo = 'estatus'; // Forzar tab de estatus si se consulta un folio
    $stmt = $db->prepare("
        SELECT r.*, a.nombre_area, u.nombre_unidad, u.clave_unidad, u.ubicacion,
               p.nombre AS nombre_tecnico, p.categoria AS categoria_tecnico, p.turno AS turno_tecnico
        FROM reportes r
        INNER JOIN unidades u ON r.unidad_id = u.id
        INNER JOIN areas_maestro a ON r.area_id = a.id
        LEFT JOIN personal_operativo p ON r.tecnico_asignado_id = p.id
        WHERE r.folio = ?
        LIMIT 1
    ");
    $stmt->execute([trim($folioParam)]);
    $ticket = $stmt->fetch();
}

$isTabEstatus = ($tabActivo === 'estatus');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal de Solicitudes de Conservación</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16.png">
    <link rel="icon" type="image/png" href="conservacion.png">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0C6839">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Conservación IMSS">
    <style>
        body {
            background-color: #f5f5f5;
            color: #333;
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
        }

        /* ===== HEADER ===== */
        .header-custom {
            display: flex;
            align-items: center;
            padding: 1rem 3rem;
            background-color: #f5f5f5;
        }
        .header-left {
            display: flex;
            align-items: center;
        }
        .header-left h1 {
            font-size: 1.6rem;
            font-weight: 700;
            color: #0d4d35;
            margin: 0 0 0 1.5rem;
        }

        /* ===== TAB BAR ===== */
        .tab-bar {
            background-color: #0b2b1d;
            display: flex;
            padding: 0 3rem;
            align-items: center;
            border-bottom: 2px solid #081d13;
        }
        .tab {
            padding: 1rem 1.5rem;
            color: #8fa398;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1rem;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            transition: color 0.2s;
            background: none;
            border-top: none;
            border-left: none;
            border-right: none;
        }
        .tab:hover {
            color: white;
        }
        .tab.active {
            color: #65a30d;
            border-bottom-color: #65a30d;
        }
        .tab-separator {
            width: 1px;
            height: 24px;
            background-color: #3b5046;
            margin: 0 0.5rem;
        }

        /* ===== CONTENEDOR PRINCIPAL ===== */
        .main-container {
            max-width: 960px;
            margin: 1rem auto;
            background-color: #1a1a1a;
            border-radius: 12px;
            padding: 1.25rem 2rem;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            color: white;
        }

        /* ===== FORMULARIO ===== */
        .form-label {
            font-size: 0.88rem;
            font-weight: 700;
            color: white;
            margin-bottom: 0.35rem;
            display: block;
        }
        .form-input {
            width: 100%;
            background-color: #242424;
            border: 1px solid #444;
            color: white;
            padding: 0.5rem 0.75rem;
            border-radius: 6px;
            font-size: 0.9rem;
            box-sizing: border-box;
        }
        .form-input:focus {
            outline: none;
            border-color: #3b8c4c;
        }
        .btn-enviar {
            background-color: #2b7a4b;
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: bold;
            width: 100%;
            cursor: pointer;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            transition: background-color 0.2s;
        }
        .btn-enviar:hover {
            background-color: #23633d;
        }

        /* ===== ESTATUS (Búsqueda) ===== */
        .search-title {
            font-size: 2rem;
            font-weight: 700;
            margin: 0 0 0.5rem 0;
            text-align: center;
        }
        .title-underline {
            width: 120px;
            height: 3px;
            background-color: #3b8c4c;
            margin: 0 auto 2rem auto;
        }
        .search-form {
            display: flex;
            gap: 1rem;
            justify-content: center;
            max-width: 600px;
            margin: 1.5rem auto 2rem auto;
        }
        .input-wrapper {
            position: relative;
            flex: 1;
        }
        .input-wrapper svg {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #888;
        }
        .search-input {
            width: 100%;
            background-color: #242424;
            border: 1px solid #444;
            color: white;
            padding: 1rem 1rem 1rem 3rem;
            border-radius: 8px;
            font-size: 1.1rem;
            box-sizing: border-box;
        }
        .search-input:focus {
            outline: none;
            border-color: #3b8c4c;
        }
        .btn-search {
            background-color: #3b8c4c;
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: bold;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: background-color 0.2s;
        }
        .btn-search:hover {
            background-color: #2b7a4b;
        }

        /* ===== STEPPER ===== */
        .stepper-container {
            border-top: 1px solid #333;
            padding-top: 3rem;
        }
        .stepper-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 3rem;
            text-align: center;
        }
        .custom-stepper {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            position: relative;
            max-width: 600px;
            margin: 0 auto;
        }
        .custom-stepper::before {
            content: '';
            position: absolute;
            top: 35px;
            left: 10%;
            right: 10%;
            height: 4px;
            background-color: #333;
            z-index: 1;
        }
        .custom-stepper-progress {
            position: absolute;
            top: 35px;
            left: 10%;
            height: 4px;
            background-color: #65a30d;
            z-index: 2;
            transition: width 0.3s;
        }
        .step-circle-wrapper {
            position: relative;
            z-index: 3;
            background-color: #1a1a1a;
            padding: 0 10px;
        }
        .step-custom {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
            width: 120px;
        }
        .custom-circle {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            border: 3px solid #555;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #1a1a1a;
            color: #888;
        }
        .step-custom.completed .custom-circle,
        .step-custom.active .custom-circle {
            border-color: #65a30d;
            color: white;
            box-shadow: 0 0 15px rgba(101, 163, 13, 0.4);
        }
        .step-custom.active .custom-circle {
            background-color: rgba(101, 163, 13, 0.1);
        }
        .custom-label {
            font-weight: bold;
            font-size: 1.1rem;
            color: #ccc;
        }
        .step-custom.completed .custom-label,
        .step-custom.active .custom-label {
            color: white;
        }
        .custom-date {
            font-size: 0.85rem;
            color: #888;
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
            align-items: center;
        }
        @media (max-width: 768px) {
            .header-custom { padding: 0.75rem 1rem; }
            .header-left h1 { font-size: 1.15rem; margin-left: 0.75rem; }
            .tab-bar { padding: 0 1rem; justify-content: center; }
            .tab { padding: 0.75rem 1rem; font-size: 0.9rem; }
            .main-container { margin: 1rem; padding: 1.5rem 1rem; }
            .form-grid-3 { grid-template-columns: 1fr !important; }
        }
    </style>
</head>
<body>

    <!-- ===== HEADER CON LOGO + BOTÓN LOGIN ESCONDIDO ===== -->
    <header class="header-custom">
        <div class="header-left">
            <a href="admin/login.php" style="text-decoration:none; display:flex; align-items:center; gap:0.5rem; font-weight:bold; color:#0d4d35; font-size:1.5rem; line-height:1.1; cursor:pointer;" title="Acceso Administrativo">
                <img src="../Images/IMSS.png" onerror="this.src='IMSS.png'" alt="Logo IMSS" style="width: 50px; height: auto;">
                <div>
                    <div>IMSS</div>
                    <div style="font-size:0.55rem; color:#333; font-weight:normal;">INSTITUTO MEXICANO<br>DEL SEGURO SOCIAL</div>
                </div>
            </a>
            <h1>Portal de Solicitudes de Conservación</h1>
        </div>
    </header>

    <!-- ===== TAB BAR (Crear Reporte | Consultar Estatus) ===== -->
    <div class="tab-bar">
        <button type="button" class="tab <?= !$isTabEstatus ? 'active' : '' ?>" onclick="cambiarTab('reporte')">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
            Crear Reporte
        </button>
        <div class="tab-separator"></div>
        <button type="button" class="tab <?= $isTabEstatus ? 'active' : '' ?>" onclick="cambiarTab('estatus')">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
            Consultar Estatus
        </button>
    </div>

    <!-- ===== SECCIÓN: CREAR REPORTE ===== -->
    <main id="section-reporte" class="main-container" style="<?= $isTabEstatus ? 'display:none;' : '' ?>">
        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
            <svg width="26" height="26" fill="none" stroke="#3b8c4c" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path></svg>
            <h2 style="color: white; font-size: 1.25rem; margin: 0;">Reportar Falla o Servicio</h2>
        </div>

        <form id="formNuevoReporte">
            <!-- Fila 1: Unidad y Área (2 columnas) -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 0.85rem;">
                <div>
                    <label class="form-label">1. Unidad Médica o Administrativa *</label>
                    <select name="unidad_id" id="unidadSelect" class="form-input" required onchange="cargarAreas()">
                        <option value="">-- SELECCIONE UNA UNIDAD --</option>
                        <?php foreach($unidades as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= h($u['clave_unidad'] . ' - ' . $u['nombre_unidad']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">2. Área o Ubicación *</label>
                    <select name="area_id" id="areaSelect" class="form-input" required>
                        <option value="">-- SELECCIONE PRIMERO UNA UNIDAD --</option>
                    </select>
                </div>
            </div>

            <!-- Fila 2: Descripción del Trabajo / Falla -->
            <div style="margin-bottom: 0.85rem;">
                <label class="form-label">3. Descripción del Trabajo / Falla *</label>
                <textarea id="descripcion_falla" name="descripcion_falla" rows="2" class="form-input" placeholder="Describa detalladamente el trabajo a realizar o la falla presentada..." required></textarea>
            </div>

            <!-- Fila 3: Datos del Solicitante (4 columnas compactas) -->
            <div style="margin-bottom: 0.85rem;">
                <label class="form-label">4. Datos del Solicitante</label>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1.2fr 1.3fr; gap: 0.75rem;">
                    <div>
                        <label style="color: #bbb; font-size: 0.78rem; margin-bottom: 0.2rem; display: block;">Apellido Paterno</label>
                        <input type="text" id="solicitante_ap_paterno" name="solicitante_ap_paterno" class="form-input" placeholder="Primer apellido">
                    </div>
                    <div>
                        <label style="color: #bbb; font-size: 0.78rem; margin-bottom: 0.2rem; display: block;">Apellido Materno</label>
                        <input type="text" id="solicitante_ap_materno" name="solicitante_ap_materno" class="form-input" placeholder="Segundo apellido">
                    </div>
                    <div>
                        <label style="color: #bbb; font-size: 0.78rem; margin-bottom: 0.2rem; display: block;">Nombre(s) *</label>
                        <input type="text" id="solicitante_nombres" name="solicitante_nombres" class="form-input" placeholder="Nombre(s)" required>
                    </div>
                    <div>
                        <label style="color: #bbb; font-size: 0.78rem; margin-bottom: 0.2rem; display: block;">Categoría / Puesto</label>
                        <input type="text" id="cargo_solicitante" name="cargo_solicitante" class="form-input" placeholder="Ej. MÉDICO, ENFERMERA, JEFE">
                    </div>
                </div>
            </div>

            <!-- Fila 4: Observaciones -->
            <div style="margin-bottom: 1.15rem;">
                <label class="form-label">5. Observaciones (Opcional)</label>
                <textarea id="observaciones" name="observaciones" rows="1" class="form-input" placeholder="Información adicional (opcional)..." style="resize:vertical;"></textarea>
            </div>

            <button type="submit" class="btn-enviar">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"></path></svg>
                [ ENVIAR REPORTE ]
            </button>
        </form>
    </main>

    <!-- ===== SECCIÓN: CONSULTAR ESTATUS ===== -->
    <main id="section-estatus" class="main-container" style="<?= !$isTabEstatus ? 'display:none;' : '' ?> text-align: center;">
        <h2 class="search-title">Consultar Estatus de su Reporte</h2>
        <div class="title-underline"></div>

        <p style="color: #ccc; margin-bottom: 1rem;">Ingrese su número de folio</p>

        <form method="GET" action="index.php" class="search-form">
            <input type="hidden" name="tab" value="estatus">
            <div class="input-wrapper">
                <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"></path></svg>
                <input type="text" id="folio" name="folio" class="search-input" value="<?= h($folioParam) ?>" placeholder="Ej. UMF40-2600754" required>
            </div>
            <button type="submit" class="btn-search">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                Buscar
            </button>
        </form>

        <?php if ($folioParam && !$ticket): ?>
            <div style="background-color: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 8px; padding: 2rem; margin-top: 1rem;">
                <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">⚠️</div>
                <h3 style="color: #F87171; font-size: 1.25rem; font-weight: 700; margin-bottom: 0.5rem;">
                    No se encontró ningún reporte con el folio "<?= h($folioParam) ?>"
                </h3>
                <p style="color: #aaa; font-size: 0.9rem;">
                    Verifique que el formato sea correcto (ej. UMF40-2600754).
                </p>
            </div>
        <?php elseif ($ticket): ?>
            <?php
                $estatus = $ticket['estatus'];
                $isAsignado = in_array($estatus, ['Asignado', 'Concluido']);
                $isConcluido = ($estatus === 'Concluido');

                $progressWidth = '0%';
                if ($isAsignado) $progressWidth = '50%';
                if ($isConcluido) $progressWidth = '100%';
            ?>
            <div class="stepper-container">
                <div class="stepper-title">Estatus de su solicitud</div>

                <div class="custom-stepper">
                    <div class="custom-stepper-progress" style="width: <?= $progressWidth ?>;"></div>

                    <!-- Paso 1 -->
                    <div class="step-custom completed">
                        <div class="step-circle-wrapper">
                            <div class="custom-circle">
                                <svg width="36" height="36" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                            </div>
                        </div>
                        <div class="custom-label">1. Recibido</div>
                        <div class="custom-date">
                            <span>📅 <?= date('d/m/Y', strtotime($ticket['fecha_recepcion'])) ?></span>
                            <span>🕒 <?= date('h:i A', strtotime($ticket['fecha_recepcion'])) ?></span>
                        </div>
                    </div>

                    <!-- Paso 2 -->
                    <div class="step-custom <?= $isAsignado ? 'completed' : '' ?>">
                        <div class="step-circle-wrapper">
                            <div class="custom-circle">
                                <svg width="32" height="32" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                            </div>
                        </div>
                        <div class="custom-label">2. Asignado</div>
                        <div class="custom-date">
                            <?php if ($ticket['fecha_asignacion']): ?>
                                <span>📅 <?= date('d/m/Y', strtotime($ticket['fecha_asignacion'])) ?></span>
                                <span>🕒 <?= date('h:i A', strtotime($ticket['fecha_asignacion'])) ?></span>
                            <?php else: ?>
                                <span>📅 Pendiente</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Paso 3 -->
                    <div class="step-custom <?= $isConcluido ? 'completed' : '' ?>">
                        <div class="step-circle-wrapper">
                            <div class="custom-circle">
                                <svg width="32" height="32" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            </div>
                        </div>
                        <div class="custom-label">3. Concluido</div>
                        <div class="custom-date">
                            <?php if ($ticket['fecha_conclusion']): ?>
                                <span>📅 <?= date('d/m/Y', strtotime($ticket['fecha_conclusion'])) ?></span>
                                <span>🕒 <?= date('h:i A', strtotime($ticket['fecha_conclusion'])) ?></span>
                            <?php else: ?>
                                <span>📅 Pendiente</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 3rem;">
                    <a href="reporte_pdf.php?folio=<?= urlencode($ticket['folio']) ?>" target="_blank" class="btn-search" style="display: inline-flex; background-color: #333; text-decoration: none;">
                        🖨️ Ver Detalles / Imprimir Orden
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <!-- ===== MODAL DE ÉXITO ===== -->
    <div id="modalExitoReporte" class="modal-overlay">
        <div class="modal-content" style="background-color: #1a1a1a; text-align: center; border-radius: 12px; padding: 3rem; max-width: 500px; color: white;">

            <div style="display: flex; justify-content: center; align-items: center; margin-bottom: 1.5rem;">
                <div style="position: relative;">
                    <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="#65a30d" stroke-width="2">
                        <circle cx="12" cy="12" r="10" stroke-width="2" stroke="#65a30d"></circle>
                        <path d="M8 12.5l3 3 5-6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
                    </svg>
                    <div style="position: absolute; top: 10px; left: -15px; width: 10px; height: 2px; background: #65a30d; transform: rotate(20deg);"></div>
                    <div style="position: absolute; top: 30px; left: -20px; width: 10px; height: 2px; background: #65a30d;"></div>
                    <div style="position: absolute; top: 10px; right: -15px; width: 10px; height: 2px; background: #65a30d; transform: rotate(-20deg);"></div>
                    <div style="position: absolute; top: 30px; right: -20px; width: 10px; height: 2px; background: #65a30d;"></div>
                </div>
            </div>

            <h2 style="font-size: 1.8rem; font-weight: 700; margin-bottom: 1rem;">
                ¡Petición enviada con éxito!
            </h2>
            <p style="color: #ccc; font-size: 1rem; margin-bottom: 2rem; line-height: 1.5;">
                Su solicitud ha sido turnada al área de conservación<br>
                y se atenderá a la brevedad posible.
            </p>

            <div style="background-color: transparent; border: 1px solid #4d7c0f; border-radius: 8px; padding: 1.5rem; margin-bottom: 2rem;">
                <div style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                    <svg width="24" height="24" fill="none" stroke="#65a30d" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path></svg>
                    <span style="font-size: 1.4rem; font-weight: bold; color: white;">FOLIO: <span id="modalFolioGenerado" style="color: #65a30d;">---</span></span>
                </div>
                <div style="font-size: 0.9rem; color: #ccc;">
                    Guarde este folio para dar seguimiento a su solicitud.
                </div>
                <div style="display:none;">
                    <span id="modalAreaGenerada">---</span>
                    <span id="modalFechaGenerada">---</span>
                </div>
            </div>

            <button type="button" onclick="closeModal('modalExitoReporte'); location.reload();" style="background-color: transparent; border: 1px solid #555; color: white; width: 100%; padding: 1rem; border-radius: 6px; font-size: 1rem; cursor: pointer;">
                [ Cerrar ]
            </button>

            <a id="modalBtnPdf" href="#" target="_blank" style="display:none;"></a>
            <a id="modalBtnEstatus" href="#" style="display:none;"></a>
        </div>
    </div>

    <script src="assets/js/app.js"></script>
    <script>
        async function cargarAreas() {
            const unidadId = document.getElementById('unidadSelect').value;
            const areaSelect = document.getElementById('areaSelect');
            areaSelect.innerHTML = '<option value="">-- CARGANDO ÁREAS... --</option>';
            if (!unidadId) {
                areaSelect.innerHTML = '<option value="">-- SELECCIONE PRIMERO UNA UNIDAD --</option>';
                return;
            }
            try {
                const res = await fetch(`api_public.php?action=get_areas&unidad_id=${unidadId}`);
                const data = await res.json();
                if (data.success) {
                    areaSelect.innerHTML = '<option value="">-- SELECCIONE UN ÁREA --</option>';
                    data.areas.forEach(a => {
                        areaSelect.innerHTML += `<option value="${a.id}">${a.nombre_area}</option>`;
                    });
                }
            } catch (e) {
                areaSelect.innerHTML = '<option value="">-- ERROR AL CARGAR --</option>';
            }
        }
        
        function cambiarTab(tab) {
            const sReporte = document.getElementById('section-reporte');
            const sEstatus = document.getElementById('section-estatus');
            const tabs = document.querySelectorAll('.tab-bar .tab');

            if (tab === 'estatus') {
                sReporte.style.display = 'none';
                sEstatus.style.display = 'block';
                tabs[0].classList.remove('active');
                tabs[1].classList.add('active');
            } else {
                sReporte.style.display = 'block';
                sEstatus.style.display = 'none';
                tabs[0].classList.add('active');
                tabs[1].classList.remove('active');
            }
        }
    </script>
</body>
</html>

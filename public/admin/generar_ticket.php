<?php
/**
 * Generación de Ticket Manual (Admin)
 */
require_once __DIR__ . '/../../config/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$db = Database::getConnection();
$userUnidadId = $_SESSION['user_unidad_id'];
$userRol = $_SESSION['user_rol'];
$userName = $_SESSION['user_name'];

// Unidades habilitadas
if ($userRol === 'SuperAdmin') {
    $unidades = $db->query("SELECT id, clave_unidad, nombre_unidad FROM unidades WHERE estatus='Activo' ORDER BY clave_unidad")->fetchAll();
} else {
    $stmtU = $db->prepare("
        SELECT u.id, u.clave_unidad, u.nombre_unidad 
        FROM unidades u 
        INNER JOIN usuarios_unidades uu ON u.id = uu.unidad_id
        WHERE uu.usuario_id = ? AND u.estatus='Activo'
        ORDER BY u.clave_unidad ASC
    ");
    $stmtU->execute([$_SESSION['user_id']]);
    $unidades = $stmtU->fetchAll();
    if (empty($unidades) && $userUnidadId) {
        $stmtU2 = $db->prepare("SELECT id, clave_unidad, nombre_unidad FROM unidades WHERE id = ? AND estatus='Activo'");
        $stmtU2->execute([$userUnidadId]);
        $unidades = $stmtU2->fetchAll();
    }
}
// Cálculo de tickets pendientes para notificaciones
if ($userRol === 'SuperAdmin') {
    $pendientesCount = (int)$db->query("SELECT COUNT(*) FROM reportes WHERE estatus='Pendiente'")->fetchColumn();
} else {
    $stmtUCount = $db->prepare("
        SELECT COUNT(DISTINCT r.id) 
        FROM reportes r
        INNER JOIN usuarios_unidades uu ON r.unidad_id = uu.unidad_id
        WHERE uu.usuario_id = ? AND r.estatus = 'Pendiente'
    ");
    $stmtUCount->execute([$_SESSION['user_id']]);
    $pendientesCount = (int)$stmtUCount->fetchColumn();
    if ($pendientesCount === 0 && !empty($userUnidadId)) {
        $stmtUCount2 = $db->prepare("SELECT COUNT(*) FROM reportes WHERE estatus='Pendiente' AND unidad_id = ?");
        $stmtUCount2->execute([$userUnidadId]);
        $pendientesCount = (int)$stmtUCount2->fetchColumn();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generar Reporte Manual - Panel de Conservación</title>
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
    <style>
        body { background:#f5f5f5; margin:0; padding:0; font-family:Arial,sans-serif; display:flex; flex-direction:column; min-height:100vh; }
        .header-custom { display:flex; align-items:center; justify-content:space-between; padding:1rem 2.5rem; background:#f5f5f5; }
        .header-left { display:flex; align-items:center; gap:1rem; }
        .header-left h1 { font-size:1.4rem; font-weight:700; color:#111; margin:0; }
        .header-right { display:flex; align-items:center; gap:1.5rem; }
        .profile-info { display:flex; align-items:center; gap:0.75rem; }
        .profile-icon { width:38px; height:38px; background:#666; color:white; border-radius:50%; display:flex; align-items:center; justify-content:center; }
        .profile-text .profile-title { font-weight:bold; font-size:0.9rem; color:#222; }
        .profile-text .profile-subtitle { font-size:0.78rem; color:#666; }
        .bell-icon { position:relative; background:#1a1a1a; color:white; width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; cursor:pointer; text-decoration:none; }
        .bell-icon .badge, .bell-icon .bell-badge { position:absolute; top:-5px; right:-5px; background:#3b8c4c; color:white; font-size:0.75rem; font-weight:bold; border-radius:50%; width:20px; height:20px; display:flex; align-items:center; justify-content:center; line-height:1; }
        .layout-wrapper { display:flex; flex:1; padding:0 2.5rem 2.5rem 2.5rem; gap:1.5rem; }
        .sidebar { width:220px; background:#1a1a1a; border-radius:12px; padding:1rem 0; flex-shrink:0; align-self:stretch; }
        .sidebar-item { display:flex; align-items:center; gap:0.75rem; padding:0.85rem 1.5rem; color:#aaa; text-decoration:none; font-size:0.95rem; transition:all 0.2s; }
        .sidebar-item:hover { color:white; background:#2a2a2a; }
        .sidebar-item.active { background:#333; color:white; border-left:3px solid #3b8c4c; padding-left:calc(1.5rem - 3px); }
        .sidebar-section { display:block; font-size:0.7rem; color:#555; text-transform:uppercase; font-weight:700; padding:0.75rem 1.5rem 0.25rem; letter-spacing:0.05em; margin-top:0.5rem; }
        .main-content { flex:1; }
        .page-title { font-size:1.6rem; font-weight:bold; margin:0 0 0.35rem 0; color: #111; }
        .title-underline { width:60px; height:3px; background:#3b8c4c; margin-bottom:1.5rem; }
        .form-card { background-color: #1a1a1a; border-radius: 12px; padding: 1.25rem 2rem; color: white; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .form-label { font-size: 0.85rem; font-weight: 700; color: white; margin-bottom: 0.3rem; display: block; }
        .form-input { width: 100%; background-color: #242424; border: 1px solid #444; color: white; padding: 0.45rem 0.7rem; border-radius: 6px; font-size: 0.88rem; box-sizing: border-box; }
        .form-input:focus { outline: none; border-color: #3b8c4c; }
        .btn-enviar { background-color: #2b7a4b; color: white; padding: 0.75rem 1.5rem; border: none; border-radius: 6px; font-size: 1rem; font-weight: bold; width: 100%; cursor: pointer; display: flex; justify-content: center; align-items: center; gap: 0.5rem; margin-top:1rem; transition:background 0.2s; }
        .btn-enviar:hover { background-color: #23633d; }
    </style>
</head>
<body>
<header class="header-custom">
    <div class="header-left">
        <button type="button" class="sidebar-toggle-btn" onclick="toggleMobileSidebar()" aria-label="Menú">
            <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"></path></svg>
        </button>
        <div style="display:flex;align-items:center;gap:0.4rem;font-weight:bold;color:#0d4d35;font-size:1.3rem;line-height:1.1;">
            <img src="../../Images/IMSS.png" alt="Logo IMSS" style="width: 45px; height: auto;">
            <div><div>IMSS</div><div style="font-size:0.5rem;color:#333;font-weight:normal;">INSTITUTO MEXICANO<br>DEL SEGURO SOCIAL</div></div>
        </div>
        <h1>Panel de Conservación</h1>
    </div>
    <div class="header-right">
        <div class="profile-info">
            <div class="profile-icon"><svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2m8-10a4 4 0 100-8 4 4 0 000 8z"></path></svg></div>
            <div class="profile-text">
                <div class="profile-title"><?= h($userRol) ?> de Conservación</div>
                <div class="profile-subtitle">(Sesión: [<?= h($userName) ?>])</div>
            </div>
        </div>
        <a href="dashboard.php" class="bell-icon" title="Ver Tickets">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
            <?php if ($pendientesCount > 0): ?><span class="badge"><?= $pendientesCount ?></span><?php endif; ?>
        </a>
        <a href="logout.php" style="color:#666; text-decoration:none;" title="Cerrar Sesión"><svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg></a>
    </div>
</header>

<div class="layout-wrapper">
    <aside class="sidebar">
        <div class="sidebar-mobile-header">
            <span style="color:#FFF;font-weight:bold;font-size:0.95rem;">Menú Principal</span>
            <button type="button" class="sidebar-mobile-close" onclick="toggleMobileSidebar()">✖</button>
        </div>
        <span class="sidebar-section">Operaciones</span>
        <a href="generar_ticket.php" class="sidebar-item active">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 4v16m8-8H4"></path></svg>
            Generar Reportes
        </a>
        <a href="dashboard.php" class="sidebar-item">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
            Reportes Activos
        </a>
        <a href="dashboard.php?estatus=Concluido" class="sidebar-item">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            Historial
        </a>
        <a href="auditoria.php" class="sidebar-item">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
            Tableros de control
        </a>
        <span class="sidebar-section">Catálogos</span>
        <a href="areas_maestro.php" class="sidebar-item">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
            Áreas Maestro
        </a>
        <a href="personal_operativo.php" class="sidebar-item">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
            Personal Operativo
        </a>
        <?php if ($userRol === 'SuperAdmin'): ?>
        <a href="unidades.php" class="sidebar-item">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
            Unidades
        </a>
        <?php endif; ?>
        <a href="usuarios.php" class="sidebar-item">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
            Usuarios
        </a>
    </aside>

    <main class="main-content">
        <h2 class="page-title">Generar Reporte Manual</h2>
        <div class="title-underline"></div>
        <div class="form-card">
            <p style="color: #aaa; margin-bottom: 1rem; font-size:0.85rem;">Utilice este formulario para registrar folios pasados o tickets recibidos manualmente.</p>

        <form id="formNuevoReporte">
            <!-- Fila 1: Fecha y Unidad (2 cols) -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 0.75rem;">
                <div>
                    <label class="form-label">Fecha de Recepción *</label>
                    <input type="datetime-local" id="fecha_recepcion_manual" name="fecha_recepcion_manual" class="form-input" required>
                </div>
                <div>
                    <label class="form-label">Unidad Médica o Administrativa *</label>
                    <select name="unidad_id" id="unidadSelect" class="form-input" required onchange="cargarAreas()">
                        <option value="">-- SELECCIONE UNA UNIDAD --</option>
                        <?php foreach($unidades as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= h($u['clave_unidad'] . ' - ' . $u['nombre_unidad']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Fila 2: Área y Categoría Solicitante (2 cols) -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 0.75rem;">
                <div>
                    <label class="form-label">Área o Ubicación *</label>
                    <select name="area_id" id="areaSelect" class="form-input" required>
                        <option value="">-- SELECCIONE PRIMERO UNA UNIDAD --</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Categoría / Puesto del Solicitante</label>
                    <input type="text" id="cargo_solicitante" name="cargo_solicitante" class="form-input" placeholder="Ej. JEFE DE PISO, ENFERMERA, MÉDICO, PERSONAL IMSS">
                </div>
            </div>

            <!-- Fila 3: Descripción del Trabajo / Falla -->
            <div style="margin-bottom: 0.75rem;">
                <label class="form-label">Descripción del Trabajo / Falla *</label>
                <textarea id="descripcion_falla" name="descripcion_falla" rows="2" class="form-input" placeholder="Describa detalladamente el trabajo a realizar o la falla presentada..." required></textarea>
            </div>

            <!-- Fila 4: Nombres del Solicitante (3 cols) -->
            <div style="margin-bottom: 0.75rem;">
                <label class="form-label">Nombre del Solicitante</label>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem;">
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
                </div>
            </div>

            <!-- Fila 5: Observaciones -->
            <div style="margin-bottom: 0.75rem;">
                <label class="form-label">Observaciones (Opcional)</label>
                <textarea id="observaciones" name="observaciones" rows="1" class="form-input" placeholder="Información adicional (opcional)..." style="resize:vertical;"></textarea>
            </div>

            <button type="submit" class="btn-enviar" id="btnGuardar">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"></path></svg>
                GENERAR TICKET MANUALMENTE
            </button>
        </form>
        </div>
    </main>
</div>

<script>
    // Set default date to now formatted for datetime-local
    const now = new Date();
    const tzoffset = now.getTimezoneOffset() * 60000;
    const localISOTime = (new Date(now - tzoffset)).toISOString().slice(0, 16);
    document.getElementById('fecha_recepcion_manual').value = localISOTime;

    async function cargarAreas() {
        const unidadId = document.getElementById('unidadSelect').value;
        const areaSelect = document.getElementById('areaSelect');
        areaSelect.innerHTML = '<option value="">-- CARGANDO ÁREAS... --</option>';
        if (!unidadId) {
            areaSelect.innerHTML = '<option value="">-- SELECCIONE PRIMERO UNA UNIDAD --</option>';
            return;
        }
        try {
            const res = await fetch(`../api_public.php?action=get_areas&unidad_id=${unidadId}`);
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

    document.getElementById('formNuevoReporte').addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = document.getElementById('btnGuardar');
        btn.disabled = true;
        btn.innerHTML = 'Guardando...';

        const fd = new FormData(this);
        // Formatear la fecha para MySQL (YYYY-MM-DD HH:MM:SS)
        const dt = new Date(document.getElementById('fecha_recepcion_manual').value);
        if (!isNaN(dt)) {
            const tzo = dt.getTimezoneOffset() * 60000;
            const mySqlDate = new Date(dt.getTime() - tzo).toISOString().slice(0, 19).replace('T', ' ');
            fd.set('fecha_recepcion_manual', mySqlDate);
        }

        try {
            const res = await fetch('../api_public.php?action=create_report', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                alert('Ticket generado exitosamente.\nFolio: ' + data.folio);
                window.location.href = 'dashboard.php';
            } else {
                alert('Error: ' + data.error);
                btn.disabled = false;
                btn.innerHTML = '<svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"></path></svg> GENERAR TICKET MANUALMENTE';
            }
        } catch (err) {
            alert('Error de red al crear el ticket.');
            btn.disabled = false;
            btn.innerHTML = '<svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"></path></svg> GENERAR TICKET MANUALMENTE';
        }
    });
</script>
<script src="../assets/js/app.js"></script>
</body>
</html>

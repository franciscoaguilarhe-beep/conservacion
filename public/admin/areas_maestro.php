<?php
/**
 * Panel Administrativo: Áreas Maestro — CRUD
 */
require_once __DIR__ . '/../../config/config.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$db = Database::getConnection();
$userUnidadId = $_SESSION['user_unidad_id'];
$userRol      = $_SESSION['user_rol'];
$userName     = $_SESSION['user_name'];

$userId = $_SESSION['user_id'];

// Unidad de referencia para habilitar/deshabilitar áreas
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
    $stmtU->execute([$userId]);
    $unidades = $stmtU->fetchAll();
    if (empty($unidades) && $userUnidadId) {
        $stmtU2 = $db->prepare("SELECT id, clave_unidad, nombre_unidad FROM unidades WHERE id = ? AND estatus='Activo'");
        $stmtU2->execute([$userUnidadId]);
        $unidades = $stmtU2->fetchAll();
    }
}

$filtroUnidadId = filter_input(INPUT_GET, 'unidad_id', FILTER_VALIDATE_INT);
$unidadFiltro   = ($filtroUnidadId && in_array($filtroUnidadId, array_column($unidades, 'id'))) ? $filtroUnidadId : ($unidades[0]['id'] ?? $userUnidadId ?? 1);
$unidadNombre   = '';
foreach ($unidades as $u) { if ($u['id'] == $unidadFiltro) { $unidadNombre = $u['clave_unidad']; break; } }

// Cargar todas las áreas con estado de habilitación para la unidad seleccionada
$stmtA = $db->prepare("
    SELECT a.id, a.nombre_area, a.created_at,
           COALESCE(ua.estatus, 'Deshabilitado') AS estado_unidad
    FROM areas_maestro a
    LEFT JOIN unidades_areas ua ON a.id = ua.id_area AND ua.id_unidad = ?
    ORDER BY a.nombre_area ASC
");
$stmtA->execute([$unidadFiltro]);
$areas = $stmtA->fetchAll();

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
    $stmtUCount->execute([$userId]);
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
    <title>Áreas Maestro — Panel de Conservación</title>
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
        .table-scroll-container { max-height: calc(100vh - 290px) !important; }
        .sidebar-item { display:flex; align-items:center; gap:0.75rem; padding:0.85rem 1.5rem; color:#aaa; text-decoration:none; font-size:0.95rem; transition:all 0.2s; }
        .sidebar-item:hover { color:white; background:#2a2a2a; }
        .sidebar-item.active { background:#333; color:white; border-left:3px solid #3b8c4c; padding-left:calc(1.5rem - 3px); }
        .sidebar-section { display:block; font-size:0.7rem; color:#555; text-transform:uppercase; font-weight:700; padding:0.75rem 1.5rem 0.25rem; letter-spacing:0.05em; margin-top:0.5rem; }
        .main-content { flex:1; }
        .page-title { font-size:1.6rem; font-weight:bold; color:#111; margin:0 0 0.35rem 0; }
        .title-underline { width:60px; height:3px; background:#3b8c4c; margin-bottom:1.5rem; }
        .toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:0.5rem; }
        .btn-add { background:#2b7a4b; color:white; border:none; padding:0.6rem 1.2rem; border-radius:6px; font-size:0.95rem; cursor:pointer; display:flex; align-items:center; gap:0.4rem; }
        .btn-add:hover { background:#23633d; }
        .data-table { width:100%; border-collapse:collapse; background:white; border-radius:8px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.06); }
        .data-table th { background:#e5e7eb; color:#374151; font-weight:600; padding:0.85rem 1rem; text-align:left; font-size:0.9rem; border-bottom:1px solid #d1d5db; }
        .data-table td { padding:0.85rem 1rem; border-bottom:1px solid #f3f4f6; font-size:0.9rem; vertical-align:middle; color:#1f2937; }
        .data-table tr:last-child td { border-bottom:none; }
        .badge-habilitado { display:inline-flex; align-items:center; gap:0.35rem; padding:0.25rem 0.8rem; border-radius:9999px; background:#dcfce7; color:#166534; font-size:0.78rem; font-weight:600; }
        .badge-deshabilitado { display:inline-flex; align-items:center; gap:0.35rem; padding:0.25rem 0.8rem; border-radius:9999px; background:#f3f4f6; color:#6b7280; font-size:0.78rem; font-weight:600; }
        .btn-sm { border:none; padding:0.3rem 0.75rem; border-radius:4px; font-size:0.8rem; cursor:pointer; }
        .btn-edit { background:#e5e7eb; color:#374151; }
        .btn-edit:hover { background:#d1d5db; }
        .toggle-btn { border:none; padding:0.35rem 0.9rem; border-radius:4px; font-size:0.8rem; cursor:pointer; font-weight:600; }
        .modal-bg { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:1000; align-items:center; justify-content:center; }
        .modal-bg.open { display:flex; }
        .modal-box { background:#1e1e1e; color:white; border-radius:12px; padding:2rem; width:480px; max-width:95vw; }
        .modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; }
        .modal-title { font-size:1.2rem; font-weight:700; margin:0; }
        .modal-close { background:transparent; border:none; color:#aaa; font-size:1.5rem; cursor:pointer; }
        .form-group { margin-bottom:1rem; }
        .form-group label { display:block; font-size:0.85rem; color:#ccc; margin-bottom:0.3rem; }
        .form-group input { width:100%; background:#2a2a2a; border:1px solid #444; color:white; padding:0.65rem 0.75rem; border-radius:5px; font-size:0.95rem; box-sizing:border-box; }
        .form-group input:focus { outline:none; border-color:#3b8c4c; }
        .btn-submit { width:100%; background:#2b7a4b; color:white; border:none; padding:0.85rem; border-radius:6px; font-size:1rem; font-weight:bold; cursor:pointer; margin-top:0.5rem; }
        .btn-submit:hover { background:#23633d; }
        .filter-bar { display:flex; align-items:center; gap:0.75rem; }
        .filter-bar select { background:white; border:1px solid #d1d5db; color:#374151; padding:0.4rem 0.75rem; border-radius:5px; font-size:0.9rem; cursor:pointer; }
        .logout-link { color:#666; text-decoration:none; display:flex; align-items:center; }
        .info-badge { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:0.5rem 1rem; font-size:0.85rem; color:#166534; margin-bottom:1rem; }
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
        <a href="logout.php" class="logout-link" title="Cerrar Sesión"><svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg></a>
    </div>
</header>

<div class="layout-wrapper">
    <aside class="sidebar">
        <div class="sidebar-mobile-header">
            <span style="color:#FFF;font-weight:bold;font-size:0.95rem;">Menú Principal</span>
            <button type="button" class="sidebar-mobile-close" onclick="toggleMobileSidebar()">✖</button>
        </div>
        <span class="sidebar-section">Operaciones</span>
        <a href="generar_ticket.php" class="sidebar-item">
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
        <a href="areas_maestro.php" class="sidebar-item active">
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
        <h2 class="page-title">Áreas Maestro</h2>
        <div class="title-underline"></div>

        <div class="info-badge">
            📋 Las áreas habilitadas aparecen en el formulario público de la unidad seleccionada.
            Puedes agregar áreas al catálogo global y luego habilitarlas por unidad.
        </div>

        <div class="toolbar">
            <div class="filter-bar">
                <label style="font-size:0.9rem;color:#555;">Unidad de referencia:</label>
                <?php if ($userRol === 'SuperAdmin' || count($unidades) > 1): ?>
                    <form method="GET" style="display:flex;align-items:center;gap:0.5rem;">
                        <select name="unidad_id" onchange="this.form.submit()">
                            <?php foreach ($unidades as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= ($unidadFiltro == $u['id']) ? 'selected' : '' ?>><?= h($u['clave_unidad']) ?> - <?= h($u['nombre_unidad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php else: ?>
                    <strong style="color:var(--text-main);"><?= h($unidadNombre) ?></strong>
                <?php endif; ?>
                <span style="font-size:0.85rem;color:#666;"><?= count($areas) ?> área(s) en catálogo</span>
            </div>
            <button class="btn-add" onclick="abrirModalArea()">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 4v16m8-8H4"></path></svg>
                Nueva Área
            </button>
        </div>

        <div class="table-scroll-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nombre del Área</th>
                    <th>Estado en <?= h($unidadNombre ?: 'Unidad') ?></th>
                    <th>Creada</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($areas)): ?>
                    <tr><td colspan="5" style="text-align:center;color:#888;padding:2rem;">No hay áreas registradas.</td></tr>
                <?php else: foreach ($areas as $a): ?>
                <tr>
                    <td style="font-family:monospace;color:#888;"><?= $a['id'] ?></td>
                    <td style="font-weight:600;"><?= h($a['nombre_area']) ?></td>
                    <td>
                        <?php $hab = ($a['estado_unidad'] === 'Habilitado'); ?>
                        <span class="badge-<?= $hab ? 'habilitado' : 'deshabilitado' ?>">
                            <?= $hab ? '✓ Habilitada' : '○ Deshabilitada' ?>
                        </span>
                    </td>
                    <td style="font-size:0.82rem;color:#555;"><?= date('d/m/Y', strtotime($a['created_at'])) ?></td>
                    <td style="display:flex;gap:0.4rem;align-items:center;">
                        <button class="btn-sm btn-edit" onclick='editarArea(<?= json_encode(['id'=>$a['id'],'nombre_area'=>$a['nombre_area']]) ?>)' title="Renombrar">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                        </button>
                        <button class="toggle-btn"
                            style="background:<?= $hab ? '#fef3c7' : '#dcfce7' ?>;color:<?= $hab ? '#92400e' : '#166534' ?>;border:none;padding:0.3rem;border-radius:4px;cursor:pointer;"
                            onclick="toggleArea(<?= $a['id'] ?>, <?= $unidadFiltro ?>)" title="<?= $hab ? 'Deshabilitar' : 'Habilitar' ?>">
                            <?php if ($hab): ?>
                                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path></svg>
                            <?php else: ?>
                                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            <?php endif; ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </main>
</div>

<!-- Modal Agregar / Renombrar -->
<div id="modalArea" class="modal-bg">
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitulo">Nueva Área</h3>
            <button class="modal-close" onclick="cerrarModalArea()">✖</button>
        </div>
        <form id="formArea" onsubmit="guardarArea(event)">
            <input type="hidden" id="aId" name="id" value="">
            <div class="form-group">
                <label>Nombre del Área *</label>
                <input type="text" id="aNombre" name="nombre_area" placeholder="Ej. CONSULTA EXTERNA, URGENCIAS..." required>
            </div>
            <p style="font-size:0.8rem;color:#888;">El nombre se guardará en mayúsculas. Una vez creada, puedes habilitarla en cualquier unidad.</p>
            <button type="submit" class="btn-submit" id="btnGuardar">Guardar</button>
        </form>
    </div>
</div>

<script>
    const UNIDAD_FILTRO = <?= (int)$unidadFiltro ?>;

    function abrirModalArea() {
        document.getElementById('modalTitulo').textContent = 'Nueva Área';
        document.getElementById('aId').value = '';
        document.getElementById('aNombre').value = '';
        document.getElementById('modalArea').classList.add('open');
    }
    function cerrarModalArea() {
        document.getElementById('modalArea').classList.remove('open');
    }
    function editarArea(a) {
        document.getElementById('modalTitulo').textContent = 'Renombrar Área';
        document.getElementById('aId').value = a.id;
        document.getElementById('aNombre').value = a.nombre_area;
        document.getElementById('modalArea').classList.add('open');
    }
    async function guardarArea(e) {
        e.preventDefault();
        const btn = document.getElementById('btnGuardar');
        btn.disabled = true; btn.textContent = 'Guardando...';
        const fd = new FormData(document.getElementById('formArea'));
        try {
            const res = await fetch('api.php?action=save_area', {method:'POST', body:fd});
            const data = await res.json();
            if (data.success) { alert('Área guardada.'); location.reload(); }
            else { alert('Error: ' + data.error); }
        } catch(err) { alert('Error de conexión.'); }
        btn.disabled = false; btn.textContent = 'Guardar';
    }
    async function toggleArea(areaId, unidadId) {
        const fd = new FormData();
        fd.append('area_id', areaId);
        fd.append('unidad_id', unidadId);
        try {
            const res = await fetch('api.php?action=toggle_unidad_area', {method:'POST', body:fd});
            const data = await res.json();
            if (data.success) location.reload();
            else alert('Error: ' + data.error);
        } catch(err) { alert('Error de conexión.'); }
    }
    document.addEventListener('keydown', e => { if(e.key==='Escape') cerrarModalArea(); });
    document.getElementById('modalArea').addEventListener('click', e => { if(e.target===e.currentTarget) cerrarModalArea(); });
</script>
<script src="../assets/js/app.js"></script>
</body>
</html>

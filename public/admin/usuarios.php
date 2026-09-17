<?php
/**
 * Panel Administrativo: Usuarios — CRUD
 */
require_once __DIR__ . '/../../config/config.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$db = Database::getConnection();
$userUnidadId = $_SESSION['user_unidad_id'];
$userRol      = $_SESSION['user_rol'];
$userName     = $_SESSION['user_name'];

// Cargar usuarios con sus unidades asignadas concatenadas
if ($userRol === 'SuperAdmin') {
    $usuarios = $db->query("
        SELECT u.*, 
               GROUP_CONCAT(DISTINCT un.clave_unidad ORDER BY un.clave_unidad SEPARATOR ', ') AS unidades_claves,
               GROUP_CONCAT(DISTINCT un.id ORDER BY un.id SEPARATOR ',') AS unidades_ids
        FROM usuarios_admin u 
        LEFT JOIN usuarios_unidades uu ON u.id = uu.usuario_id
        LEFT JOIN unidades un ON uu.unidad_id = un.id 
        GROUP BY u.id
        ORDER BY u.nombre_completo ASC
    ")->fetchAll();
    $unidades = $db->query("SELECT id, clave_unidad, nombre_unidad FROM unidades WHERE estatus='Activo' ORDER BY clave_unidad")->fetchAll();
    $pendientesCount = (int)$db->query("SELECT COUNT(*) FROM reportes WHERE estatus='Pendiente'")->fetchColumn();
} else {
    $stmtMyUnits = $db->prepare("SELECT unidad_id FROM usuarios_unidades WHERE usuario_id = ?");
    $stmtMyUnits->execute([$_SESSION['user_id']]);
    $unidadesUsuarioArr = $stmtMyUnits->fetchAll(PDO::FETCH_COLUMN);
    if (empty($unidadesUsuarioArr) && $userUnidadId) {
        $unidadesUsuarioArr = [$userUnidadId];
    }
    $inClause = !empty($unidadesUsuarioArr) ? implode(',', array_map('intval', $unidadesUsuarioArr)) : '0';
    $usuarios = $db->query("
        SELECT u.*, 
               GROUP_CONCAT(DISTINCT un.clave_unidad ORDER BY un.clave_unidad SEPARATOR ', ') AS unidades_claves,
               GROUP_CONCAT(DISTINCT un.id ORDER BY un.id SEPARATOR ',') AS unidades_ids
        FROM usuarios_admin u 
        LEFT JOIN usuarios_unidades uu ON u.id = uu.usuario_id
        LEFT JOIN unidades un ON uu.unidad_id = un.id 
        WHERE u.rol != 'SuperAdmin' 
          AND (
            u.id IN (SELECT usuario_id FROM usuarios_unidades WHERE unidad_id IN ($inClause))
            OR u.unidad_id IN ($inClause)
          )
        GROUP BY u.id
        ORDER BY u.nombre_completo ASC
    ")->fetchAll();

    $stmtUList = $db->prepare("
        SELECT u.id, u.clave_unidad, u.nombre_unidad 
        FROM unidades u 
        INNER JOIN usuarios_unidades uu ON u.id = uu.unidad_id 
        WHERE uu.usuario_id = ? AND u.estatus='Activo' 
        ORDER BY u.clave_unidad
    ");
    $stmtUList->execute([$_SESSION['user_id']]);
    $unidades = $stmtUList->fetchAll();
    if (empty($unidades) && $userUnidadId) {
        $stmtUList2 = $db->prepare("SELECT id, clave_unidad, nombre_unidad FROM unidades WHERE id = ? AND estatus='Activo'");
        $stmtUList2->execute([$userUnidadId]);
        $unidades = $stmtUList2->fetchAll();
    }

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
    <title>Usuarios — Panel de Conservación</title>
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
        .bell-badge { position:absolute; top:-5px; right:-5px; background:#3b8c4c; color:white; font-size:0.7rem; font-weight:bold; border-radius:50%; width:18px; height:18px; display:flex; align-items:center; justify-content:center; }
        .layout-wrapper { display:flex; flex:1; padding:0 2.5rem 2.5rem 2.5rem; gap:1.5rem; }
        .sidebar { width:220px; background:#1a1a1a; border-radius:12px; padding:1rem 0; flex-shrink:0; align-self:stretch; }
        .table-scroll-container { max-height: calc(100vh - 250px) !important; }
        .sidebar-item { display:flex; align-items:center; gap:0.75rem; padding:0.85rem 1.5rem; color:#aaa; text-decoration:none; font-size:0.95rem; transition:all 0.2s; }
        .sidebar-item:hover { color:white; background:#2a2a2a; }
        .sidebar-item.active { background:#333; color:white; border-left:3px solid #3b8c4c; padding-left:calc(1.5rem - 3px); }
        .sidebar-section { font-size:0.7rem; color:#555; text-transform:uppercase; font-weight:700; padding:0.75rem 1.5rem 0.25rem; letter-spacing:0.05em; margin-top:0.5rem; }
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
        .badge-activo   { display:inline-block; padding:0.2rem 0.7rem; border-radius:9999px; background:#dcfce7; color:#166534; font-size:0.78rem; font-weight:600; }
        .badge-inactivo { display:inline-block; padding:0.2rem 0.7rem; border-radius:9999px; background:#fee2e2; color:#991b1b; font-size:0.78rem; font-weight:600; }
        .btn-sm { border:none; padding:0.3rem 0.75rem; border-radius:4px; font-size:0.8rem; cursor:pointer; }
        .btn-edit { background:#e5e7eb; color:#374151; }
        .btn-edit:hover { background:#d1d5db; }
        .modal-bg { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:1000; align-items:center; justify-content:center; }
        .modal-bg.open { display:flex; }
        .modal-box { background:#1e1e1e; color:white; border-radius:12px; padding:2rem; width:560px; max-width:95vw; max-height:90vh; overflow-y:auto; }
        .modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; }
        .modal-title { font-size:1.2rem; font-weight:700; margin:0; }
        .modal-close { background:transparent; border:none; color:#aaa; font-size:1.5rem; cursor:pointer; }
        .form-group { margin-bottom:1rem; }
        .form-group label { display:block; font-size:0.85rem; color:#ccc; margin-bottom:0.3rem; }
        .form-group input, .form-group select { width:100%; background:#2a2a2a; border:1px solid #444; color:white; padding:0.65rem 0.75rem; border-radius:5px; font-size:0.95rem; box-sizing:border-box; }
        .form-group input:focus, .form-group select:focus { outline:none; border-color:#3b8c4c; }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
        .btn-submit { width:100%; background:#2b7a4b; color:white; border:none; padding:0.85rem; border-radius:6px; font-size:1rem; font-weight:bold; cursor:pointer; margin-top:0.5rem; }
        .btn-submit:hover { background:#23633d; }
        .logout-link { color:#666; text-decoration:none; display:flex; align-items:center; }
        .clave-pill { display:inline-block; background:#0d4d35; color:white; font-weight:700; font-size:0.82rem; padding:0.2rem 0.6rem; border-radius:4px; font-family:monospace; }
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
            <?php if ($pendientesCount > 0): ?><span class="bell-badge"><?= $pendientesCount ?></span><?php endif; ?>
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
        <a href="usuarios.php" class="sidebar-item active">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
            Usuarios
        </a>
    </aside>

    <main class="main-content">
        <h2 class="page-title">Usuarios Administradores</h2>
        <div class="title-underline"></div>

        <div class="toolbar">
            <button class="btn-add" onclick="abrirModalUsuario()">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 4v16m8-8H4"></path></svg>
                Nuevo Usuario
            </button>
        </div>

        <div class="table-scroll-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Usuario</th>
                    <th>Nombre Completo</th>
                    <th>Rol</th>
                    <th>Unidades a Cargo</th>
                    <th>Estatus</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($usuarios)): ?>
                    <tr><td colspan="6" style="text-align:center;color:#888;padding:2rem;">No hay usuarios registrados.</td></tr>
                <?php else: foreach ($usuarios as $u): ?>
                <tr>
                    <td style="font-weight:600;"><?= h($u['username']) ?></td>
                    <td style="font-size:0.88rem;"><?= h($u['nombre_completo']) ?></td>
                    <td style="font-size:0.85rem;color:#555;"><?= h($u['rol']) ?></td>
                    <td style="font-size:0.82rem;">
                        <?php if ($u['rol'] === 'SuperAdmin' && empty($u['unidades_claves'])): ?>
                            <span class="badge" style="background:#e0e7ff;color:#3730a3;font-size:0.75rem;">Global / Todas</span>
                        <?php elseif (!empty($u['unidades_claves'])): 
                            $claves = explode(', ', $u['unidades_claves']);
                            foreach ($claves as $c): ?>
                                <span class="clave-pill" style="margin-right:4px;margin-bottom:3px;display:inline-block;"><?= h($c) ?></span>
                            <?php endforeach;
                        else: ?>
                            <span style="color:#999;font-style:italic;">Sin Unidad</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge-<?= strtolower($u['estatus']) ?>"><?= h($u['estatus']) ?></span></td>
                    <td>
                        <button class="btn-sm btn-edit" onclick='editarUsuario(<?= json_encode($u) ?>)' title="Editar">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                        </button>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </main>
</div>

<!-- Modal Agregar / Editar -->
<div id="modalUsuario" class="modal-bg">
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitulo">Nuevo Usuario</h3>
            <button class="modal-close" onclick="cerrarModalUsuario()">✖</button>
        </div>
        <form id="formUsuario" onsubmit="guardarUsuario(event)">
            <input type="hidden" id="uId" name="id" value="">
            <div class="form-grid">
                <div class="form-group">
                    <label>Nombre de Usuario (Login) *</label>
                    <input type="text" id="uUsername" name="username" placeholder="Ej. jperez" required maxlength="50">
                </div>
                <div class="form-group">
                    <label>Contraseña (Dejar en blanco para mantener actual / imss2026 defecto)</label>
                    <input type="password" id="uPassword" name="password" placeholder="***">
                </div>
            </div>
            <div class="form-group">
                <label>Nombre Completo *</label>
                <input type="text" id="uNombre" name="nombre_completo" placeholder="Ej. Juan Pérez" required>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Rol *</label>
                    <select id="uRol" name="rol" required>
                        <?php if ($userRol === 'SuperAdmin'): ?>
                            <option value="SuperAdmin">SuperAdmin</option>
                        <?php endif; ?>
                        <?php if ($userRol === 'SuperAdmin' || $userRol === 'Jefe'): ?>
                            <option value="Jefe">Jefe de Conservación</option>
                        <?php endif; ?>
                        <option value="Subjefe">Subjefe de Conservación</option>
                        <option value="AUO">Auxiliar Universal de Oficinas (AUO)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Estatus</label>
                    <select id="uEstatus" name="estatus">
                        <option value="Activo">Activo</option>
                        <option value="Inactivo">Inactivo (Dado de baja)</option>
                    </select>
                </div>
            </div>

            <!-- Asignación Multi-Unidad -->
            <div class="form-group" style="margin-top:0.5rem;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.4rem;">
                    <label style="margin:0; font-size:0.85rem; color:#ccc;">Unidades Médicas a Cargo *</label>
                    <?php if ($userRol === 'SuperAdmin'): ?>
                    <span style="font-size:0.78rem;">
                        <a href="javascript:void(0)" onclick="toggleAllUnits(true)" style="color:#86efac; margin-right:10px; text-decoration:underline;">Marcar todas</a>
                        <a href="javascript:void(0)" onclick="toggleAllUnits(false)" style="color:#fca5a5; text-decoration:underline;">Desmarcar</a>
                    </span>
                    <?php endif; ?>
                </div>
                <div id="unitCheckboxesContainer" style="background:#2a2a2a; border:1px solid #444; border-radius:6px; padding:0.75rem; max-height:160px; overflow-y:auto; display:grid; grid-template-columns:repeat(auto-fill, minmax(140px, 1fr)); gap:0.5rem;">
                    <?php foreach($unidades as $un): ?>
                        <label style="display:flex; align-items:center; gap:0.5rem; font-size:0.85rem; color:#e5e7eb; cursor:pointer; background:#1e1e1e; padding:0.4rem 0.6rem; border-radius:4px; border:1px solid #383838;">
                            <input type="checkbox" name="unidades[]" value="<?= $un['id'] ?>" class="unit-checkbox" style="width:auto; margin:0; cursor:pointer;">
                            <span style="font-weight:700; font-family:monospace; color:#86efac;"><?= h($un['clave_unidad']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <small style="color:#9ca3af; font-size:0.75rem; margin-top:0.35rem; display:block;">
                    💡 Puedes marcar una o varias unidades para asignar al mismo Jefe, Subjefe o AUO sin duplicar usuarios.
                </small>
            </div>

            <button type="submit" class="btn-submit" id="btnGuardar">Guardar Usuario</button>
        </form>
    </div>
</div>

<script>
    function toggleAllUnits(check) {
        document.querySelectorAll('.unit-checkbox').forEach(cb => cb.checked = check);
    }

    function abrirModalUsuario() {
        document.getElementById('modalTitulo').textContent = 'Nuevo Usuario';
        document.getElementById('uId').value = '';
        document.getElementById('formUsuario').reset();
        toggleAllUnits(false);
        document.getElementById('modalUsuario').classList.add('open');
    }

    function cerrarModalUsuario() {
        document.getElementById('modalUsuario').classList.remove('open');
    }

    function editarUsuario(u) {
        document.getElementById('modalTitulo').textContent = 'Editar Usuario';
        document.getElementById('uId').value = u.id;
        document.getElementById('uUsername').value = u.username;
        document.getElementById('uNombre').value = u.nombre_completo;
        document.getElementById('uRol').value = u.rol;
        document.getElementById('uEstatus').value = u.estatus;
        document.getElementById('uPassword').value = ''; // No cargar hash

        // Marcar las casillas de las unidades asignadas
        toggleAllUnits(false);
        if (u.unidades_ids) {
            const assignedIds = u.unidades_ids.toString().split(',');
            document.querySelectorAll('.unit-checkbox').forEach(cb => {
                if (assignedIds.includes(cb.value.toString())) {
                    cb.checked = true;
                }
            });
        }

        document.getElementById('modalUsuario').classList.add('open');
    }

    async function guardarUsuario(e) {
        e.preventDefault();
        const btn = document.getElementById('btnGuardar');
        btn.disabled = true; btn.textContent = 'Guardando...';
        const fd = new FormData(document.getElementById('formUsuario'));
        try {
            const res = await fetch('api.php?action=save_usuario', {method:'POST', body:fd});
            const data = await res.json();
            if (data.success) { alert('Usuario guardado con éxito.'); location.reload(); }
            else { alert('Error: ' + data.error); }
        } catch(err) { alert('Error de conexión.'); }
        btn.disabled = false; btn.textContent = 'Guardar Usuario';
    }

    document.addEventListener('keydown', e => { if(e.key==='Escape') cerrarModalUsuario(); });
    document.getElementById('modalUsuario').addEventListener('click', e => { if(e.target===e.currentTarget) cerrarModalUsuario(); });
</script>
<script src="../assets/js/app.js"></script>
</body>
</html>

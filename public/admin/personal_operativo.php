<?php
/**
 * Panel Administrativo: Personal Operativo — CRUD
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

$userId = $_SESSION['user_id'];

// Cargar unidades accesibles para el usuario
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

// Cargar personal según unidad seleccionada
$filtroUnidadId = filter_input(INPUT_GET, 'unidad_id', FILTER_VALIDATE_INT);
$unidadFiltro = ($filtroUnidadId && in_array($filtroUnidadId, array_column($unidades, 'id'))) ? $filtroUnidadId : ($unidades[0]['id'] ?? $userUnidadId ?? 1);
$unidadNombre = '';
foreach ($unidades as $u) { if ($u['id'] == $unidadFiltro) { $unidadNombre = $u['clave_unidad']; break; } }

$sqlP = "SELECT p.*, u.clave_unidad FROM personal_operativo p 
         INNER JOIN unidades u ON p.unidad_id = u.id 
         WHERE p.unidad_id = ?
         ORDER BY p.nombre ASC";
$stmtP = $db->prepare($sqlP); 
$stmtP->execute([(int)$unidadFiltro]);
$personal = $stmtP->fetchAll();

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
    <title>Personal Operativo — Panel de Conservación</title>
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
        .badge-cat { display:inline-block; padding:0.2rem 0.6rem; border-radius:4px; font-size:0.78rem; font-weight:600; background:#e0f2fe; color:#0369a1; }
        .badge-activo { display:inline-flex; align-items:center; gap:0.35rem; padding:0.25rem 0.8rem; border-radius:9999px; background:#dcfce7; color:#166534; font-size:0.78rem; font-weight:600; }
        .badge-inactivo { display:inline-flex; align-items:center; gap:0.35rem; padding:0.25rem 0.8rem; border-radius:9999px; background:#fee2e2; color:#991b1b; font-size:0.78rem; font-weight:600; }
        .btn-sm { border:none; padding:0.3rem 0.75rem; border-radius:4px; font-size:0.8rem; cursor:pointer; }
        .btn-edit { background:#e5e7eb; color:#374151; }
        .btn-edit:hover { background:#d1d5db; }
        .modal-bg { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:1000; align-items:center; justify-content:center; }
        .modal-bg.open { display:flex; }
        .modal-box { background:#1e1e1e; color:white; border-radius:12px; padding:2rem; width:480px; max-width:95vw; }
        .modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; }
        .modal-title { font-size:1.2rem; font-weight:700; margin:0; }
        .modal-close { background:transparent; border:none; color:#aaa; font-size:1.5rem; cursor:pointer; }
        .form-group { margin-bottom:1rem; }
        .form-group label { display:block; font-size:0.85rem; color:#ccc; margin-bottom:0.3rem; }
        .form-group input, .form-group select { width:100%; background:#2a2a2a; border:1px solid #444; color:white; padding:0.65rem 0.75rem; border-radius:5px; font-size:0.95rem; box-sizing:border-box; }
        .form-group input:focus, .form-group select:focus { outline:none; border-color:#3b8c4c; }
        .btn-submit { width:100%; background:#2b7a4b; color:white; border:none; padding:0.85rem; border-radius:6px; font-size:1rem; font-weight:bold; cursor:pointer; margin-top:0.5rem; }
        .btn-submit:hover { background:#23633d; }
        .filter-bar { display:flex; align-items:center; gap:0.75rem; }
        .filter-bar select { background:white; border:1px solid #d1d5db; color:#374151; padding:0.4rem 0.75rem; border-radius:5px; font-size:0.9rem; cursor:pointer; }
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
        <a href="areas_maestro.php" class="sidebar-item">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
            Áreas Maestro
        </a>
        <a href="personal_operativo.php" class="sidebar-item active">
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
        <h2 class="page-title">Personal Operativo</h2>
        <div class="title-underline"></div>

        <div class="toolbar">
            <div class="filter-bar">
                <label style="font-size:0.9rem;color:#555;">Unidad:</label>
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
                <span style="font-size:0.85rem;color:#666;"><?= count($personal) ?> registro(s)</span>
            </div>
            <button class="btn-add" onclick="abrirModalPersonal()">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 4v16m8-8H4"></path></svg>
                Agregar Técnico
            </button>
        </div>

        <div class="table-scroll-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Matrícula</th>
                    <th>Nombre Completo</th>
                    <th>Categoría</th>
                    <th>Turno</th>
                    <th>Horario</th>
                    <th>Unidad</th>
                    <th>Estatus</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($personal)): ?>
                    <tr><td colspan="8" style="text-align:center;color:#888;padding:2rem;">No hay personal registrado en esta unidad.</td></tr>
                <?php else: foreach ($personal as $p): ?>
                <tr>
                    <td style="font-family:monospace;font-weight:600;"><?= h($p['matricula']) ?></td>
                    <td><?= h($p['nombre']) ?></td>
                    <td><?= h($p['categoria']) ?></td>
                    <td><?= h($p['turno']) ?></td>
                    <td style="font-size:0.82rem;color:#555;"><?= h(date('H:i',strtotime($p['horario_entrada']))) ?> – <?= h(date('H:i',strtotime($p['horario_salida']))) ?></td>
                    <td><?= h($p['clave_unidad']) ?></td>
                    <td><span class="badge-<?= strtolower($p['estatus']) ?>"><?= h($p['estatus']) ?></span></td>
                    <td style="display:flex;gap:0.4rem;align-items:center;">
                        <button class="btn-sm btn-edit" onclick='editarPersonal(<?= json_encode($p) ?>)' title="Editar">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                        </button>
                        <button class="btn-sm <?= $p['estatus']==='Activo'?'btn-toggle-off':'btn-toggle-on' ?>"
                            onclick="togglePersonal(<?= $p['id'] ?>, this)" title="<?= $p['estatus']==='Activo' ? 'Desactivar' : 'Activar' ?>">
                            <?php if ($p['estatus']==='Activo'): ?>
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

<!-- Modal Agregar / Editar -->
<div id="modalPersonal" class="modal-bg">
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitulo">Agregar Técnico</h3>
            <button class="modal-close" onclick="cerrarModalPersonal()">✖</button>
        </div>
        <form id="formPersonal" onsubmit="guardarPersonal(event)">
            <input type="hidden" id="pId" name="id" value="">
            <div class="form-grid">
                <div class="form-group">
                    <label>Unidad de Adscripción *</label>
                    <?php if ($userRol === 'SuperAdmin' || count($unidades) > 1): ?>
                        <select id="pUnidad" name="unidad_id" required>
                            <option value="">-- Seleccione --</option>
                            <?php foreach($unidades as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= ($unidadFiltro == $u['id']) ? 'selected' : '' ?>><?= h($u['clave_unidad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="hidden" id="pUnidad" name="unidad_id" value="<?= $unidadFiltro ?>">
                        <input type="text" value="<?= h($unidadNombre) ?>" disabled>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Matrícula *</label>
                    <input type="text" id="pMatricula" name="matricula" placeholder="Ej. 99382101" required>
                </div>
            </div>
            <div class="form-group">
                <label>Nombre Completo *</label>
                <input type="text" id="pNombre" name="nombre" placeholder="Nombre como aparece en nómina" required>
            </div>
            <div class="form-group">
                <label>Categoría / Especialidad *</label>
                <input type="text" id="pCategoria" name="categoria" placeholder="Ej. Electricista, Plomero, Frigorista...">
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Turno</label>
                    <select id="pTurno" name="turno">
                        <option>Matutino</option>
                        <option>Vespertino</option>
                        <option>Nocturno</option>
                        <option>Jornada Acumulada</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Días de Descanso</label>
                    <input type="text" id="pDescanso" name="dias_descanso" placeholder="Sábado y Domingo">
                </div>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Hora Entrada</label>
                    <input type="time" id="pEntrada" name="horario_entrada" value="07:00">
                </div>
                <div class="form-group">
                    <label>Hora Salida</label>
                    <input type="time" id="pSalida" name="horario_salida" value="15:00">
                </div>
            </div>
            <button type="submit" class="btn-submit">Guardar</button>
        </form>
    </div>
</div>

<script>
    function abrirModalPersonal(data) {
        document.getElementById('modalPersonal').classList.add('open');
    }
    function cerrarModalPersonal() {
        document.getElementById('modalPersonal').classList.remove('open');
        document.getElementById('formPersonal').reset();
        document.getElementById('pId').value = '';
        document.getElementById('modalTitulo').textContent = 'Agregar Técnico';
    }
    function editarPersonal(p) {
        document.getElementById('modalTitulo').textContent = 'Editar Técnico';
        document.getElementById('pId').value = p.id;
        document.getElementById('pUnidad').value = p.unidad_id;
        document.getElementById('pMatricula').value = p.matricula;
        document.getElementById('pNombre').value = p.nombre;
        document.getElementById('pCategoria').value = p.categoria;
        document.getElementById('pTurno').value = p.turno;
        document.getElementById('pEntrada').value = p.horario_entrada ? p.horario_entrada.slice(0,5) : '07:00';
        document.getElementById('pSalida').value = p.horario_salida ? p.horario_salida.slice(0,5) : '15:00';
        document.getElementById('pDescanso').value = p.dias_descanso;
        document.getElementById('modalPersonal').classList.add('open');
    }
    async function guardarPersonal(e) {
        e.preventDefault();
        const btn = e.target.querySelector('button[type=submit]');
        btn.disabled = true; btn.textContent = 'Guardando...';
        const fd = new FormData(document.getElementById('formPersonal'));
        try {
            const res = await fetch('api.php?action=save_personal', {method:'POST', body:fd});
            const data = await res.json();
            if (data.success) { alert('Guardado correctamente.'); location.reload(); }
            else { alert('Error: ' + data.error); }
        } catch(err) { alert('Error de conexión.'); }
        btn.disabled = false; btn.textContent = 'Guardar';
    }
    async function togglePersonal(id, btn) {
        if (!confirm('¿Cambiar el estatus de este técnico?')) return;
        const fd = new FormData(); fd.append('id', id);
        try {
            const res = await fetch('api.php?action=toggle_personal', {method:'POST', body:fd});
            const data = await res.json();
            if (data.success) location.reload();
            else alert('Error: ' + data.error);
        } catch(err) { alert('Error de conexión.'); }
    }
    // Cerrar con Escape
    document.addEventListener('keydown', e => { if(e.key==='Escape') cerrarModalPersonal(); });
    document.getElementById('modalPersonal').addEventListener('click', e => { if(e.target===e.currentTarget) cerrarModalPersonal(); });
</script>
<script src="../assets/js/app.js"></script>
</body>
</html>

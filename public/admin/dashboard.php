<?php
/**
 * Dashboard Administrativo de Conservación IMSS
 */
require_once __DIR__ . '/../../config/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$db = Database::getConnection();
$userUnidadId = $_SESSION['user_unidad_id'];
$userRol = $_SESSION['user_rol'];

$userId = $_SESSION['user_id'];

// Obtener lista de unidades accesibles para el usuario
if ($userRol === 'SuperAdmin') {
    $unidadesDisponibles = $db->query("SELECT id, clave_unidad, nombre_unidad FROM unidades WHERE estatus='Activo' ORDER BY clave_unidad ASC")->fetchAll();
} else {
    $stmtU = $db->prepare("
        SELECT u.id, u.clave_unidad, u.nombre_unidad 
        FROM unidades u 
        INNER JOIN usuarios_unidades uu ON u.id = uu.unidad_id
        WHERE uu.usuario_id = ? AND u.estatus = 'Activo'
        ORDER BY u.clave_unidad ASC
    ");
    $stmtU->execute([$userId]);
    $unidadesDisponibles = $stmtU->fetchAll();
    
    // Fallback si no tiene registros en usuarios_unidades
    if (empty($unidadesDisponibles) && $userUnidadId) {
        $stmtU2 = $db->prepare("SELECT id, clave_unidad, nombre_unidad FROM unidades WHERE id = ?");
        $stmtU2->execute([$userUnidadId]);
        $unidadesDisponibles = $stmtU2->fetchAll();
    }
}
$unidadesIdsDisponibles = array_column($unidadesDisponibles, 'id');

// Filtro opcional por unidad seleccionada
$filtroUnidadId = filter_input(INPUT_GET, 'unidad_id', FILTER_VALIDATE_INT);
$selectedUnidadId = null;
if ($filtroUnidadId && ($userRol === 'SuperAdmin' || in_array($filtroUnidadId, $unidadesIdsDisponibles))) {
    $selectedUnidadId = $filtroUnidadId;
}

// Filtro opcional por estatus
$filtroEstatus = filter_input(INPUT_GET, 'estatus', FILTER_SANITIZE_SPECIAL_CHARS);

// Construir cláusula WHERE para unidades
$sqlWhereUnit = "";
if ($selectedUnidadId) {
    $sqlWhereUnit = " AND r.unidad_id = " . (int)$selectedUnidadId;
} elseif ($userRol !== 'SuperAdmin' && !empty($unidadesIdsDisponibles)) {
    $sqlWhereUnit = " AND r.unidad_id IN (" . implode(',', array_map('intval', $unidadesIdsDisponibles)) . ")";
} elseif ($userRol !== 'SuperAdmin' && empty($unidadesIdsDisponibles) && $userUnidadId) {
    $sqlWhereUnit = " AND r.unidad_id = " . (int)$userUnidadId;
}

// Consultar métricas de tickets
$sqlMetricas = "
    SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN estatus = 'Pendiente' THEN 1 ELSE 0 END) AS pendientes,
        SUM(CASE WHEN estatus = 'Asignado' THEN 1 ELSE 0 END) AS asignados,
        SUM(CASE WHEN estatus = 'Concluido' THEN 1 ELSE 0 END) AS concluidos,
        COALESCE(MAX(id), 0) AS max_id
    FROM reportes r
    WHERE 1=1 {$sqlWhereUnit}
";
$metricas = $db->query($sqlMetricas)->fetch();

// Consultar tickets para la tabla principal
$sqlTickets = "
    SELECT r.*, a.nombre_area, u.clave_unidad,
           p.nombre AS nombre_tecnico, p.categoria AS categoria_tecnico
    FROM reportes r
    INNER JOIN unidades u ON r.unidad_id = u.id
    INNER JOIN areas_maestro a ON r.area_id = a.id
    LEFT JOIN personal_operativo p ON r.tecnico_asignado_id = p.id
    WHERE 1=1 {$sqlWhereUnit}
";
if ($filtroEstatus && in_array($filtroEstatus, ['Pendiente', 'Asignado', 'Concluido'])) {
    $sqlTickets .= " AND r.estatus = " . $db->quote($filtroEstatus);
} else {
    $sqlTickets .= " AND r.estatus IN ('Pendiente', 'Asignado')";
}
$sqlTickets .= " ORDER BY r.fecha_recepcion DESC LIMIT 50";

$tickets = $db->query($sqlTickets)->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Conservación - <?= h($unidadInfo['clave_unidad'] ?? 'IMSS') ?></title>
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
        body {
            background-color: #f5f5f5;
            color: #333;
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        .header-custom {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 2.5rem;
            background-color: #f5f5f5;
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .header-left h1 {
            font-size: 1.4rem;
            font-weight: 700;
            color: #111;
            margin: 0;
        }
        .header-right {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        .profile-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-align: left;
        }
        .profile-icon {
            width: 38px;
            height: 38px;
            background-color: #666;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        .profile-text {
            line-height: 1.2;
        }
        .profile-title {
            font-weight: bold;
            font-size: 0.9rem;
            color: #222;
        }
        .profile-subtitle {
            font-size: 0.78rem;
            color: #666;
        }
        .bell-icon {
            position: relative;
            background-color: #1a1a1a;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            text-decoration: none;
        }
        .bell-icon .badge, .bell-icon .bell-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: #3b8c4c;
            color: white;
            font-size: 0.75rem;
            font-weight: bold;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
        }
        .layout-wrapper {
            display: flex;
            flex: 1;
            padding: 0 2.5rem 2.5rem 2.5rem;
            gap: 1.5rem;
        }
        .sidebar {
            width: 220px;
            background-color: #1a1a1a;
            border-radius: 12px;
            padding: 1rem 0;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            align-self: stretch;
        }
        .sidebar-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.85rem 1.5rem;
            color: #aaa;
            text-decoration: none;
            font-size: 0.95rem;
            transition: all 0.2s;
        }
        .sidebar-item:hover {
            color: white;
            background-color: #2a2a2a;
        }
        .sidebar-item.active {
            background-color: #333;
            color: white;
            border-left: 4px solid transparent; /* Assuming no border, just bg change in image */
        }
        .main-content {
            flex: 1;
            background-color: transparent;
        }
        .page-title {
            font-size: 1.75rem;
            font-weight: bold;
            color: #111;
            margin: 0 0 0.5rem 0;
        }
        .title-underline {
            width: 80px;
            height: 3px;
            background-color: #3b8c4c;
            margin-bottom: 2rem;
        }
        /* Tabla personalizada para admin con estilo blanco y gris */
        .admin-table {
            width: 100%;
            border-collapse: collapse;
            background: #FFFFFF;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .admin-table th {
            background-color: #e5e7eb;
            color: #374151 !important;
            font-weight: 700;
            padding: 0.85rem 1rem;
            text-align: left;
            border-bottom: 1px solid #d1d5db;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .admin-table td {
            padding: 0.85rem 1rem;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
            color: #374151 !important;
            font-size: 0.9rem;
            background-color: #FFFFFF;
        }
        .admin-table tbody tr {
            cursor: pointer;
            transition: background 0.15s;
        }
        .admin-table tbody tr:hover td {
            background-color: #f8fafc !important;
        }
        .clave-pill {
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
            color: #374151;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-rojo {
            color: #dc2626;
            font-weight: bold;
        }
        .status-verde {
            color: #16a34a;
            font-weight: bold;
        }
        .status-azul {
            color: #2563eb;
            font-weight: bold;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.4rem;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            margin: 0 0.15rem;
            width: 34px;
            height: 34px;
            vertical-align: middle;
            transition: background-color 0.15s ease;
        }
        .btn-atender {
            background-color: #e5e7eb;
            color: #374151;
            border: 1px solid #d1d5db;
        }
        .btn-atender:hover { background-color: #d1d5db; }
        .btn-imprimir {
            background-color: #1f2937;
            color: #FFFFFF;
            border: 1px solid #111827;
        }
        .btn-imprimir:hover { background-color: #374151; }
        .btn-orden {
            background-color: #2563eb;
            color: #FFFFFF;
            border: 1px solid #1d4ed8;
        }
        .btn-orden:hover { background-color: #1d4ed8; }

        /* Filtro de Unidad */
        .filter-card {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: #FFFFFF;
            padding: 0.4rem 0.75rem;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            border: 1px solid #e5e7eb;
        }
        .unit-select {
            background-color: #1f2937 !important;
            color: #FFFFFF !important;
            border: 1px solid #374151 !important;
            border-radius: 6px;
            padding: 0.35rem 0.65rem;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
        }
        .unit-select option {
            background-color: #1f2937 !important;
            color: #FFFFFF !important;
            padding: 6px 10px;
        }

        /* Autocomplete Dropdown */
        .autocomplete-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #242424;
            border: 1px solid #444;
            border-radius: 6px;
            max-height: 220px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 0 8px 20px rgba(0,0,0,0.5);
            margin-top: 2px;
        }
        .autocomplete-item {
            padding: 0.6rem 0.85rem;
            border-bottom: 1px solid #333;
            cursor: pointer;
            transition: background 0.15s;
        }
        .autocomplete-item:hover, .autocomplete-item.active {
            background: #2b7a4b;
        }
        .autocomplete-item:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>
    <header class="header-custom">
        <div class="header-left">
            <button type="button" class="sidebar-toggle-btn" onclick="toggleMobileSidebar()" aria-label="Menú">
                <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"></path></svg>
            </button>
            <div style="display:flex; align-items:center; gap:0.4rem; font-weight:bold; color:#0d4d35; font-size:1.3rem; line-height:1.1;">
                <img src="../../Images/IMSS.png" alt="Logo IMSS" style="width: 45px; height: auto;">
                <div>
                    <div>IMSS</div>
                    <div style="font-size:0.5rem; color:#333; font-weight:normal;">INSTITUTO MEXICANO<br>DEL SEGURO SOCIAL</div>
                </div>
            </div>
            <h1>Panel de Conservación</h1>
        </div>
        <div class="header-right">
            <div class="profile-info">
                <div class="profile-icon">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2m8-10a4 4 0 100-8 4 4 0 000 8z"></path></svg>
                </div>
                <div class="profile-text">
                    <div class="profile-title"><?= h($userRol) ?> de Conservación</div>
                    <div class="profile-subtitle">(Sesión: [<?= h($_SESSION['user_name']) ?>])</div>
                </div>
            </div>
            <a href="dashboard.php" class="bell-icon" title="Ver Tickets" onclick="location.reload(); return false;">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                <?php if ($metricas['pendientes'] > 0): ?>
                    <span class="badge"><?= (int)$metricas['pendientes'] ?></span>
                <?php endif; ?>
            </a>
            <a href="logout.php" style="color: #666; margin-left: 1rem; text-decoration: none;" title="Cerrar Sesión">
                <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
            </a>
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
            <a href="dashboard.php" class="sidebar-item <?= ($filtroEstatus !== 'Concluido') ? 'active' : '' ?>">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                Reportes Activos
            </a>
            <a href="dashboard.php?estatus=Concluido" class="sidebar-item <?= ($filtroEstatus === 'Concluido') ? 'active' : '' ?>">
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
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem; margin-bottom:1.25rem;">
                <div>
                    <h2 class="page-title" style="margin:0 0 0.35rem 0;"><?= ($filtroEstatus === 'Concluido') ? 'Historial de Servicios' : 'Reportes Activos' ?></h2>
                    <div class="title-underline" style="margin-bottom:0;"></div>
                </div>
                <?php if (count($unidadesDisponibles) > 1 || $userRol === 'SuperAdmin'): ?>
                    <form method="GET" class="filter-card">
                        <?php if ($filtroEstatus): ?><input type="hidden" name="estatus" value="<?= h($filtroEstatus) ?>"><?php endif; ?>
                        <label style="font-size:0.85rem; font-weight:700; color:#374151;">Unidad:</label>
                        <select name="unidad_id" onchange="this.form.submit()" class="unit-select">
                            <option value="" style="background-color:#1f2937; color:#FFFFFF;">-- Todas mis unidades (<?= count($unidadesDisponibles) ?>) --</option>
                            <?php foreach ($unidadesDisponibles as $ud): ?>
                                <option value="<?= $ud['id'] ?>" <?= ($selectedUnidadId == $ud['id']) ? 'selected' : '' ?> style="background-color:#1f2937; color:#FFFFFF;">
                                    <?= h($ud['clave_unidad']) ?> - <?= h($ud['nombre_unidad']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php endif; ?>
            </div>

            <div class="table-scroll-container">
            <table class="admin-table" id="adminDashboardTable" data-last-id="<?= (int)$metricas['max_id'] ?>">
                <thead>
                    <tr>
                        <th>Folio</th>
                        <?php if (!$selectedUnidadId && (count($unidadesDisponibles) > 1 || $userRol === 'SuperAdmin')): ?>
                            <th>Unidad</th>
                        <?php endif; ?>
                        <th>Área</th>
                        <th>Falla Exacta</th>
                        <th>Hora Recepción</th>
                        <th>Estatus</th>
                        <th style="text-align:center;">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tickets)): ?>
                        <tr>
                            <td colspan="<?= (!$selectedUnidadId && (count($unidadesDisponibles) > 1 || $userRol === 'SuperAdmin')) ? '7' : '6' ?>" style="text-align: center; color: #888; padding: 2rem;">
                                No hay reportes de servicio registrados.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($tickets as $t): ?>
                            <tr class="ticket-row" onclick="abrirModalEdicionTicket(<?= (int)$t['id'] ?>, event)" title="Haga clic para editar datos del ticket">
                                <td style="color: #111827; font-weight:700;">
                                    <?= h($t['folio']) ?>
                                </td>
                                <?php if (!$selectedUnidadId && (count($unidadesDisponibles) > 1 || $userRol === 'SuperAdmin')): ?>
                                    <td><span class="clave-pill"><?= h($t['clave_unidad']) ?></span></td>
                                <?php endif; ?>
                                <td style="color: #374151;">
                                    <?= h($t['nombre_area']) ?>
                                </td>
                                <td style="color: #374151;">
                                    <?= h(strlen($t['descripcion_falla']) > 40 ? substr($t['descripcion_falla'], 0, 40) . '...' : $t['descripcion_falla']) ?>
                                </td>
                                <td style="color: #374151; white-space:nowrap;">
                                    <?= date('h:i A', strtotime($t['fecha_recepcion'])) ?>
                                </td>
                                <td>
                                    <?php if ($t['estatus'] === 'Pendiente'): ?>
                                        <span class="status-rojo">Pendiente</span>
                                    <?php elseif ($t['estatus'] === 'Asignado'): ?>
                                        <span class="status-azul">Asignado</span>
                                    <?php else: ?>
                                        <span class="status-verde">Concluido</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center; white-space:nowrap;">
                                    <button onclick="event.stopPropagation(); gestionarTicket(<?= (int)$t['id'] ?>)" class="btn-action btn-atender" title="Atender / Gestionar Ticket">
                                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                    </button>
                                    <a href="../reporte_pdf.php?id=<?= (int)$t['id'] ?>" target="_blank" onclick="event.stopPropagation();" class="btn-action btn-imprimir" title="Imprimir Ticket">
                                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                                    </a>
                                    <?php if ($t['estatus'] === 'Asignado' || $t['estatus'] === 'Concluido'): ?>
                                    <a href="../orden_servicio.php?id=<?= (int)$t['id'] ?>" target="_blank" onclick="event.stopPropagation();" class="btn-action btn-orden" title="Imprimir Orden y Entrega de Servicio" style="background:#2563EB;">
                                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path></svg>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </main>
    </div>


    <!-- Modal Dinámico de Gestión (Asignación y Cierre de Tickets) -->
    <div id="modalGestionTicket" class="modal-overlay">
        <div class="modal-content">
            <div class="card-header">
                <div>
                    <h3 style="font-size: 1.25rem; font-weight: 800; color: #FFFFFF;">Gestión de Orden de Servicio</h3>
                    <div style="font-size: 0.85rem; color: var(--imss-green-light); font-weight: 700;" id="mgFolio">UMF40-2600000</div>
                </div>
                <button onclick="closeModal('modalGestionTicket')" class="btn" style="background:transparent; color:#9CA3AF; font-size:1.25rem;">✖</button>
            </div>

            <input type="hidden" id="mgTicketId">

            <!-- Resumen del Ticket -->
            <div style="background: var(--dark-card); padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.25rem; font-size: 0.85rem; color: #FFFFFF;">
                <div style="display:flex; justify-content:space-between; margin-bottom: 0.35rem;">
                    <span><strong style="color:#FACC15;">Área:</strong> <span id="mgArea">---</span></span>
                    <span><strong style="color:#FACC15;">Recepción:</strong> <span id="mgFechaRecepcion">---</span></span>
                </div>
                <div style="margin-bottom: 0.35rem;"><strong style="color:#FACC15;">Solicita:</strong> <span id="mgSolicitante">---</span></div>
                <div><strong style="color:#FACC15;">Trabajo Reportado:</strong> <span id="mgFalla">---</span></div>
            </div>

            <!-- FASE 1: ASIGNACIÓN DE TÉCNICO (CON AUTOCOMPLETADO GLOBAL) -->
            <div id="seccionAsignacion" style="display: none;">
                <h4 style="font-size: 0.95rem; font-weight: 700; color: #FACC15; margin-bottom: 0.75rem;">
                    Fase de Asignación Operativa
                </h4>
                <div class="form-group" style="position:relative; margin-bottom: 1rem;">
                    <label for="autoTecnicoInput" style="color:#FFFFFF; font-weight:700; font-size:0.85rem; margin-bottom:0.35rem; display:block;">
                        Técnico de Conservación Responsable *
                    </label>
                    <div id="tecnicoInputContainer" style="position:relative;">
                        <input type="text" id="autoTecnicoInput" placeholder="Escriba nombre o matrícula del técnico (ej. Carlos, 9901...)" autocomplete="off" style="width:100%; background:#242424; color:#FFFFFF; border:1px solid #555; padding:0.55rem 0.75rem; border-radius:6px; font-size:0.9rem;">
                        <div id="autoTecnicoDropdown" class="autocomplete-dropdown" style="display:none;"></div>
                    </div>
                    <!-- Badge de técnico seleccionado -->
                    <div id="autoTecnicoSeleccionado" style="display:none; margin-top:0.5rem; background:#163828; border:1px solid #2b7a4b; padding:0.6rem 0.85rem; border-radius:6px; color:#FFFFFF; align-items:center; justify-content:space-between;">
                        <div>
                            <div style="font-weight:bold; color:#4ADE80; font-size:0.92rem;" id="autoTecnicoNombre">---</div>
                            <div style="font-size:0.78rem; color:#A7F3D0;" id="autoTecnicoDetalle">---</div>
                        </div>
                        <button type="button" onclick="limpiarTecnicoSeleccionado()" class="btn" style="background:#374151; color:#F3F4F6; padding:0.25rem 0.6rem; font-size:0.75rem; border-radius:4px; cursor:pointer;">
                            Cambiar
                        </button>
                    </div>
                    <input type="hidden" id="selectTecnicoAsignar" value="">
                </div>
                <div class="form-group" style="margin-bottom: 1rem;">
                    <label for="mgInstruccionesAsignacion" style="color:#FFFFFF; font-weight:700; font-size:0.85rem; margin-bottom:0.35rem; display:block;">Instrucciones u Observaciones Iniciales</label>
                    <textarea id="mgInstruccionesAsignacion" rows="2" placeholder="Ej. Atender con prioridad, llevar kit de empaques o repuestos" style="width:100%; background:#242424; color:#FFFFFF; border:1px solid #555; padding:0.5rem 0.75rem; border-radius:6px; font-size:0.88rem;"></textarea>
                </div>
                <button onclick="enviarAsignacion()" class="btn btn-primary" style="width: 100%;">
                    ✓ Confirmar y Asignar Técnico
                </button>
            </div>

            <!-- FASE 2: CIERRE CON EVIDENCIA FOTOGRÁFICA -->
            <div id="seccionCierre" style="display: none;">
                <h4 style="font-size: 0.95rem; font-weight: 700; color: #60A5FA; margin-bottom: 0.75rem;">
                    Fase de Cierre y Conclusión de Trabajo
                </h4>
                <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1rem;">
                    Técnico actual a cargo: <strong id="mgTecnicoAsignadoNombre" style="color: #FFFFFF;">---</strong>
                </p>

                <form id="formCierreTicket" enctype="multipart/form-data">
                    <div class="grid grid-cols-2" style="gap:0.75rem; margin-bottom: 1rem; border:1px solid #444; padding:0.75rem; border-radius:8px; background:#242424;">
                        <div>
                            <label for="fecha_recepcion_edit" style="font-size:0.8rem; color:#FACC15; margin-bottom:0.3rem; display:block; font-weight:700;">Fecha y Hora de Recepción *</label>
                            <input type="datetime-local" id="fecha_recepcion_edit" name="fecha_recepcion_edit" style="background:#1a1a1a; color:white; border:1px solid #444; padding:0.4rem; border-radius:4px; width:100%;">
                        </div>
                        <div>
                            <label for="fecha_conclusion_manual" style="font-size:0.8rem; color:#4ADE80; margin-bottom:0.3rem; display:block; font-weight:700;">Fecha y Hora de Cierre</label>
                            <input type="datetime-local" id="fecha_conclusion_manual" name="fecha_conclusion_manual" style="background:#1a1a1a; color:white; border:1px solid #444; padding:0.4rem; border-radius:4px; width:100%;">
                        </div>
                    </div>
                    <!-- Tipo de Origen del Servicio -->
                    <div class="form-group" style="margin-bottom: 1rem;">
                        <label style="margin-bottom: 0.5rem; display:block; font-weight:700; color:#D1D5DB;">Tipo de Origen del Servicio *</label>
                        <div style="display:flex; gap:1.5rem; align-items:center; background:#242424; padding:0.6rem 1rem; border-radius:8px; border:1px solid #444;">
                            <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; color:#FFFFFF; margin-bottom:0; font-size:0.9rem;">
                                <input type="radio" name="tipo_servicio" id="tipoServicioCorrectivo" value="Correctivo" checked style="accent-color:#3b8c4c; width:auto;">
                                <strong>Correctivo</strong>
                            </label>
                            <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; color:#FFFFFF; margin-bottom:0; font-size:0.9rem;">
                                <input type="radio" name="tipo_servicio" id="tipoServicioPreventivo" value="Preventivo" style="accent-color:#3b8c4c; width:auto;">
                                <strong>Preventivo</strong>
                            </label>
                        </div>
                    </div>

                    <!-- Toggle Sin Fotos -->
                    <div style="margin-bottom: 1rem; display: flex; align-items: center; gap: 0.75rem;">
                        <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-size:0.9rem; color:#D1D5DB; margin-bottom:0;">
                            <input type="checkbox" id="chkSinFotos" onchange="toggleFotos()" style="width:auto; accent-color:#3b8c4c;">
                            <strong>Sin Fotos</strong> — No adjuntar evidencia fotográfica
                        </label>
                    </div>

                    <div id="seccionFotos">
                        <!-- Fotos ANTES -->
                        <div style="margin-bottom: 1rem; padding: 0.75rem; background: rgba(234,179,8,0.1); border: 1px solid rgba(234,179,8,0.3); border-radius: 8px;">
                            <div style="font-size: 0.85rem; font-weight: 700; color: #FACC15; margin-bottom: 0.5rem;">📷 Fotos ANTES del Servicio</div>
                            <div class="grid grid-cols-2">
                                <div class="form-group" style="margin-bottom:0.5rem;">
                                    <label for="foto_antes_1" style="font-size:0.8rem;">Foto Antes #1</label>
                                    <input type="file" id="foto_antes_1" name="foto_antes_1" accept="image/*">
                                </div>
                                <div class="form-group" style="margin-bottom:0.5rem;">
                                    <label for="foto_antes_2" style="font-size:0.8rem;">Foto Antes #2 (Opcional)</label>
                                    <input type="file" id="foto_antes_2" name="foto_antes_2" accept="image/*">
                                </div>
                            </div>
                        </div>

                        <!-- Fotos DESPUÉS -->
                        <div style="margin-bottom: 1rem; padding: 0.75rem; background: rgba(59,140,76,0.1); border: 1px solid rgba(59,140,76,0.3); border-radius: 8px;">
                            <div style="font-size: 0.85rem; font-weight: 700; color: #4ADE80; margin-bottom: 0.5rem;">📷 Fotos DESPUÉS del Servicio</div>
                            <div class="grid grid-cols-2">
                                <div class="form-group" style="margin-bottom:0.5rem;">
                                    <label for="foto_despues_1" style="font-size:0.8rem;">Foto Después #1</label>
                                    <input type="file" id="foto_despues_1" name="foto_despues_1" accept="image/*">
                                </div>
                                <div class="form-group" style="margin-bottom:0.5rem;">
                                    <label for="foto_despues_2" style="font-size:0.8rem;">Foto Después #2 (Opcional)</label>
                                    <input type="file" id="foto_despues_2" name="foto_despues_2" accept="image/*">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="mgObservacionesCierre">Descripción Completa del Servicio / Solución Aplicada *</label>
                        <textarea id="mgObservacionesCierre" name="observaciones_cierre" rows="3" placeholder="Detalle los trabajos efectuados y la solución definitiva." required></textarea>
                    </div>

                    <div class="form-group">
                        <label for="mgMaterialesRefacciones">Observaciones, Materiales y Refacciones Empleados</label>
                        <textarea id="mgMaterialesRefacciones" name="materiales_refacciones" rows="2" placeholder="Ej. 2 METROS DE CABLE CALIBRE 12, PINZAS DE ELECTRICISTA, CINTA AISLANTE..."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="mgCondicionFinal">Condición Final de Funcionamiento</label>
                        <textarea id="mgCondicionFinal" name="condicion_final" rows="2" placeholder="Ej. EQUIPO O INSTALACIÓN QUEDA EN ESTADO FUNCIONAL Y OPERATIVO"></textarea>
                    </div>

                    <div class="form-group">
                        <label for="mgRecomendaciones">Recomendaciones</label>
                        <textarea id="mgRecomendaciones" name="recomendaciones" rows="2" placeholder="Ej. SE RECOMIENDA MANTENIMIENTO PREVENTIVO PERIÓDICO Y OPERAR CONFORME A MANUAL"></textarea>
                    </div>

                    <!-- Datos de Recepción de Conformidad -->
                    <div style="margin-top: 1rem; margin-bottom: 1.25rem; padding: 0.75rem; background: rgba(59,130,246,0.1); border: 1px solid rgba(59,130,246,0.3); border-radius: 8px;">
                        <div style="font-size: 0.85rem; font-weight: 700; color: #60A5FA; margin-bottom: 0.5rem; display: flex; justify-content: space-between; align-items: center;">
                            <span>âœï¸ Recibe de Conformidad (Área Médica / Solicitante)</span>
                            <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-size:0.8rem; color:#D1D5DB; margin-bottom:0;">
                                <input type="checkbox" id="chkSinRecepcion" onchange="toggleRecepcion()" style="width:auto; accent-color:#3b8c4c;">
                                <strong>Sin Recepción</strong>
                            </label>
                        </div>
                        <div id="seccionRecepcion" class="grid grid-cols-2" style="gap:0.75rem;">
                            <div class="form-group" style="margin-bottom:0;">
                                <label for="mgRecibeNombre" style="font-size:0.8rem;">Nombre de quien Recibe</label>
                                <input type="text" id="mgRecibeNombre" name="recibe_nombre" placeholder="Ej. DRA. MARÍA GONZÁLEZ">
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label for="mgRecibeMatricula" style="font-size:0.8rem;">Matrícula o Puesto</label>
                                <input type="text" id="mgRecibeMatricula" name="recibe_matricula" placeholder="Ej. 99482103 / JEFA DE PISO">
                            </div>
                        </div>
                    </div>

                    <button type="button" onclick="enviarCierre()" class="btn btn-success" style="width: 100%;">
                        ✅ Concluir Orden de Servicio
                    </button>
                </form>
            </div>

            <!-- FASE 3: DETALLE DE TICKET CONCLUIDO -->
            <div id="seccionConcluido" style="display: none; text-align: center;">
                <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">🎉</div>
                <h4 style="font-size: 1.1rem; font-weight: 700; color: var(--imss-green-light); margin-bottom: 0.5rem;">
                    Orden de Servicio Concluida
                </h4>
                <p style="font-size: 0.85rem; color: #D1D5DB; margin-bottom: 0.5rem;">
                    Atendida por <strong id="mgConcluidoTecnico" style="color: #FFFFFF;">---</strong> el <span id="mgConcluidoFecha" style="color:#FFFFFF;">---</span>.
                </p>
                <div style="font-size: 0.8rem; color: #9CA3AF; margin-bottom: 1rem;">
                    Asignó / Elaboró: <strong id="mgConcluidoElaboro" style="color: #FACC15;">---</strong> | Origen: <strong id="mgConcluidoOrigen" style="color:#4ADE80;">---</strong>
                </div>
                <div style="background: var(--dark-card); padding: 0.75rem; border-radius: 0.5rem; text-align: left; margin-bottom: 0.75rem; font-size: 0.85rem;">
                    <strong style="color:#FACC15;">Descripción del Servicio / Solución:</strong>
                    <div id="mgConcluidoObs" style="color: #FFFFFF; margin-top: 0.25rem;">---</div>
                </div>
                <div style="background: var(--dark-card); padding: 0.75rem; border-radius: 0.5rem; text-align: left; margin-bottom: 0.75rem; font-size: 0.85rem;">
                    <strong style="color:#FACC15;">Materiales y Refacciones:</strong>
                    <div id="mgConcluidoMateriales" style="color: #FFFFFF; margin-top: 0.25rem;">---</div>
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.5rem; margin-bottom: 0.75rem;">
                    <div style="background: var(--dark-card); padding: 0.75rem; border-radius: 0.5rem; text-align: left; font-size: 0.85rem;">
                        <strong style="color:#60A5FA;">Condición Final:</strong>
                        <div id="mgConcluidoCondicion" style="color: #FFFFFF; margin-top: 0.25rem;">---</div>
                    </div>
                    <div style="background: var(--dark-card); padding: 0.75rem; border-radius: 0.5rem; text-align: left; font-size: 0.85rem;">
                        <strong style="color:#60A5FA;">Recomendaciones:</strong>
                        <div id="mgConcluidoRecomendaciones" style="color: #FFFFFF; margin-top: 0.25rem;">---</div>
                    </div>
                </div>
                <div style="background: var(--dark-card); padding: 0.75rem; border-radius: 0.5rem; text-align: left; margin-bottom: 1.25rem; font-size: 0.85rem;">
                    <strong style="color:#60A5FA;">Recibe de Conformidad:</strong>
                    <div style="color:#FFFFFF; margin-top:0.25rem;">
                        <span id="mgConcluidoRecibeNombre">---</span> <span id="mgConcluidoRecibeMatricula" style="color:#9CA3AF;"></span>
                    </div>
                </div>

                <!-- EVIDENCIA FOTOGRÁFICA -->
                <div id="seccionEvidencia" style="margin-bottom: 1.25rem; display:none;">
                    <h4 style="font-size: 1rem; font-weight: 700; color: #FACC15; margin-bottom: 0.75rem;">📷 EVIDENCIA FOTOGRÁFICA</h4>
                    <div style="display: flex; gap: 0.75rem; justify-content: center;">
                        <button type="button" id="btnVerAntes" onclick="verFotos('antes')" class="btn btn-secondary" style="flex:1;">
                            📷 ANTES
                        </button>
                        <button type="button" id="btnVerDespues" onclick="verFotos('despues')" class="btn btn-secondary" style="flex:1;">
                            📷 DESPUÉS
                        </button>
                    </div>
                </div>
                <div id="noEvidencia" style="margin-bottom: 1.25rem; display:none;">
                    <p style="color:#9CA3AF; font-size:0.85rem;">No se adjuntó evidencia fotográfica.</p>
                </div>

                <button type="button" onclick="editarCierre()" class="btn btn-secondary" style="width: 100%; margin-bottom: 1rem; background: #4B5563; border-color: #4B5563;">
                    âœï¸ Editar Cierre (Añadir Fotos / Modificar Datos)
                </button>

                <a id="mgBtnPdfNormado" href="#" target="_blank" class="btn btn-primary" style="width: 100%;">
                    🖨� Imprimir Formato Institucional Normado (Clave 14C2-003-002)
                </a>
            </div>
        </div>
    </div>

    <!-- Modal Visor de Evidencias -->
    <div id="modalVisorFotos" class="modal-overlay" style="z-index:2000;">
        <div class="modal-content" style="max-width: 800px; text-align:center;">
            <div class="card-header" style="margin-bottom: 1rem;">
                <h3 style="font-size: 1.25rem; font-weight: 800; color: #FFFFFF;" id="visorTitulo">Evidencia</h3>
                <button onclick="closeModal('modalVisorFotos')" class="btn" style="background:transparent; color:#9CA3AF; font-size:1.25rem;">✖</button>
            </div>
            <div id="visorContenedor" class="grid grid-cols-2" style="gap:1rem; align-items:center;">
                <!-- Las imagenes se inyectarán aquí -->
            </div>
        </div>
    </div>

    <!-- Modal Pop-Up de Edición de Ticket -->
    <div id="modalEditarTicket" class="modal-overlay" style="z-index:1500;">
        <div class="modal-content" style="max-width: 760px; max-height: 90vh; overflow-y: auto;">
            <div class="card-header" style="margin-bottom: 1rem;">
                <div>
                    <h3 style="font-size: 1.25rem; font-weight: 800; color: #FFFFFF;">Editar Datos de Ticket</h3>
                    <div style="font-size: 0.85rem; color: #4ADE80; font-weight: 700;" id="editModalFolio">---</div>
                </div>
                <button onclick="closeModal('modalEditarTicket')" class="btn" style="background:transparent; color:#9CA3AF; font-size:1.25rem;">✖</button>
            </div>

            <form id="formEditarTicket">
                <input type="hidden" id="editTicketId" name="ticket_id">

                <div class="grid grid-cols-2" style="gap: 0.75rem; margin-bottom: 0.75rem;">
                    <div>
                        <label style="font-size:0.82rem; font-weight:700; color:#FFFFFF; margin-bottom:0.25rem; display:block;">Fecha de Recepción *</label>
                        <input type="datetime-local" id="editFechaRecepcion" name="fecha_recepcion" style="background:#242424; color:#FFFFFF; border:1px solid #444; padding:0.45rem 0.65rem; border-radius:6px; width:100%;" required>
                    </div>
                    <div>
                        <label style="font-size:0.82rem; font-weight:700; color:#FFFFFF; margin-bottom:0.25rem; display:block;">Unidad Médica *</label>
                        <select id="editUnidadId" name="unidad_id" onchange="cargarAreasEdicion(this.value)" style="background:#242424; color:#FFFFFF; border:1px solid #444; padding:0.45rem 0.65rem; border-radius:6px; width:100%;" required>
                            <?php foreach ($unidadesDisponibles as $ud): ?>
                                <option value="<?= $ud['id'] ?>"><?= h($ud['clave_unidad']) ?> - <?= h($ud['nombre_unidad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2" style="gap: 0.75rem; margin-bottom: 0.75rem;">
                    <div>
                        <label style="font-size:0.82rem; font-weight:700; color:#FFFFFF; margin-bottom:0.25rem; display:block;">Área o Ubicación *</label>
                        <select id="editAreaId" name="area_id" style="background:#242424; color:#FFFFFF; border:1px solid #444; padding:0.45rem 0.65rem; border-radius:6px; width:100%;" required>
                            <option value="">-- Seleccione un área --</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:0.82rem; font-weight:700; color:#FFFFFF; margin-bottom:0.25rem; display:block;">Categoría / Puesto del Solicitante</label>
                        <input type="text" id="editCargoSolicitante" name="cargo_solicitante" placeholder="Ej. JEFE DE PISO, ENFERMERA..." style="background:#242424; color:#FFFFFF; border:1px solid #444; padding:0.45rem 0.65rem; border-radius:6px; width:100%;">
                    </div>
                </div>

                <div style="margin-bottom: 0.75rem;">
                    <label style="font-size:0.82rem; font-weight:700; color:#FFFFFF; margin-bottom:0.25rem; display:block;">Descripción del Trabajo / Falla *</label>
                    <textarea id="editDescripcionFalla" name="descripcion_falla" rows="2" style="background:#242424; color:#FFFFFF; border:1px solid #444; padding:0.45rem 0.65rem; border-radius:6px; width:100%;" required></textarea>
                </div>

                <div style="margin-bottom: 0.75rem;">
                    <label style="font-size:0.82rem; font-weight:700; color:#FFFFFF; margin-bottom:0.25rem; display:block;">Datos del Solicitante</label>
                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:0.6rem;">
                        <div>
                            <label style="font-size:0.75rem; color:#A1A1AA; display:block; margin-bottom:0.15rem;">Apellido Paterno</label>
                            <input type="text" id="editSolicitanteApPaterno" name="solicitante_ap_paterno" style="background:#242424; color:#FFFFFF; border:1px solid #444; padding:0.4rem 0.6rem; border-radius:6px; width:100%;">
                        </div>
                        <div>
                            <label style="font-size:0.75rem; color:#A1A1AA; display:block; margin-bottom:0.15rem;">Apellido Materno</label>
                            <input type="text" id="editSolicitanteApMaterno" name="solicitante_ap_materno" style="background:#242424; color:#FFFFFF; border:1px solid #444; padding:0.4rem 0.6rem; border-radius:6px; width:100%;">
                        </div>
                        <div>
                            <label style="font-size:0.75rem; color:#A1A1AA; display:block; margin-bottom:0.15rem;">Nombre(s) *</label>
                            <input type="text" id="editSolicitanteNombres" name="solicitante_nombres" style="background:#242424; color:#FFFFFF; border:1px solid #444; padding:0.4rem 0.6rem; border-radius:6px; width:100%;" required>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom: 1rem;">
                    <label style="font-size:0.82rem; font-weight:700; color:#FFFFFF; margin-bottom:0.25rem; display:block;">Observaciones</label>
                    <textarea id="editObservaciones" name="observaciones" rows="1" style="background:#242424; color:#FFFFFF; border:1px solid #444; padding:0.45rem 0.65rem; border-radius:6px; width:100%;"></textarea>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:0.75rem;">
                    <button type="button" onclick="closeModal('modalEditarTicket')" class="btn btn-secondary" style="padding:0.5rem 1.25rem;">
                        Cancelar
                    </button>
                    <button type="button" id="btnGuardarEdicionTicket" onclick="guardarEdicionTicket()" class="btn btn-primary" style="padding:0.5rem 1.5rem;">
                        💾 Guardar Cambios
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="../assets/js/app.js"></script>
    <script>
        async function enviarAsignacion() {
            const ticketId = document.getElementById('mgTicketId').value;
            const tecnicoId = document.getElementById('selectTecnicoAsignar').value;
            const instrucciones = document.getElementById('mgInstruccionesAsignacion').value;

            if (!tecnicoId) {
                alert('Debe seleccionar un técnico de la lista.');
                return;
            }

            const formData = new FormData();
            formData.append('ticket_id', ticketId);
            formData.append('tecnico_id', tecnicoId);
            formData.append('instrucciones', instrucciones);

            try {
                const res = await fetch('api.php?action=assign_technician', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    alert('Técnico asignado exitosamente.');
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (e) {
                alert('Error al comunicar con el servidor.');
            }
        }

        function toggleFotos() {
            const sinFotos = document.getElementById('chkSinFotos').checked;
            document.getElementById('seccionFotos').style.display = sinFotos ? 'none' : 'block';
        }

        function toggleRecepcion() {
            const sinRecepcion = document.getElementById('chkSinRecepcion').checked;
            document.getElementById('seccionRecepcion').style.display = sinRecepcion ? 'none' : 'grid';
            if (sinRecepcion) {
                document.getElementById('mgRecibeNombre').value = '';
                document.getElementById('mgRecibeMatricula').value = '';
            }
        }

        async function enviarCierre() {
            const ticketId = document.getElementById('mgTicketId').value;
            const obs = document.getElementById('mgObservacionesCierre').value;

            if (!obs.trim()) {
                alert('Por favor ingrese las observaciones de solución/cierre.');
                return;
            }

            const form = document.getElementById('formCierreTicket');
            const formData = new FormData(form);
            formData.append('ticket_id', ticketId);

            // Formatear la fecha de recepcion si se modificó
            const dtRecManual = document.getElementById('fecha_recepcion_edit').value;
            if (dtRecManual) {
                const dtR = new Date(dtRecManual);
                if (!isNaN(dtR)) {
                    const tzo = dtR.getTimezoneOffset() * 60000;
                    const mySqlDateR = new Date(dtR.getTime() - tzo).toISOString().slice(0, 19).replace('T', ' ');
                    formData.set('fecha_recepcion_edit', mySqlDateR);
                }
            }

            // Formatear la fecha manual de conclusion si se proporcionó
            const dtManual = document.getElementById('fecha_conclusion_manual').value;
            if (dtManual) {
                const dt = new Date(dtManual);
                if (!isNaN(dt)) {
                    const tzo = dt.getTimezoneOffset() * 60000;
                    const mySqlDate = new Date(dt.getTime() - tzo).toISOString().slice(0, 19).replace('T', ' ');
                    formData.set('fecha_conclusion_manual', mySqlDate);
                }
            }

            // Agregar flag de sin fotos
            if (document.getElementById('chkSinFotos').checked) {
                formData.append('sin_fotos', '1');
            }

            try {
                const res = await fetch('api.php?action=close_ticket', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    alert('Orden de servicio guardada/concluida exitosamente.');
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (e) {
                alert('Error al comunicar con el servidor.');
            }
        }

        function editarCierre() {
            document.getElementById('seccionConcluido').style.display = 'none';
            document.getElementById('seccionCierre').style.display = 'block';
            
            const t = window.ticketActual;
            if (t) {
                document.getElementById('mgTecnicoAsignadoNombre').textContent = t.nombre_tecnico || 'Asignado';
                document.getElementById('mgObservacionesCierre').value = t.solucion_cierre || '';
                document.getElementById('mgMaterialesRefacciones').value = t.materiales_refacciones || '';
                document.getElementById('mgCondicionFinal').value = t.condicion_final || '';
                document.getElementById('mgRecomendaciones').value = t.recomendaciones || '';
                document.getElementById('mgRecibeNombre').value = t.recibe_nombre || '';
                document.getElementById('mgRecibeMatricula').value = t.recibe_matricula || '';
                
                if (t.tipo_servicio === 'Preventivo') {
                    document.getElementById('tipoServicioPreventivo').checked = true;
                } else {
                    document.getElementById('tipoServicioCorrectivo').checked = true;
                }
                
                // Pre-llenar fecha de recepcion
                if (t.fecha_recepcion) {
                    const dtR = new Date(t.fecha_recepcion.replace(' ', 'T'));
                    if (!isNaN(dtR)) {
                        const tzo = dtR.getTimezoneOffset() * 60000;
                        const localISOTimeR = (new Date(dtR.getTime() - tzo)).toISOString().slice(0, 16);
                        document.getElementById('fecha_recepcion_edit').value = localISOTimeR;
                    }
                }

                // Pre-llenar fecha de conclusion
                if (t.fecha_conclusion) {
                    const dt = new Date(t.fecha_conclusion.replace(' ', 'T'));
                    if (!isNaN(dt)) {
                        const tzo = dt.getTimezoneOffset() * 60000;
                        const localISOTime = (new Date(dt.getTime() - tzo)).toISOString().slice(0, 16);
                        document.getElementById('fecha_conclusion_manual').value = localISOTime;
                    }
                }
            }
        }
    </script>
</body>
</html>

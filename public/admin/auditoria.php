<?php
/**
 * Panel de Tableros de control y Analítica de Conservación IMSS
 */
require_once __DIR__ . '/../../config/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$db = Database::getConnection();
$userId = $_SESSION['user_id'];
$userRol = $_SESSION['user_rol'];
$userUnidadId = $_SESSION['user_unidad_id'];
$userName = $_SESSION['user_name'];

// 1. Obtener lista de unidades accesibles para el usuario
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
    
    if (empty($unidadesDisponibles) && $userUnidadId) {
        $stmtU2 = $db->prepare("SELECT id, clave_unidad, nombre_unidad FROM unidades WHERE id = ?");
        $stmtU2->execute([$userUnidadId]);
        $unidadesDisponibles = $stmtU2->fetchAll();
    }
}
$unidadesIdsDisponibles = array_column($unidadesDisponibles, 'id');

// 2. Filtros
$filtroUnidadId = filter_input(INPUT_GET, 'unidad_id', FILTER_VALIDATE_INT);
$selectedUnidadId = null;
if ($filtroUnidadId && ($userRol === 'SuperAdmin' || in_array($filtroUnidadId, $unidadesIdsDisponibles))) {
    $selectedUnidadId = $filtroUnidadId;
}

$rangoFechas = filter_input(INPUT_GET, 'rango_fechas', FILTER_SANITIZE_SPECIAL_CHARS);
$fechaInicio = filter_input(INPUT_GET, 'fecha_inicio', FILTER_SANITIZE_SPECIAL_CHARS);
$fechaFin = filter_input(INPUT_GET, 'fecha_fin', FILTER_SANITIZE_SPECIAL_CHARS);

if (!empty($rangoFechas) && (empty($fechaInicio) || empty($fechaFin))) {
    $partes = preg_split('/\s+(?:to|a)\s+/i', trim($rangoFechas));
    if (count($partes) >= 2) {
        $fechaInicio = trim($partes[0]);
        $fechaFin = trim($partes[1]);
    } elseif (count($partes) === 1 && !empty($partes[0])) {
        $fechaInicio = trim($partes[0]);
        $fechaFin = trim($partes[0]);
    }
}

// Periodo por defecto: Año en curso
if (!$fechaInicio) {
    $fechaInicio = date('Y-01-01');
}
if (!$fechaFin) {
    $fechaFin = date('Y-m-d');
}

// 3. Construir WHERE para las consultas
$whereClauses = ["1=1"];
$params = [];

if ($selectedUnidadId) {
    $whereClauses[] = "r.unidad_id = :uid";
    $params[':uid'] = $selectedUnidadId;
} elseif ($userRol !== 'SuperAdmin' && !empty($unidadesIdsDisponibles)) {
    $inIds = implode(',', array_map('intval', $unidadesIdsDisponibles));
    $whereClauses[] = "r.unidad_id IN ($inIds)";
} elseif ($userRol !== 'SuperAdmin' && $userUnidadId) {
    $whereClauses[] = "r.unidad_id = " . (int)$userUnidadId;
}

if ($fechaInicio) {
    $whereClauses[] = "DATE(r.fecha_recepcion) >= :f_ini";
    $params[':f_ini'] = $fechaInicio;
}
if ($fechaFin) {
    $whereClauses[] = "DATE(r.fecha_recepcion) <= :f_fin";
    $params[':f_fin'] = $fechaFin;
}

$whereSql = implode(' AND ', $whereClauses);

// 4. Métricas Principales (KPIs)
$sqlKPIs = "
    SELECT 
        COUNT(*) AS total_reportes,
        SUM(CASE WHEN r.estatus = 'Concluido' THEN 1 ELSE 0 END) AS concluidos,
        SUM(CASE WHEN r.estatus = 'Asignado' THEN 1 ELSE 0 END) AS asignados,
        SUM(CASE WHEN r.estatus = 'Pendiente' THEN 1 ELSE 0 END) AS pendientes,
        AVG(CASE WHEN r.estatus = 'Concluido' AND r.fecha_conclusion IS NOT NULL 
                 THEN TIMESTAMPDIFF(HOUR, r.fecha_recepcion, r.fecha_conclusion) 
                 ELSE NULL END) AS horas_promedio_cierre
    FROM reportes r
    WHERE $whereSql
";
$stmtKPI = $db->prepare($sqlKPIs);
$stmtKPI->execute($params);
$kpis = $stmtKPI->fetch();

$totalReportes = (int)($kpis['total_reportes'] ?? 0);
$totalConcluidos = (int)($kpis['concluidos'] ?? 0);
$totalAsignados = (int)($kpis['asignados'] ?? 0);
$totalPendientes = (int)($kpis['pendientes'] ?? 0);
$efectividad = $totalReportes > 0 ? round(($totalConcluidos / $totalReportes) * 100, 1) : 0;
$horasPromedio = round((float)($kpis['horas_promedio_cierre'] ?? 0), 1);

// 5. Técnico Más Productivo
$sqlTopTecnico = "
    SELECT p.nombre, p.categoria, COUNT(r.id) AS concluidos
    FROM reportes r
    INNER JOIN personal_operativo p ON r.tecnico_asignado_id = p.id
    WHERE $whereSql AND r.estatus = 'Concluido'
    GROUP BY p.id
    ORDER BY concluidos DESC
    LIMIT 1
";
$stmtTT = $db->prepare($sqlTopTecnico);
$stmtTT->execute($params);
$topTecnico = $stmtTT->fetch();

// 6. Área con Mayor Incidencia
$sqlTopArea = "
    SELECT a.nombre_area, COUNT(r.id) AS total
    FROM reportes r
    INNER JOIN areas_maestro a ON r.area_id = a.id
    WHERE $whereSql
    GROUP BY a.id
    ORDER BY total DESC
    LIMIT 1
";
$stmtTA = $db->prepare($sqlTopArea);
$stmtTA->execute($params);
$topArea = $stmtTA->fetch();

// 7. Datos para Gráfico: Mantenimientos por Personal Operativo
$sqlPersonalGrafico = "
    SELECT 
        COALESCE(p.nombre, 'Sin Asignar') AS tecnico,
        COUNT(r.id) AS total,
        SUM(CASE WHEN r.estatus = 'Concluido' THEN 1 ELSE 0 END) AS concluidos,
        SUM(CASE WHEN r.estatus = 'Asignado' THEN 1 ELSE 0 END) AS en_proceso
    FROM reportes r
    LEFT JOIN personal_operativo p ON r.tecnico_asignado_id = p.id
    WHERE $whereSql
    GROUP BY r.tecnico_asignado_id
    ORDER BY concluidos DESC, total DESC
    LIMIT 10
";
$stmtPG = $db->prepare($sqlPersonalGrafico);
$stmtPG->execute($params);
$dataPersonal = $stmtPG->fetchAll();

// 8. Datos para Gráfico: Tendencia Temporal por Fecha
$sqlTendencia = "
    SELECT DATE(r.fecha_recepcion) AS fecha, COUNT(r.id) AS total
    FROM reportes r
    WHERE $whereSql
    GROUP BY DATE(r.fecha_recepcion)
    ORDER BY fecha ASC
";
$stmtT = $db->prepare($sqlTendencia);
$stmtT->execute($params);
$dataTendencia = $stmtT->fetchAll();

// 9. Datos para Gráfico: Top 10 Áreas
$sqlAreas = "
    SELECT a.nombre_area, COUNT(r.id) AS total
    FROM reportes r
    INNER JOIN areas_maestro a ON r.area_id = a.id
    WHERE $whereSql
    GROUP BY a.id
    ORDER BY total DESC
    LIMIT 10
";
$stmtA = $db->prepare($sqlAreas);
$stmtA->execute($params);
$dataAreas = $stmtA->fetchAll();

// 10. Datos para Gráfico: Especialidad / Categoría
$sqlCategorias = "
    SELECT 
        COALESCE(NULLIF(p.categoria, ''), 'General / No asignado') AS categoria,
        COUNT(r.id) AS total
    FROM reportes r
    LEFT JOIN personal_operativo p ON r.tecnico_asignado_id = p.id
    WHERE $whereSql
    GROUP BY categoria
    ORDER BY total DESC
";
$stmtC = $db->prepare($sqlCategorias);
$stmtC->execute($params);
$dataCategorias = $stmtC->fetchAll();

// Badge pendientes para campana
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
    <title>Tableros de control y Analítica — Panel de Conservación IMSS</title>
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
    <!-- Flatpickr (Calendario de Rango) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        .main-content { flex:1; min-width:0; }
        
        .page-title { font-size:1.6rem; font-weight:bold; color:#111; margin:0 0 0.35rem 0; }
        .title-underline { width:60px; height:3px; background:#3b8c4c; margin-bottom:1.25rem; }
        
        /* Contenedor con scroll interno */
        .analytics-scroll-container {
            max-height: calc(100vh - 210px);
            overflow-y: auto;
            padding-right: 0.5rem;
        }
        .analytics-scroll-container::-webkit-scrollbar { width: 7px; height: 7px; }
        .analytics-scroll-container::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
        .analytics-scroll-container::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .analytics-scroll-container::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        /* Barra de Filtros en una sola fila compacta */
        .filter-card {
            background: white;
            border-radius: 10px;
            padding: 0.75rem 1.25rem;
            margin-bottom: 1.25rem;
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
            border: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .filter-group { display:flex; align-items:center; gap:0.5rem; flex-wrap:nowrap; }
        .filter-group label { font-size:0.85rem; font-weight:700; color:#374151; white-space:nowrap; }
        .filter-select, .filter-input {
            background: #ffffff !important;
            border: 1px solid #d1d5db;
            color: #000000 !important;
            padding: 0.42rem 0.7rem;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .filter-select:focus, .filter-input:focus { outline:none; border-color:#2b7a4b; box-shadow: 0 0 0 2px rgba(43,122,75,0.15); }
        .input-icon-wrapper {
            position: relative;
            display: inline-flex;
            align-items: center;
        }
        .input-icon-wrapper .input-icon {
            position: absolute;
            left: 0.75rem;
            color: #4b5563;
            pointer-events: none;
            z-index: 2;
        }
        .input-icon-wrapper .filter-input,
        .input-icon-wrapper .flatpickr-input,
        #filtroPeriodo {
            padding-left: 2.45rem !important;
            width: 235px;
            background-color: #ffffff !important;
            color: #000000 !important;
            font-weight: 600 !important;
            cursor: pointer;
        }
        .btn-filter {
            background: #2b7a4b;
            color: white;
            border: none;
            padding: 0.42rem 0.85rem;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            transition: background 0.15s;
        }
        .btn-filter:hover { background: #23633d; }
        .btn-preset {
            background: #f3f4f6;
            color: #4b5563;
            border: 1px solid #e5e7eb;
            padding: 0.38rem 0.65rem;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.15s;
        }
        .btn-preset:hover { background: #e5e7eb; color: #111; }
        .btn-preset.active { background: #dcfce7; color: #166534; border-color: #86efac; font-weight: 700; }

        /* Personalización Flatpickr IMSS */
        .flatpickr-calendar {
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.12);
            border: 1px solid #e5e7eb;
            font-family: inherit;
        }
        .flatpickr-day.selected, .flatpickr-day.startRange, .flatpickr-day.endRange, 
        .flatpickr-day.selected.inRange, .flatpickr-day.startRange.inRange, .flatpickr-day.endRange.inRange {
            background: #2b7a4b !important;
            border-color: #2b7a4b !important;
            color: #fff !important;
        }
        .flatpickr-day.inRange {
            background: #dcfce7 !important;
            border-color: #dcfce7 !important;
            color: #166534 !important;
        }
        .flatpickr-months .flatpickr-month {
            background: #0d4d35;
            color: #fff;
            fill: #fff;
            border-top-left-radius: 7px;
            border-top-right-radius: 7px;
        }
        .flatpickr-current-month .flatpickr-monthDropdown-months, 
        .flatpickr-current-month input.cur-year {
            color: #fff !important;
            font-weight: bold;
        }
        .flatpickr-months .flatpickr-prev-month svg, .flatpickr-months .flatpickr-next-month svg {
            fill: #fff !important;
        }
        span.flatpickr-weekday {
            background: #0d4d35;
            color: rgba(255,255,255,0.9) !important;
            font-weight: 600;
        }

        /* Tarjetas de KPIs */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.25rem;
        }
        .kpi-card {
            background: white;
            border-radius: 10px;
            padding: 1.25rem;
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
            border: 1px solid #e5e7eb;
            border-left: 4px solid #3b8c4c;
            position: relative;
            overflow: hidden;
        }
        .kpi-title { font-size: 0.8rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.4rem; }
        .kpi-value { font-size: 1.8rem; font-weight: 800; color: #111; line-height: 1.1; margin-bottom: 0.3rem; }
        .kpi-sub { font-size: 0.78rem; color: #6b7280; display: flex; align-items: center; gap: 0.3rem; }
        
        /* Cuadrícula de Gráficos */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .chart-card {
            background: white;
            border-radius: 10px;
            padding: 1.25rem 1.5rem;
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
            border: 1px solid #e5e7eb;
            display: flex;
            flex-direction: column;
        }
        .chart-card.full-width { grid-column: 1 / -1; }
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            border-bottom: 1px solid #f3f4f6;
            padding-bottom: 0.5rem;
        }
        .chart-title { font-size: 1rem; font-weight: 700; color: #1f2937; margin: 0; display: flex; align-items: center; gap: 0.5rem; }
        .chart-canvas-container { position: relative; flex: 1; min-height: 250px; max-height: 320px; width: 100%; }

        @media (max-width: 1024px) {
            .charts-grid { grid-template-columns: 1fr; }
        }
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
        <a href="logout.php" style="color:#666;text-decoration:none;" title="Cerrar Sesión"><svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg></a>
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
        <a href="auditoria.php" class="sidebar-item active">
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
        <h2 class="page-title">Tableros de control y Desempeño Operativo</h2>
        <div class="title-underline"></div>

        <div class="analytics-scroll-container">
            <!-- Barra de Filtros en una sola fila compacta -->
            <form method="GET" class="filter-card" id="formFiltros">
                <div class="filter-group">
                    <label for="unidad_id">Unidad Médica:</label>
                    <select name="unidad_id" id="unidad_id" class="filter-select" onchange="this.form.submit()" style="min-width: 280px; max-width: 480px;">
                        <?php if ($userRol === 'SuperAdmin' || count($unidadesDisponibles) > 1): ?>
                            <option value="">-- Todas mis unidades (<?= count($unidadesDisponibles) ?>) --</option>
                        <?php endif; ?>
                        <?php foreach ($unidadesDisponibles as $ud): ?>
                            <option value="<?= $ud['id'] ?>" <?= ($selectedUnidadId == $ud['id']) ? 'selected' : '' ?>>
                                <?= h($ud['clave_unidad']) ?> - <?= h($ud['nombre_unidad']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="rango_fechas">Periodo:</label>
                    <div class="input-icon-wrapper">
                        <svg class="input-icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        <input type="text" id="rango_fechas" name="rango_fechas" class="filter-input" placeholder="Fecha inicio a fin..." readonly>
                    </div>
                    <input type="hidden" name="fecha_inicio" id="fecha_inicio" value="<?= h($fechaInicio) ?>">
                    <input type="hidden" name="fecha_fin" id="fecha_fin" value="<?= h($fechaFin) ?>">
                    <button type="submit" class="btn-filter" title="Aplicar Filtro">
                        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        Filtrar
                    </button>
                </div>

                <div class="filter-group" style="gap:0.35rem;">
                    <a href="?<?= http_build_query(array_merge($_GET, ['fecha_inicio' => date('Y-m-d', strtotime('-30 days')), 'fecha_fin' => date('Y-m-d'), 'rango_fechas' => ''])) ?>" class="btn-preset <?= ($fechaInicio === date('Y-m-d', strtotime('-30 days')) && $fechaFin === date('Y-m-d')) ? 'active' : '' ?>">Últimos 30 días</a>
                    <a href="?<?= http_build_query(array_merge($_GET, ['fecha_inicio' => date('Y-m-01'), 'fecha_fin' => date('Y-m-t'), 'rango_fechas' => ''])) ?>" class="btn-preset <?= ($fechaInicio === date('Y-m-01') && $fechaFin === date('Y-m-t')) ? 'active' : '' ?>">Mes Actual</a>
                    <a href="?<?= http_build_query(array_merge($_GET, ['fecha_inicio' => date('Y-01-01'), 'fecha_fin' => date('Y-12-31'), 'rango_fechas' => ''])) ?>" class="btn-preset <?= ($fechaInicio === date('Y-01-01') && $fechaFin === date('Y-12-31')) ? 'active' : '' ?>">Año <?= date('Y') ?></a>
                </div>
            </form>

            <!-- Tarjetas de KPIs -->
            <div class="kpi-grid">
                <div class="kpi-card" style="border-left-color: #2b7a4b;">
                    <div class="kpi-title">Total Solicitudes</div>
                    <div class="kpi-value"><?= number_format($totalReportes) ?></div>
                    <div class="kpi-sub">
                        <span style="color:#166534;font-weight:700;">✓ <?= $totalConcluidos ?> concluidos</span> • 
                        <span style="color:#ca8a04;font-weight:700;">⏳ <?= $totalPendientes ?> pendientes</span>
                    </div>
                </div>

                <div class="kpi-card" style="border-left-color: #10b981;">
                    <div class="kpi-title">Tasa de Efectividad</div>
                    <div class="kpi-value" style="color: #059669;"><?= $efectividad ?>%</div>
                    <div class="kpi-sub">
                        <span>Porcentaje de conclusión de órdenes</span>
                    </div>
                </div>

                <div class="kpi-card" style="border-left-color: #3b82f6;">
                    <div class="kpi-title">Tiempo Promedio de Cierre</div>
                    <div class="kpi-value" style="color: #2563eb;"><?= $horasPromedio ?> <span style="font-size:1rem;font-weight:600;">hrs</span></div>
                    <div class="kpi-sub">
                        <span>Desde recepción hasta conclusión</span>
                    </div>
                </div>

                <div class="kpi-card" style="border-left-color: #8b5cf6;">
                    <div class="kpi-title">Técnico Más Productivo</div>
                    <div class="kpi-value" style="font-size:1.15rem; color:#6d28d9; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                        <?= h($topTecnico['nombre'] ?? 'Sin datos') ?>
                    </div>
                    <div class="kpi-sub">
                        <strong style="color:#111;"><?= (int)($topTecnico['concluidos'] ?? 0) ?></strong> servicios concluidos exitosamente
                    </div>
                </div>

                <div class="kpi-card" style="border-left-color: #f59e0b;">
                    <div class="kpi-title">Área con Mayor Demanda</div>
                    <div class="kpi-value" style="font-size:1.15rem; color:#b45309; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                        <?= h($topArea['nombre_area'] ?? 'Sin datos') ?>
                    </div>
                    <div class="kpi-sub">
                        <strong style="color:#111;"><?= (int)($topArea['total'] ?? 0) ?></strong> reportes registrados en el periodo
                    </div>
                </div>
            </div>

            <!-- Gráficos Analíticos -->
            <div class="charts-grid">
                <!-- Gráfico 1: Mantenimientos por Personal Operativo -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">
                            <svg width="18" height="18" fill="none" stroke="#2b7a4b" stroke-width="2" viewBox="0 0 24 24"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                            Mantenimientos por Personal Operativo
                        </h3>
                    </div>
                    <div class="chart-canvas-container">
                        <canvas id="chartPersonal"></canvas>
                    </div>
                </div>

                <!-- Gráfico 2: Distribución por Estatus -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">
                            <svg width="18" height="18" fill="none" stroke="#2b7a4b" stroke-width="2" viewBox="0 0 24 24"><path d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"></path><path d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"></path></svg>
                            Distribución de Estatus
                        </h3>
                    </div>
                    <div class="chart-canvas-container">
                        <canvas id="chartEstatus"></canvas>
                    </div>
                </div>

                <!-- Gráfico 3: Tendencia Temporal de Solicitudes -->
                <div class="chart-card full-width">
                    <div class="chart-header">
                        <h3 class="chart-title">
                            <svg width="18" height="18" fill="none" stroke="#2b7a4b" stroke-width="2" viewBox="0 0 24 24"><path d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path></svg>
                            Tendencia Temporal de Solicitudes (Línea de Tiempo)
                        </h3>
                    </div>
                    <div class="chart-canvas-container" style="max-height: 280px;">
                        <canvas id="chartTendencia"></canvas>
                    </div>
                </div>

                <!-- Gráfico 4: Top 10 Áreas Médicas con Mayor Incidencia -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">
                            <svg width="18" height="18" fill="none" stroke="#2b7a4b" stroke-width="2" viewBox="0 0 24 24"><path d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                            Top Áreas con Mayor Demanda
                        </h3>
                    </div>
                    <div class="chart-canvas-container">
                        <canvas id="chartAreas"></canvas>
                    </div>
                </div>

                <!-- Gráfico 5: Mantenimientos por Especialidad Técnica -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">
                            <svg width="18" height="18" fill="none" stroke="#2b7a4b" stroke-width="2" viewBox="0 0 24 24"><path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                            Distribución por Especialidad / Categoría
                        </h3>
                    </div>
                    <div class="chart-canvas-container">
                        <canvas id="chartCategorias"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // 1. Gráfico Personal Operativo (Barras Horizontales Apiladas)
    const ctxPersonal = document.getElementById('chartPersonal').getContext('2d');
    const personalLabels = <?= json_encode(array_column($dataPersonal, 'tecnico')) ?>;
    const personalConcluidos = <?= json_encode(array_map('intval', array_column($dataPersonal, 'concluidos'))) ?>;
    const personalEnProceso = <?= json_encode(array_map('intval', array_column($dataPersonal, 'en_proceso'))) ?>;

    new Chart(ctxPersonal, {
        type: 'bar',
        data: {
            labels: personalLabels.length > 0 ? personalLabels : ['Sin registros'],
            datasets: [
                {
                    label: 'Concluidos',
                    data: personalConcluidos.length > 0 ? personalConcluidos : [0],
                    backgroundColor: '#16a34a',
                    borderRadius: 4
                },
                {
                    label: 'En Proceso (Asignados)',
                    data: personalEnProceso.length > 0 ? personalEnProceso : [0],
                    backgroundColor: '#3b82f6',
                    borderRadius: 4
                }
            ]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { stacked: true, beginAtZero: true, ticks: { stepSize: 1 } },
                y: { stacked: true }
            },
            plugins: {
                legend: { position: 'top' },
                tooltip: { mode: 'index', intersect: false }
            }
        }
    });

    // 2. Gráfico Distribución por Estatus (Doughnut)
    const ctxEstatus = document.getElementById('chartEstatus').getContext('2d');
    new Chart(ctxEstatus, {
        type: 'doughnut',
        data: {
            labels: ['Concluidos', 'Asignados', 'Pendientes'],
            datasets: [{
                data: [<?= $totalConcluidos ?>, <?= $totalAsignados ?>, <?= $totalPendientes ?>],
                backgroundColor: ['#16a34a', '#3b82f6', '#eab308'],
                hoverOffset: 6,
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            },
            cutout: '65%'
        }
    });

    // 3. Gráfico Tendencia Temporal (Line con Gradiente)
    const ctxTendencia = document.getElementById('chartTendencia').getContext('2d');
    const fechas = <?= json_encode(array_map(function($f) { return date('d/m', strtotime($f)); }, array_column($dataTendencia, 'fecha'))) ?>;
    const totalesFecha = <?= json_encode(array_map('intval', array_column($dataTendencia, 'total'))) ?>;

    const gradient = ctxTendencia.createLinearGradient(0, 0, 0, 260);
    gradient.addColorStop(0, 'rgba(43, 122, 75, 0.4)');
    gradient.addColorStop(1, 'rgba(43, 122, 75, 0.0)');

    new Chart(ctxTendencia, {
        type: 'line',
        data: {
            labels: fechas.length > 0 ? fechas : ['Sin datos'],
            datasets: [{
                label: 'Reportes Recibidos',
                data: totalesFecha.length > 0 ? totalesFecha : [0],
                borderColor: '#2b7a4b',
                backgroundColor: gradient,
                fill: true,
                tension: 0.35,
                pointRadius: 4,
                pointHoverRadius: 6,
                pointBackgroundColor: '#2b7a4b'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 } }
            },
            plugins: {
                legend: { display: false },
                tooltip: { mode: 'index', intersect: false }
            }
        }
    });

    // 4. Gráfico Top Áreas (Barra Vertical)
    const ctxAreas = document.getElementById('chartAreas').getContext('2d');
    const areaLabels = <?= json_encode(array_column($dataAreas, 'nombre_area')) ?>;
    const areaTotales = <?= json_encode(array_map('intval', array_column($dataAreas, 'total'))) ?>;

    new Chart(ctxAreas, {
        type: 'bar',
        data: {
            labels: areaLabels.length > 0 ? areaLabels : ['Sin registros'],
            datasets: [{
                label: 'Reportes',
                data: areaTotales.length > 0 ? areaTotales : [0],
                backgroundColor: '#0d4d35',
                borderRadius: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 } },
                x: {
                    ticks: {
                        maxRotation: 45,
                        minRotation: 25,
                        font: { size: 10 }
                    }
                }
            },
            plugins: {
                legend: { display: false }
            }
        }
    });

    // 5. Gráfico Categorías / Especialidad (Pie / PolarArea)
    const ctxCategorias = document.getElementById('chartCategorias').getContext('2d');
    const catLabels = <?= json_encode(array_column($dataCategorias, 'categoria')) ?>;
    const catTotales = <?= json_encode(array_map('intval', array_column($dataCategorias, 'total'))) ?>;

    new Chart(ctxCategorias, {
        type: 'pie',
        data: {
            labels: catLabels.length > 0 ? catLabels : ['Sin datos'],
            datasets: [{
                data: catTotales.length > 0 ? catTotales : [0],
                backgroundColor: ['#047857', '#0284c7', '#d97706', '#7c3aed', '#db2777', '#64748b'],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });

    // 6. Inicializar Flatpickr en modo Rango (1er clic = Inicio, 2do clic = Fin)
    const fInicio = "<?= $fechaInicio ?>";
    const fFin = "<?= $fechaFin ?>";
    
    flatpickr("#rango_fechas", {
        mode: "range",
        locale: "es",
        dateFormat: "Y-m-d",
        altInput: true,
        altFormat: "d/m/Y",
        altInputClass: "filter-input",
        defaultDate: [fInicio, fFin],
        conjunction: "  a  ",
        onChange: function(selectedDates, dateStr, instance) {
            if (selectedDates.length === 2) {
                document.getElementById('fecha_inicio').value = instance.formatDate(selectedDates[0], "Y-m-d");
                document.getElementById('fecha_fin').value = instance.formatDate(selectedDates[1], "Y-m-d");
            } else if (selectedDates.length === 1) {
                document.getElementById('fecha_inicio').value = instance.formatDate(selectedDates[0], "Y-m-d");
                document.getElementById('fecha_fin').value = instance.formatDate(selectedDates[0], "Y-m-d");
            }
        }
    });
});
</script>
<script src="../assets/js/app.js"></script>
</body>
</html>

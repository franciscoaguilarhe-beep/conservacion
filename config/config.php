<?php
/**
 * Configuración Global del Sistema
 * Plataforma de Gestión de Conservación IMSS (Fase 1)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';

// Zona horaria institucional para Baja California Sur (La Paz)
date_default_timezone_set('America/Mazatlan');

// Constantes Institucionales IMSS
define('APP_NAME', 'Plataforma de Conservación');
define('IMSS_ORG', 'INSTITUTO MEXICANO DEL SEGURO SOCIAL');
define('IMSS_NORMA_CLAVE', '14C2-003-002');
define('DEFAULT_CLAVE_UNIDAD', 'UMF40');

// Helper: Detección dinámica de la Unidad Activa
function getActiveUnit(): array {
    $db = Database::getConnection();
    
    // 1. Revisar si viene por parámetro GET (?u=UMF40)
    $claveRequested = filter_input(INPUT_GET, 'u', FILTER_SANITIZE_SPECIAL_CHARS);
    if ($claveRequested) {
        $_SESSION['active_unit_clave'] = strtoupper(trim($claveRequested));
    }

    // 2. Revisar si hay un usuario logueado con unidad_id asignada
    if (isset($_SESSION['user_unidad_id']) && $_SESSION['user_unidad_id']) {
        $stmt = $db->prepare("SELECT * FROM unidades WHERE id = ? AND estatus = 'Activo' LIMIT 1");
        $stmt->execute([$_SESSION['user_unidad_id']]);
        $unit = $stmt->fetch();
        if ($unit) return $unit;
    }

    // 3. Revisar sesión activa previa
    if (isset($_SESSION['active_unit_clave'])) {
        $stmt = $db->prepare("SELECT * FROM unidades WHERE clave_unidad = ? AND estatus = 'Activo' LIMIT 1");
        $stmt->execute([$_SESSION['active_unit_clave']]);
        $unit = $stmt->fetch();
        if ($unit) return $unit;
    }

    // 4. Default: UMF40 o la primera unidad activa registrada
    $stmt = $db->prepare("SELECT * FROM unidades WHERE clave_unidad = ? AND estatus = 'Activo' LIMIT 1");
    $stmt->execute([DEFAULT_CLAVE_UNIDAD]);
    $unit = $stmt->fetch();
    if ($unit) return $unit;

    $stmt = $db->query("SELECT * FROM unidades WHERE estatus = 'Activo' ORDER BY id ASC LIMIT 1");
    return $stmt->fetch() ?: [
        'id' => 1,
        'nombre_unidad' => 'UNIDAD DE MEDICINA FAMILIAR No.40',
        'clave_unidad' => 'UMF40',
        'ubicacion' => 'La Paz, B.C.S.'
    ];
}

// Sanitización de salida HTML
function h(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

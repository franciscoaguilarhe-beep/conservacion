<?php
/**
 * Controlador de Emisión de Formato de Servicio Normado IMSS
 * Clave: 14C2-003-002
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/ReportePdfGenerator.php';

$folio = filter_input(INPUT_GET, 'folio', FILTER_SANITIZE_SPECIAL_CHARS);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$db = Database::getConnection();

if ($folio) {
    $stmt = $db->prepare("
        SELECT r.*, a.nombre_area, u.nombre_unidad, u.clave_unidad, u.ubicacion,
               p.nombre AS nombre_tecnico, p.matricula AS matricula_tecnico, p.categoria AS categoria_tecnico
        FROM reportes r
        INNER JOIN unidades u ON r.unidad_id = u.id
        INNER JOIN areas_maestro a ON r.area_id = a.id
        LEFT JOIN personal_operativo p ON r.tecnico_asignado_id = p.id
        WHERE r.folio = ?
        LIMIT 1
    ");
    $stmt->execute([$folio]);
} elseif ($id) {
    $stmt = $db->prepare("
        SELECT r.*, a.nombre_area, u.nombre_unidad, u.clave_unidad, u.ubicacion,
               p.nombre AS nombre_tecnico, p.matricula AS matricula_tecnico, p.categoria AS categoria_tecnico
        FROM reportes r
        INNER JOIN unidades u ON r.unidad_id = u.id
        INNER JOIN areas_maestro a ON r.area_id = a.id
        LEFT JOIN personal_operativo p ON r.tecnico_asignado_id = p.id
        WHERE r.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
} else {
    die("Folio o ID de reporte no proporcionado.");
}

$reporte = $stmt->fetch();

if (!$reporte) {
    die("El reporte solicitado no existe.");
}

$unidad = [
    'nombre_unidad' => $reporte['nombre_unidad'],
    'clave_unidad'  => $reporte['clave_unidad'],
    'ubicacion'     => $reporte['ubicacion']
];

echo ReportePdfGenerator::renderHtml($reporte, $unidad);

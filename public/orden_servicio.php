<?php
/**
 * Controlador de Emisión del Formato "Orden y Entrega de Servicio"
 * Clave: 14C2-003-002
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/OrdenServicioGenerator.php';

$folio = filter_input(INPUT_GET, 'folio', FILTER_SANITIZE_SPECIAL_CHARS);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$db = Database::getConnection();

$sql = "
    SELECT r.*, a.nombre_area, u.nombre_unidad, u.clave_unidad, u.ubicacion,
           u.delegacion, u.clave_presupuestal,
           u_asig.nombre_completo AS nombre_usuario_asigno,
           (
               SELECT ua.nombre_completo 
               FROM usuarios_admin ua
               LEFT JOIN usuarios_unidades uu ON ua.id = uu.usuario_id
               WHERE (uu.unidad_id = u.id OR ua.unidad_id = u.id) AND ua.rol = 'Jefe' AND ua.estatus = 'Activo' 
               ORDER BY ua.id DESC LIMIT 1
           ) AS nombre_jcu,
           (
               SELECT ua.nombre_completo 
               FROM usuarios_admin ua
               LEFT JOIN usuarios_unidades uu ON ua.id = uu.usuario_id
               WHERE (uu.unidad_id = u.id OR ua.unidad_id = u.id) AND ua.rol = 'AUO' AND ua.estatus = 'Activo' 
               ORDER BY ua.id DESC LIMIT 1
           ) AS nombre_auo,
           p.nombre AS nombre_tecnico, p.matricula AS matricula_tecnico, p.categoria AS categoria_tecnico
    FROM reportes r
    INNER JOIN unidades u ON r.unidad_id = u.id
    INNER JOIN areas_maestro a ON r.area_id = a.id
    LEFT JOIN personal_operativo p ON r.tecnico_asignado_id = p.id
    LEFT JOIN usuarios_admin u_asig ON r.usuario_asigno_id = u_asig.id
    WHERE " . ($folio ? "r.folio = ?" : "r.id = ?") . "
    LIMIT 1
";

if ($folio || $id) {
    $stmt = $db->prepare($sql);
    $stmt->execute([$folio ?: $id]);
} else {
    die("Folio o ID de reporte no proporcionado.");
}

$reporte = $stmt->fetch();

if (!$reporte) {
    die("El reporte solicitado no existe.");
}

$unidad = [
    'nombre_unidad'      => $reporte['nombre_unidad'],
    'clave_unidad'       => $reporte['clave_unidad'],
    'ubicacion'          => $reporte['ubicacion'],
    'delegacion'         => $reporte['delegacion'] ?? 'BCS',
    'clave_presupuestal' => $reporte['clave_presupuestal'] ?? '',
    'nombre_jcu'         => $reporte['nombre_jcu'] ?? '',
];

$elaboro = $reporte['nombre_usuario_asigno'] ?? $reporte['nombre_auo'] ?? $reporte['nombre_jcu'] ?? ($_SESSION['user_name'] ?? '');

echo OrdenServicioGenerator::renderHtml($reporte, $unidad, $elaboro);

<?php
/**
 * API Pública para Levantamiento de Reportes
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/FolioGenerator.php';

$action = filter_input(INPUT_GET, 'action', FILTER_SANITIZE_SPECIAL_CHARS);
$db = Database::getConnection();

if ($action === 'create_report') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'error' => 'Método no permitido']);
        exit;
    }

    $unidadId = filter_input(INPUT_POST, 'unidad_id', FILTER_VALIDATE_INT);
    $areaId = filter_input(INPUT_POST, 'area_id', FILTER_VALIDATE_INT);
    $apPaterno = strtoupper(trim(filter_input(INPUT_POST, 'solicitante_ap_paterno', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''));
    $apMaterno = strtoupper(trim(filter_input(INPUT_POST, 'solicitante_ap_materno', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''));
    $nombres = strtoupper(trim(filter_input(INPUT_POST, 'solicitante_nombres', FILTER_SANITIZE_SPECIAL_CHARS)));
    $cargo = strtoupper(trim(filter_input(INPUT_POST, 'cargo_solicitante', FILTER_SANITIZE_SPECIAL_CHARS) ?? 'PERSONAL IMSS'));
    $descripcionFalla = strtoupper(trim(filter_input(INPUT_POST, 'descripcion_falla', FILTER_SANITIZE_SPECIAL_CHARS)));
    $observaciones = strtoupper(trim(filter_input(INPUT_POST, 'observaciones', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''));

    if (!$unidadId || !$areaId || empty($nombres) || empty($descripcionFalla)) {
        echo json_encode(['success' => false, 'error' => 'Por favor complete todos los campos obligatorios.']);
        exit;
    }

    try {
        $db->beginTransaction();

        // Obtener clave de unidad
        $stmtU = $db->prepare("SELECT clave_unidad, nombre_unidad FROM unidades WHERE id = ?");
        $stmtU->execute([$unidadId]);
        $unidad = $stmtU->fetch();

        if (!$unidad) {
            throw new Exception('Unidad médica no válida.');
        }

        // Obtener nombre de área
        $stmtA = $db->prepare("SELECT nombre_area FROM areas_maestro WHERE id = ?");
        $stmtA->execute([$areaId]);
        $area = $stmtA->fetch();

        // Generar folio según la regla institucional
        $folioData = FolioGenerator::generar($db, $unidadId, $unidad['clave_unidad']);

        // Determinar fecha_recepcion
        $fechaRecepcion = 'NOW()';
        $manualDate = filter_input(INPUT_POST, 'fecha_recepcion_manual', FILTER_SANITIZE_SPECIAL_CHARS);
        if ($manualDate && isset($_SESSION['user_id'])) {
            $fechaRecepcion = $db->quote($manualDate);
        }

        $stmtIns = $db->prepare("
            INSERT INTO reportes (
                folio, unidad_id, area_id,
                solicitante_ap_paterno, solicitante_ap_materno, solicitante_nombres, cargo_solicitante,
                descripcion_falla, observaciones,
                fecha_recepcion, estatus, anio_consecutivo, numero_consecutivo, clave_formato
            ) VALUES (
                :folio, :unidad_id, :area_id,
                :paterno, :materno, :nombres, :cargo,
                :falla, :obs,
                $fechaRecepcion, 'Pendiente', :anio, :consecutivo, :norma
            )
        ");

        $stmtIns->execute([
            ':folio'       => $folioData['folio'],
            ':unidad_id'   => $unidadId,
            ':area_id'     => $areaId,
            ':paterno'     => $apPaterno,
            ':materno'     => $apMaterno,
            ':nombres'     => $nombres,
            ':cargo'       => $cargo,
            ':falla'       => $descripcionFalla,
            ':obs'         => $observaciones,
            ':anio'        => $folioData['anio'],
            ':consecutivo' => $folioData['consecutivo'],
            ':norma'       => IMSS_NORMA_CLAVE
        ]);

        $db->commit();

        echo json_encode([
            'success' => true,
            'folio'   => $folioData['folio'],
            'area'    => $area['nombre_area'] ?? '',
            'fecha'   => date('d/m/Y H:i')
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
if ($action === 'get_areas') {
    $unidadId = filter_input(INPUT_GET, 'unidad_id', FILTER_VALIDATE_INT);
    if (!$unidadId) {
        echo json_encode(['success' => false, 'error' => 'Unidad no especificada']);
        exit;
    }
    
    $stmt = $db->prepare("
        SELECT a.id, a.nombre_area 
        FROM areas_maestro a
        INNER JOIN unidades_areas ua ON a.id = ua.id_area
        WHERE ua.id_unidad = ? AND ua.estatus = 'Habilitado'
        ORDER BY a.nombre_area ASC
    ");
    $stmt->execute([$unidadId]);
    $areas = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'areas' => $areas]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Acción inválida']);

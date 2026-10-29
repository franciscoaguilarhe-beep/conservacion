<?php
/**
 * API Administrativa - Polling, Asignación y Cierre de Tickets
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$db = Database::getConnection();
$action = filter_input(INPUT_GET, 'action', FILTER_SANITIZE_SPECIAL_CHARS);
$userUnidadId = $_SESSION['user_unidad_id'];

// 1. Polling de Nuevos Tickets
if ($action === 'check_updates') {
    $lastId = filter_input(INPUT_GET, 'last_id', FILTER_VALIDATE_INT) ?? 0;

    $sql = "
        SELECT r.id, r.folio, r.descripcion_falla, r.fecha_recepcion, a.nombre_area,
               r.solicitante_ap_paterno, r.solicitante_nombres, r.estatus
        FROM reportes r
        INNER JOIN areas_maestro a ON r.area_id = a.id
        WHERE r.id > :last_id
    ";
    $params = [':last_id' => $lastId];

    if ($userUnidadId) {
        $sql .= " AND r.unidad_id = :unidad_id";
        $params[':unidad_id'] = $userUnidadId;
    }

    $sql .= " ORDER BY r.id DESC LIMIT 10";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $newTickets = $stmt->fetchAll();

    $stmtMax = $db->prepare("SELECT COALESCE(MAX(id), 0) AS max_id FROM reportes" . ($userUnidadId ? " WHERE unidad_id = ?" : ""));
    $userUnidadId ? $stmtMax->execute([$userUnidadId]) : $stmtMax->execute();
    $latestId = (int)$stmtMax->fetch()['max_id'];

    echo json_encode([
        'success'   => true,
        'new_count' => count($newTickets),
        'tickets'   => $newTickets,
        'latest_id' => $latestId
    ]);
    exit;
}

// 2. Obtener Detalle de Ticket
if ($action === 'get_ticket') {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID inválido']);
        exit;
    }

    // Asegurar esquema de rechazo/eliminación
    try {
        $db->exec("ALTER TABLE reportes MODIFY COLUMN estatus ENUM('Pendiente', 'Asignado', 'Concluido', 'Rechazado') NOT NULL DEFAULT 'Pendiente'");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE reportes ADD COLUMN motivo_rechazo TEXT NULL AFTER recomendaciones");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE reportes ADD COLUMN usuario_rechazo_id INT NULL AFTER usuario_asigno_id");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE reportes ADD COLUMN fecha_rechazo DATETIME NULL AFTER fecha_conclusion");
    } catch (Exception $e) {}

    $stmt = $db->prepare("
        SELECT r.*, a.nombre_area, p.nombre AS nombre_tecnico, p.categoria AS categoria_tecnico,
               u_asig.nombre_completo AS nombre_elaboro,
               u_rech.nombre_completo AS nombre_rechazo, u_rech.rol AS rol_rechazo
        FROM reportes r
        INNER JOIN areas_maestro a ON r.area_id = a.id
        LEFT JOIN personal_operativo p ON r.tecnico_asignado_id = p.id
        LEFT JOIN usuarios_admin u_asig ON r.usuario_asigno_id = u_asig.id
        LEFT JOIN usuarios_admin u_rech ON r.usuario_rechazo_id = u_rech.id
        WHERE r.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $ticket = $stmt->fetch();

    if ($ticket) {
        echo json_encode(['success' => true, 'ticket' => $ticket]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Ticket no encontrado']);
    }
    exit;
}

// 2.1 Eliminar / Rechazar Reporte con Motivo e Historial
if ($action === 'reject_ticket') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'error' => 'Método inválido']);
        exit;
    }

    $ticketId = filter_input(INPUT_POST, 'ticket_id', FILTER_VALIDATE_INT);
    $motivoRechazo = strtoupper(trim(filter_input(INPUT_POST, 'motivo_rechazo', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''));
    $usuarioRechazoId = $_SESSION['user_id'] ?? null;

    if (!$ticketId || empty($motivoRechazo)) {
        echo json_encode(['success' => false, 'error' => 'Debe proporcionar el motivo por el cual no procede o se elimina el reporte.']);
        exit;
    }

    // Asegurar columnas de rechazo en la tabla reportes
    try {
        $db->exec("ALTER TABLE reportes MODIFY COLUMN estatus ENUM('Pendiente', 'Asignado', 'Concluido', 'Rechazado') NOT NULL DEFAULT 'Pendiente'");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE reportes ADD COLUMN motivo_rechazo TEXT NULL AFTER recomendaciones");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE reportes ADD COLUMN usuario_rechazo_id INT NULL AFTER usuario_asigno_id");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE reportes ADD COLUMN fecha_rechazo DATETIME NULL AFTER fecha_conclusion");
    } catch (Exception $e) {}

    try {
        $stmt = $db->prepare("
            UPDATE reportes
            SET estatus = 'Rechazado',
                motivo_rechazo = ?,
                usuario_rechazo_id = ?,
                fecha_rechazo = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$motivoRechazo, $usuarioRechazoId, $ticketId]);

        echo json_encode(['success' => true, 'message' => 'Reporte marcado como no procedente / eliminado y guardado en el historial.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// 3. Listar Técnicos Operativos
if ($action === 'list_tecnicos') {
    $unidadId = filter_input(INPUT_GET, 'unidad_id', FILTER_VALIDATE_INT) ?? $userUnidadId ?? 1;
    $stmt = $db->prepare("
        SELECT id, matricula, nombre, categoria, turno 
        FROM personal_operativo 
        WHERE unidad_id = ? AND estatus = 'Activo' 
        ORDER BY nombre ASC
    ");
    $stmt->execute([$unidadId]);
    $tecnicos = $stmt->fetchAll();

    echo json_encode(['success' => true, 'tecnicos' => $tecnicos]);
    exit;
}

// 3.1 Búsqueda Autocompletada de Técnicos Global (por Nombre, Matrícula o Categoría)
if ($action === 'search_tecnicos_global') {
    $q = trim(filter_input(INPUT_GET, 'q', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
    $unidadId = filter_input(INPUT_GET, 'unidad_id', FILTER_VALIDATE_INT) ?? $userUnidadId ?? 0;
    
    if (strlen($q) === 0) {
        $sql = "
            SELECT p.id, p.matricula, p.nombre, p.categoria, p.turno, u.clave_unidad, u.nombre_unidad,
                   (CASE WHEN p.unidad_id = :uid THEN 1 ELSE 0 END) AS es_misma_unidad
            FROM personal_operativo p
            INNER JOIN unidades u ON p.unidad_id = u.id
            WHERE p.estatus = 'Activo'
            ORDER BY es_misma_unidad DESC, p.nombre ASC
            LIMIT 20
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([':uid' => $unidadId]);
    } else {
        $term = "%{$q}%";
        $sql = "
            SELECT p.id, p.matricula, p.nombre, p.categoria, p.turno, u.clave_unidad, u.nombre_unidad,
                   (CASE WHEN p.unidad_id = :uid THEN 1 ELSE 0 END) AS es_misma_unidad
            FROM personal_operativo p
            INNER JOIN unidades u ON p.unidad_id = u.id
            WHERE p.estatus = 'Activo' 
              AND (p.nombre LIKE :t1 OR p.matricula LIKE :t2 OR p.categoria LIKE :t3)
            ORDER BY es_misma_unidad DESC, p.nombre ASC
            LIMIT 20
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':uid' => $unidadId,
            ':t1' => $term,
            ':t2' => $term,
            ':t3' => $term
        ]);
    }
    $tecnicos = $stmt->fetchAll();
    echo json_encode(['success' => true, 'tecnicos' => $tecnicos]);
    exit;
}

// 3.2 Edición de Datos del Reporte / Ticket
if ($action === 'edit_ticket_details') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'error' => 'Método inválido']);
        exit;
    }

    $ticketId = filter_input(INPUT_POST, 'ticket_id', FILTER_VALIDATE_INT);
    $unidadId = filter_input(INPUT_POST, 'unidad_id', FILTER_VALIDATE_INT);
    $areaId = filter_input(INPUT_POST, 'area_id', FILTER_VALIDATE_INT);
    $descripcionFalla = strtoupper(trim(filter_input(INPUT_POST, 'descripcion_falla', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''));
    $solicitanteApPaterno = strtoupper(trim(filter_input(INPUT_POST, 'solicitante_ap_paterno', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''));
    $solicitanteApMaterno = strtoupper(trim(filter_input(INPUT_POST, 'solicitante_ap_materno', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''));
    $solicitanteNombres = strtoupper(trim(filter_input(INPUT_POST, 'solicitante_nombres', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''));
    $cargoSolicitante = strtoupper(trim(filter_input(INPUT_POST, 'cargo_solicitante', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''));
    $observaciones = strtoupper(trim(filter_input(INPUT_POST, 'observaciones', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''));
    $fechaRecepcion = filter_input(INPUT_POST, 'fecha_recepcion', FILTER_SANITIZE_SPECIAL_CHARS);

    if (!$ticketId || !$unidadId || !$areaId || empty($descripcionFalla) || empty($solicitanteNombres)) {
        echo json_encode(['success' => false, 'error' => 'Por favor complete todos los campos obligatorios (*)']);
        exit;
    }

    try {
        $sql = "
            UPDATE reportes
            SET unidad_id = :unidad_id,
                area_id = :area_id,
                descripcion_falla = :descripcion_falla,
                solicitante_ap_paterno = :solicitante_ap_paterno,
                solicitante_ap_materno = :solicitante_ap_materno,
                solicitante_nombres = :solicitante_nombres,
                cargo_solicitante = :cargo_solicitante,
                observaciones = :observaciones
        ";
        $params = [
            ':unidad_id' => $unidadId,
            ':area_id' => $areaId,
            ':descripcion_falla' => $descripcionFalla,
            ':solicitante_ap_paterno' => $solicitanteApPaterno,
            ':solicitante_ap_materno' => $solicitanteApMaterno,
            ':solicitante_nombres' => $solicitanteNombres,
            ':cargo_solicitante' => $cargoSolicitante,
            ':observaciones' => $observaciones,
            ':id' => $ticketId
        ];

        if (!empty($fechaRecepcion)) {
            $sql .= ", fecha_recepcion = :fecha_recepcion";
            $params[':fecha_recepcion'] = $fechaRecepcion;
        }

        $sql .= " WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['success' => true, 'message' => 'Reporte actualizado exitosamente']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// 4. Asignar Técnico a Ticket
if ($action === 'assign_technician') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'error' => 'Método inválido']);
        exit;
    }

    $ticketId = filter_input(INPUT_POST, 'ticket_id', FILTER_VALIDATE_INT);
    $tecnicoId = filter_input(INPUT_POST, 'tecnico_id', FILTER_VALIDATE_INT);
    $instrucciones = strtoupper(trim(filter_input(INPUT_POST, 'instrucciones', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''));
    $usuarioAsignoId = $_SESSION['user_id'] ?? null;

    if (!$ticketId || !$tecnicoId) {
        echo json_encode(['success' => false, 'error' => 'Datos incompletos para asignación']);
        exit;
    }

    // Asegurar que la columna usuario_asigno_id exista
    try {
        $db->exec("ALTER TABLE reportes ADD COLUMN usuario_asigno_id INT NULL AFTER tecnico_asignado_id");
    } catch (Exception $e) { /* ya existe */ }

    try {
        $stmt = $db->prepare("
            UPDATE reportes 
            SET tecnico_asignado_id = ?, 
                usuario_asigno_id = ?,
                estatus = 'Asignado', 
                fecha_asignacion = NOW(),
                instrucciones_asignacion = ?
            WHERE id = ?
        ");
        $stmt->execute([$tecnicoId, $usuarioAsignoId, $instrucciones, $ticketId]);

        echo json_encode(['success' => true, 'message' => 'Técnico asignado exitosamente']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// 5. Concluir Ticket con Evidencia Fotográfica
if ($action === 'close_ticket') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'error' => 'Método inválido']);
        exit;
    }

    $ticketId = filter_input(INPUT_POST, 'ticket_id', FILTER_VALIDATE_INT);
    $obsCierre = strtoupper(trim(filter_input(INPUT_POST, 'observaciones_cierre', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''));
    $materialesRefacciones = strtoupper(trim(filter_input(INPUT_POST, 'materiales_refacciones', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''));
    $condicionFinal = strtoupper(trim(filter_input(INPUT_POST, 'condicion_final', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''));
    $recomendaciones = strtoupper(trim(filter_input(INPUT_POST, 'recomendaciones', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''));
    $tipoServicio = filter_input(INPUT_POST, 'tipo_servicio', FILTER_SANITIZE_SPECIAL_CHARS) ?? 'Correctivo';
    if (!in_array($tipoServicio, ['Preventivo', 'Correctivo'])) {
        $tipoServicio = 'Correctivo';
    }
    $recibeNombre = strtoupper(trim(filter_input(INPUT_POST, 'recibe_nombre', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''));
    $recibeMatricula = strtoupper(trim(filter_input(INPUT_POST, 'recibe_matricula', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''));
    $sinFotos = filter_input(INPUT_POST, 'sin_fotos', FILTER_SANITIZE_SPECIAL_CHARS);

    if (!$ticketId) {
        echo json_encode(['success' => false, 'error' => 'ID de ticket no especificado']);
        exit;
    }

    // Asegurar que las columnas extra existan (migracion segura)
    try {
        $db->exec("ALTER TABLE reportes ADD COLUMN foto_antes_path_2 VARCHAR(255) NULL AFTER foto_antes_path");
    } catch (Exception $e) { /* ya existe */ }
    try {
        $db->exec("ALTER TABLE reportes ADD COLUMN foto_despues_path_2 VARCHAR(255) NULL AFTER foto_despues_path");
    } catch (Exception $e) { /* ya existe */ }
    try {
        $db->exec("ALTER TABLE reportes ADD COLUMN condicion_final TEXT NULL AFTER materiales_refacciones");
    } catch (Exception $e) { /* ya existe */ }
    try {
        $db->exec("ALTER TABLE reportes ADD COLUMN recomendaciones TEXT NULL AFTER condicion_final");
    } catch (Exception $e) { /* ya existe */ }
    try {
        $db->exec("ALTER TABLE reportes ADD COLUMN tipo_servicio VARCHAR(20) NULL DEFAULT 'Correctivo' AFTER estatus");
    } catch (Exception $e) { /* ya existe */ }
    try {
        $db->exec("ALTER TABLE reportes ADD COLUMN recibe_nombre VARCHAR(150) NULL AFTER recomendaciones");
    } catch (Exception $e) { /* ya existe */ }
    try {
        $db->exec("ALTER TABLE reportes ADD COLUMN recibe_matricula VARCHAR(50) NULL AFTER recibe_nombre");
    } catch (Exception $e) { /* ya existe */ }

    // Directorio de almacenamiento de uploads
    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $fotoPaths = [
        'foto_antes_path' => null,
        'foto_antes_path_2' => null,
        'foto_despues_path' => null,
        'foto_despues_path_2' => null,
    ];

    // Mapeo de campo de formulario -> columna de DB
    $fileMap = [
        'foto_antes_1'   => 'foto_antes_path',
        'foto_antes_2'   => 'foto_antes_path_2',
        'foto_despues_1' => 'foto_despues_path',
        'foto_despues_2' => 'foto_despues_path_2',
    ];

    // Obtener el folio del ticket para nombrar las imagenes
    $stmtFolio = $db->prepare("SELECT folio FROM reportes WHERE id = ?");
    $stmtFolio->execute([$ticketId]);
    $folioRes = $stmtFolio->fetch();
    $folio = $folioRes ? $folioRes['folio'] : 'TICKET-' . $ticketId;

    $sufijos = [
        'foto_antes_1'   => '_A1',
        'foto_antes_2'   => '_A2',
        'foto_despues_1' => '_D1',
        'foto_despues_2' => '_D2',
    ];

    if ($sinFotos !== '1') {
        foreach ($fileMap as $inputName => $dbCol) {
            if (isset($_FILES[$inputName]) && $_FILES[$inputName]['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES[$inputName]['name'], PATHINFO_EXTENSION));
                $sufijo = $sufijos[$inputName];
                $fileName = $folio . $sufijo . '.' . $ext;
                
                if (move_uploaded_file($_FILES[$inputName]['tmp_name'], $uploadDir . $fileName)) {
                    $fotoPaths[$dbCol] = 'uploads/' . $fileName;
                }
            }
        }
    }

    $fechaConclusionQuery = "NOW()";
    $fechaConclusionManual = filter_input(INPUT_POST, 'fecha_conclusion_manual', FILTER_SANITIZE_SPECIAL_CHARS);
    if ($fechaConclusionManual) {
        $fechaConclusionQuery = $db->quote($fechaConclusionManual);
    }
    $fechaRecepcionEdit = filter_input(INPUT_POST, 'fecha_recepcion_edit', FILTER_SANITIZE_SPECIAL_CHARS);

    try {
        $sql = "UPDATE reportes SET estatus = 'Concluido', fecha_conclusion = $fechaConclusionQuery, 
                solucion_cierre = ?, materiales_refacciones = ?, condicion_final = ?, recomendaciones = ?,
                tipo_servicio = ?, recibe_nombre = ?, recibe_matricula = ?";
        $params = [$obsCierre, $materialesRefacciones, $condicionFinal, $recomendaciones, $tipoServicio, $recibeNombre, $recibeMatricula];

        if ($fechaRecepcionEdit) {
            $sql .= ", fecha_recepcion = ?";
            $params[] = $fechaRecepcionEdit;
        }

        foreach ($fotoPaths as $col => $path) {
            if ($path) {
                $sql .= ", $col = ?";
                $params[] = $path;
            }
        }

        $sql .= " WHERE id = ?";
        $params[] = $ticketId;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// PERSONAL OPERATIVO — CRUD
// ============================================================

// 6. Listar todo el personal operativo
if ($action === 'list_personal') {
    $unidadFiltro = filter_input(INPUT_GET, 'unidad_id', FILTER_VALIDATE_INT) ?? $userUnidadId;
    $sql = "SELECT p.*, u.clave_unidad FROM personal_operativo p 
            INNER JOIN unidades u ON p.unidad_id = u.id
            WHERE 1=1";
    $params = [];
    if ($unidadFiltro) { $sql .= " AND p.unidad_id = ?"; $params[] = $unidadFiltro; }
    $sql .= " ORDER BY p.nombre ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'personal' => $stmt->fetchAll()]);
    exit;
}

// 7. Guardar personal operativo (crear o editar)
if ($action === 'save_personal' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id        = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
    if ($_SESSION['user_rol'] === 'SuperAdmin') {
        $unidadId  = filter_input(INPUT_POST, 'unidad_id', FILTER_VALIDATE_INT);
    } else {
        $unidadId  = $_SESSION['user_unidad_id'];
    }
    $matricula = strtoupper(trim(filter_input(INPUT_POST, 'matricula', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''));
    $nombre    = strtoupper(trim(filter_input(INPUT_POST, 'nombre', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''));
    $categoria = trim(filter_input(INPUT_POST, 'categoria', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
    $turno     = trim(filter_input(INPUT_POST, 'turno', FILTER_SANITIZE_SPECIAL_CHARS) ?? 'Matutino');
    $entrada   = filter_input(INPUT_POST, 'horario_entrada', FILTER_SANITIZE_SPECIAL_CHARS) ?: '07:00:00';
    $salida    = filter_input(INPUT_POST, 'horario_salida', FILTER_SANITIZE_SPECIAL_CHARS) ?: '15:00:00';
    $descanso  = filter_input(INPUT_POST, 'dias_descanso', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'Sábado y Domingo';

    if (!$unidadId || !$matricula || !$nombre || !$categoria) {
        echo json_encode(['success' => false, 'error' => 'Campos obligatorios faltantes']);
        exit;
    }
    try {
        if ($id) {
            $stmt = $db->prepare("UPDATE personal_operativo SET unidad_id=?,matricula=?,nombre=?,categoria=?,turno=?,horario_entrada=?,horario_salida=?,dias_descanso=? WHERE id=?");
            $stmt->execute([$unidadId,$matricula,$nombre,$categoria,$turno,$entrada,$salida,$descanso,$id]);
        } else {
            $stmt = $db->prepare("INSERT INTO personal_operativo (unidad_id,matricula,nombre,categoria,turno,horario_entrada,horario_salida,dias_descanso,estatus) VALUES (?,?,?,?,?,?,?,?,'Activo')");
            $stmt->execute([$unidadId,$matricula,$nombre,$categoria,$turno,$entrada,$salida,$descanso]);
            $id = $db->lastInsertId();
        }
        echo json_encode(['success' => true, 'id' => $id]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// 8. Activar/Desactivar personal operativo
if ($action === 'toggle_personal' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) { echo json_encode(['success'=>false,'error'=>'ID inválido']); exit; }
    $stmt = $db->prepare("UPDATE personal_operativo SET estatus = IF(estatus='Activo','Inactivo','Activo') WHERE id=?");
    $stmt->execute([$id]);
    $row = $db->query("SELECT estatus FROM personal_operativo WHERE id=$id")->fetch();
    echo json_encode(['success' => true, 'nuevo_estatus' => $row['estatus']]);
    exit;
}

// ============================================================
// ÁREAS MAESTRO — CRUD
// ============================================================

// 9. Listar áreas maestro con estado de habilitación por unidad
if ($action === 'list_areas') {
    $unidadId = filter_input(INPUT_GET, 'unidad_id', FILTER_VALIDATE_INT) ?? $userUnidadId ?? 0;
    $stmt = $db->prepare("
        SELECT a.id, a.nombre_area,
               COALESCE(ua.estatus, 'Deshabilitado') AS estado_unidad
        FROM areas_maestro a
        LEFT JOIN unidades_areas ua ON a.id = ua.id_area AND ua.id_unidad = ?
        ORDER BY a.nombre_area ASC
    ");
    $stmt->execute([$unidadId]);
    echo json_encode(['success' => true, 'areas' => $stmt->fetchAll()]);
    exit;
}

// 10. Guardar área maestra (crear o renombrar)
if ($action === 'save_area' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
    $nombre = strtoupper(trim(filter_input(INPUT_POST, 'nombre_area', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''));
    if (!$nombre) { echo json_encode(['success'=>false,'error'=>'Nombre requerido']); exit; }
    try {
        if ($id) {
            $db->prepare("UPDATE areas_maestro SET nombre_area=? WHERE id=?")->execute([$nombre,$id]);
        } else {
            $db->prepare("INSERT INTO areas_maestro (nombre_area) VALUES (?)")->execute([$nombre]);
            $id = $db->lastInsertId();
        }
        echo json_encode(['success'=>true,'id'=>$id]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// 11. Habilitar/Deshabilitar área en una unidad
if ($action === 'toggle_unidad_area' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $areaId   = filter_input(INPUT_POST, 'area_id', FILTER_VALIDATE_INT);
    $unidadId = filter_input(INPUT_POST, 'unidad_id', FILTER_VALIDATE_INT) ?? $userUnidadId;
    if (!$areaId || !$unidadId) { echo json_encode(['success'=>false,'error'=>'IDs inválidos']); exit; }

    // Verificar si ya existe el registro
    $check = $db->prepare("SELECT id, estatus FROM unidades_areas WHERE id_unidad=? AND id_area=?");
    $check->execute([$unidadId, $areaId]);
    $row = $check->fetch();

    if ($row) {
        $nuevo = ($row['estatus'] === 'Habilitado') ? 'Deshabilitado' : 'Habilitado';
        $db->prepare("UPDATE unidades_areas SET estatus=? WHERE id=?")->execute([$nuevo,$row['id']]);
    } else {
        $db->prepare("INSERT INTO unidades_areas (id_unidad,id_area,estatus) VALUES (?,?,'Habilitado')")->execute([$unidadId,$areaId]);
        $nuevo = 'Habilitado';
    }
    echo json_encode(['success'=>true,'nuevo_estatus'=>$nuevo]);
    exit;
}

// ============================================================
// UNIDADES — CRUD
// ============================================================

// 12. Listar unidades
if ($action === 'list_unidades') {
    $stmt = $db->query("SELECT * FROM unidades ORDER BY clave_unidad ASC");
    echo json_encode(['success'=>true,'unidades'=>$stmt->fetchAll()]);
    exit;
}

// 13. Guardar unidad (crear o editar)
if ($action === 'save_unidad' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_SESSION['user_rol'] !== 'SuperAdmin') {
        echo json_encode(['success' => false, 'error' => 'Solo SuperAdmin puede gestionar unidades']);
        exit;
    }
    $id                = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
    $nombre            = strtoupper(trim(filter_input(INPUT_POST, 'nombre_unidad', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''));
    $clave             = strtoupper(trim(filter_input(INPUT_POST, 'clave_unidad', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''));
    $ubic              = strtoupper(trim(filter_input(INPUT_POST, 'ubicacion', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''));
    $dir               = strtoupper(trim(filter_input(INPUT_POST, 'direccion', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''));
    $delegacion        = strtoupper(trim(filter_input(INPUT_POST, 'delegacion', FILTER_SANITIZE_SPECIAL_CHARS) ?? 'BCS'));
    $clavePresupuestal = trim(filter_input(INPUT_POST, 'clave_presupuestal', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
    $estatus           = in_array(filter_input(INPUT_POST,'estatus',FILTER_SANITIZE_SPECIAL_CHARS),['Activo','Inactivo']) ? filter_input(INPUT_POST,'estatus',FILTER_SANITIZE_SPECIAL_CHARS) : 'Activo';

    if (!$nombre || !$clave) { echo json_encode(['success'=>false,'error'=>'Nombre y clave requeridos']); exit; }
    try {
        if ($id) {
            $stmt = $db->prepare("UPDATE unidades SET nombre_unidad=?, clave_unidad=?, ubicacion=?, direccion=?, delegacion=?, clave_presupuestal=?, estatus=? WHERE id=?");
            $stmt->execute([$nombre, $clave, $ubic, $dir, $delegacion, $clavePresupuestal, $estatus, $id]);
        } else {
            $stmt = $db->prepare("INSERT INTO unidades (nombre_unidad, clave_unidad, ubicacion, direccion, delegacion, clave_presupuestal, estatus) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$nombre, $clave, $ubic, $dir, $delegacion, $clavePresupuestal, $estatus]);
            $id = $db->lastInsertId();
        }
        echo json_encode(['success'=>true,'id'=>$id]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// ============================================================
// USUARIOS ADMIN — CRUD
// ============================================================

// 14. Guardar usuario admin (crear o editar)
if ($action === 'save_usuario' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
    $username = trim(filter_input(INPUT_POST, 'username', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
    $nombre = strtoupper(trim(filter_input(INPUT_POST, 'nombre_completo', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''));
    $rol = filter_input(INPUT_POST, 'rol', FILTER_SANITIZE_SPECIAL_CHARS) ?? 'Jefe';
    
    // Procesar unidades seleccionadas (array o valor único)
    $unidadesInput = $_POST['unidades'] ?? [];
    if (!is_array($unidadesInput)) {
        $unidadesInput = !empty($unidadesInput) ? explode(',', $unidadesInput) : [];
    }
    if (empty($unidadesInput) && !empty($_POST['unidad_id'])) {
        $unidadesInput = [$_POST['unidad_id']];
    }
    
    $unidadesValidas = [];
    foreach ($unidadesInput as $u) {
        $uInt = (int)$u;
        if ($uInt > 0) {
            $unidadesValidas[] = $uInt;
        }
    }
    $unidadesValidas = array_values(array_unique($unidadesValidas));

    if ($_SESSION['user_rol'] !== 'SuperAdmin') {
        if ($rol === 'SuperAdmin') {
            echo json_encode(['success' => false, 'error' => 'No tiene permisos para asignar el rol SuperAdmin']);
            exit;
        }
        if ($rol === 'Jefe' && $_SESSION['user_rol'] !== 'Jefe') {
            echo json_encode(['success' => false, 'error' => 'No tiene permisos para asignar el rol Jefe']);
            exit;
        }
        // Restringir a las unidades del usuario actual si no seleccionó ninguna
        if (empty($unidadesValidas)) {
            $stmtMyU = $db->prepare("SELECT unidad_id FROM usuarios_unidades WHERE usuario_id = ?");
            $stmtMyU->execute([$_SESSION['user_id']]);
            $unidadesValidas = $stmtMyU->fetchAll(PDO::FETCH_COLUMN);
            if (empty($unidadesValidas) && !empty($_SESSION['user_unidad_id'])) {
                $unidadesValidas = [(int)$_SESSION['user_unidad_id']];
            }
        }
    }

    $unidadPrincipal = $unidadesValidas[0] ?? null;
    $estatus = filter_input(INPUT_POST, 'estatus', FILTER_SANITIZE_SPECIAL_CHARS) ?? 'Activo';
    $password = trim(filter_input(INPUT_POST, 'password', FILTER_DEFAULT) ?? '');

    if (!$username || !$nombre || !$rol) {
        echo json_encode(['success'=>false,'error'=>'Username, nombre y rol son obligatorios']);
        exit;
    }
    try {
        if ($id) {
            if ($password) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE usuarios_admin SET username=?, nombre_completo=?, rol=?, unidad_id=?, estatus=?, password_hash=? WHERE id=?");
                $stmt->execute([$username, $nombre, $rol, $unidadPrincipal, $estatus, $hash, $id]);
            } else {
                $stmt = $db->prepare("UPDATE usuarios_admin SET username=?, nombre_completo=?, rol=?, unidad_id=?, estatus=? WHERE id=?");
                $stmt->execute([$username, $nombre, $rol, $unidadPrincipal, $estatus, $id]);
            }
        } else {
            if (!$password) $password = 'imss2026';
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO usuarios_admin (username, password_hash, nombre_completo, rol, unidad_id, estatus) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$username, $hash, $nombre, $rol, $unidadPrincipal, $estatus]);
            $id = (int)$db->lastInsertId();
        }

        // Sincronizar tabla pivote usuarios_unidades
        $db->prepare("DELETE FROM usuarios_unidades WHERE usuario_id = ?")->execute([$id]);
        if (!empty($unidadesValidas)) {
            $stmtPiv = $db->prepare("INSERT INTO usuarios_unidades (usuario_id, unidad_id) VALUES (?, ?)");
            foreach ($unidadesValidas as $uId) {
                $stmtPiv->execute([$id, $uId]);
            }
        }

        echo json_encode(['success'=>true,'id'=>$id]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Acción no reconocida']);


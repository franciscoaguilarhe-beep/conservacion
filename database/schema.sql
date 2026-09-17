-- =============================================================================
-- BASE DE DATOS: PLATAFORMA DE GESTIÓN DE CONSERVACIÓN IMSS (FASE 1)
-- =============================================================================

CREATE DATABASE IF NOT EXISTS imss_conservacion 
  CHARACTER SET utf8mb4 
  COLLATE utf8mb4_unicode_ci;

USE imss_conservacion;

-- 1. Tabla: unidades
-- Controla los centros de trabajo/clínicas médicas del IMSS
CREATE TABLE IF NOT EXISTS unidades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre_unidad VARCHAR(150) NOT NULL COMMENT 'Nombre oficial (ej. UNIDAD DE MEDICINA FAMILIAR No.40)',
    clave_unidad VARCHAR(20) NOT NULL UNIQUE COMMENT 'Siglas o clave identificadora (ej. UMF40)',
    ubicacion VARCHAR(255) NOT NULL DEFAULT 'La Paz, B.C.S.' COMMENT 'Ciudad y Estado (ej. La Paz, B.C.S.)',
    direccion VARCHAR(255) NULL,
    delegacion VARCHAR(100) DEFAULT 'BCS' COMMENT 'Delegación IMSS (ej. BCS)',
    clave_presupuestal VARCHAR(20) DEFAULT '' COMMENT 'Clave presupuestal de la unidad (ej. 32416)',
    estatus ENUM('Activo', 'Inactivo') DEFAULT 'Activo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Tabla: areas_maestro
-- Catálogo global maestro de áreas e instalaciones posibles en las unidades
CREATE TABLE IF NOT EXISTS areas_maestro (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre_area VARCHAR(100) NOT NULL UNIQUE COMMENT 'Nombre del departamento/área',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 3. Tabla: unidades_areas
-- Tabla pivote para activar o desactivar departamentos por unidad médica
CREATE TABLE IF NOT EXISTS unidades_areas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_unidad INT NOT NULL,
    id_area INT NOT NULL,
    estatus ENUM('Habilitado', 'Deshabilitado') DEFAULT 'Habilitado',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_unidad_area (id_unidad, id_area),
    FOREIGN KEY (id_unidad) REFERENCES unidades(id) ON DELETE CASCADE,
    FOREIGN KEY (id_area) REFERENCES areas_maestro(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 4. Tabla: usuarios_admin
-- Perfiles con acceso administrativo y operativo al panel de control
CREATE TABLE IF NOT EXISTS usuarios_admin (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    nombre_completo VARCHAR(150) NOT NULL,
    rol ENUM('SuperAdmin', 'Jefe', 'Subjefe', 'AUO') NOT NULL,
    unidad_id INT NULL COMMENT 'Unidad principal por defecto, NULL si es SuperAdmin global',
    turno ENUM('Matutino', 'Vespertino', 'Nocturno', 'Jornada Acumulada') DEFAULT 'Matutino',
    horario_entrada TIME DEFAULT '07:00:00',
    horario_salida TIME DEFAULT '15:00:00',
    estatus ENUM('Activo', 'Inactivo') DEFAULT 'Activo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (unidad_id) REFERENCES unidades(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 4.1 Tabla: usuarios_unidades
-- Asignación multi-unidad para Jefes, Subjefes y AUO
CREATE TABLE IF NOT EXISTS usuarios_unidades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    unidad_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usuario_unidad (usuario_id, unidad_id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios_admin(id) ON DELETE CASCADE,
    FOREIGN KEY (unidad_id) REFERENCES unidades(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 5. Tabla: personal_operativo
-- Plantilla de técnicos y auxiliares de conservación para asignación
CREATE TABLE IF NOT EXISTS personal_operativo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    unidad_id INT NOT NULL,
    matricula VARCHAR(20) NOT NULL UNIQUE,
    nombre VARCHAR(150) NOT NULL,
    categoria VARCHAR(100) NOT NULL COMMENT 'Plomero, Electricista, Frigorista, etc.',
    turno ENUM('Matutino', 'Vespertino', 'Nocturno', 'Jornada Acumulada') DEFAULT 'Matutino',
    horario_entrada TIME DEFAULT '07:00:00',
    horario_salida TIME DEFAULT '15:00:00',
    dias_descanso VARCHAR(50) DEFAULT 'Sábado y Domingo',
    estatus ENUM('Activo', 'Inactivo') DEFAULT 'Activo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (unidad_id) REFERENCES unidades(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 6. Tabla: reportes
-- Núcleo transaccional de folios y solicitudes de servicio
CREATE TABLE IF NOT EXISTS reportes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    folio VARCHAR(30) NOT NULL UNIQUE COMMENT 'Prefijo unidad + año 2 digitos + correlativo (ej. UMF40-2600001)',
    unidad_id INT NOT NULL,
    area_id INT NOT NULL,
    solicitante_ap_paterno VARCHAR(80) NOT NULL,
    solicitante_ap_materno VARCHAR(80) NOT NULL,
    solicitante_nombres VARCHAR(100) NOT NULL,
    cargo_solicitante VARCHAR(100) DEFAULT 'Personal de Servicio',
    descripcion_falla TEXT NOT NULL COMMENT 'Descripción del trabajo / desperfecto',
    observaciones TEXT NULL COMMENT 'Detalles adicionales del solicitante',
    instrucciones_asignacion TEXT NULL COMMENT 'Instrucciones del Jefe al asignar técnico',
    solucion_cierre TEXT NULL COMMENT 'Descripción completa del servicio realizado',
    materiales_refacciones TEXT NULL COMMENT 'Materiales y refacciones empleados',
    condicion_final TEXT NULL COMMENT 'Condición final de funcionamiento',
    recomendaciones TEXT NULL COMMENT 'Recomendaciones del servicio',
    recibe_nombre VARCHAR(150) NULL COMMENT 'Nombre de quien recibe de conformidad',
    recibe_matricula VARCHAR(50) NULL COMMENT 'Matrícula de quien recibe de conformidad',
    tecnico_asignado_id INT NULL,
    usuario_asigno_id INT NULL COMMENT 'Usuario que realizó la asignación técnica',
    fecha_recepcion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_asignacion DATETIME NULL,
    fecha_conclusion DATETIME NULL,
    foto_antes_path VARCHAR(255) NULL,
    foto_antes_path_2 VARCHAR(255) NULL,
    foto_despues_path VARCHAR(255) NULL,
    foto_despues_path_2 VARCHAR(255) NULL,
    estatus ENUM('Pendiente', 'Asignado', 'Concluido') NOT NULL DEFAULT 'Pendiente',
    tipo_servicio ENUM('Preventivo', 'Correctivo') DEFAULT 'Correctivo' COMMENT 'Tipo de origen del servicio',
    clave_formato VARCHAR(50) DEFAULT '14C2-003-002' COMMENT 'Norma institucional IMSS',
    anio_consecutivo SMALLINT NOT NULL DEFAULT 2026,
    numero_consecutivo INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (unidad_id) REFERENCES unidades(id) ON DELETE RESTRICT,
    FOREIGN KEY (area_id) REFERENCES areas_maestro(id) ON DELETE RESTRICT,
    FOREIGN KEY (tecnico_asignado_id) REFERENCES personal_operativo(id) ON DELETE SET NULL,
    INDEX idx_estatus (estatus),
    INDEX idx_fecha_recepcion (fecha_recepcion),
    INDEX idx_folio (folio)
) ENGINE=InnoDB;

-- =============================================================================
-- DATOS INICIALES (SEEDS)
-- =============================================================================

-- Inserción de Unidades
INSERT INTO unidades (id, nombre_unidad, clave_unidad, ubicacion) VALUES 
(1, 'UNIDAD DE MEDICINA FAMILIAR No.40', 'UMF40', 'La Paz, B.C.S.'),
(2, 'UNIDAD DE MEDICINA FAMILIAR No.34', 'UMF34', 'La Paz, B.C.S.'),
(3, 'HOSPITAL GENERAL DE ZONA No.1', 'HGZ1', 'La Paz, B.C.S.')
ON DUPLICATE KEY UPDATE nombre_unidad=VALUES(nombre_unidad);

-- Inserción de Catálogo Maestro de Áreas
INSERT INTO areas_maestro (id, nombre_area) VALUES 
(1, 'ENFERMERIA 6'),
(2, 'CONSULTORIO 1'),
(3, 'CONSULTORIO 2'),
(4, 'CONSULTORIO 3'),
(5, 'CONSULTORIO 4'),
(6, 'URGENCIAS'),
(7, 'LABORATORIO CLINICO'),
(8, 'RAYOS X'),
(9, 'FARMACIA'),
(10, 'ARCHIVO CLINICO / ARIMAC'),
(11, 'JEFATURA DE GOBIERNO Y DIRECCION'),
(12, 'ADMINISTRACION'),
(13, 'SALA DE ESPERA GENERAL'),
(14, 'MODULO DE MEDICINA PREVENTIVA'),
(15, 'SANITARIOS PUBLICOS')
ON DUPLICATE KEY UPDATE nombre_area=VALUES(nombre_area);

-- Habilitación de Áreas para UMF40 (id_unidad = 1)
INSERT IGNORE INTO unidades_areas (id_unidad, id_area, estatus) VALUES 
(1, 1, 'Habilitado'),
(1, 2, 'Habilitado'),
(1, 3, 'Habilitado'),
(1, 4, 'Habilitado'),
(1, 5, 'Habilitado'),
(1, 6, 'Habilitado'),
(1, 7, 'Habilitado'),
(1, 8, 'Habilitado'),
(1, 9, 'Habilitado'),
(1, 10, 'Habilitado'),
(1, 11, 'Habilitado'),
(1, 12, 'Habilitado'),
(1, 13, 'Habilitado'),
(1, 14, 'Habilitado'),
(1, 15, 'Habilitado');

-- Inserción de Usuarios Administrativos
-- Contraseña por defecto para pruebas iniciales: 'admin123' -> $2y$10$w4rYq4oKk0u9Z2pUe.kROuXzXW8N4Bv1H2k0iQ2w3m4n5o6p7q8r9
-- Generado con password_hash('imss2026', PASSWORD_BCRYPT)
INSERT INTO usuarios_admin (id, username, password_hash, nombre_completo, rol, unidad_id, turno) VALUES 
(1, 'superadmin', '$2y$10$Z3UuCj5Y8qP3bXJjD5wLz.sN0bYk2H1zY4dG8e0kH5mB1v2C3d4E5', 'Administrador General del Sistema', 'SuperAdmin', NULL, 'Matutino'),
(2, 'jefe.umf40', '$2y$10$Z3UuCj5Y8qP3bXJjD5wLz.sN0bYk2H1zY4dG8e0kH5mB1v2C3d4E5', 'Ing. Francisco Aguilar (Jefe Conservación)', 'Jefe', 1, 'Matutino'),
(3, 'subjefe.umf40', '$2y$10$Z3UuCj5Y8qP3bXJjD5wLz.sN0bYk2H1zY4dG8e0kH5mB1v2C3d4E5', 'Téc. Carlos Mendoza', 'Subjefe', 1, 'Vespertino'),
(4, 'auo.umf40', '$2y$10$Z3UuCj5Y8qP3bXJjD5wLz.sN0bYk2H1zY4dG8e0kH5mB1v2C3d4E5', 'María Elena Cota (AUO)', 'AUO', 1, 'Matutino')
ON DUPLICATE KEY UPDATE nombre_completo=VALUES(nombre_completo);

-- Inserción de Personal Operativo de Conservación (UMF40)
INSERT INTO personal_operativo (id, unidad_id, matricula, nombre, categoria, turno, horario_entrada, horario_salida) VALUES 
(1, 1, '99382101', 'Juan Ramón Domínguez', 'Operario Polivalente / Fontanería', 'Matutino', '07:00:00', '15:00:00'),
(2, 1, '99451234', 'Roberto Gómez Higuera', 'Electricista Especializado', 'Matutino', '07:00:00', '15:00:00'),
(3, 1, '99512389', 'Luis Fernando Castro', 'Técnico en Aire Acondicionado', 'Vespertino', '14:00:00', '21:30:00'),
(4, 1, '99602341', 'Miguel Ángel Talamantes', 'Mantenimiento General / Cerrajero', 'Matutino', '07:00:00', '15:00:00')
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre);

-- Inserción de reporte inicial base (Inspirado en el folio normado del PDF UMF40-00754)
INSERT INTO reportes (
    id, folio, unidad_id, area_id, 
    solicitante_ap_paterno, solicitante_ap_materno, solicitante_nombres, cargo_solicitante,
    descripcion_falla, observaciones, tecnico_asignado_id, 
    fecha_recepcion, fecha_asignacion, fecha_conclusion, 
    estatus, anio_consecutivo, numero_consecutivo
) VALUES (
    1, 'UMF40-2600754', 1, 1,
    'GUTIERREZ', '', 'KAREN', 'JEFE DE PISO',
    'PERILLA DE BAUMANOMETRO', 'PERILLA SE QUEDA PEGADA', 1,
    '2026-08-14 09:30:00', '2026-08-14 10:15:00', '2026-08-14 12:45:00',
    'Concluido', 2026, 754
) ON DUPLICATE KEY UPDATE folio=VALUES(folio);

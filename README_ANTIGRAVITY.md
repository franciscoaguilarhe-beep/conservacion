# Plataforma de Gestión de Conservación IMSS (Fase 1)
### Documentación Técnica y Guía de Desarrollo para Antigravity IDE

---

## 1. Visión General del Proyecto
La **Plataforma de Gestión de Conservación** es un sistema web diseñado específicamente para las Jefaturas de Servicios de Conservación de Unidades Médicas del Instituto Mexicano del Seguro Social (IMSS), tomando como piloto y estándar normativo la **Unidad de Medicina Familiar No. 40 (UMF40)** en La Paz, Baja California Sur.

El sistema digitaliza el levantamiento de reportes de fallas, automatiza la asignación de personal técnico, permite el cierre con evidencia fotográfica (Antes/Después) y genera la orden de servicio oficial normada bajo la **Clave: 14C2-003-002** (duplicada en hoja carta para entrega de talón original y copia al solicitante).

---

## 2. Stack Tecnológico
- **Entorno Local / Contenedores:** Docker Desktop con `docker-compose` (PHP 8.2 Apache + MySQL 8.0 + phpMyAdmin).
- **Compilación de Assets:** Node.js, Tailwind CSS 3.4, PostCSS.
- **Backend:** PHP 8.2 con PDO, Prepared Statements y manejo atómico de transacciones (`FOR UPDATE`).
- **Base de Datos:** MySQL 8.0 (`utf8mb4_unicode_ci`), tablas relacionales con llaves foráneas e integridad referencial.
- **Frontend y UX/UI:**
  - Modo oscuro minimalista nativo con fondo base `#121212`, superficies `#1E1E1E` y `#242424`.
  - Acentos institucionales en Verde IMSS (`#0C6839`, `#073B20`, `#22c55e`) y Dorado (`#C5A572`).
  - Alerta sonora institucional sintetizada vía **Web Audio API** (sin dependencias de archivos externos de audio).
  - Consulta pública con **Línea de Tiempo Visual (Stepper)** de 3 fases (Recibido, Asignado, Concluido).
  - Polling en tiempo real vía AJAX cada 6 segundos para actualización dinámica del Dashboard.

---

## 3. Regla Institucional de Folios
El folio de cada reporte de servicio se genera de forma atómica y consecutiva por año y por unidad médica:
$$\text{FOLIO} = \text{[CLAVE\_UNIDAD]} - \text{[YY]}[XXXXX]$$
- **Prefijo:** Siglas de la unidad médica (ej. `UMF40`).
- **Separador:** Guion medio (`-`).
- **Año a 2 dígitos:** Año fiscal en curso (ej. `26` para el año 2026).
- **Consecutivo:** 5 dígitos numéricos incrementables con relleno de ceros a la izquierda (ej. `00001`, `00754`).
- **Ejemplos reales:**
  - `UMF40-2600001` (Primer reporte del año 2026 en UMF40)
  - `UMF40-2600754` (Folio correspondiente al formato institucional normado)

---

## 4. Estructura de Base de Datos (MySQL)
1. **`unidades`:** Controla los centros de trabajo/clínicas (`id`, `nombre_unidad`, `clave_unidad`, `ubicacion`, `estatus`).
2. **`areas_maestro`:** Catálogo global maestro de áreas (`id`, `nombre_area`).
3. **`unidades_areas`:** Tabla pivote con interruptor por clínica (`id_unidad`, `id_area`, `estatus` ['Habilitado', 'Deshabilitado']).
4. **`usuarios_admin`:** Acceso administrativo (`id`, `username`, `password_hash`, `rol` [SuperAdmin, Jefe, Subjefe, AUO], `unidad_id`, `turno`).
5. **`personal_operativo`:** Plantilla de técnicos de conservación (`id`, `unidad_id`, `matricula`, `nombre`, `categoria`, `turno`, `horarios`, `dias_descanso`).
6. **`reportes`:** Núcleo transaccional (`id`, `folio`, `unidad_id`, `area_id`, `solicitante_ap_paterno`, `solicitante_ap_materno`, `solicitante_nombres`, `cargo_solicitante`, `descripcion_falla`, `observaciones`, `tecnico_asignado_id`, `fecha_recepcion`, `fecha_asignacion`, `fecha_conclusion`, `foto_antes_path`, `foto_despues_path`, `estatus`, `anio_consecutivo`, `numero_consecutivo`, `clave_formato`).

---

## 5. Módulos y Flujos Implementados
- **Portal Público (`public/index.php`):** Formulario con separación estricta de nombres y apellidos, selector dinámico de áreas habilitadas y modal con folio generado.
- **Consulta de Estatus (`public/estatus.php`):** Rastreo por folio con visualizador Stepper, datos del técnico y fotos de evidencia.
- **Emisión de Formato Normado (`public/reporte_pdf.php`):** Imprime en hoja carta dos talones idénticos con línea de corte (Original Conservación y Copia Solicitante), acorde a la norma IMSS **Clave: 14C2-003-002**.
- **Panel Administrativo (`public/admin/dashboard.php`):** Métricas, tabla en vivo, chime de audio y modal dinámico de gestión (Fase Asignar / Fase Cerrar).
- **Módulo de Setup (`public/admin/setup.php`):** Interruptores de áreas por unidad, catálogo global y gestión de usuarios.

---

## 6. Puesta en Marcha en Antigravity IDE
1. Abrir la carpeta del proyecto en **Antigravity**.
2. Iniciar contenedores Docker:
   ```bash
   docker compose up -d
   ```
3. Compilar estilos frontend (opcional si se modifican clases Tailwind):
   ```bash
   npm install
   npm run build
   ```
4. Accesos directos en el navegador:
   - **Portal Público:** `http://localhost:8080/`
   - **Consulta de Folios:** `http://localhost:8080/estatus.php`
   - **Acceso Administrativo:** `http://localhost:8080/admin/login.php`
   - **Gestor phpMyAdmin:** `http://localhost:8081/`
5. **Credenciales predeterminadas de prueba:**
   - **Jefe de Conservación (UMF40):** Usuario: `jefe.umf40` | Contraseña: `imss2026`
   - **SuperAdmin Global:** Usuario: `superadmin` | Contraseña: `admin123`

---

## 7. Modalidad Portable (Instalación Mínima para Pruebas y Actualizaciones)

Si no deseas o no puedes instalar **Docker Desktop** en tu equipo (por requisitos de virtualización, permisos de administrador o consumo de memoria RAM), puedes utilizar una de las siguientes opciones portables ultra-ligeras:

### Opción A: Laragon Portable o XAMPP Portable (Recomendada)
1. Descarga la versión **Portable** (archivo ZIP) de Laragon o XAMPP.
2. Descomprímela en cualquier ruta de tu disco (ej. `C:\Laragon` o una memoria USB). **No requiere instalación en Windows ni modifica el registro.**
3. Coloca la carpeta de este proyecto dentro del directorio `www` (en Laragon) o `htdocs` (en XAMPP).
4. Abre el panel de control y haz clic en **Start All** (iniciará PHP 8 y MySQL de forma aislada).
5. Abre phpMyAdmin, crea la base de datos `imss_conservacion` e importa el archivo `database/schema.sql`.
6. Accede en tu navegador a `http://localhost/conservacion_app/public` (o mediante el Virtual Host automático de Laragon).

### Opción B: Servidor PHP Integrado (Zero-Install vía Batch)
Si ya cuentas con el binario de PHP y el servicio de MySQL o MariaDB:
1. Haz doble clic en el script **`iniciar_portable.bat`** ubicado en la raíz del proyecto.
2. Este script levantará el servidor web integrado de PHP en el puerto `8080` apuntando a `public/` y abrirá automáticamente tu navegador.
3. Para detenerlo por completo sin dejar procesos residentes en tu sistema operativo, simplemente ejecuta **`detener_portable.bat`** o presiona `CTRL + C`.

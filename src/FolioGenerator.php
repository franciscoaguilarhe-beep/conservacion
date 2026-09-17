<?php
/**
 * Generador de Folios Institucionales de Conservación IMSS
 * 
 * Regla de Negocio:
 * Prefijo: Siglas de la unidad médica (ej. UMF40)
 * Separador: Guion (-)
 * Consecutivo: 2 dígitos del año en curso (ej. 26 para 2026) seguido de números consecutivos incrementables.
 * Formato resultante: UMF40-2600001, UMF40-2600754, etc.
 */

class FolioGenerator {
    /**
     * Genera el siguiente folio atómico para una unidad médica
     * 
     * @param PDO $db Conexión activa a la base de datos
     * @param int $unidadId ID de la unidad
     * @param string $claveUnidad Clave de la unidad (ej. UMF40)
     * @return array ['folio' => string, 'anio' => int, 'consecutivo' => int]
     */
    public static function generar(PDO $db, int $unidadId, string $claveUnidad): array {
        $anioActual = (int)date('Y');
        $anio2d = date('y'); // ej. '26'
        
        // Bloqueo y obtención del último consecutivo del año para esta unidad
        $stmt = $db->prepare("
            SELECT COALESCE(MAX(numero_consecutivo), 0) AS ultimo_consecutivo 
            FROM reportes 
            WHERE unidad_id = ? AND anio_consecutivo = ?
            FOR UPDATE
        ");
        $stmt->execute([$unidadId, $anioActual]);
        $row = $stmt->fetch();
        
        $nuevoConsecutivo = ((int)$row['ultimo_consecutivo']) + 1;
        
        // Construcción del correlativo con 5 dígitos rellenados con cero: 2600001
        $numeroFormateado = $anio2d . str_pad((string)$nuevoConsecutivo, 5, '0', STR_PAD_LEFT);
        $folioFinal = strtoupper(trim($claveUnidad)) . '-' . $numeroFormateado;

        return [
            'folio' => $folioFinal,
            'anio' => $anioActual,
            'consecutivo' => $nuevoConsecutivo
        ];
    }
}

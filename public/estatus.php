<?php
/**
 * Redirect: Consulta de Estatus → Portal unificado (index.php)
 * Mantiene compatibilidad con enlaces existentes como estatus.php?folio=UMF40-2600754
 */
$params = $_GET;
$params['tab'] = 'estatus';
$queryString = http_build_query($params);
header("Location: index.php?{$queryString}");
exit;

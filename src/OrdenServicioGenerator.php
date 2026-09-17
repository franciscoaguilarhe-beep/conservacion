<?php
/**
 * Generador del Formato Institucional "Orden y Entrega de Servicio"
 * Norma / Clave: 14C2-003-002
 */

class OrdenServicioGenerator {
    public static function renderHtml(array $reporte, array $unidad, string $elaboro = ''): string {
        $fechaEmision = !empty($reporte['fecha_recepcion']) ? date('d/m/Y', strtotime($reporte['fecha_recepcion'])) : date('d/m/Y');
        $fechaConclusion = !empty($reporte['fecha_conclusion']) ? date('d/m/Y', strtotime($reporte['fecha_conclusion'])) : '';
        $solicitante = trim(($reporte['cargo_solicitante'] ?? '') . ' ' . ($reporte['solicitante_ap_paterno'] ?? '') . ' ' . ($reporte['solicitante_ap_materno'] ?? '') . ' ' . ($reporte['solicitante_nombres'] ?? ''));
        
        $isConcluido = ($reporte['estatus'] ?? '') === 'Concluido';
        $tipoServicio = $reporte['tipo_servicio'] ?? '';
        if ($isConcluido && $tipoServicio === 'Preventivo') {
            $origenHtml = 'Preventivo &#9679;&nbsp;&nbsp;Correctivo &#9675;';
        } elseif ($isConcluido && $tipoServicio === 'Correctivo') {
            $origenHtml = 'Preventivo &#9675;&nbsp;&nbsp;Correctivo &#9679;';
        } else {
            $origenHtml = 'Preventivo &#9675;&nbsp;&nbsp;Correctivo &#9675;';
        }
        
        ob_start();
        ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Orden de Servicio - <?= htmlspecialchars($reporte['folio']) ?></title>
  <style>
    @page { size: letter portrait; margin: 0; }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; background: #eee; }
    body { font-family: Arial, Helvetica, sans-serif; color: #000; font-size: 8pt; }
    .page { position: relative; width: 612pt; height: 792pt; margin: 0 auto; background: #fff; overflow: hidden; }
    .frame { position: absolute; left: 18.5pt; width: 571.4pt; border: 1px solid #000; }
    .header { top: 54pt; height: 54.6pt; display: flex; align-items: center; padding-left: 7pt; }
    .header-logo { width: 46.8pt; height: 36.3pt; object-fit: contain; }
    .header-text { margin-left: 7pt; color: #054C39; font-family: Arial, Helvetica, sans-serif; font-weight: 700; line-height: 1.05; }
    .header-text .main { font-size: 13pt; }
    .header-text .sub { font-size: 8.5pt; margin-top: 2pt; }
    .body-frame { top: 114.7pt; height: 580.6pt; padding: 0; }
    .body-logo { position: absolute; top: 15pt; right: 20pt; width: 35.1pt; height: 39.3pt; object-fit: contain; }
    .admin { position: absolute; top: 14pt; right: 74pt; width: 200pt; text-align: right; font-family: Calibri, Arial, sans-serif; font-size: 6.9pt; line-height: 1.22; }
    .admin strong { font-weight: 700; }
    .title { position: absolute; top: 30pt; left: -70pt; right: 70pt; text-align: center; font-size: 12pt; font-weight: 700; }
    .program { position: absolute; top: 63pt; left: 29pt; font-size: 8pt; font-weight: 700; }
    .program span { margin-left: 30pt; font-size: 9pt; }
    .section { position: absolute; left: 5.5pt; width: 560.2pt; border: 1px solid #000; border-collapse: collapse; table-layout: fixed; }
    .section td, .section th { border: 1px solid #000; padding: 0 3pt; vertical-align: middle; line-height: 1.05; }
    .gray { background: #d9d9d9; font-weight: 700; }
    .center { text-align: center; }
    .bold { font-weight: 700; }
    .identity { top: 73pt; height: 106pt; }
    .identity col.unit { width: 165.6pt; }
    .identity col.equip { width: 270.4pt; }
    .identity col.order { width: 124.2pt; }
    .identity tr.h { height: 22pt; }
    .identity tr.r1, .identity tr.r2 { height: 18pt; }
    .identity tr.r3 { height: 25pt; }
    .identity tr.r4 { height: 22pt; }
    .identity td:first-child { position: relative; padding-left: 43pt; }
    .identity .unit-label { position: absolute; left: 5pt; top: 50%; transform: translateY(-50%); white-space: nowrap; text-align: left; font-size: 7.6pt; }
    .identity .unit-value { display: block; text-align: center; font-weight: 700; margin: 0; }
    .identity .equip-label { display: inline-block; min-width: 45pt; }
    .identity .equip-value { font-weight: 700; }
    .conditions { top: 183.3pt; height: 47.4pt; }
    .conditions col.tech { width: 147pt; }
    .conditions col.found { width: 413.2pt; }
    .conditions tr:first-child { height: 18pt; }
    .conditions tr:last-child { height: 29.4pt; }
    .partida { top: 235.3pt; height: 39.1pt; }
    .partida col.p1 { width: 62.4pt; }
    .partida col.p2 { width: 196.5pt; }
    .partida col.p3 { width: 98.2pt; }
    .partida col.time { width: 33.85pt; }
    .partida tr:first-child { height: 18pt; }
    .partida tr:last-child { height: 21pt; }
    .partida .small { font-size: 7pt; font-weight: 400; }
    .description { top: 274.4pt; height: 163.5pt; }
    .description col.left { width: 315.8pt; }
    .description col.right { width: 244.4pt; }
    .description tr:first-child { height: 18pt; }
    .description tr:last-child { height: 145.5pt; }
    .description td { vertical-align: top; padding: 4pt 6pt; font-size: 8.5pt; line-height: 1.3; }
    .final { top: 442.9pt; height: 54.6pt; }
    .final col.left { width: 315.8pt; }
    .final col.right { width: 244.4pt; }
    .final tr:first-child { height: 18pt; }
    .final tr:last-child { height: 36.6pt; }
    .final td { vertical-align: top; padding: 3pt 5pt; font-size: 8pt; line-height: 1.2; }
    .delivery { top: 502.3pt; height: 73.2pt; }
    .delivery col.c1 { width: 62.4pt; }
    .delivery col.c2 { width: 196.5pt; }
    .delivery col.c3 { width: 98.2pt; }
    .delivery col.c4 { width: 140.5pt; }
    .delivery col.c5 { width: 62.4pt; }
    .delivery tr { height: 24.4pt; }
    .delivery td { font-size: 7.8pt; padding: 2pt 4pt; vertical-align: top; }
    .delivery .side { text-align: center; font-weight: 700; font-size: 8.2pt; vertical-align: middle; }
    .delivery .date { text-align: center; font-weight: 700; vertical-align: middle; }
    .footer-key { position: absolute; left: 474pt; top: 696pt; font-size: 7.9pt; }
    @media print {
      .no-print { display: none !important; }
      html, body { background: #fff; }
      .page { margin: 0; }
    }
    .print-bar { background: #1E1E1E; color: #FFFFFF; padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; font-family: Arial, sans-serif; }
  </style>
</head>
<body>
  <div class="print-bar no-print">
      <span><strong>IMSS Conservación</strong> — Orden y Entrega de Servicio (Clave <?= htmlspecialchars($reporte['clave_formato'] ?? '14C2-003-002') ?>)</span>
      <div>
          <button onclick="window.print()" style="background:#0C6839;color:#fff;border:none;padding:8px 16px;border-radius:4px;font-weight:bold;cursor:pointer;">🖨️ Imprimir</button>
          <button onclick="window.close()" style="background:#444;color:#fff;border:none;padding:8px 16px;border-radius:4px;margin-left:8px;cursor:pointer;">Cerrar</button>
      </div>
  </div>
  <main class="page">
    <div class="frame header">
      <img class="header-logo" src="../Images/IMSS.png" alt="IMSS">
      <div class="header-text">
        <div class="main">INSTITUTO MEXICANO DEL SEGURO SOCIAL</div>
        <div class="sub">SEGURIDAD Y SOLIDARIDAD SOCIAL</div>
      </div>
    </div>

    <section class="frame body-frame">
      <img class="body-logo" src="../Images/IMSS.png" alt="IMSS">
      <div class="admin"><strong>Dirección de Administración</strong><br>Unidad de Administración<br>Coordinación de
        Conservación y Servicio Generales<br>Coordinación Técnica de Conservación y
        Servicio<br>Complementarios<br>División de Conservación</div>
      <div class="title">Orden y Entrega de Servicio</div>
      <div class="program">Reporte de Servicio: <span><?= htmlspecialchars($reporte['folio']) ?></span></div>

      <table class="section identity">
        <colgroup>
          <col class="unit">
          <col class="equip">
          <col class="order">
        </colgroup>
        <tr class="h gray center">
          <th>Identificación de la Unidad</th>
          <th>Identificación del Equipo, Sistema o<br>Instalación</th>
          <th rowspan="2">Numero de Orden:<br><strong><?= htmlspecialchars($reporte['folio']) ?></strong></th>
        </tr>
        <tr class="r1">
          <td><span class="unit-label">Delegación:</span><span class="unit-value"><?= htmlspecialchars($unidad['delegacion'] ?? 'BCS') ?></span></td>
          <td><span class="equip-label">Nombre:</span><span class="equip-value"></span></td>
        </tr>
        <tr class="r2">
          <td><span class="unit-label">Unidad:</span><span class="unit-value"><?= htmlspecialchars($unidad['clave_unidad']) ?></span></td>
          <td><span style="display:inline-block; width:140pt;"><span class="equip-label">Marca:</span><span class="equip-value"></span></span><span>Modelo:</span></td>
          <td><span>Origen:</span><span style="float:right; font-weight:normal; font-size:7.5pt;"><?= $origenHtml ?></span></td>
        </tr>
        <tr class="r3">
          <td><span class="unit-label">Clave de la unidad:</span><span class="unit-value"><?= htmlspecialchars($unidad['clave_presupuestal'] ?? '') ?></span></td>
          <td><span style="display:inline-block; width:140pt;"><span class="equip-label">Serie:</span><span class="equip-value"></span></span><span>No. Eco:</span></td>
          <td><span>Fecha de<br>Emisión:</span><strong style="float:right"><?= $fechaEmision ?></strong></td>
        </tr>
        <tr class="r4">
          <td><span class="unit-label">JCU:</span><span class="unit-value"><?= htmlspecialchars($unidad['nombre_jcu'] ?? '') ?></span></td>
          <td><span style="display:inline-block; width:140pt;"><span class="equip-label">NNI:</span></span><span>UBICACIÓN:</span> <strong style="font-size: 7.5pt;"><?= htmlspecialchars($reporte['nombre_area'] ?? '') ?></strong></td>
          <td style="vertical-align: top; padding-top: 2pt; line-height: 1.1;"><span>Elaboró:</span><strong style="display:block; text-align:right; font-size:6.5pt; margin-top:2pt; width:100%;"><?= htmlspecialchars($elaboro) ?></strong></td>
        </tr>
      </table>

      <table class="section conditions">
        <colgroup>
          <col class="tech">
          <col class="found">
        </colgroup>
        <tr class="gray center">
          <th>Técnico o Empresa</th>
          <th>Condiciones en que se encontró</th>
        </tr>
        <tr>
          <td class="center bold"><?= htmlspecialchars($reporte['nombre_tecnico'] ?? 'POR ASIGNAR') ?></td>
          <td class="center"><?= htmlspecialchars($reporte['descripcion_falla']) ?></td>
        </tr>
      </table>

      <table class="section partida">
        <colgroup>
          <col class="p1"><col class="p2"><col class="p3">
          <col class="time"><col class="time"><col class="time"><col class="time"><col class="time"><col class="time">
        </colgroup>
        <tr class="gray center">
          <th>Partida</th>
          <th>Especialidad</th>
          <th>Subespecialidad</th>
          <th colspan="6">Tiempo de Ejecución</th>
        </tr>
        <tr class="center small">
          <td></td>
          <td></td>
          <td></td>
          <td class="bold">Hora<br>Inicio</td><td></td>
          <td class="bold">Hora<br>Termino</td><td></td>
          <td class="bold">Tiempo<br>Real</td><td></td>
        </tr>
      </table>

      <table class="section description">
        <colgroup>
          <col class="left">
          <col class="right">
        </colgroup>
        <tr class="gray center">
          <th>Descripción Completa del Servicio</th>
          <th>Observaciones, Materiales y Refacciones Empleados</th>
        </tr>
        <tr>
          <td><?= nl2br(htmlspecialchars($reporte['solucion_cierre'] ?? '')) ?></td>
          <td><?= nl2br(htmlspecialchars($reporte['materiales_refacciones'] ?? '')) ?></td>
        </tr>
      </table>

      <table class="section final">
        <colgroup>
          <col class="left">
          <col class="right">
        </colgroup>
        <tr class="gray center">
          <th>Condición Final de Funcionamiento</th>
          <th>Recomendaciones</th>
        </tr>
        <tr>
          <td><?= nl2br(htmlspecialchars($reporte['condicion_final'] ?? '')) ?></td>
          <td><?= nl2br(htmlspecialchars($reporte['recomendaciones'] ?? '')) ?></td>
        </tr>
      </table>

      <table class="section delivery">
        <colgroup>
          <col class="c1"><col class="c2"><col class="c3"><col class="c4"><col class="c5">
        </colgroup>
        <tr>
          <td rowspan="3" class="gray side">Entrega&nbsp;del<br>Servicio</td>
          <td><strong>Nombre.</strong><br><?= htmlspecialchars($reporte['nombre_tecnico'] ?? '') ?: '&nbsp;' ?></td>
          <td rowspan="3" class="gray side">Recibe&nbsp;de<br>Conformidad</td>
          <td><strong>Nombre.</strong><br><?= htmlspecialchars($reporte['recibe_nombre'] ?? '') ?: '&nbsp;' ?></td>
          <td class="gray date" style="font-size:7.5pt;">Fecha de<br>Entrega</td>
        </tr>
        <tr>
          <td><strong>No. Matricula o Contrato.</strong><br><?= htmlspecialchars($reporte['matricula_tecnico'] ?? '') ?: '&nbsp;' ?></td>
          <td><strong>No. Matricula</strong><br><?= htmlspecialchars($reporte['recibe_matricula'] ?? '') ?: '&nbsp;' ?></td>
          <td rowspan="2" class="center bold" style="font-size:8.5pt; vertical-align:middle;"><?= $fechaConclusion ?></td>
        </tr>
        <tr>
          <td><strong>Firma</strong><br>&nbsp;</td>
          <td><strong>Firma.</strong><br>&nbsp;</td>
        </tr>
      </table>
    </section>
    <div class="footer-key">Clave: <?= htmlspecialchars($reporte['clave_formato'] ?? '14C2-003-002') ?></div>
  </main>
</body>
</html>
        <?php
        return ob_get_clean();
    }
}

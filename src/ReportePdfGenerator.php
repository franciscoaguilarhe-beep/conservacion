<?php
/**
 * Generador del Formato Institucional Normado de Conservación IMSS
 * Norma / Clave: 14C2-003-002
 * Imprime dos talones idénticos (Original y Copia) por hoja tamaño carta
 */

class ReportePdfGenerator {
    public static function renderHtml(array $reporte, array $unidad): string {
        $fecha = date('d/m/Y', strtotime($reporte['fecha_recepcion']));
        $solicitante = trim($reporte['cargo_solicitante'] . ' ' . $reporte['solicitante_ap_paterno'] . ' ' . $reporte['solicitante_ap_materno'] . ' ' . $reporte['solicitante_nombres']);
        
        ob_start();
        ?>
<!doctype html>
<html lang="es">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($reporte['folio']) ?></title>
  <style>
    @page {
      size: letter portrait;
      margin: 0;
    }

    :root {
      --green: #054C39;
      --ink: #111;
    }

    * {
      box-sizing: border-box;
    }

    html,
    body {
      margin: 0;
      padding: 0;
      background: #eee;
    }

    body {
      font-family: "Aptos Narrow", "Arial Narrow", Arial, sans-serif;
      color: var(--ink);
    }

    .page {
      width: 8.5in;
      height: 11in;
      margin: 0 auto;
      background: #fff;
      overflow: hidden;
    }

    .copy {
      position: relative;
      width: 100%;
      height: 5.5in;
      padding: .28in .28in .08in;
      page-break-inside: avoid;
    }

    .copy:first-child::after {
      content: "";
      position: absolute;
      left: .28in;
      right: .28in;
      bottom: .02in;
      border-top: 3px dashed #222;
    }

    .form {
      position: relative;
      width: 100%;
      height: 100%;
    }

    .header {
      position: absolute;
      left: 0;
      top: 0;
      width: 100%;
      height: .50in;
      border: 1px solid var(--ink);
      display: flex;
      align-items: center;
    }

    .header .logo {
      width: .43in;
      height: .36in;
      object-fit: contain;
      margin: 0 .05in 0 .02in;
    }

    .institution {
      color: var(--green);
      font-family: Candara, Calibri, Arial, sans-serif;
      font-weight: 700;
      line-height: 1.05;
    }

    .institution .main {
      font-size: 18px;
    }

    .institution .sub {
      font-size: 13px;
      margin-top: 2px;
    }

    .form::after {
      content: "";
      position: absolute;
      z-index: 0;
      left: 0;
      right: 0;
      top: .58in;
      bottom: .34in;
      border: 1px solid var(--ink);
      pointer-events: none;
    }

    .unit-title {
      position: absolute;
      z-index: 1;
      top: .65in;
      right: .02in;
      font-size: 18px;
      font-weight: 700;
      white-space: nowrap;
    }

    .unit-title span {
      margin-left: .10in;
      font-weight: 400;
    }

    .logo-secondary {
      position: absolute;
      z-index: 1;
      left: .18in;
      top: 1.08in;
      width: .58in;
      height: .58in;
      object-fit: contain;
    }

    .date-row {
      position: absolute;
      z-index: 1;
      right: .72in;
      top: 1.19in;
      display: flex;
      gap: .42in;
      font-size: 18px;
    }

    .service-row {
      position: absolute;
      z-index: 1;
      left: .95in;
      right: 3.05in;
      top: 1.60in;
      height: .25in;
      display: flex;
      align-items: center;
      font-size: 15px;
    }

    .service-row .label {
      width: 1.20in;
      white-space: nowrap;
      overflow: hidden;
    }

    .service-id {
      width: 1.75in;
      text-align: center;
      border-bottom: 1px solid var(--ink);
      height: .23in;
      padding-top: 1px;
    }

    .fields-container {
      position: absolute;
      z-index: 1;
      left: 0;
      right: 0;
      top: 1.95in;
      bottom: .34in;
      display: flex;
      flex-direction: column;
      gap: 0.10in;
    }

    .field-row {
      display: flex;
      align-items: flex-end;
      font-size: 15px;
    }

    .field-row .label {
      flex: 0 0 auto;
      white-space: nowrap;
      margin-right: 0.1in;
      font-weight: normal;
    }

    .field-row .value {
      flex: 1 1 auto;
      border-bottom: 1px solid var(--ink);
      text-align: center;
      word-wrap: break-word;
      white-space: normal;
      line-height: 1.1;
      padding-bottom: 2px;
      font-size: 13px;
    }

    .observations {
      display: flex;
      flex-direction: column;
      font-size: 15px;
      flex: 1;
    }

    .observations .label {
      margin-bottom: 0.05in;
    }

    .observations .box {
      border-bottom: 1px solid var(--ink);
      flex: 1;
      display: flex;
      align-items: flex-start;
      justify-content: center;
      text-align: center;
      word-wrap: break-word;
      white-space: normal;
      line-height: 1.1;
      font-size: 13px;
      padding: 4px;
    }

    .bottom-note {
      position: absolute;
      z-index: 1;
      right: .17in;
      bottom: .18in;
      font-size: 11px;
      font-weight: 700;
    }

    .cut-mark {
      position: absolute;
      z-index: 2;
      right: -.01in;
      bottom: -.07in;
      font-size: 12px;
      line-height: 1;
      background: white;
      padding-left: 2px;
    }

    @media print {
      .no-print { display: none !important; }
      html,
      body {
        background: white;
      }

      .page {
        margin: 0;
      }
    }

    .print-bar {
        background: #1E1E1E;
        color: #FFFFFF;
        padding: 10px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-family: Arial, sans-serif;
    }
  </style>
</head>

<body>
  <div class="print-bar no-print">
      <span><strong>IMSS Conservación</strong> — Vista previa de impresión normada (Clave <?= htmlspecialchars($reporte['clave_formato'] ?? '14C2-003-002') ?>)</span>
      <div>
          <button onclick="window.print()" style="background:#0C6839;color:#fff;border:none;padding:8px 16px;border-radius:4px;font-weight:bold;cursor:pointer;">🖨️ Imprimir Formato</button>
          <button onclick="window.close()" style="background:#444;color:#fff;border:none;padding:8px 16px;border-radius:4px;margin-left:8px;cursor:pointer;">Cerrar</button>
      </div>
  </div>
  <main class="page">
    <section class="copy">
      <div class="form">
        <header class="header"><img class="logo" src="../Images/IMSS.png" alt="IMSS">
          <div class="institution">
            <div class="main">INSTITUTO MEXICANO DEL SEGURO SOCIAL</div>
            <div class="sub">SEGURIDAD Y SOLIDARIDAD SOCIAL</div>
          </div>
        </header>
        <div class="unit-title">JEFATURA DE CONSERVACION DE UNIDAD N°: <span><?= htmlspecialchars($unidad['clave_unidad']) ?></span></div>
        <img class="logo-secondary" src="../Images/IMSS.png" alt="IMSS">
        <div class="date-row"><span><?= htmlspecialchars($unidad['ubicacion']) ?>, a</span><span><?= $fecha ?></span></div>
        <div class="service-row"><span class="label">Reporte N°:</span><span class="service-id"><?= htmlspecialchars($reporte['folio']) ?></span></div>
        <div class="fields-container">
          <div class="field-row"><span class="label">Unidad:</span><span class="value"><?= htmlspecialchars($unidad['nombre_unidad']) ?></span></div>
          <div class="field-row"><span class="label">Ubicación:</span><span class="value"><?= htmlspecialchars($reporte['nombre_area'] ?? '') ?></span></div>
          <div class="field-row"><span class="label">Descripción del Trabajo:</span><span class="value"><?= htmlspecialchars($reporte['descripcion_falla']) ?></span></div>
          <div class="field-row"><span class="label">Nombre de quien Reporta:</span><span class="value"><?= htmlspecialchars($solicitante) ?></span></div>
          <div class="observations"><span class="label">Observaciones:</span><span class="box"><?= htmlspecialchars($reporte['observaciones'] ?? '') ?></span></div>
        </div>
        <div class="bottom-note">Clave:<?= htmlspecialchars($reporte['clave_formato'] ?? '14C2-003-002') ?></div>
      </div><span class="cut-mark">✂</span>
    </section>
    <section class="copy">
      <div class="form">
        <header class="header"><img class="logo" src="../Images/IMSS.png" alt="IMSS">
          <div class="institution">
            <div class="main">INSTITUTO MEXICANO DEL SEGURO SOCIAL</div>
            <div class="sub">SEGURIDAD Y SOLIDARIDAD SOCIAL</div>
          </div>
        </header>
        <div class="unit-title">JEFATURA DE CONSERVACION DE UNIDAD N°: <span><?= htmlspecialchars($unidad['clave_unidad']) ?></span></div>
        <img class="logo-secondary" src="../Images/IMSS.png" alt="IMSS">
        <div class="date-row"><span><?= htmlspecialchars($unidad['ubicacion']) ?>, a</span><span><?= $fecha ?></span></div>
        <div class="service-row"><span class="label">Reporte N°:</span><span class="service-id"><?= htmlspecialchars($reporte['folio']) ?></span></div>
        <div class="fields-container">
          <div class="field-row"><span class="label">Unidad:</span><span class="value"><?= htmlspecialchars($unidad['nombre_unidad']) ?></span></div>
          <div class="field-row"><span class="label">Ubicación:</span><span class="value"><?= htmlspecialchars($reporte['nombre_area'] ?? '') ?></span></div>
          <div class="field-row"><span class="label">Descripción del Trabajo:</span><span class="value"><?= htmlspecialchars($reporte['descripcion_falla']) ?></span></div>
          <div class="field-row"><span class="label">Nombre de quien Reporta:</span><span class="value"><?= htmlspecialchars($solicitante) ?></span></div>
          <div class="observations"><span class="label">Observaciones:</span><span class="box"><?= htmlspecialchars($reporte['observaciones'] ?? '') ?></span></div>
        </div>
        <div class="bottom-note">Clave:<?= htmlspecialchars($reporte['clave_formato'] ?? '14C2-003-002') ?></div>
      </div>
    </section>
  </main>
</body>

</html>
        <?php
        return ob_get_clean();
    }
}

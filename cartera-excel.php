<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/facturas.php';

requerir_sesion();

/* =========================================================
   Descarga en Excel de la cartera pendiente: mismas cuotas que se ven
   en cartera.php (vencidas + pendientes por vencer), en un solo listado
   con una columna "Estado" para distinguirlas.

   Se genera como SpreadsheetML (el formato XML que entiende Excel
   directamente, sin necesitar ninguna librería externa ni ZipArchive).
   Excel lo abre igual que un .xls normal, con colores y formato de
   moneda ya listos.
   ========================================================= */

$todas = leer_facturas();

$filas = [];
foreach ($todas as $f) {
    if (!empty($f['anulada']) || $f['plan'] !== 'cuotas') {
        continue;
    }
    foreach ($f['cuotas'] as $idx => $cuota) {
        $pendiente = cuota_pendiente($cuota);
        if ($pendiente <= 0) {
            continue;
        }
        $vencida = esta_vencida($cuota['vence']);
        $filas[] = [
            'cliente'   => (string) $f['cliente'],
            'telefono'  => (string) $f['telefono'],
            'numero'    => (string) $f['numero'],
            'rotulo'    => rotulo_cuota($idx, count($f['cuotas'])),
            'vence'     => (string) $cuota['vence'],
            'pactado'   => (int) $cuota['monto'],
            'pendiente' => $pendiente,
            'vencida'   => $vencida,
        ];
    }
}
usort($filas, fn($a, $b) => strcmp($a['vence'], $b['vence']));

$totalVencido   = 0;
$totalPendiente = 0;
foreach ($filas as $r) {
    if ($r['vencida']) {
        $totalVencido += $r['pendiente'];
    } else {
        $totalPendiente += $r['pendiente'];
    }
}

/* --- Helpers de escritura XML --- */

function xls_texto(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function xls_celda_texto(string $valor, string $estilo = ''): string
{
    $ss = $estilo !== '' ? ' ss:StyleID="' . $estilo . '"' : '';
    return '<Cell' . $ss . '><Data ss:Type="String">' . xls_texto($valor) . '</Data></Cell>';
}

function xls_celda_numero(int $valor, string $estilo): string
{
    return '<Cell ss:StyleID="' . $estilo . '"><Data ss:Type="Number">' . $valor . '</Data></Cell>';
}

$nombreArchivo = 'cartera-' . date('Y-m-d') . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<?mso-application progid=\"Excel.Sheet\"?>\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:o="urn:schemas-microsoft-com:office:office"
          xmlns:x="urn:schemas-microsoft-com:office:excel"
          xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Styles>
  <Style ss:ID="encabezado">
   <Font ss:Bold="1" ss:Color="#FFFFFF"/>
   <Interior ss:Color="#7A3B69" ss:Pattern="Solid"/>
   <Alignment ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="textoVencido">
   <Interior ss:Color="#FCE4E4" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="moneda">
   <NumberFormat ss:Format="&quot;$&quot;#,##0"/>
  </Style>
  <Style ss:ID="monedaVencida">
   <Interior ss:Color="#FCE4E4" ss:Pattern="Solid"/>
   <NumberFormat ss:Format="&quot;$&quot;#,##0"/>
  </Style>
  <Style ss:ID="totalEtiqueta">
   <Font ss:Bold="1"/>
  </Style>
  <Style ss:ID="totalMoneda">
   <Font ss:Bold="1"/>
   <NumberFormat ss:Format="&quot;$&quot;#,##0"/>
  </Style>
 </Styles>
 <Worksheet ss:Name="Cartera pendiente">
  <Table>
   <Column ss:Width="150"/>
   <Column ss:Width="90"/>
   <Column ss:Width="80"/>
   <Column ss:Width="100"/>
   <Column ss:Width="90"/>
   <Column ss:Width="80"/>
   <Column ss:Width="100"/>
   <Column ss:Width="110"/>
   <Row>
    <?= xls_celda_texto('Cliente', 'encabezado') ?>
    <?= xls_celda_texto('Teléfono', 'encabezado') ?>
    <?= xls_celda_texto('No. factura', 'encabezado') ?>
    <?= xls_celda_texto('Cuota', 'encabezado') ?>
    <?= xls_celda_texto('Vence', 'encabezado') ?>
    <?= xls_celda_texto('Estado', 'encabezado') ?>
    <?= xls_celda_texto('Monto pactado', 'encabezado') ?>
    <?= xls_celda_texto('Monto pendiente', 'encabezado') ?>
   </Row>
<?php foreach ($filas as $r):
    $estiloTexto  = $r['vencida'] ? 'textoVencido'  : '';
    $estiloMoneda = $r['vencida'] ? 'monedaVencida' : 'moneda';
?>
   <Row>
    <?= xls_celda_texto($r['cliente'], $estiloTexto) ?>
    <?= xls_celda_texto($r['telefono'], $estiloTexto) ?>
    <?= xls_celda_texto($r['numero'], $estiloTexto) ?>
    <?= xls_celda_texto($r['rotulo'], $estiloTexto) ?>
    <?= xls_celda_texto(fecha_larga($r['vence']), $estiloTexto) ?>
    <?= xls_celda_texto($r['vencida'] ? 'Vencida' : 'Pendiente', $estiloTexto) ?>
    <?= xls_celda_numero($r['pactado'], $estiloMoneda) ?>
    <?= xls_celda_numero($r['pendiente'], $estiloMoneda) ?>
   </Row>
<?php endforeach; ?>
   <Row>
    <Cell ss:StyleID="totalEtiqueta" ss:MergeAcross="6"><Data ss:Type="String">Total vencido</Data></Cell>
    <?= xls_celda_numero($totalVencido, 'totalMoneda') ?>
   </Row>
   <Row>
    <Cell ss:StyleID="totalEtiqueta" ss:MergeAcross="6"><Data ss:Type="String">Total pendiente (sin vencer)</Data></Cell>
    <?= xls_celda_numero($totalPendiente, 'totalMoneda') ?>
   </Row>
   <Row>
    <Cell ss:StyleID="totalEtiqueta" ss:MergeAcross="6"><Data ss:Type="String">Total por cobrar</Data></Cell>
    <?= xls_celda_numero($totalVencido + $totalPendiente, 'totalMoneda') ?>
   </Row>
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
   <FreezePanes/>
   <FrozenNoSplit/>
   <SplitHorizontal>1</SplitHorizontal>
   <TopRowBottomPane>1</TopRowBottomPane>
   <ActivePane>2</ActivePane>
  </WorksheetOptions>
 </Worksheet>
</Workbook>

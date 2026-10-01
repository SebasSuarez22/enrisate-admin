<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ayudas_guardado_masivo.php';
require_once __DIR__ . '/../includes/clientes.php';

/* Clientas: guardar por fila da EXACTAMENTE lo mismo que reescribir todo.

   Prueba diferencial: el mismo guion se corre con la implementación anterior
   (leer todas -> cambiar una -> borrar todas -> reinsertar todas) y con la nueva
   (solo la fila que cambia). Los ids son aleatorios (uniqid), así que se
   comparan todos los demás campos y cada clienta se ubica por su nombre. */

function limpiar_clientas_prueba(): void
{
    db()->exec('DROP TRIGGER IF EXISTS cuenta_borrados_clientes');
    db()->exec('DROP TRIGGER IF EXISTS cuenta_insertados_clientes');
    db()->exec('DROP TABLE IF EXISTS prueba_movimientos');
    db()->exec('DELETE FROM clientes');
}
limpiar_clientas_prueba();
register_shutdown_function('limpiar_clientas_prueba');

/* --- La implementación anterior, tal cual era (sobre guardar_clientes masivo) --- */
function ant_agregar(string $nombre, string $telefono, string $notas): string
{
    $nombre = trim($nombre); $telefono = normalizar_telefono($telefono); $notas = trim($notas);
    if ($nombre === '') { return 'Escribe el nombre completo de la clienta.'; }
    $clientes = leer_clientes();
    $clientes[] = ['id' => uniqid('cli_', true), 'nombre' => $nombre, 'telefono' => $telefono, 'notas' => $notas, 'creado' => date('c')];
    guardar_clientes($clientes);
    return '';
}
function ant_actualizar(string $id, string $nombre, string $telefono, string $notas, int $saldoFavor = 0): string
{
    $nombre = trim($nombre); $telefono = normalizar_telefono($telefono); $notas = trim($notas);
    if ($nombre === '') { return 'Escribe el nombre completo de la clienta.'; }
    $clientes = leer_clientes(); $hallada = false;
    foreach ($clientes as &$c) {
        if ($c['id'] === $id) { $c['nombre'] = $nombre; $c['telefono'] = $telefono; $c['notas'] = $notas; $c['saldo_favor'] = max(0, $saldoFavor); $hallada = true; break; }
    }
    unset($c);
    if (!$hallada) { return 'No encontré esa clienta.'; }
    guardar_clientes($clientes);
    return '';
}
function ant_eliminar(array $ids): int
{
    $ids = array_values(array_unique(array_filter($ids, fn($id) => $id !== '')));
    if (!$ids) { return 0; }
    $clientes = leer_clientes();
    $filtrados = array_values(array_filter($clientes, fn($c) => !in_array($c['id'], $ids, true)));
    $borradas = count($clientes) - count($filtrados);
    if ($borradas > 0) { guardar_clientes($filtrados); }
    return $borradas;
}
function ant_por_id(string $id): ?array
{
    foreach (leer_clientes() as $c) { if ($c['id'] === $id) { return $c; } }
    return null;
}
function ant_restar(string $id, int $monto): int
{
    if ($monto <= 0) { return 0; }
    $clientes = leer_clientes(); $usado = 0;
    foreach ($clientes as &$c) {
        if ($c['id'] === $id) { $disp = (int) ($c['saldo_favor'] ?? 0); $usado = max(0, min($disp, $monto)); $c['saldo_favor'] = $disp - $usado; break; }
    }
    unset($c);
    if ($usado > 0) { guardar_clientes($clientes); }
    return $usado;
}
function ant_desde_factura(string $nombre, string $telefono): void
{
    $nombre = trim($nombre); $telefono = normalizar_telefono($telefono);
    if ($nombre === '') { return; }
    if (!cliente_ya_existe(leer_clientes(), $nombre, $telefono)) { ant_agregar($nombre, $telefono, ''); }
}
function ant_sincronizar(array $facturas): int
{
    $clientes = leer_clientes(); $agregadas = 0;
    foreach ($facturas as $f) {
        $nombre = trim((string) ($f['cliente'] ?? '')); $telefono = normalizar_telefono((string) ($f['telefono'] ?? ''));
        if ($nombre === '' || cliente_ya_existe($clientes, $nombre, $telefono)) { continue; }
        $clientes[] = ['id' => uniqid('cli_', true), 'nombre' => $nombre, 'telefono' => $telefono, 'notas' => '', 'creado' => $f['creada'] ?? date('c')];
        $agregadas++;
    }
    if ($agregadas > 0) { guardar_clientes($clientes); }
    return $agregadas;
}

/* --- Un mismo guion de uso, parametrizado por implementación --- */
function id_de(string $nombre): string
{
    foreach (leer_clientes() as $c) { if ($c['nombre'] === $nombre) { return $c['id']; } }
    return 'no-existe';
}
function guion(array $f): array
{
    $r = [];
    $r[] = $f['agregar']('  María José Peñaloza  ', '+57 300 111 2222', ' notas 🌸 ');
    $r[] = $f['agregar']('Beatriz', '3002223333', '');
    $r[] = $f['agregar']('Carla', '', 'sin teléfono');
    $r[] = $f['agregar']('   ', '3009999999', '');                 // sin nombre: se rechaza
    $r[] = $f['agregar']('Diana', '300 444 5555', '');
    $r[] = $f['agregar']('Elena', '3005556666', '');
    $r[] = $f['actualizar'](id_de('Beatriz'), 'Beatriz Gómez', '3002223333', 'vip', 50000);
    $r[] = $f['actualizar'](id_de('Carla'), 'Carla', '', 'saldo negativo se corrige', -500);
    $r[] = $f['actualizar']('cli_no_existe', 'X', '', '', 0);
    $r[] = $f['actualizar'](id_de('Diana'), '  ', '', '', 0);        // sin nombre: se rechaza
    $r[] = $f['restar'](id_de('Beatriz Gómez'), 20000);
    $r[] = $f['restar'](id_de('Beatriz Gómez'), 999999);             // más de lo que tiene
    $r[] = $f['restar'](id_de('Beatriz Gómez'), 5000);               // ya sin saldo
    $r[] = $f['restar'](id_de('Diana'), 0);
    $r[] = $f['restar']('cli_no_existe', 1000);
    $f['desde_factura']('Diana', '3004445555');                      // ya existe por teléfono
    $f['desde_factura']('ELENA', '');                                // ya existe por nombre (otra mayúscula)
    $f['desde_factura']('Fernanda', '301 777 8888');                 // nueva
    $f['desde_factura']('  ', '3000000000');                         // sin nombre
    $r[] = $f['sincronizar']([
        ['cliente' => 'Gloria', 'telefono' => '3011112222', 'creada' => '2025-05-01T10:00:00-05:00'],
        ['cliente' => 'gloria', 'telefono' => '', 'creada' => '2025-05-02T10:00:00-05:00'],  // misma, en el mismo lote
        ['cliente' => 'Beatriz Gómez', 'telefono' => '3002223333', 'creada' => '2025-05-03T10:00:00-05:00'],
        ['cliente' => 'Helena', 'telefono' => '+57 302 333 4444', 'creada' => '2025-05-04T10:00:00-05:00'],
        ['cliente' => '', 'telefono' => '3020000000'],
    ]);
    $r[] = $f['sincronizar']([]);
    $r[] = $f['eliminar']([id_de('Elena'), id_de('Elena'), '', 'cli_no_existe']);
    $r[] = $f['eliminar']([]);
    $r[] = $f['eliminar']([strtoupper(id_de('Carla'))]);            // otra mayúscula: no borra
    $r[] = $f['eliminar']([id_de('Carla') . ' ']);                  // con espacio: no borra
    $busquedas = [];
    foreach (['Diana', 'Carla'] as $n) {
        $id = id_de($n);
        foreach ([$id, strtoupper($id), $id . ' ', ''] as $variante) {
            $c = $f['por_id']($variante);
            $busquedas[] = $c === null ? null : $c['nombre'];
        }
    }
    return ['respuestas' => $r, 'busquedas' => $busquedas];
}

// Los ids son aleatorios y 'creado' lleva la hora: se comparan todos los demás campos.
function foto(): array
{
    return array_map(function ($c) {
        unset($c['id']);
        if (!str_starts_with($c['creado'], '2025-')) { $c['creado'] = '(hora)'; }
        return $c;
    }, leer_clientes());
}

$ant = guion(['agregar' => 'ant_agregar', 'actualizar' => 'ant_actualizar', 'restar' => 'ant_restar', 'eliminar' => 'ant_eliminar',
              'por_id' => 'ant_por_id', 'desde_factura' => 'ant_desde_factura', 'sincronizar' => 'ant_sincronizar']);
$fotoAnt = foto();

db()->exec('DELETE FROM clientes');
$nue = guion(['agregar' => 'agregar_cliente', 'actualizar' => 'actualizar_cliente', 'restar' => 'restar_saldo_favor', 'eliminar' => 'eliminar_clientes',
              'por_id' => 'cliente_por_id', 'desde_factura' => 'agregar_cliente_desde_factura', 'sincronizar' => 'sincronizar_clientes_desde_facturas']);
$fotoNue = foto();

verificar($nue['respuestas'] === $ant['respuestas'], 'cada operación responde lo mismo que antes (' . count($ant['respuestas']) . ' respuestas: mensajes, saldos descontados, cantidades)');
verificar($fotoNue === $fotoAnt, 'la lista de clientas queda EXACTAMENTE igual que con la implementación anterior (' . count($fotoAnt) . ' clientas, todos los campos y el mismo orden)');
verificar($nue['busquedas'] === $ant['busquedas'], 'buscar por id da lo mismo, incluso con otra mayúscula, con espacio o vacío');
verificar(count(array_unique(array_column(leer_clientes(), 'id'))) === count($fotoNue), 'los ids siguen siendo únicos');

/* --- Lo que cambia: tocar una clienta no reescribe las demás --- */
db()->exec('CREATE TABLE prueba_movimientos (tipo varchar(10))');
db()->exec("CREATE TRIGGER cuenta_borrados_clientes AFTER DELETE ON clientes FOR EACH ROW INSERT INTO prueba_movimientos VALUES ('borrado')");
db()->exec("CREATE TRIGGER cuenta_insertados_clientes AFTER INSERT ON clientes FOR EACH ROW INSERT INTO prueba_movimientos VALUES ('insertado')");
$movs = function (callable $accion): array {
    db()->exec('DELETE FROM prueba_movimientos');
    $accion();
    $filas = db()->query('SELECT tipo, COUNT(*) FROM prueba_movimientos GROUP BY tipo')->fetchAll(PDO::FETCH_KEY_PAIR);
    return ['borrado' => (int) ($filas['borrado'] ?? 0), 'insertado' => (int) ($filas['insertado'] ?? 0)];
};
$total = count(leer_clientes());
$m = $movs(fn() => actualizar_cliente(id_de('Diana'), 'Diana', '3004445555', 'nota nueva', 1000));
verificar($m === ['borrado' => 0, 'insertado' => 0], "editar una clienta no borra ni reinserta ninguna fila (antes: $total borradas y $total reinsertadas)");
$m = $movs(fn() => restar_saldo_favor(id_de('Diana'), 500));
verificar($m === ['borrado' => 0, 'insertado' => 0], 'descontar saldo a favor no borra ni reinserta ninguna fila');
$m = $movs(fn() => agregar_cliente('Isabel', '3031112222', ''));
verificar($m === ['borrado' => 0, 'insertado' => 1], 'agregar una clienta inserta solo esa fila');
$m = $movs(fn() => eliminar_clientes([id_de('Isabel')]));
verificar($m === ['borrado' => 1, 'insertado' => 0], 'eliminar una clienta borra solo esa fila');
$m = $movs(fn() => ant_actualizar(id_de('Diana'), 'Diana', '3004445555', 'otra nota', 1000));
verificar($m['borrado'] === $total && $m['insertado'] === $total, "(comprobación) la implementación anterior sí borraba y reinsertaba las $total clientas por una sola edición");

/* --- Seguridad del INSERT: un id repetido falla en vez de pisar a otra clienta --- */
$diana = cliente_por_id(id_de('Diana'));
$fallo = false;
try {
    guardar_cliente(['id' => $diana['id'], 'nombre' => 'Intrusa', 'telefono' => '', 'notas' => ''], true);
} catch (PDOException $e) {
    $fallo = true;
}
verificar($fallo && cliente_por_id($diana['id']) === $diana, 'guardar una clienta "nueva" con un id que ya existe falla y no pisa a la original');

terminar_pruebas();

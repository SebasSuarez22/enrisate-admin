<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/* Catálogo: guardar por fila da EXACTAMENTE lo mismo que reescribir todo.

   Prueba diferencial: el mismo guion se corre con la implementación anterior
   (leer todo -> cambiar un producto -> borrar todos -> reinsertar todos) y con
   la nueva (solo la fila que cambia). Se comparan las respuestas, lo que ve la
   misma petición justo después de guardar (la caché en memoria) y el catálogo
   final completo. */

function limpiar_catalogo_prueba(): void
{
    foreach (['borrado', 'insertado', 'editado'] as $t) {
        db()->exec("DROP TRIGGER IF EXISTS cuenta_{$t}_productos");
    }
    db()->exec('DROP TABLE IF EXISTS prueba_movimientos');
    db()->exec('DELETE FROM productos');
    catalogo_olvidar();
}
limpiar_catalogo_prueba();
register_shutdown_function('limpiar_catalogo_prueba');

/* --- La implementación anterior, tal cual era (sobre guardar_catalogo masivo) --- */
function ant_agregar(string $sku, string $marca, string $nombre, int $precio, int $costo, string $categoria = ''): string
{
    catalogo_cache(true);
    $sku = strtoupper(trim($sku)); $marca = trim($marca); $nombre = trim($nombre);
    if ($sku === '' || !preg_match('/^[A-Z0-9\-_]+$/', $sku)) { return 'El SKU es obligatorio y solo puede tener letras, números, guiones y guion bajo.'; }
    if ($marca === '' || $nombre === '') { return 'Escribe la marca y el nombre del producto.'; }
    if ($precio < 0 || $costo < 0) { return 'Precio y costo no pueden ser negativos.'; }
    if (producto_por_sku($sku)) { return 'Ya existe un producto con el SKU ' . $sku . '.'; }
    $categoria = trim($categoria);
    if (!categoria_valida($categoria)) { return 'Esa categoría no existe.'; }
    if ($categoria === '') { $categoria = categoria_deducida($nombre); }
    $productos = catalogo();
    $productos[] = ['sku' => $sku, 'marca' => $marca, 'nombre' => $nombre, 'categoria' => $categoria, 'precio' => $precio, 'costo' => $costo, 'stock' => 0];
    try { guardar_catalogo($productos); } catch (PDOException $e) { return 'No se pudo guardar: revisa que el SKU, la marca y el nombre no sean demasiado largos.'; }
    return '';
}
function ant_precio_costo(string $sku, int $precio, int $costo): bool
{
    catalogo_cache(true);
    $productos = catalogo(); $hallado = false;
    foreach ($productos as &$p) { if ($p['sku'] === $sku) { $p['precio'] = max(0, $precio); $p['costo'] = max(0, $costo); $hallado = true; break; } }
    unset($p);
    if ($hallado) { guardar_catalogo($productos); }
    return $hallado;
}
function ant_descripcion(string $sku, string $descripcion): bool
{
    catalogo_cache(true);
    $descripcion = trim($descripcion);
    $productos = catalogo(); $hallado = false;
    foreach ($productos as &$p) { if ($p['sku'] === $sku) { $p['descripcion'] = $descripcion; $hallado = true; break; } }
    unset($p);
    if ($hallado) { guardar_catalogo($productos); }
    return $hallado;
}
function ant_actualizar(string $sku, string $marca, string $nombre, ?string $categoria = null): bool
{
    catalogo_cache(true);
    $marca = trim($marca); $nombre = trim($nombre);
    if ($marca === '' || $nombre === '') { return false; }
    if ($categoria !== null) { $categoria = trim($categoria); if (!categoria_valida($categoria)) { return false; } }
    $productos = catalogo(); $hallado = false;
    foreach ($productos as &$p) {
        if ($p['sku'] === $sku) { $p['marca'] = $marca; $p['nombre'] = $nombre; if ($categoria !== null) { $p['categoria'] = $categoria; } $hallado = true; break; }
    }
    unset($p);
    if ($hallado) { try { guardar_catalogo($productos); } catch (PDOException $e) { return false; } }
    return $hallado;
}
function ant_eliminar(array $skus): int
{
    catalogo_cache(true);
    $skus = array_values(array_unique(array_filter($skus, fn($sku) => $sku !== '')));
    if (!$skus) { return 0; }
    $productos = catalogo();
    $filtrados = array_values(array_filter($productos, fn($p) => !in_array($p['sku'], $skus, true)));
    $borrados = count($productos) - count($filtrados);
    if ($borrados > 0) { guardar_catalogo($filtrados); }
    return $borrados;
}

/* --- Un mismo guion de uso, parametrizado por implementación --- */
// Lo que la MISMA petición ve justo después de guardar (la caché en memoria).
function visto(string $sku): ?array
{
    return producto_por_sku($sku);
}
function guion(array $f): array
{
    $largo = str_repeat('n', 200); // más de lo que aceptan las columnas
    $r = [];
    $r[] = $f['agregar']('acc-99', 'Marca', 'Shampoo de prueba', 1000, 500);   // tabla vacía: siembra y agrega
    $r[] = count(catalogo());
    $r[] = visto('ACC-99');
    $r[] = $f['agregar']('ACC-99', 'Marca', 'Otro', 1, 1);                      // repetido
    $r[] = $f['agregar']('ACC-04', 'Marca', 'Otro', 1, 1);                      // repetido (de la siembra)
    $r[] = $f['agregar']('mal sku!', 'Marca', 'Otro', 1, 1);
    $r[] = $f['agregar']('', 'Marca', 'Otro', 1, 1);
    $r[] = $f['agregar']('NEW-1', '  ', 'Otro', 1, 1);
    $r[] = $f['agregar']('NEW-2', 'Marca', 'Otro', -1, 0);
    $r[] = $f['agregar']('NEW-3', 'Marca', 'Otro', 1, 1, 'NoExiste');
    $r[] = $f['agregar']('NEW-4', '  Marca Ñ ', '  Crema 🌸 rizos ', 2000, 1500, ' Shampoo ');
    $r[] = $f['agregar']('NEW-5', 'Marca', $largo, 1, 1);                       // demasiado largo
    $r[] = $f['agregar']('NEW-6', 'Zeta', 'Gel fijador', 3000, 2000);            // categoría deducida
    $r[] = visto('NEW-4');
    $r[] = visto('NEW-5');
    $r[] = count(catalogo());

    $r[] = $f['precio_costo']('ACC-04', 71000, 46000);
    $r[] = visto('ACC-04');
    $r[] = $f['precio_costo']('ACC-03', -5, -9);                                // negativos -> 0
    $r[] = $f['precio_costo']('acc-04', 1, 1);                                  // otra mayúscula: no existe
    $r[] = $f['precio_costo']('ACC-04 ', 1, 1);                                 // con espacio: no existe
    $r[] = $f['precio_costo']('NOPE', 1, 1);
    $r[] = visto('ACC-04');

    $r[] = $f['descripcion']('ACC-03', "  Ideal para rizos.\nSegunda línea ✨  ");
    $r[] = $f['descripcion']('acc-03', 'x');
    $r[] = $f['descripcion']('NOPE', 'x');
    $r[] = visto('ACC-03');

    $r[] = $f['actualizar']('ACC-02', ' Marca dos ', ' Nombre dos ');           // categoría intacta
    $r[] = visto('ACC-02');
    $r[] = $f['actualizar']('ACC-02', 'Marca', 'Nombre', 'Shampoo');
    $r[] = $f['actualizar']('ACC-02', 'Marca', 'Nombre', 'Inexistente');
    $r[] = $f['actualizar']('ACC-02', '', 'Nombre');
    $r[] = $f['actualizar']('ACC-02', 'Marca', $largo);                         // demasiado largo
    $r[] = $f['actualizar']('acc-02', 'Marca', 'Nombre');
    $r[] = visto('ACC-02');
    $r[] = $f['actualizar']('ACC-06', 'Marca', 'Nombre', '');                   // categoría vacía es válida

    $r[] = $f['eliminar'](['ACC-01', 'ACC-01', '', 'NOPE', 'acc-05', 'ACC-05 ', 'ET-02']);
    $r[] = $f['eliminar']([]);
    $r[] = $f['eliminar'](['', '']);
    $r[] = $f['eliminar'](['ACC-01']);                                          // ya no está
    $r[] = visto('ACC-01');
    $r[] = visto('ACC-05');
    $r[] = count(catalogo());
    return $r;
}

/* La siembra también ocurre si lo PRIMERO que se hace con la tabla vacía es editar o eliminar. */
function guion_tabla_vacia(array $f): array
{
    $r = [];
    $casos = [
        fn() => $f['precio_costo']('ACC-04', 1, 1),
        fn() => $f['descripcion']('ACC-04', 'd'),
        fn() => $f['actualizar']('ACC-04', 'M', 'N'),
        fn() => $f['actualizar']('ACC-04', '', ''),        // rechazado, pero igual siembra
        fn() => $f['eliminar'](['ACC-04']),
        fn() => $f['eliminar']([]),                         // nada que borrar, pero igual siembra
    ];
    foreach ($casos as $caso) {
        db()->exec('DELETE FROM productos');
        $r[] = [$caso(), (int) db()->query('SELECT COUNT(*) FROM productos')->fetchColumn()];
    }
    return $r;
}

$implAnt = ['agregar' => 'ant_agregar', 'precio_costo' => 'ant_precio_costo', 'descripcion' => 'ant_descripcion',
            'actualizar' => 'ant_actualizar', 'eliminar' => 'ant_eliminar'];
$implNue = ['agregar' => 'agregar_producto', 'precio_costo' => 'actualizar_precio_costo', 'descripcion' => 'actualizar_descripcion',
            'actualizar' => 'actualizar_producto', 'eliminar' => 'eliminar_productos'];

$ant = guion($implAnt);
$fotoAnt = catalogo_cache(true);
limpiar_catalogo_prueba();
$nue = guion($implNue);
$fotoNue = catalogo_cache(true);

// Única diferencia, a propósito: antes, justo después de agregar, la copia en
// memoria tenía el producto nuevo SIN la clave 'descripcion' (se armaba a mano);
// ahora se vuelve a leer de la base y llega completo, con descripción ''.
$esperado = $ant;
verificar(!array_key_exists('descripcion', $ant[2]), '(comprobación) antes, el producto recién agregado quedaba en memoria sin descripción');
$esperado[2] = producto_desde_fila($ant[2] + ['descripcion' => '']);
verificar($nue === $esperado, 'cada operación responde lo mismo que antes, y la misma petición ve lo mismo justo después de guardar (' . count($ant) . ' comprobaciones)');
verificar($fotoNue === $fotoAnt, 'el catálogo queda EXACTAMENTE igual que con la implementación anterior (' . count($fotoAnt) . ' productos, todos los campos y el mismo orden)');
verificar(count($fotoAnt) === 92 + 3 - 2, 'el guion sí sembró el catálogo, agregó 3 y eliminó 2 (' . count($fotoAnt) . ' productos)');

limpiar_catalogo_prueba();
$vaciaAnt = guion_tabla_vacia($implAnt);
limpiar_catalogo_prueba();
$vaciaNue = guion_tabla_vacia($implNue);
verificar($vaciaNue === $vaciaAnt, 'con la tabla vacía, editar o eliminar siembra el catálogo igual que antes (' . json_encode($vaciaNue) . ')');

/* --- Lo que cambia: tocar un producto no reescribe los demás --- */
limpiar_catalogo_prueba();
catalogo();
db()->exec('CREATE TABLE prueba_movimientos (tipo varchar(10))');
foreach (['borrado' => 'DELETE', 'insertado' => 'INSERT', 'editado' => 'UPDATE'] as $tipo => $evento) {
    db()->exec("CREATE TRIGGER cuenta_{$tipo}_productos AFTER $evento ON productos FOR EACH ROW INSERT INTO prueba_movimientos VALUES ('$tipo')");
}
$movs = function (callable $accion): array {
    db()->exec('DELETE FROM prueba_movimientos');
    $accion();
    $filas = db()->query('SELECT tipo, COUNT(*) FROM prueba_movimientos GROUP BY tipo')->fetchAll(PDO::FETCH_KEY_PAIR);
    return ['borrado' => (int) ($filas['borrado'] ?? 0), 'insertado' => (int) ($filas['insertado'] ?? 0), 'editado' => (int) ($filas['editado'] ?? 0)];
};
$total = count(catalogo_cache(true));
$m = $movs(fn() => actualizar_precio_costo('ACC-04', 80000, 50000));
verificar($m === ['borrado' => 0, 'insertado' => 0, 'editado' => 1], "cambiar un precio edita solo esa fila (antes: $total borradas y $total reinsertadas)");
$m = $movs(fn() => actualizar_descripcion('ACC-04', 'nueva'));
verificar($m === ['borrado' => 0, 'insertado' => 0, 'editado' => 1], 'cambiar una descripción edita solo esa fila');
$m = $movs(fn() => actualizar_producto('ACC-04', 'Accesorios', 'Carotone grande'));
verificar($m === ['borrado' => 0, 'insertado' => 0, 'editado' => 1], 'cambiar marca y nombre edita solo esa fila');
$m = $movs(fn() => agregar_producto('NUEVO-1', 'Marca', 'Producto', 1000, 500));
verificar($m === ['borrado' => 0, 'insertado' => 1, 'editado' => 0], 'agregar un producto inserta solo esa fila');
$m = $movs(fn() => eliminar_productos(['NUEVO-1', 'ACC-03']));
verificar($m === ['borrado' => 2, 'insertado' => 0, 'editado' => 0], 'eliminar dos productos borra solo esas dos filas');
$m = $movs(fn() => ant_precio_costo('ACC-04', 81000, 50000));
verificar($m['borrado'] === $total - 1 && $m['insertado'] === $total - 1, '(comprobación) la implementación anterior sí borraba y reinsertaba los ' . ($total - 1) . ' productos por un solo precio');

/* --- La caché en memoria: después de guardar, la misma petición ve el dato nuevo --- */
catalogo();                                  // caché cargada
actualizar_precio_costo('ACC-04', 12345, 1);
verificar(producto_por_sku('ACC-04')['precio'] === 12345, 'después de cambiar un precio, la misma petición ya ve el precio nuevo (no la copia vieja en memoria)');
catalogo();
agregar_producto('NUEVO-2', 'Marca', 'Producto', 1000, 500);
verificar(producto_por_sku('NUEVO-2') !== null, 'después de agregar, la misma petición ya ve el producto nuevo');
catalogo();
eliminar_productos(['NUEVO-2']);
verificar(producto_por_sku('NUEVO-2') === null, 'después de eliminar, la misma petición ya no lo ve');

/* --- Seguridad del INSERT: un SKU repetido falla en vez de pisar a otro producto --- */
$original = leer_producto('ACC-04');
$fallo = false;
try {
    guardar_producto(['sku' => 'ACC-04', 'marca' => 'Intrusa', 'nombre' => 'Pisado', 'precio' => 1, 'costo' => 1], true);
} catch (PDOException $e) {
    $fallo = true;
}
verificar($fallo && leer_producto('ACC-04') === $original, 'guardar un producto "nuevo" con un SKU que ya existe falla y no pisa al original');

/* --- leer_producto: solo el SKU exacto, aunque MySQL compare sin distinguir mayúsculas --- */
verificar(leer_producto('acc-04') === null && leer_producto('ACC-04 ') === null && leer_producto('') === null,
    'leer_producto no confunde ACC-04 con "acc-04", "ACC-04 " ni vacío');

terminar_pruebas();

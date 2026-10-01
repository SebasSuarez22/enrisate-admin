<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/categorias.php';

/* =========================================================
   Catálogo de productos · almacén en MySQL (capa 3)
   =========================================================
   Lo que antes estaba al principio de facturas.php. Los productos se
   leen de la tabla `productos` (con una copia en memoria por petición,
   ver catalogo_cache) y se guardan de a uno: solo se escribe la fila que
   cambia. Las páginas que solo muestran o editan productos (el catálogo
   público, Productos, el pedido público) cargan este archivo sin traer
   todo el código de facturas.
   ========================================================= */

/**
 * ¿La tabla productos ya tiene la columna `categoria`?
 *
 * La agrega storage/actualizar-2026-08-23-categorias.sql. Mientras no se
 * haya importado, todo sigue funcionando: el catálogo deduce la categoría
 * del nombre del producto. Sin esta comprobación, la página se caía con
 * "Unknown column 'categoria'" hasta importar el .sql.
 */
function productos_tiene_categoria(): bool
{
    static $tiene = null;

    if ($tiene === null) {
        try {
            $tiene = (bool) db()->query("SHOW COLUMNS FROM productos LIKE 'categoria'")->fetch();
        } catch (Throwable $e) {
            $tiene = false;
        }
    }

    return $tiene;
}

/**
 * ¿La tabla productos ya tiene la columna `descripcion`?
 *
 * La agrega storage/actualizar-2026-09-05-descripcion.sql. Mismo mecanismo
 * defensivo que productos_tiene_categoria(): mientras no se haya
 * importado, el catálogo sigue funcionando sin descripción.
 */
function productos_tiene_descripcion(): bool
{
    static $tiene = null;

    if ($tiene === null) {
        try {
            $tiene = (bool) db()->query("SHOW COLUMNS FROM productos LIKE 'descripcion'")->fetch();
        } catch (Throwable $e) {
            $tiene = false;
        }
    }

    return $tiene;
}

/** Columnas de productos que existen en esta base, en el orden en que se leen y escriben. */
function productos_columnas(): array
{
    $columnas = ['sku', 'marca', 'nombre'];
    if (productos_tiene_categoria()) {
        $columnas[] = 'categoria';
    }
    if (productos_tiene_descripcion()) {
        $columnas[] = 'descripcion';
    }
    array_push($columnas, 'precio', 'costo', 'stock');
    return $columnas;
}

/** Una fila de la tabla productos, con la forma que usa el resto de la app. */
function producto_desde_fila(array $p): array
{
    return [
        'sku'    => (string) $p['sku'],
        'marca'  => (string) $p['marca'],
        'nombre' => (string) $p['nombre'],
        'categoria'   => (string) ($p['categoria'] ?? ''),
        'descripcion' => (string) ($p['descripcion'] ?? ''),
        'precio' => (int) $p['precio'],
        'costo'  => (int) $p['costo'],
        'stock'  => (int) $p['stock'],
    ];
}

/** Los valores de un producto listos para el INSERT o UPDATE, solo de las columnas dadas. */
function producto_a_parametros(array $p, array $columnas): array
{
    $valores = [
        'sku'         => (string) $p['sku'],
        'marca'       => (string) $p['marca'],
        'nombre'      => (string) $p['nombre'],
        'categoria'   => (string) ($p['categoria'] ?? ''),
        'descripcion' => (string) ($p['descripcion'] ?? ''),
        'precio'      => (int) $p['precio'],
        'costo'       => (int) $p['costo'],
        'stock'       => (int) ($p['stock'] ?? 0),
    ];
    $parametros = [];
    foreach ($columnas as $c) {
        $parametros[':' . $c] = $valores[$c];
    }
    return $parametros;
}

/** La caché en memoria del catálogo (null = hay que volver a leerlo de la base). */
function &catalogo_memoria(): ?array
{
    static $cat = null;
    return $cat;
}

/** Marca la copia en memoria como vieja: la próxima lectura vuelve a la base. */
function catalogo_olvidar(): void
{
    $cat = &catalogo_memoria();
    $cat = null;
}

/**
 * Devuelve una referencia a la caché en memoria del catálogo, para que
 * todas las lecturas de una misma petición vean el mismo estado sin volver
 * a consultar la base. Las escrituras la marcan como vieja (catalogo_olvidar).
 */
function &catalogo_cache(bool $recargar = false): array
{
    $cat = &catalogo_memoria();

    if ($recargar) {
        $cat = null;
    }

    if ($cat === null) {
        $filas = db()->query(
            'SELECT ' . implode(', ', productos_columnas()) . ' FROM productos ORDER BY marca, nombre'
        )->fetchAll();

        if ($filas) {
            $cat = array_map('producto_desde_fila', $filas);
        } else {
            // Primera vez (tabla vacía): siembra el catálogo editable desde el archivo base.
            $semilla = require BASE_DIR . '/datos/catalogo.php';
            foreach ($semilla as &$p) {
                $p['stock'] = $p['stock'] ?? 0;
                $p['categoria'] = $p['categoria'] ?? categoria_deducida((string) $p['nombre']);
                $p['descripcion'] = $p['descripcion'] ?? '';
            }
            unset($p);
            $cat = $semilla;
            guardar_catalogo($cat);
        }
    }

    return $cat;
}

function catalogo(): array
{
    return catalogo_cache();
}

/**
 * Reemplaza el catálogo COMPLETO en la base de datos. La app ya no lo usa para
 * los cambios del día a día (se guarda de a un producto con guardar_producto());
 * queda solo para sembrar la tabla vacía desde datos/catalogo.php.
 */
function guardar_catalogo(array $productos): void
{
    $productos = array_values($productos);
    $columnas  = productos_columnas();

    en_transaccion(function () use ($productos, $columnas): void {
        $pdo = db();
        $pdo->exec('DELETE FROM productos');
        $st = $pdo->prepare(
            'INSERT INTO productos (' . implode(', ', $columnas) . ')
             VALUES (' . implode(', ', array_map(fn($c) => ':' . $c, $columnas)) . ')'
        );
        foreach ($productos as $p) {
            $st->execute(producto_a_parametros($p, $columnas));
        }
    });

    $cache = &catalogo_memoria();
    $cache = $productos;
}

/**
 * Guarda UN producto: si es nuevo lo inserta (y falla si el SKU ya existiera, en
 * vez de pisar a otro); si ya existe, actualiza solo su fila. Los demás productos
 * no se tocan.
 */
function guardar_producto(array $p, bool $esNuevo): void
{
    $columnas = productos_columnas();
    if ($esNuevo) {
        $sql = 'INSERT INTO productos (' . implode(', ', $columnas) . ')
                VALUES (' . implode(', ', array_map(fn($c) => ':' . $c, $columnas)) . ')';
    } else {
        $cambios = array_map(fn($c) => $c . ' = :' . $c, array_diff($columnas, ['sku']));
        $sql = 'UPDATE productos SET ' . implode(', ', $cambios) . ' WHERE sku = :sku';
    }
    db()->prepare($sql)->execute(producto_a_parametros($p, $columnas));
    catalogo_olvidar();
}

/**
 * Lee UN producto directo de la base (no de la caché): lo usan las funciones que
 * escriben, dentro del candado, para partir siempre del dato más reciente.
 */
function leer_producto(string $sku): ?array
{
    $st = db()->prepare('SELECT ' . implode(', ', productos_columnas()) . ' FROM productos WHERE sku = ?');
    $st->execute([$sku]);
    foreach ($st->fetchAll() as $fila) {
        // MySQL compara sin distinguir mayúsculas ni espacios al final; aquí,
        // igual que producto_por_sku(), solo vale el SKU exacto.
        if ((string) $fila['sku'] === $sku) {
            return producto_desde_fila($fila);
        }
    }
    return null;
}

/**
 * La primera vez (tabla vacía) el catálogo se siembra desde datos/catalogo.php al
 * leerlo. Las funciones que escriben ya no leen el catálogo completo, así que lo
 * piden aquí: sin esto, agregar un producto a una tabla vacía la dejaría con ese
 * único producto y la siembra no ocurriría nunca.
 */
function catalogo_sembrar_si_vacio(): void
{
    if (db()->query('SELECT 1 FROM productos LIMIT 1')->fetchColumn() === false) {
        catalogo_cache(true);
    }
}

function producto_por_sku(string $sku): ?array
{
    foreach (catalogo() as $p) {
        if ($p['sku'] === $sku) {
            return $p;
        }
    }
    return null;
}

/** Número final de un SKU, para ordenar por número (p. ej. "MIL-03" → 3, "DES01" → 1). */
function sku_orden(string $sku): int
{
    return preg_match('/(\d+)$/', $sku, $m) ? (int) $m[1] : 0;
}

/** Agrega un producto nuevo al catálogo. Devuelve '' si quedó bien, o un mensaje de error. */
function agregar_producto(string $sku, string $marca, string $nombre, int $precio, int $costo, string $categoria = ''): string
{
    return con_bloqueo('catalogo', function () use ($sku, $marca, $nombre, $precio, $costo, $categoria) {
        catalogo_sembrar_si_vacio();
        $sku    = strtoupper(trim($sku));
        $marca  = trim($marca);
        $nombre = trim($nombre);

        if ($sku === '' || !preg_match('/^[A-Z0-9\-_]+$/', $sku)) {
            return 'El SKU es obligatorio y solo puede tener letras, números, guiones y guion bajo.';
        }
        if ($marca === '' || $nombre === '') {
            return 'Escribe la marca y el nombre del producto.';
        }
        if ($precio < 0 || $costo < 0) {
            return 'Precio y costo no pueden ser negativos.';
        }
        if (leer_producto($sku)) {
            return 'Ya existe un producto con el SKU ' . $sku . '.';
        }
        $categoria = trim($categoria);
        if (!categoria_valida($categoria)) {
            return 'Esa categoría no existe.';
        }
        if ($categoria === '') {
            $categoria = categoria_deducida($nombre);
        }

        $nuevo = [
            'sku'       => $sku,
            'marca'     => $marca,
            'nombre'    => $nombre,
            'categoria' => $categoria,
            'precio'    => $precio,
            'costo'     => $costo,
            'stock'     => 0,
        ];
        // El SKU, la marca y el nombre no tienen límite de largo validado
        // arriba. Si algo llega más largo de lo que la columna acepta (o el
        // SKU choca con otro que MySQL considera igual, p. ej. con otra
        // mayúscula), MySQL rechaza el INSERT con una excepción — sin este
        // catch eso tumbaba la página entera, en vez de un aviso normal.
        try {
            guardar_producto($nuevo, true);
        } catch (PDOException $e) {
            return 'No se pudo guardar: revisa que el SKU, la marca y el nombre no sean demasiado largos.';
        }

        return '';
    });
}

/** Fija el precio y el costo de un SKU existente. Devuelve true si lo encontró. */
function actualizar_precio_costo(string $sku, int $precio, int $costo): bool
{
    return con_bloqueo('catalogo', function () use ($sku, $precio, $costo) {
        catalogo_sembrar_si_vacio();
        $p = leer_producto($sku);
        if ($p === null) {
            return false;
        }
        $p['precio'] = max(0, $precio);
        $p['costo']  = max(0, $costo);
        guardar_producto($p, false);
        return true;
    });
}

/** Fija la descripción de un SKU existente. Devuelve true si lo encontró. */
function actualizar_descripcion(string $sku, string $descripcion): bool
{
    return con_bloqueo('catalogo', function () use ($sku, $descripcion) {
        catalogo_sembrar_si_vacio();
        $p = leer_producto($sku);
        if ($p === null) {
            return false;
        }
        $p['descripcion'] = trim($descripcion);
        guardar_producto($p, false);
        return true;
    });
}

/** Actualiza marca y nombre de un SKU existente. Devuelve true si lo encontró. */
function actualizar_producto(string $sku, string $marca, string $nombre, ?string $categoria = null): bool
{
    return con_bloqueo('catalogo', function () use ($sku, $marca, $nombre, $categoria) {
        catalogo_sembrar_si_vacio();
        $marca  = trim($marca);
        $nombre = trim($nombre);
        if ($marca === '' || $nombre === '') {
            return false;
        }
        if ($categoria !== null) {
            $categoria = trim($categoria);
            if (!categoria_valida($categoria)) {
                return false;
            }
        }

        $p = leer_producto($sku);
        if ($p === null) {
            return false;
        }
        $p['marca']  = $marca;
        $p['nombre'] = $nombre;
        if ($categoria !== null) {
            $p['categoria'] = $categoria;
        }
        // Mismo caso que en agregar_producto(): un nombre o marca más
        // largo de lo que la columna acepta tumbaba la página con la
        // traza de PHP en vez de un aviso. Al editar no hay un mensaje
        // específico que devolver (la función solo entrega bool), así
        // que se trata igual que "no se pudo": el llamador ya muestra un
        // aviso genérico para ese caso.
        try {
            guardar_producto($p, false);
        } catch (PDOException $e) {
            return false;
        }
        return true;
    });
}

/** Elimina varios productos del catálogo por SKU. Devuelve cuántos encontró y quitó. */
function eliminar_productos(array $skus): int
{
    return con_bloqueo('catalogo', function () use ($skus) {
        catalogo_sembrar_si_vacio();
        $skus = array_values(array_unique(array_filter($skus, fn($sku) => $sku !== '')));
        if (!$skus) {
            return 0;
        }

        // Solo se borran los SKU que coinciden exacto (leer_producto); MySQL solo
        // con "WHERE sku = ?" borraría también "mil-03" o "MIL-03 " al pedir "MIL-03".
        $borrados = en_transaccion(function () use ($skus): int {
            $st       = db()->prepare('DELETE FROM productos WHERE sku = ?');
            $borrados = 0;
            foreach ($skus as $sku) {
                $p = is_string($sku) ? leer_producto($sku) : null;
                if ($p !== null) {
                    $st->execute([$p['sku']]);
                    $borrados++;
                }
            }
            return $borrados;
        });
        if ($borrados > 0) {
            catalogo_olvidar();
        }
        return $borrados;
    });
}

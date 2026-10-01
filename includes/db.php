<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

/* =========================================================
   Conexión a MySQL (PDO) · capa 3
   =========================================================
   Una sola conexión por petición (patrón singleton con variable estática).
   Si la conexión falla, se corta la ejecución con un mensaje claro en vez
   de dejar que PHP muestre un error críptico de PDO.
   ========================================================= */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            DB_NOMBRE
        );

        try {
            $pdo = new PDO($dsn, DB_USUARIO, DB_CLAVE, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            $detalle = 'No se pudo conectar a la base de datos MySQL "' . DB_NOMBRE . '" en '
                . DB_HOST . ':' . DB_PORT . ': ' . $e->getMessage();

            // Desde la terminal (herramientas, pruebas, el arranque del contenedor)
            // sí se muestra todo: ahí lo lee quien administra, no un visitante.
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, $detalle . "\nEn XAMPP: revisa que MySQL esté encendido y que la base exista "
                    . "(importa storage/esquema.sql desde phpMyAdmin).\n");
                exit(1);
            }

            // En la web: el detalle (servidor, nombre de la base, mensaje del driver)
            // solo va al registro. El visitante ve un aviso genérico.
            error_log('[enrisate] ' . $detalle);
            if (!headers_sent()) {
                header('Retry-After: 60');
            }
            responder_error_generico(503, 'El sistema no está disponible en este momento. Intenta de nuevo en unos minutos.');
        }
    }

    return $pdo;
}

/**
 * Ejecuta $fn con el recurso $recurso ("facturas", "clientes", "pedidos",
 * "catalogo") reservado: mientras corre, ninguna otra petición puede escribir
 * ese mismo recurso. Toda escritura lee primero (¿ya existe ese SKU?, ¿cuánto saldo
 * le queda?, ¿qué número de pedido sigue?) y luego escribe; si dos peticiones corren
 * a la vez, la segunda decide con un dato viejo y pisa lo que hizo la primera, sin
 * ningún aviso (abonos perdidos, saldos a favor mal descontados, números repetidos).
 * Esperar turno lo evita.
 *
 * Usa el candado de MySQL (GET_LOCK): se libera solo si la petición termina o
 * se cae. Se puede anidar con el mismo recurso (registrar_abono llama a
 * actualizar_factura), y solo el nivel de afuera toma y suelta el candado. Si no
 * consigue turno en 15 s lanza un error en vez de guardar a ciegas.
 *
 * Regla: toda función que LEA un recurso para luego ESCRIBIRLO debe hacer las dos
 * cosas dentro del mismo bloqueo. Si una operación necesita varios recursos a la
 * vez, pídelos juntos con con_bloqueos() (siempre en el mismo orden, así dos
 * peticiones nunca se esperan la una a la otra); no tomes uno mientras tienes otro
 * reservado por tu cuenta.
 */
function con_bloqueo(string $recurso, callable $fn): mixed
{
    static $profundidad = [];
    $pdo    = db();
    $nombre = 'enrisate_' . $recurso . ':' . DB_NOMBRE;

    if (($profundidad[$recurso] ?? 0) === 0) {
        $st = $pdo->prepare('SELECT GET_LOCK(?, 15)');
        $st->execute([$nombre]);
        if ((int) $st->fetchColumn() !== 1) {
            throw new RuntimeException('No se pudo guardar: otra operación sobre ' . $recurso . ' está tardando demasiado. Intenta de nuevo en unos segundos.');
        }
    }

    $profundidad[$recurso] = ($profundidad[$recurso] ?? 0) + 1;
    try {
        return $fn();
    } finally {
        $profundidad[$recurso]--;
        if ($profundidad[$recurso] === 0) {
            $st = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $st->execute([$nombre]);
        }
    }
}

/**
 * Reserva varios recursos a la vez, siempre en el mismo orden (facturas, clientes,
 * pedidos, catálogo) sin importar el orden en que se pidan: así dos operaciones que
 * necesitan los mismos recursos nunca se quedan esperando una a la otra (deadlock).
 */
function con_bloqueos(array $recursos, callable $fn): mixed
{
    $orden      = ['facturas', 'clientes', 'pedidos', 'catalogo'];
    $recursos   = array_values(array_unique($recursos));
    $desconocido = array_diff($recursos, $orden);
    if ($desconocido) {
        throw new InvalidArgumentException('Recurso sin candado: ' . implode(', ', $desconocido));
    }
    usort($recursos, fn($a, $b) => array_search($a, $orden, true) <=> array_search($b, $orden, true));

    $paso = $fn;
    foreach (array_reverse($recursos) as $recurso) {
        $siguiente = $paso;
        $paso = fn() => con_bloqueo($recurso, $siguiente);
    }
    return $paso();
}

/**
 * Ejecuta $fn dentro de una transacción de MySQL: o se guarda todo, o no se guarda
 * nada. Si ya hay una transacción abierta, se une a ella (la que la abrió decide el
 * commit o el rollback), así los guardar_*() se pueden usar solos o dentro de una
 * operación más grande.
 *
 * Importante: reserva los candados (con_bloqueos) ANTES de abrir la transacción. La
 * transacción ve la base tal como estaba en su primera lectura; si esperara el
 * candado ya dentro, leería una copia vieja.
 */
function en_transaccion(callable $fn): mixed
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn();
    }
    $pdo->beginTransaction();
    try {
        $resultado = $fn();
        $pdo->commit();
        return $resultado;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

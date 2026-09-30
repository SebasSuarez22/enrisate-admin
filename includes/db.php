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
            http_response_code(500);
            die(
                'No se pudo conectar a la base de datos MySQL. '
                . 'Verifica que XAMPP tenga MySQL encendido y que la base "'
                . DB_NOMBRE . '" exista (importa storage/esquema.sql desde phpMyAdmin). '
                . 'Detalle técnico: ' . $e->getMessage()
            );
        }
    }

    return $pdo;
}

/**
 * Ejecuta $fn con el recurso $recurso ("facturas", "clientes", "pedidos",
 * "catalogo") reservado: mientras corre, ninguna otra petición puede escribir
 * ese mismo recurso. Cada uno de esos almacenes se guarda leyendo TODO, cambiando
 * una cosa y reescribiendo TODO, así que si dos peticiones corren a la vez la
 * segunda guarda una copia vieja y borra lo que hizo la primera, sin ningún aviso
 * (abonos, pedidos, clientas o productos perdidos, saldos a favor mal descontados).
 * Esperar turno lo evita.
 *
 * Usa el candado de MySQL (GET_LOCK): se libera solo si la petición termina o
 * se cae. Se puede anidar con el mismo recurso (registrar_abono llama a
 * actualizar_factura), y solo el nivel de afuera toma y suelta el candado. Si no
 * consigue turno en 15 s lanza un error en vez de guardar a ciegas.
 *
 * Regla: toda función que LEA un recurso para luego ESCRIBIRLO debe hacer las dos
 * cosas dentro del mismo bloqueo. Para evitar bloqueos cruzados, no tomes un
 * recurso mientras tienes otro distinto reservado.
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

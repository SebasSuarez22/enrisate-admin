<?php
declare(strict_types=1);

/* =========================================================
   Errores no previstos: aviso genérico al visitante, detalle al registro
   =========================================================
   Si algo falla sin que el código lo haya previsto (una consulta que se cae,
   un archivo que falta), PHP mostraría el mensaje técnico completo: rutas del
   servidor, consultas SQL, nombres de tablas. Eso le sirve a un atacante y
   asusta a una clienta. En su lugar: el detalle va al registro (los logs de
   Railway) y en pantalla sale un aviso corto y neutro. (OWASP A05 · CWE-209)

   Desde la terminal (herramientas, pruebas) no se toca nada: ahí sí conviene
   ver el error completo.
   ========================================================= */

/**
 * Termina la petición con un aviso genérico. Si la respuesta ya estaba marcada
 * como JSON (el envío de pedidos del catálogo público), el aviso sale en JSON
 * para que el formulario se lo muestre a la clienta.
 */
function responder_error_generico(int $codigo, string $mensaje): never
{
    // Lo que ya se hubiera armado de la página (a medias) no se envía.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $esJson = false;
    foreach (headers_list() as $h) {
        if (stripos($h, 'Content-Type: application/json') === 0) {
            $esJson = true;
        }
    }

    if (!headers_sent()) {
        http_response_code($codigo);
    }
    echo $esJson
        ? json_encode(['ok' => false, 'error' => $mensaje], JSON_UNESCAPED_UNICODE)
        : $mensaje;
    exit;
}

if (PHP_SAPI !== 'cli') {
    set_exception_handler(static function (Throwable $e): void {
        error_log(sprintf(
            "[enrisate] Error no controlado en %s: %s: %s (%s:%d)\n%s",
            $_SERVER['REQUEST_URI'] ?? '?',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ));

        responder_error_generico(500, 'Algo salió mal y no se pudo completar. Intenta de nuevo en unos minutos.');
    });
}

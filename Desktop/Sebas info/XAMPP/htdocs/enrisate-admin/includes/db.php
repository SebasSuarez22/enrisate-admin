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

<?php
declare(strict_types=1);

require_once __DIR__ . '/formato.php';
require_once __DIR__ . '/catalogo.php';
require_once __DIR__ . '/imagenes.php';

/* =========================================================
   API del catálogo · para el bot de WhatsApp (n8n)
   =========================================================
   Solo lectura: el bot pregunta por productos y precios, nunca escribe.
   Entrega lo mismo que ya ve cualquiera en el catálogo público (marca,
   nombre, categoría, descripción, precio, foto). Nunca el costo ni el
   margen. Tampoco dice si hay unidades: el panel no lleva el inventario
   (la columna stock queda en 0), y el bot diría que todo está agotado.

   Se protege con una llave (variable de entorno API_TOKEN, generada con
   php tools/generar_token_api.php). Sin la variable, la API no abre:
   mejor cerrada que abierta por un olvido. (OWASP ASVS V4 · CWE-306)

   El endpoint es api-catalogo.php; aquí está la lógica, sin nada de
   la petición HTTP, para poder probarla desde tests/api_catalogo_test.php.
   ========================================================= */

/** Largo mínimo de la llave: con menos, se trata como si no estuviera configurada. */
const API_TOKEN_MIN = 32;

/** Cuántos productos devuelve como máximo una consulta (y cuántos si no se pide un límite). */
const API_LIMITE_MAX     = 100;
const API_LIMITE_DEFECTO = 20;

/** La llave configurada en el servidor, o '' si falta o es demasiado corta. */
function api_token_configurado(): string
{
    $token = trim((string) getenv('API_TOKEN'));
    return strlen($token) >= API_TOKEN_MIN ? $token : '';
}

/**
 * La llave que mandó quien consulta: cabecera X-Api-Key (la recomendada en
 * n8n) o "Authorization: Bearer ...". Apache no siempre deja llegar
 * Authorization a $_SERVER, por eso se busca también en las otras rutas
 * por donde suele aparecer.
 */
function api_token_de_peticion(array $server): string
{
    $llave = trim((string) ($server['HTTP_X_API_KEY'] ?? ''));
    if ($llave !== '') {
        return $llave;
    }

    $auth = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($auth === '' && function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $nombre => $valor) {
            if (strcasecmp((string) $nombre, 'Authorization') === 0) {
                $auth = (string) $valor;
            }
        }
    }

    return preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $auth, $m) ? $m[1] : '';
}

/** ¿La llave recibida es la configurada? Comparación en tiempo constante. */
function api_autorizado(string $recibido, string $esperado): bool
{
    return $esperado !== '' && $recibido !== '' && hash_equals($esperado, $recibido);
}

/**
 * Responde en JSON y termina. Lo usan los endpoints api-*.php.
 * Sin JSON_UNESCAPED_SLASHES las direcciones de las fotos saldrían con "\/".
 */
function api_responder(int $codigo, array $cuerpo): never
{
    http_response_code($codigo);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Lo primero que hace cada endpoint de la API: respuesta JSON sin copia
 * guardada, solo GET y llave válida. Si algo no cumple, responde y termina.
 */
function api_proteger(): void
{
    header('Content-Type: application/json; charset=utf-8');
    // Precios, compras y saldos cambian: ni el navegador ni un proxy guardan copia.
    header('Cache-Control: no-store');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        api_responder(405, ['ok' => false, 'error' => 'Método no permitido. Usa GET.']);
    }

    $esperado = api_token_configurado();
    if ($esperado === '') {
        api_responder(503, ['ok' => false, 'error' => 'La API no está activada en este servidor.']);
    }
    if (!api_autorizado(api_token_de_peticion($_SERVER), $esperado)) {
        api_responder(401, ['ok' => false, 'error' => 'Falta la llave de la API o no es válida.']);
    }
}

/** Texto para comparar sin mayúsculas, tildes ni espacios de más ("Champú  Rizos" → "champu rizos"). */
function api_normalizar(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return (string) preg_replace('/\s+/u', ' ', $s);
}

/**
 * Filtra el catálogo. Filtros (todos opcionales, se combinan):
 *   q           palabras a buscar en marca, nombre, categoría, descripción y SKU;
 *               deben aparecer TODAS ("shampoo rizos" no trae acondicionadores)
 *   sku         SKU exacto (sin importar mayúsculas)
 *   marca       marca exacta (sin importar mayúsculas ni tildes)
 *   categoria   categoría exacta (la guardada o, si no tiene, la deducida del nombre)
 * Devuelve los productos ordenados por marca y nombre.
 */
function api_buscar_productos(array $productos, array $filtros): array
{
    $q         = api_normalizar((string) ($filtros['q'] ?? ''));
    $palabras  = $q === '' ? [] : explode(' ', $q);
    $sku       = strtoupper(trim((string) ($filtros['sku'] ?? '')));
    $marca     = api_normalizar((string) ($filtros['marca'] ?? ''));
    $categoria = api_normalizar((string) ($filtros['categoria'] ?? ''));

    $encontrados = [];
    foreach ($productos as $p) {
        $cat = categoria_de($p);
        if ($sku !== '' && strtoupper((string) $p['sku']) !== $sku) {
            continue;
        }
        if ($marca !== '' && api_normalizar((string) $p['marca']) !== $marca) {
            continue;
        }
        if ($categoria !== '' && api_normalizar($cat) !== $categoria) {
            continue;
        }
        if ($palabras) {
            $texto = api_normalizar(implode(' ', [
                $p['marca'], $p['nombre'], $cat, $p['descripcion'] ?? '', $p['sku'],
            ]));
            foreach ($palabras as $palabra) {
                if (!str_contains($texto, $palabra)) {
                    continue 2;
                }
            }
        }
        $encontrados[] = $p;
    }

    usort($encontrados, fn($a, $b) => strcasecmp($a['marca'], $b['marca']) ?: strcasecmp($a['nombre'], $b['nombre']));
    return $encontrados;
}

/** Límite pedido, dentro de [1, API_LIMITE_MAX]; si no se pidió (o no es un número), el de defecto. */
function api_limite(mixed $pedido): int
{
    if (!is_numeric($pedido)) {
        return API_LIMITE_DEFECTO;
    }
    return max(1, min(API_LIMITE_MAX, (int) $pedido));
}

/**
 * Dirección base pública de la app (sin "/" final), tomada del enlace oficial
 * del catálogo y no de la cabecera Host: esa la puede inventar quien consulta.
 */
function api_url_base(): string
{
    $partes = parse_url(APP_URL_CATALOGO);
    $ruta   = rtrim(dirname((string) ($partes['path'] ?? '/')), '/');
    return ($partes['scheme'] ?? 'https') . '://' . ($partes['host'] ?? '') . $ruta;
}

/**
 * Lo que la API muestra de un producto. $foto es la ruta relativa de su foto
 * (producto_ruta_foto) o null. Nada de costo ni de unidades.
 */
function api_producto_publico(array $p, ?string $foto, string $base): array
{
    return [
        'sku'          => (string) $p['sku'],
        'marca'        => (string) $p['marca'],
        'nombre'       => (string) $p['nombre'],
        'categoria'    => categoria_de($p),
        'descripcion'  => (string) ($p['descripcion'] ?? ''),
        'precio'       => (int) $p['precio'],
        'precio_texto' => pesos((int) $p['precio']),
        'foto'         => $foto === null ? null : $base . '/' . $foto,
    ];
}

/** La promoción vigente del catálogo público, para que el bot la pueda mencionar. */
function api_promo(): array
{
    return [
        'minimo_unidades' => PROMO_MINIMO_UNIDADES,
        'porcentaje'      => PROMO_PORCENTAJE,
        'texto'           => 'Por la compra de ' . PROMO_MINIMO_UNIDADES . ' o más productos, '
                           . PROMO_PORCENTAJE . ' % de descuento en toda la compra.',
    ];
}

/** La respuesta completa de una consulta al catálogo. */
function api_respuesta_catalogo(array $productos, array $filtros, string $base): array
{
    $encontrados = api_buscar_productos($productos, $filtros);
    $limite      = api_limite($filtros['limite'] ?? null);
    $mostrados   = array_slice($encontrados, 0, $limite);

    return [
        'ok'           => true,
        'total'        => count($encontrados),
        'mostrados'    => count($mostrados),
        'productos'    => array_map(
            fn(array $p) => api_producto_publico($p, producto_ruta_foto((string) $p['sku']), $base),
            $mostrados
        ),
        'promo'        => api_promo(),
        'catalogo_url' => APP_URL_CATALOGO,
    ];
}

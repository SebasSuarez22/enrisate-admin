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
 * El JSON de una respuesta. Sin JSON_UNESCAPED_SLASHES las direcciones de las
 * fotos saldrían con "\/". Con JSON_INVALID_UTF8_SUBSTITUTE, un nombre con un
 * carácter dañado (pegado de otro programa) sale con "�" en vez de tumbar toda
 * la respuesta: sin eso json_encode devolvía false y el bot recibía un 200 vacío.
 */
function api_json(array $cuerpo): string
{
    return (string) json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

/** Responde en JSON y termina. Lo usan los endpoints api-*.php. */
function api_responder(int $codigo, array $cuerpo): never
{
    http_response_code($codigo);
    echo api_json($cuerpo);
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

/**
 * Texto para comparar sin mayúsculas, tildes, signos ni espacios de más
 * ("¿Champú,  Rizos?" → "champu rizos"; "ET-01" → "et 01"). Se aplica igual a
 * lo que se busca y a donde se busca, así los dos lados quedan parejos.
 */
function api_normalizar(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    $s = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    return trim($s);
}

/**
 * Palabras que no dicen qué producto se busca. El bot pasa lo que escribe la
 * clienta ("hola, ¿tienen shampoo para rizos?"): sin quitarlas, "tienen" y
 * "para" tendrían que aparecer en el producto y no saldría nada.
 */
const API_PALABRAS_VACIAS = [
    'a', 'al', 'algo', 'algun', 'alguna', 'alguno', 'buenas', 'buenos', 'busco', 'como', 'con', 'cual', 'cuales',
    'cuanto', 'cuesta', 'cuestan', 'de', 'del', 'dias', 'el', 'en', 'es', 'esta', 'este', 'favor', 'hay', 'hola',
    'la', 'las', 'le', 'les', 'lo', 'los', 'mas', 'me', 'mi', 'mis', 'muy', 'necesito', 'noches', 'o', 'para',
    'por', 'porfa', 'precio', 'precios', 'que', 'quiero', 'quisiera', 'se', 'si', 'sin', 'son', 'su', 'sus',
    'tardes', 'te', 'tendran', 'tengo', 'tiene', 'tienen', 'tienes', 'tu', 'tus', 'un', 'una', 'unas', 'unos',
    'vale', 'valen', 'vende', 'venden', 'y', 'ya',
];

/**
 * Las palabras útiles de una búsqueda: normalizadas, sin palabras vacías, sin
 * repetir y en singular aproximado ("shampoos" → "shampoo", "acondicionadores"
 * → "acondicionador", "cremas" → "crema"). Como después se busca por
 * pedazo de texto, recortar el plural solo puede encontrar más, nunca menos.
 */
function api_palabras_busqueda(string $q): array
{
    $palabras = [];
    foreach (explode(' ', api_normalizar($q)) as $p) {
        if ($p === '' || in_array($p, API_PALABRAS_VACIAS, true)) {
            continue;
        }
        if (strlen($p) >= 4 && !ctype_digit($p)) {
            if (str_ends_with($p, 'es') && strlen($p) >= 6) {
                $p = substr($p, 0, -2);
            } elseif (str_ends_with($p, 's')) {
                $p = substr($p, 0, -1);
            }
        }
        if (!in_array($p, $palabras, true)) {
            $palabras[] = $p;
        }
    }
    // Lista y no claves de un arreglo: array_keys() convierte "3" en el número 3, y con
    // strict_types str_contains() se caía con TypeError en cualquier búsqueda con números.
    return $palabras;
}

/**
 * Filtra el catálogo. Filtros (todos opcionales, se combinan):
 *   q           lo que se busca, en marca, nombre, categoría, descripción y SKU
 *   sku         SKU exacto (sin importar mayúsculas)
 *   marca       marca exacta (sin importar mayúsculas ni tildes)
 *   categoria   categoría exacta (la guardada o, si no tiene, la deducida del nombre)
 * $modo 'todas': cada palabra útil de q debe aparecer ("shampoo rizos" no trae
 * acondicionadores). 'algunas': basta una, y salen primero los que tienen más.
 * Una q que no tiene ninguna palabra útil ("hola") no encuentra nada (sin q, todo).
 * Devuelve los productos ordenados por marca y nombre (en 'algunas', antes por coincidencias).
 */
function api_buscar_productos(array $productos, array $filtros, string $modo = 'todas'): array
{
    $q         = trim((string) ($filtros['q'] ?? ''));
    $palabras  = $q === '' ? [] : api_palabras_busqueda($q);
    $sku       = strtoupper(trim((string) ($filtros['sku'] ?? '')));
    $marca     = api_normalizar((string) ($filtros['marca'] ?? ''));
    $categoria = api_normalizar((string) ($filtros['categoria'] ?? ''));

    if ($q !== '' && !$palabras) {
        return [];
    }

    $encontrados = [];
    $aciertos    = [];
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
        $n = 0;
        if ($palabras) {
            $texto = api_normalizar(implode(' ', [
                $p['marca'], $p['nombre'], $cat, $p['descripcion'] ?? '', $p['sku'],
            ]));
            foreach ($palabras as $palabra) {
                if (str_contains($texto, $palabra)) {
                    $n++;
                }
            }
            if ($n === 0 || ($modo === 'todas' && $n < count($palabras))) {
                continue;
            }
        }
        $aciertos[(string) $p['sku']] = $n;
        $encontrados[] = $p;
    }

    usort($encontrados, fn($a, $b) => ($modo === 'algunas' ? $aciertos[(string) $b['sku']] <=> $aciertos[(string) $a['sku']] : 0)
        ?: strcasecmp($a['marca'], $b['marca']) ?: strcasecmp($a['nombre'], $b['nombre']));
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

/**
 * La respuesta completa de una consulta al catálogo. Primero busca productos
 * que tengan TODAS las palabras; si no hay ninguno, los que tienen ALGUNA
 * (ordenados por cuántas tienen). `coincidencia` le dice al bot cuál pasó,
 * para que diga "no tengo justo eso, pero mira estos" en vez de afirmar.
 */
function api_respuesta_catalogo(array $productos, array $filtros, string $base): array
{
    $coincidencia = 'todas';
    $encontrados  = api_buscar_productos($productos, $filtros, 'todas');
    if (!$encontrados && count(api_palabras_busqueda((string) ($filtros['q'] ?? ''))) > 1) {
        $encontrados  = api_buscar_productos($productos, $filtros, 'algunas');
        $coincidencia = 'algunas';
    }
    if (!$encontrados) {
        $coincidencia = 'ninguna';
    }
    $limite    = api_limite($filtros['limite'] ?? null);
    $mostrados = array_slice($encontrados, 0, $limite);

    return [
        'ok'           => true,
        'coincidencia' => $coincidencia,
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

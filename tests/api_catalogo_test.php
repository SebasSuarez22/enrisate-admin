<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/api.php';

/* API del catálogo para el bot: la llave, la búsqueda y lo que se muestra
   (nunca el costo ni las unidades). Todo con un catálogo de mentira
   en memoria: no toca la tabla productos. */

$catalogo = [
    ['sku' => 'ETN-01', 'marca' => 'Etniker', 'nombre' => 'Shampoo Rizos Definidos', 'categoria' => 'Shampoo',
     'descripcion' => 'Limpieza suave para rizos', 'precio' => 38000, 'costo' => 21000, 'stock' => 4],
    ['sku' => 'ETN-02', 'marca' => 'Etniker', 'nombre' => 'Acondicionador Rizos', 'categoria' => 'Acondicionador',
     'descripcion' => '', 'precio' => 41000, 'costo' => 23000, 'stock' => 0],
    ['sku' => 'POC-07', 'marca' => 'La Poción', 'nombre' => 'Crema para peinar', 'categoria' => '',
     'descripcion' => 'Con aceite de coco', 'precio' => 45000, 'costo' => 25000, 'stock' => 2],
    ['sku' => 'ORB-03', 'marca' => 'Origen Botánico', 'nombre' => 'Champú de romero', 'categoria' => 'Shampoo',
     'descripcion' => '', 'precio' => 52000, 'costo' => 30000, 'stock' => 9],
];
$skus = fn(array $ps): array => array_column($ps, 'sku');

/* --- La llave --- */
$llave = str_repeat('a1', 32);
putenv('API_TOKEN=' . $llave);
verificar(api_token_configurado() === $llave, 'la llave configurada se lee de API_TOKEN');
putenv('API_TOKEN=corta');
verificar(api_token_configurado() === '', 'una llave de menos de 32 caracteres cuenta como no configurada');
putenv('API_TOKEN');
verificar(api_token_configurado() === '', 'sin API_TOKEN la API queda cerrada');

verificar(api_autorizado($llave, $llave), 'la llave correcta entra');
verificar(!api_autorizado($llave . 'x', $llave), 'una llave distinta no entra');
verificar(!api_autorizado('', $llave), 'sin llave no entra');
verificar(!api_autorizado('', ''), 'sin llave configurada nadie entra, ni siquiera mandando una vacía');

verificar(api_token_de_peticion(['HTTP_X_API_KEY' => " $llave "]) === $llave, 'se lee la cabecera X-Api-Key');
verificar(api_token_de_peticion(['HTTP_AUTHORIZATION' => "Bearer $llave"]) === $llave, 'se lee Authorization: Bearer');
verificar(api_token_de_peticion(['REDIRECT_HTTP_AUTHORIZATION' => "bearer $llave"]) === $llave, 'Bearer también por la ruta de Apache tras una redirección');
verificar(api_token_de_peticion(['HTTP_AUTHORIZATION' => "Basic $llave"]) === '', 'Authorization Basic no cuenta como llave');
verificar(api_token_de_peticion([]) === '', 'sin cabeceras no hay llave');

/* --- Búsqueda --- */
verificar($skus(api_buscar_productos($catalogo, [])) === ['ETN-02', 'ETN-01', 'POC-07', 'ORB-03'],
    'sin filtros: todo el catálogo, por marca y nombre');
verificar($skus(api_buscar_productos($catalogo, ['q' => 'rizos'])) === ['ETN-02', 'ETN-01'], 'q busca en el nombre');
verificar($skus(api_buscar_productos($catalogo, ['q' => 'shampoo rizos'])) === ['ETN-01'], 'q exige TODAS las palabras');
verificar($skus(api_buscar_productos($catalogo, ['q' => 'CHAMPÚ'])) === ['ORB-03'], 'q no distingue mayúsculas ni tildes');
verificar($skus(api_buscar_productos($catalogo, ['q' => 'coco'])) === ['POC-07'], 'q busca en la descripción');
verificar($skus(api_buscar_productos($catalogo, ['q' => 'crema'])) === ['POC-07'], 'q busca en la categoría deducida del nombre');
verificar($skus(api_buscar_productos($catalogo, ['q' => '  shampoo   etniker '])) === ['ETN-01'], 'espacios de más no estorban');
verificar(api_buscar_productos($catalogo, ['q' => 'gelatina']) === [], 'sin coincidencias: lista vacía');

verificar($skus(api_buscar_productos($catalogo, ['sku' => 'etn-01'])) === ['ETN-01'], 'sku exacto sin importar mayúsculas');
verificar(api_buscar_productos($catalogo, ['sku' => 'ETN']) === [], 'sku no busca por pedazos');
verificar($skus(api_buscar_productos($catalogo, ['marca' => 'la pocion'])) === ['POC-07'], 'marca sin tildes ni mayúsculas');
verificar($skus(api_buscar_productos($catalogo, ['categoria' => 'shampoo'])) === ['ETN-01', 'ORB-03'], 'categoría exacta');
verificar($skus(api_buscar_productos($catalogo, ['categoria' => 'Crema'])) === ['POC-07'], 'categoría deducida del nombre cuenta');

/* --- Límite --- */
verificar(api_limite(null) === API_LIMITE_DEFECTO, 'sin límite: el de defecto');
verificar(api_limite('abc') === API_LIMITE_DEFECTO, 'límite que no es número: el de defecto');
verificar(api_limite('0') === 1 && api_limite(-5) === 1, 'límite mínimo 1');
verificar(api_limite('5000') === API_LIMITE_MAX, 'límite máximo ' . API_LIMITE_MAX);

/* --- Lo que se muestra de cada producto --- */
$base = 'https://ejemplo.test';
$pub  = api_producto_publico($catalogo[0], 'assets/img/productos/ETN-01.webp', $base);
verificar(!array_key_exists('costo', $pub) && !array_key_exists('stock', $pub), 'nunca muestra el costo ni las unidades');
verificar($pub['precio'] === 38000 && $pub['precio_texto'] === '$38.000', 'precio en número y en texto');
verificar(!array_key_exists('disponible', $pub), 'no dice si hay unidades: el panel no lleva el inventario');
verificar($pub['foto'] === 'https://ejemplo.test/assets/img/productos/ETN-01.webp', 'la foto va con la dirección completa');
verificar(api_producto_publico($catalogo[1], null, $base)['foto'] === null, 'sin foto: null');
verificar(api_producto_publico($catalogo[2], null, $base)['categoria'] === 'Crema', 'categoría vacía: se deduce del nombre');

verificar(api_url_base() === 'https://enrisate-admin-production.up.railway.app', 'la base sale del enlace oficial del catálogo, no de Host');

/* --- Respuesta completa --- */
$r = api_respuesta_catalogo($catalogo, ['q' => 'etniker', 'limite' => '1'], $base);
verificar($r['ok'] === true && $r['total'] === 2 && $r['mostrados'] === 1 && count($r['productos']) === 1,
    'total cuenta todas las coincidencias; mostrados respeta el límite');
verificar($r['promo']['minimo_unidades'] === PROMO_MINIMO_UNIDADES && $r['promo']['porcentaje'] === PROMO_PORCENTAJE,
    'trae la promoción vigente');
verificar($r['catalogo_url'] === APP_URL_CATALOGO, 'trae el enlace del catálogo');
verificar(!str_contains(json_encode($r), '21000'), 'el costo no aparece en ninguna parte de la respuesta');

echo "\n" . ($fallos === 0 ? "TODO OK" : "$fallos prueba(s) fallaron") . "\n";
exit($fallos === 0 ? 0 : 1);

<?php
declare(strict_types=1);

/* =========================================================
   ENRÍSATE · Categorías de producto
   =========================================================
   Una sola lista, usada en dos sitios:
     · inventario.php  → el desplegable para asignarla a mano
     · catalogo-publico.php → el ícono y el color de cada tarjeta

   La categoría vive en la columna `categoria` de la tabla productos
   (ver storage/actualizar-2026-08-23-categorias.sql). Cuando un producto
   todavía no la tiene asignada, cp_categoria_de() la deduce del nombre,
   igual que hacía el catálogo antes de que existiera la columna. Así la
   página nunca se queda sin nada que mostrar.
   ========================================================= */

/**
 * Categorías disponibles: nombre => [color de fondo, color de tinta, ícono].
 * Para agregar una nueva, añádela aquí y aparece sola en el desplegable
 * de Productos y en el catálogo. Los tres íconos disponibles son
 * 'gota', 'frasco' y 'cepillo'.
 */
function categorias(): array
{
    return [
        'Shampoo'        => ['#E4EFE8', '#2F6B4F', 'gota'],
        'Acondicionador' => ['#E3EDF4', '#2C5670', 'gota'],
        'Tratamiento'    => ['#F0E7F3', '#6B3F78', 'frasco'],
        'Crema'          => ['#FAEFE2', '#8A5A22', 'frasco'],
        'Definición'     => ['#F7E7ED', '#A83A5F', 'frasco'],
        'Aceite'         => ['#F5ECD9', '#7A5A15', 'gota'],
        'Mantequilla'    => ['#F7EFDF', '#7D5A1E', 'frasco'],
        'Reconstructor'  => ['#EFE7F1', '#63437A', 'frasco'],
        'Protector'      => ['#E9EEF2', '#3B5566', 'frasco'],
        'Spray'          => ['#E7EDF5', '#37527A', 'frasco'],
        'Cuidado'        => ['#E6EFEF', '#2F6068', 'frasco'],
        'Niños'          => ['#E6EEF3', '#2C5670', 'frasco'],
        'Kit'            => ['#EDEAE4', '#5A5049', 'frasco'],
        'Accesorio'      => ['#EAE7E2', '#5A5049', 'cepillo'],
    ];
}

/** ¿Es una categoría que conocemos? ('' también vale: significa "sin clasificar"). */
function categoria_valida(string $c): bool
{
    return $c === '' || array_key_exists($c, categorias());
}

/**
 * Deduce la categoría del nombre. Solo se usa como respaldo cuando el
 * producto todavía no tiene la columna `categoria` asignada.
 * El orden es el mismo que el del relleno inicial en el .sql, para que
 * los dos den siempre el mismo resultado.
 */
function categoria_deducida(string $nombre): string
{
    $n = mb_strtolower($nombre, 'UTF-8');
    $reglas = [
        'Shampoo'        => 'shampoo|champu|champú',
        'Acondicionador' => 'acondicionador',
        'Tratamiento'    => 'mascarilla|tratamiento|ampolla|keratina|botox',
        'Crema'          => 'crema',
        'Definición'     => 'gel|gelatina|espuma|mousse',
        'Aceite'         => 'aceite|oleo|óleo|serum|sérum|tonico|tónico|elixir',
        'Accesorio'      => 'cepillo|peine|secador|plancha|gorro|accesor',
        'Cuidado'        => 'jabon|jabón|desodorante',
        'Spray'          => 'spray|desenredante',
        'Protector'      => 'termoprotector|protector',
        'Mantequilla'    => 'mantequilla|manteca',
        'Reconstructor'  => 'plex|repolarizador|reconstructor',
        'Kit'            => '\bkit\b|duo|combo',
        'Niños'          => '\bkids\b|niños|ninos|infantil',
    ];
    foreach ($reglas as $categoria => $re) {
        if (preg_match('/' . $re . '/u', $n)) {
            return $categoria;
        }
    }
    return '';
}

/**
 * Categoría efectiva de un producto: la que tiene guardada, o la deducida
 * del nombre si todavía no tiene ninguna.
 */
function categoria_de(array $p): string
{
    $guardada = trim((string) ($p['categoria'] ?? ''));
    if ($guardada !== '' && categoria_valida($guardada)) {
        return $guardada;
    }
    return categoria_deducida((string) ($p['nombre'] ?? ''));
}

/**
 * Cómo pintar la tarjeta de un producto en el catálogo público.
 * Devuelve [etiqueta, color de fondo, color de tinta, ícono].
 * Si no hay categoría ni se pudo deducir, la etiqueta es la marca:
 * dice más que la palabra "Producto".
 */
function categoria_estilo(array $p, string $marcaVisible): array
{
    $cat   = categoria_de($p);
    $lista = categorias();
    if ($cat !== '' && isset($lista[$cat])) {
        [$fondo, $tinta, $icono] = $lista[$cat];
        return [$cat, $fondo, $tinta, $icono];
    }
    return [$marcaVisible, '#F1EAE2', '#6B4013', 'frasco'];
}

/** SVG del ícono de una categoría. */
function categoria_icono(string $clave): string
{
    $t = 'fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"';
    return match ($clave) {
        'gota'    => '<svg class="cp-tipo-icono" viewBox="0 0 24 24" ' . $t . ' aria-hidden="true"><path d="M12 3s6 6.4 6 10.5A6 6 0 0 1 6 13.5C6 9.4 12 3 12 3Z"/></svg>',
        'cepillo' => '<svg class="cp-tipo-icono" viewBox="0 0 24 24" ' . $t . ' aria-hidden="true"><rect x="9" y="3" width="6" height="10" rx="3"/><path d="M12 13v8"/><path d="M6 6h1M6 9h1M17 6h1M17 9h1"/></svg>',
        default   => '<svg class="cp-tipo-icono" viewBox="0 0 24 24" ' . $t . ' aria-hidden="true"><path d="M10 2.8h4v3.4l2 2.2a4 4 0 0 1 1 2.6v7a3 3 0 0 1-3 3h-4a3 3 0 0 1-3-3v-7a4 4 0 0 1 1-2.6l2-2.2V2.8Z"/><path d="M7 13.5h10"/></svg>',
    };
}

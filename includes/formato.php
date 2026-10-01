<?php
declare(strict_types=1);

/* =========================================================
   Formato y utilidades de texto · sin base de datos ni sesión
   =========================================================
   Todo lo que las páginas y el resto de los includes usan para mostrar
   o limpiar texto: el escape para plantillas, el teléfono en un formato
   único, pesos, fechas en español y los enlaces de WhatsApp. Antes vivían
   repartidos entre auth.php (e, normalizar_telefono) y facturas.php (el
   resto); aquí no se necesita nada más para usarlos, por eso este archivo
   no carga ningún otro.
   ========================================================= */

/* Escape corto para plantillas */
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/**
 * Deja el teléfono en un formato único: solo dígitos, SIN el indicativo de
 * país (57) adelante. Así, sin importar si alguien lo escribió con "+57",
 * con espacios, o solo el número, dos personas con el mismo celular quedan
 * guardadas exactamente igual — lo que hace falta para poder compararlos
 * (por ejemplo, para reconocer que quien pidió por el catálogo público ya
 * es una clienta guardada).
 *
 * Los celulares en Colombia tienen 10 dígitos (empiezan por 3). Si después
 * de quitar todo lo que no sea dígito quedan más de 10 y empieza por "57",
 * se asume que es el indicativo y se recorta.
 */
function normalizar_telefono(string $telefono): string
{
    $digitos = (string) preg_replace('/\D+/', '', $telefono);
    if (strlen($digitos) > 10 && str_starts_with($digitos, '57')) {
        $digitos = substr($digitos, 2);
    }
    return $digitos;
}

function esta_vencida(string $vence): bool
{
    return strtotime($vence) < strtotime('today');
}

/* --- Formato --- */
function pesos(float|int $n): string
{
    return '$' . number_format((float) $n, 0, ',', '.');
}

function numero(float|int $n): string
{
    return number_format((float) $n, 0, ',', '.');
}

function nombres_meses(): array
{
    return ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
            'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
}

function fecha_larga(string $iso): string
{
    $meses = nombres_meses();
    $t = strtotime($iso);
    return date('j', $t) . ' de ' . $meses[(int) date('n', $t)] . ' de ' . date('Y', $t);
}

/** "2026-07" → "julio de 2026" */
function mes_largo(string $ym): string
{
    $meses = nombres_meses();
    [$anio, $mes] = explode('-', $ym);
    return $meses[(int) $mes] . ' de ' . $anio;
}

/**
 * Rótulo en español de una cuota por su índice (0-based): "Primera cuota",
 * "Segunda cuota", ..., o "Cuota N" más allá de la cuarta. Si $totalCuotas
 * es 1 (un pago único a plazo, sin dividir), devuelve "Pago único" en vez
 * de "Primera cuota", que sonaría raro si no hay una segunda.
 */
function rotulo_cuota(int $idx, int $totalCuotas = 0): string
{
    if ($totalCuotas === 1) {
        return 'Pago único';
    }
    $rotulos = ['Primera cuota', 'Segunda cuota', 'Tercera cuota', 'Cuarta cuota'];
    return $rotulos[$idx] ?? ('Cuota ' . ($idx + 1));
}

/**
 * Arma el link de WhatsApp (wa.me) para un teléfono y un mensaje ya
 * redactado. Si el teléfono está vacío, devuelve un link sin número
 * (comportamiento existente). Se normaliza primero (quita el 57 si ya lo
 * traía) para no duplicar el indicativo — antes, un teléfono guardado con
 * "57" adelante producía un link roto.
 */
function enlace_whatsapp(string $telefono, string $mensaje): string
{
    $telefono = normalizar_telefono($telefono);
    return 'https://wa.me/' . ($telefono !== '' ? '57' . $telefono : '')
        . '?text=' . rawurlencode($mensaje);
}

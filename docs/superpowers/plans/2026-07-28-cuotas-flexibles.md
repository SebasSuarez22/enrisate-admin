# Cuotas flexibles (N cuotas + abonos parciales) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reemplazar el modelo rígido de 2 cuotas fijas (`cuota1`/`cuota2`) del panel Enrísate por una lista de 2 a 4 cuotas de monto libre, con soporte para abonos parciales dentro de cada cuota.

**Architecture:** Un solo campo `cuotas` (array de `{monto, vence, abonos: [{monto, fecha}]}`) reemplaza a los campos sueltos `cuota1`/`cuota2`/`vence1`/`vence2`/`cuota1_pagada`/`cuota2_pagada`. `includes/facturas.php` migra facturas viejas a este formato en memoria al leerlas (no reescribe el JSON). `facturar.php` produce el array nuevo al crear; `cartera.php` y `factura.php` lo consumen.

**Tech Stack:** PHP 8 plano (sin framework), JSON como almacenamiento (`storage/facturas.json`), JS vanilla en el navegador. Sin PHPUnit ni framework de pruebas — la verificación usa scripts PHP ejecutables por CLI y recorridos manuales en navegador.

**Spec:** `docs/superpowers/specs/2026-07-28-cuotas-flexibles-design.md`

## Global Constraints

- Cada archivo PHP mantiene `declare(strict_types=1);` en la primera línea.
- Todo POST valida `csrf_valido($_POST['csrf'] ?? null)` antes de procesar.
- Los montos siempre se acotan en el servidor con `max()`/`min()`; nunca se confía en valores enviados por el navegador.
- Tope de cuotas por factura: 2 a 4, validado en servidor (no solo en el `<select>` del formulario).
- Un abono parcial no genera comprobante numerado aparte — solo un mensaje de WhatsApp armado al momento.
- Las facturas existentes con el formato viejo (`cuota1`/`cuota2`) se migran solo en memoria al leerlas; `storage/facturas.json` no se reescribe por la migración.
- No hay framework de pruebas automatizadas en el proyecto. Las funciones de datos se verifican con scripts PHP en `tests/` ejecutados por CLI (`php tests/archivo.php`); las páginas completas (formularios, JS) se verifican manualmente en el navegador.

---

### Task 1: Funciones puras de cuotas (`includes/facturas.php`)

**Files:**
- Modify: `includes/facturas.php:183` (insertar después de `factura_por_numero()`, antes de `crear_factura()`)
- Create: `tests/cuotas_puras_test.php`

**Interfaces:**
- Produces: `cuota_pagado(array $cuota): int`, `cuota_pendiente(array $cuota): int`, `cuota_esta_pagada(array $cuota): bool`, `normalizar_cuotas_factura(array $f): array`. Todas puras (no tocan disco).

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/cuotas_puras_test.php`:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/facturas.php';

$fallos = 0;
function verificar(bool $cond, string $msg): void
{
    if ($cond) {
        echo "OK   - $msg\n";
    } else {
        echo "FAIL - $msg\n";
        $GLOBALS['fallos']++;
    }
}

/* --- cuota_pagado / cuota_pendiente / cuota_esta_pagada --- */

verificar(cuota_pagado(['monto' => 100, 'abonos' => []]) === 0,
    'cuota sin abonos: pagado = 0');

verificar(cuota_pagado(['monto' => 100, 'abonos' => [
    ['monto' => 30, 'fecha' => 'x'], ['monto' => 20, 'fecha' => 'y'],
]]) === 50, 'cuota con 2 abonos: pagado = suma de los abonos');

verificar(cuota_pendiente(['monto' => 100, 'abonos' => [
    ['monto' => 30, 'fecha' => 'x'],
]]) === 70, 'cuota con abono parcial: pendiente = monto - pagado');

verificar(cuota_pendiente(['monto' => 100, 'abonos' => [
    ['monto' => 150, 'fecha' => 'x'],
]]) === 0, 'pendiente nunca es negativo');

verificar(cuota_esta_pagada(['monto' => 100, 'abonos' => [
    ['monto' => 100, 'fecha' => 'x'],
]]) === true, 'cuota cubierta por completo: pagada');

verificar(cuota_esta_pagada(['monto' => 100, 'abonos' => [
    ['monto' => 60, 'fecha' => 'x'],
]]) === false, 'cuota con saldo pendiente: no pagada');

/* --- normalizar_cuotas_factura --- */

$vieja = [
    'plan'             => 'cuotas',
    'creada'           => '2024-01-01T00:00:00-05:00',
    'cuota1'           => 100000,
    'cuota2'           => 50000,
    'vence1'           => '2024-01-15',
    'vence2'           => '2024-02-15',
    'cuota1_pagada'    => true,
    'cuota1_pagada_el' => '2024-01-16T00:00:00-05:00',
    'cuota2_pagada'    => false,
];
$migrada = normalizar_cuotas_factura($vieja);

verificar(count($migrada['cuotas']) === 2,
    'migración: crea 2 cuotas desde el formato viejo');
verificar($migrada['cuotas'][0]['monto'] === 100000,
    'migración: monto de la cuota 1 correcto');
verificar($migrada['cuotas'][0]['vence'] === '2024-01-15',
    'migración: vencimiento de la cuota 1 correcto');
verificar(cuota_esta_pagada($migrada['cuotas'][0]) === true,
    'migración: cuota 1 pagada se traduce en un abono que la cubre');
verificar(cuota_esta_pagada($migrada['cuotas'][1]) === false,
    'migración: cuota 2 no pagada queda sin abonos');

$contado = ['plan' => 'contado'];
verificar(normalizar_cuotas_factura($contado) === $contado,
    'migración: una factura de contado no se toca');

$yaNueva = ['plan' => 'cuotas', 'cuotas' => [['monto' => 1, 'vence' => 'x', 'abonos' => []]]];
verificar(normalizar_cuotas_factura($yaNueva) === $yaNueva,
    'migración: una factura que ya tiene "cuotas" no se toca');

echo "\n" . ($fallos === 0 ? "TODO OK" : "$fallos prueba(s) fallaron") . "\n";
exit($fallos === 0 ? 0 : 1);
```

- [ ] **Step 2: Ejecutar el test y confirmar que falla**

Run: `php tests/cuotas_puras_test.php`
Expected: `PHP Fatal error:  Uncaught Error: Call to undefined function cuota_pagado()` (las funciones todavía no existen).

- [ ] **Step 3: Implementar las funciones**

En `includes/facturas.php`, insertar el siguiente bloque inmediatamente después de la función `factura_por_numero()` (línea 183) y antes de `crear_factura()`:

```php
/** Suma de los abonos registrados contra una cuota. */
function cuota_pagado(array $cuota): int
{
    $total = 0;
    foreach ($cuota['abonos'] ?? [] as $a) {
        $total += (int) ($a['monto'] ?? 0);
    }
    return $total;
}

/** Lo que falta por pagar de una cuota (nunca negativo). */
function cuota_pendiente(array $cuota): int
{
    return max(0, (int) ($cuota['monto'] ?? 0) - cuota_pagado($cuota));
}

function cuota_esta_pagada(array $cuota): bool
{
    return cuota_pendiente($cuota) <= 0;
}

/**
 * Garantiza que una factura a cuotas tenga el array `cuotas` (formato
 * nuevo). Si viene en el formato viejo (cuota1/cuota2 sueltos), lo
 * traduce en memoria; no reescribe el archivo. Facturas de contado, o que
 * ya tienen `cuotas`, se devuelven sin tocar.
 */
function normalizar_cuotas_factura(array $f): array
{
    if (($f['plan'] ?? '') !== 'cuotas' || isset($f['cuotas'])) {
        return $f;
    }

    $cuotas = [];
    foreach ([1, 2] as $i) {
        $monto = (int) ($f["cuota{$i}"] ?? 0);
        if ($monto <= 0) {
            continue;
        }
        $abonos = [];
        if (!empty($f["cuota{$i}_pagada"])) {
            $abonos[] = [
                'monto' => $monto,
                'fecha' => $f["cuota{$i}_pagada_el"] ?? ($f['creada'] ?? date('c')),
            ];
        }
        $cuotas[] = [
            'monto'  => $monto,
            'vence'  => $f["vence{$i}"] ?? date('Y-m-d'),
            'abonos' => $abonos,
        ];
    }
    $f['cuotas'] = $cuotas;

    return $f;
}
```

- [ ] **Step 4: Ejecutar el test y confirmar que pasa**

Run: `php tests/cuotas_puras_test.php`
Expected: todas las líneas `OK`, termina con `TODO OK` y código de salida 0.

- [ ] **Step 5: Verificar sintaxis y commitear**

```bash
php -l includes/facturas.php
git add includes/facturas.php tests/cuotas_puras_test.php
git commit -m "Agregar funciones puras de cuotas (pagado/pendiente/migración)"
```

---

### Task 2: Migración en `leer_facturas()`, `registrar_abono()` y `metricas_panel()` (`includes/facturas.php`)

**Files:**
- Modify: `includes/facturas.php:144-151` (`leer_facturas()`)
- Modify: `includes/facturas.php:219-240` (eliminar `marcar_cuota_pagada()`, agregar `total_pagado_cuotas()` y `registrar_abono()`)
- Modify: `includes/facturas.php:284-332` (`metricas_panel()`)
- Create: `tests/facturas_cuotas_test.php`

**Interfaces:**
- Consumes: `cuota_pagado()`, `cuota_pendiente()`, `cuota_esta_pagada()`, `normalizar_cuotas_factura()` (Task 1).
- Produces: `total_pagado_cuotas(array $f): int`, `registrar_abono(string $numero, int $indiceCuota, int $monto): bool`. `leer_facturas()` devuelve siempre facturas con `cuotas` normalizado cuando `plan === 'cuotas'`. Elimina `marcar_cuota_pagada()` — ya no existe, cualquier llamador debe usar `registrar_abono()`.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/facturas_cuotas_test.php`. Este test es de integración: usa el `storage/facturas.json` real, pero respalda su contenido antes y lo restaura al final (en un `finally`), así que nunca deja datos de prueba en el archivo:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/facturas.php';

$fallos = 0;
function verificar(bool $cond, string $msg): void
{
    if ($cond) {
        echo "OK   - $msg\n";
    } else {
        echo "FAIL - $msg\n";
        $GLOBALS['fallos']++;
    }
}

$ruta     = ruta_facturas();
$respaldo = is_file($ruta) ? file_get_contents($ruta) : null;

try {
    $numero = crear_factura([
        'cliente' => '__TEST__', 'telefono' => '', 'nota' => '', 'lineas' => [],
        'subtotal' => 150000, 'descuento' => 0, 'total' => 150000, 'costo' => 0, 'margen' => 150000,
        'plan' => 'cuotas', 'abono' => 0, 'saldo_aplicado' => 0,
        'cuotas' => [
            ['monto' => 100000, 'vence' => '2026-01-01', 'abonos' => []],
            ['monto' => 50000,  'vence' => '2026-02-01', 'abonos' => []],
        ],
        'pagado' => 0, 'fecha_pago' => null,
    ]);

    verificar(registrar_abono($numero, 0, 40000) === true,
        'registrar_abono: abono parcial válido se acepta');

    $f = factura_por_numero($numero);
    verificar(cuota_pendiente($f['cuotas'][0]) === 60000,
        'registrar_abono: el pendiente baja con el abono');
    verificar(cuota_esta_pagada($f['cuotas'][0]) === false,
        'registrar_abono: cuota con abono parcial sigue pendiente');
    verificar($f['pagado'] === 40000,
        'registrar_abono: el campo "pagado" de la factura se actualiza');

    verificar(registrar_abono($numero, 0, 999999) === false,
        'registrar_abono: rechaza un monto mayor al pendiente (no lo recorta)');
    verificar(registrar_abono($numero, 5, 1000) === false,
        'registrar_abono: rechaza un índice de cuota que no existe');
    verificar(registrar_abono($numero, 0, 0) === false,
        'registrar_abono: rechaza un monto de cero');

    verificar(registrar_abono($numero, 0, 60000) === true,
        'registrar_abono: completa el pendiente de la cuota');
    $f = factura_por_numero($numero);
    verificar(cuota_esta_pagada($f['cuotas'][0]) === true,
        'registrar_abono: la cuota queda pagada al completar el pendiente');

    verificar(anular_factura($numero) === true, 'setup: se anula la factura de prueba');
    verificar(registrar_abono($numero, 1, 1000) === false,
        'registrar_abono: rechaza abonos sobre facturas anuladas');

    $metricas = metricas_panel();
    verificar(is_int($metricas['porCobrar']),
        'metricas_panel: sigue devolviendo porCobrar como entero, sin fatal error');
} finally {
    if ($respaldo === null) {
        @unlink($ruta);
    } else {
        file_put_contents($ruta, $respaldo, LOCK_EX);
    }
}

echo "\n" . ($fallos === 0 ? "TODO OK" : "$fallos prueba(s) fallaron") . "\n";
exit($fallos === 0 ? 0 : 1);
```

- [ ] **Step 2: Ejecutar el test y confirmar que falla**

Run: `php tests/facturas_cuotas_test.php`
Expected: `PHP Fatal error:  Uncaught Error: Call to undefined function registrar_abono()`.

- [ ] **Step 3: Migrar en `leer_facturas()`**

Reemplazar (línea 144-151):

```php
function leer_facturas(): array
{
    if (!is_file(ruta_facturas())) {
        return [];
    }
    $d = json_decode((string) file_get_contents(ruta_facturas()), true);
    return is_array($d) ? $d : [];
}
```

por:

```php
function leer_facturas(): array
{
    if (!is_file(ruta_facturas())) {
        return [];
    }
    $d = json_decode((string) file_get_contents(ruta_facturas()), true);
    if (!is_array($d)) {
        return [];
    }
    return array_map('normalizar_cuotas_factura', $d);
}
```

- [ ] **Step 4: Reemplazar `marcar_cuota_pagada()` por `total_pagado_cuotas()` y `registrar_abono()`**

Eliminar por completo la función `marcar_cuota_pagada()` (línea 219-240):

```php
/** Marca una cuota (1 o 2) de una factura a plazos como pagada. */
function marcar_cuota_pagada(string $numero, int $cuota): bool
{
    $f = factura_por_numero($numero);
    if (!$f || $f['plan'] !== 'cuotas' || ($cuota !== 1 && $cuota !== 2)) {
        return false;
    }
    $campo = 'cuota' . $cuota . '_pagada';
    if (!empty($f[$campo])) {
        return false;
    }

    $cuota1Pagada = $cuota === 1 ? true : !empty($f['cuota1_pagada']);
    $cuota2Pagada = $cuota === 2 ? true : !empty($f['cuota2_pagada']);

    return actualizar_factura($numero, [
        $campo               => true,
        'cuota' . $cuota . '_pagada_el' => date('c'),
        'pagado'             => (int) ($f['abono'] ?? 0)
            + ($cuota1Pagada ? $f['cuota1'] : 0) + ($cuota2Pagada ? $f['cuota2'] : 0),
    ]);
}
```

y reemplazarla por:

```php
/** Total realmente cobrado de una factura a cuotas: saldo a favor aplicado + abono inicial + todos los abonos de todas las cuotas. */
function total_pagado_cuotas(array $f): int
{
    $total = (int) ($f['saldo_aplicado'] ?? 0) + (int) ($f['abono'] ?? 0);
    foreach ($f['cuotas'] ?? [] as $c) {
        $total += cuota_pagado($c);
    }
    return $total;
}

/**
 * Registra un abono de monto libre contra una cuota específica de una
 * factura a plazos. Rechaza (no recorta) montos mayores al pendiente,
 * índices de cuota inexistentes, montos de cero o facturas anuladas.
 * Devuelve true si quedó registrado.
 */
function registrar_abono(string $numero, int $indiceCuota, int $monto): bool
{
    $f = factura_por_numero($numero);
    if (!$f || !empty($f['anulada']) || ($f['plan'] ?? '') !== 'cuotas') {
        return false;
    }
    if (!isset($f['cuotas'][$indiceCuota])) {
        return false;
    }
    if ($monto <= 0) {
        return false;
    }

    $pendiente = cuota_pendiente($f['cuotas'][$indiceCuota]);
    if ($monto > $pendiente) {
        return false;
    }

    $f['cuotas'][$indiceCuota]['abonos'][] = [
        'monto' => $monto,
        'fecha' => date('c'),
    ];

    return actualizar_factura($numero, [
        'cuotas' => $f['cuotas'],
        'pagado' => total_pagado_cuotas($f),
    ]);
}
```

- [ ] **Step 5: Generalizar `metricas_panel()`**

Dentro de `metricas_panel()`, reemplazar el bloque (línea 311-325):

```php
        foreach ([1, 2] as $i) {
            if (!empty($f["cuota{$i}_pagada"])) {
                continue;
            }
            $monto = (int) ($f["cuota{$i}"] ?? 0);
            if ($monto <= 0) {
                continue;
            }
            $porCobrar += $monto;
            $cuotasPendientes++;
            if (esta_vencida((string) ($f["vence{$i}"] ?? ''))) {
                $vencido += $monto;
                $cuotasVencidas++;
            }
        }
```

por:

```php
        foreach ($f['cuotas'] ?? [] as $cuota) {
            $pendiente = cuota_pendiente($cuota);
            if ($pendiente <= 0) {
                continue;
            }
            $porCobrar += $pendiente;
            $cuotasPendientes++;
            if (esta_vencida((string) ($cuota['vence'] ?? ''))) {
                $vencido += $pendiente;
                $cuotasVencidas++;
            }
        }
```

(El resto de la función, incluyendo el `if ((($f['plan'] ?? '') !== 'cuotas') { continue; }` justo antes de este bloque, no cambia.)

- [ ] **Step 6: Ejecutar el test y confirmar que pasa**

Run: `php tests/facturas_cuotas_test.php`
Expected: todas las líneas `OK`, termina con `TODO OK` y código de salida 0. Confirmar además que `storage/facturas.json` quedó igual que antes de correr el test (por ejemplo con `git diff --stat storage/facturas.json`, no debe mostrar cambios).

- [ ] **Step 7: Verificar sintaxis y commitear**

```bash
php -l includes/facturas.php
git add includes/facturas.php tests/facturas_cuotas_test.php
git commit -m "Migrar facturas viejas en memoria y reemplazar marcar_cuota_pagada por registrar_abono"
```

---

### Task 3: Creación de facturas con N cuotas (`facturar.php`)

**Files:**
- Modify: `facturar.php:61-125` (lógica de servidor: reparto en cuotas y `crear_factura()`)
- Modify: `facturar.php:248-289` (formulario: selector de cuotas)
- Modify: `facturar.php:459-508` (JS: cálculo y filas dinámicas de cuotas)

**Interfaces:**
- Consumes: ninguna función de Task 1/2 (arma `cuotas` con `abonos` vacíos al crear).
- Produces: `facturar.php` guarda facturas con `'cuotas' => [['monto' => int, 'vence' => 'Y-m-d', 'abonos' => []], ...]` (1 a 4 elementos) en vez de `cuota1`/`cuota2`/`vence1`/`vence2`/`cuota1_pagada`/`cuota2_pagada`.

- [ ] **Step 1: Reemplazar la lógica de servidor que reparte el total en cuotas**

Reemplazar el bloque (línea 61-98):

```php
                $abono     = 0;
                $cuota1    = $total;
                $cuota2    = 0;
                $vence1    = null;
                $vence2    = null;
                $fechaPago = null;

                if ($plan === 'cuotas' && $total > 1) {
                    // Abono en efectivo + saldo a favor son plata ya cubierta contra el
                    // precio completo — no son descuento, no tocan $total ni $margen.
                    // Solo reducen lo que queda por repartir en cuotas.
                    $abono = max(0, min($total - $saldoAplicado, (int) ($_POST['abono'] ?? 0)));
                    $resto = $total - $saldoAplicado - $abono;

                    $cuota1 = $resto;
                    $cuota2 = 0;
                    if ($resto > 1) {
                        $cuota1 = (int) ($_POST['cuota1'] ?? 0);
                        $cuota1 = max(1, min($resto - 1, $cuota1));
                        $cuota2 = $resto - $cuota1;
                    }

                    $vence1 = trim((string) ($_POST['vence1'] ?? ''));
                    if ($vence1 === '') {
                        $vence1 = date('Y-m-d');
                    }
                    $vence2 = trim((string) ($_POST['vence2'] ?? ''));
                    if ($vence2 === '') {
                        $vence2 = date('Y-m-d', strtotime('+30 days'));
                    }
                } else {
                    $plan = 'contado';

                    $fechaPago = trim((string) ($_POST['fecha_pago'] ?? ''));
                    if ($fechaPago === '') {
                        $fechaPago = date('Y-m-d');
                    }
                }
```

por:

```php
                $abono     = 0;
                $cuotas    = [];
                $fechaPago = null;

                if ($plan === 'cuotas' && $total > 1) {
                    // Abono en efectivo + saldo a favor son plata ya cubierta contra el
                    // precio completo — no son descuento, no tocan $total ni $margen.
                    // Solo reducen lo que queda por repartir en cuotas.
                    $abono = max(0, min($total - $saldoAplicado, (int) ($_POST['abono'] ?? 0)));
                    $resto = $total - $saldoAplicado - $abono;

                    if ($resto <= 1) {
                        $cuotas[] = [
                            'monto'  => max(0, $resto),
                            'vence'  => date('Y-m-d'),
                            'abonos' => [],
                        ];
                    } else {
                        $numCuotas    = max(2, min(4, (int) ($_POST['num_cuotas'] ?? 2)));
                        $montosPost   = is_array($_POST['cuota_monto'] ?? null) ? $_POST['cuota_monto'] : [];
                        $vencesPost   = is_array($_POST['cuota_vence'] ?? null) ? $_POST['cuota_vence'] : [];
                        $sumaAnterior = 0;

                        for ($i = 0; $i < $numCuotas; $i++) {
                            $vence = trim((string) ($vencesPost[$i] ?? ''));
                            if ($vence === '') {
                                $vence = date('Y-m-d', strtotime('+' . (30 * $i) . ' days'));
                            }

                            if ($i === $numCuotas - 1) {
                                // La última cuota es siempre el resto exacto: nunca se
                                // confía en lo que mande el navegador, para que la suma
                                // cuadre con el total.
                                $monto = $resto - $sumaAnterior;
                            } else {
                                $restantesDespues = $numCuotas - 1 - $i;
                                $monto = (int) ($montosPost[$i] ?? 0);
                                $monto = max(1, min($resto - $sumaAnterior - $restantesDespues, $monto));
                            }

                            $cuotas[] = ['monto' => $monto, 'vence' => $vence, 'abonos' => []];
                            $sumaAnterior += $monto;
                        }
                    }
                } else {
                    $plan = 'contado';

                    $fechaPago = trim((string) ($_POST['fecha_pago'] ?? ''));
                    if ($fechaPago === '') {
                        $fechaPago = date('Y-m-d');
                    }
                }
```

- [ ] **Step 2: Actualizar la llamada a `crear_factura()`**

Reemplazar (línea 104-125):

```php
                $numero = crear_factura([
                    'cliente'        => $cliente,
                    'telefono'       => $telefono,
                    'nota'           => trim((string) ($_POST['nota'] ?? '')),
                    'lineas'         => $lineas,
                    'subtotal'       => $subtotal,
                    'descuento'      => $descuento,
                    'total'          => $total,
                    'costo'          => $costo,          // interno, nunca se imprime
                    'margen'         => $total - $costo, // interno, no lo afecta el abono ni el saldo
                    'plan'           => $plan,
                    'abono'          => $abono,
                    'saldo_aplicado' => $saldoAplicado,
                    'cuota1'         => $cuota1,
                    'cuota2'         => $cuota2,
                    'vence1'         => $vence1,
                    'vence2'         => $vence2,
                    'cuota1_pagada'  => $plan === 'contado',
                    'cuota2_pagada'  => $plan === 'contado',
                    'pagado'         => $pagado,
                    'fecha_pago'     => $fechaPago,
                ]);
```

por:

```php
                $numero = crear_factura([
                    'cliente'        => $cliente,
                    'telefono'       => $telefono,
                    'nota'           => trim((string) ($_POST['nota'] ?? '')),
                    'lineas'         => $lineas,
                    'subtotal'       => $subtotal,
                    'descuento'      => $descuento,
                    'total'          => $total,
                    'costo'          => $costo,          // interno, nunca se imprime
                    'margen'         => $total - $costo, // interno, no lo afecta el abono ni el saldo
                    'plan'           => $plan,
                    'abono'          => $abono,
                    'saldo_aplicado' => $saldoAplicado,
                    'cuotas'         => $cuotas,
                    'pagado'         => $pagado,
                    'fecha_pago'     => $fechaPago,
                ]);
```

- [ ] **Step 3: Verificar sintaxis del backend**

Run: `php -l facturar.php`
Expected: `No syntax errors detected in facturar.php`

- [ ] **Step 4: Actualizar el formulario HTML**

Reemplazar el bloque (línea 248-289):

```html
        <div class="opciones">
          <label class="opcion">
            <input type="radio" name="plan" value="contado" checked>
            <span>De contado</span>
          </label>
          <label class="opcion">
            <input type="radio" name="plan" value="cuotas">
            <span>En 2 cuotas</span>
          </label>
        </div>

        <div id="bloqueContado">
          <div class="campo">
            <label for="fecha_pago">Fecha de pago</label>
            <input type="date" id="fecha_pago" name="fecha_pago" value="<?= date('Y-m-d') ?>">
          </div>
        </div>

        <div id="bloqueCuotas" hidden>
          <div class="campo">
            <label for="abono">Abono recibido</label>
            <input type="number" id="abono" name="abono" min="0" step="500" value="0">
            <p class="pista">Lo que la clienta ya te pagó. No es descuento: no baja la ganancia.</p>
          </div>
          <div class="campo">
            <label for="cuota1">Primera cuota</label>
            <input type="number" id="cuota1" name="cuota1" min="0" step="500" value="0">
          </div>
          <div class="campo">
            <label for="vence1">Fecha de la primera cuota</label>
            <input type="date" id="vence1" name="vence1"
                   value="<?= date('Y-m-d') ?>">
          </div>
          <p class="pista">Segunda cuota: <strong id="verCuota2">$0</strong></p>
          <div class="campo">
            <label for="vence2">Fecha de la segunda cuota</label>
            <input type="date" id="vence2" name="vence2"
                   value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
          </div>
        </div>
```

por:

```html
        <div class="opciones">
          <label class="opcion">
            <input type="radio" name="plan" value="contado" checked>
            <span>De contado</span>
          </label>
          <label class="opcion">
            <input type="radio" name="plan" value="cuotas">
            <span>A cuotas</span>
          </label>
        </div>

        <div id="bloqueContado">
          <div class="campo">
            <label for="fecha_pago">Fecha de pago</label>
            <input type="date" id="fecha_pago" name="fecha_pago" value="<?= date('Y-m-d') ?>">
          </div>
        </div>

        <div id="bloqueCuotas" hidden>
          <div class="campo">
            <label for="abono">Abono recibido</label>
            <input type="number" id="abono" name="abono" min="0" step="500" value="0">
            <p class="pista">Lo que la clienta ya te pagó. No es descuento: no baja la ganancia.</p>
          </div>
          <div class="campo">
            <label for="numCuotas">Número de cuotas</label>
            <select id="numCuotas" name="num_cuotas">
              <option value="2">2 cuotas</option>
              <option value="3">3 cuotas</option>
              <option value="4">4 cuotas</option>
            </select>
          </div>
          <div id="filasCuotas"></div>
        </div>
```

- [ ] **Step 5: Reemplazar el JS de cálculo por la versión con N cuotas**

Reemplazar el bloque (línea 459-508):

```js
function calcular(editandoCuota1) {
  const sub    = items.reduce((s, i) => s + i.precio * i.cant, 0);
  const costo  = items.reduce((s, i) => s + i.costo  * i.cant, 0);
  const desc   = Math.max(0, Math.min(sub, parseInt(document.getElementById('descuento').value, 10) || 0));
  const total  = sub - desc;

  document.getElementById('verSub').textContent    = cop(sub);
  document.getElementById('verDesc').textContent   = cop(desc);
  document.getElementById('verTotal').textContent  = cop(total);
  document.getElementById('verMargen').textContent = cop(total - costo);
  document.getElementById('items').value = JSON.stringify(items.map(i => ({ sku: i.sku, cant: i.cant })));

  // Saldo a favor de la clienta (si tiene): cubre parte del total en ambos planes.
  const saldoI     = document.getElementById('saldoAplicado');
  const disponible = parseInt(saldoI.dataset.disponible, 10) || 0;
  const saldo      = Math.max(0, Math.min(disponible, total, parseInt(saldoI.value, 10) || 0));

  const esCuotas = document.querySelector('input[name="plan"]:checked').value === 'cuotas';
  const abonoI   = document.getElementById('abono');
  const abono    = esCuotas ? Math.max(0, Math.min(total - saldo, parseInt(abonoI.value, 10) || 0)) : 0;
  const resto    = total - saldo - abono;

  document.getElementById('filaSaldo').hidden = saldo <= 0;
  document.getElementById('verSaldo').textContent = cop(saldo);
  document.getElementById('filaAbono').hidden = !esCuotas || abono <= 0;
  document.getElementById('verAbono').textContent = cop(abono);
  document.getElementById('filaResto').hidden = !esCuotas || (saldo <= 0 && abono <= 0);
  document.getElementById('verResto').textContent = cop(resto);

  const c1i = document.getElementById('cuota1');
  c1i.max = Math.max(1, resto - 1);
  if (!editandoCuota1 && (!+c1i.value || +c1i.value > resto)) {
    c1i.value = Math.round(resto / 2 / 500) * 500;
  }
  const c1 = Math.max(0, Math.min(resto, +c1i.value || 0));
  document.getElementById('verCuota2').textContent = cop(resto - c1);
}

document.getElementById('descuento').addEventListener('input', () => calcular(false));
document.getElementById('abono').addEventListener('input', () => calcular(false));
document.getElementById('saldoAplicado').addEventListener('input', () => calcular(false));
document.getElementById('cuota1').addEventListener('input', () => calcular(true));

document.querySelectorAll('input[name="plan"]').forEach(r =>
  r.addEventListener('change', e => {
    document.getElementById('bloqueCuotas').hidden  = (e.target.value !== 'cuotas');
    document.getElementById('bloqueContado').hidden = (e.target.value !== 'contado');
    calcular();
  })
);
```

por:

```js
function calcular() {
  const sub    = items.reduce((s, i) => s + i.precio * i.cant, 0);
  const costo  = items.reduce((s, i) => s + i.costo  * i.cant, 0);
  const desc   = Math.max(0, Math.min(sub, parseInt(document.getElementById('descuento').value, 10) || 0));
  const total  = sub - desc;

  document.getElementById('verSub').textContent    = cop(sub);
  document.getElementById('verDesc').textContent   = cop(desc);
  document.getElementById('verTotal').textContent  = cop(total);
  document.getElementById('verMargen').textContent = cop(total - costo);
  document.getElementById('items').value = JSON.stringify(items.map(i => ({ sku: i.sku, cant: i.cant })));

  // Saldo a favor de la clienta (si tiene): cubre parte del total en ambos planes.
  const saldoI     = document.getElementById('saldoAplicado');
  const disponible = parseInt(saldoI.dataset.disponible, 10) || 0;
  const saldo      = Math.max(0, Math.min(disponible, total, parseInt(saldoI.value, 10) || 0));

  const esCuotas = document.querySelector('input[name="plan"]:checked').value === 'cuotas';
  const abonoI   = document.getElementById('abono');
  const abono    = esCuotas ? Math.max(0, Math.min(total - saldo, parseInt(abonoI.value, 10) || 0)) : 0;
  const resto    = total - saldo - abono;

  document.getElementById('filaSaldo').hidden = saldo <= 0;
  document.getElementById('verSaldo').textContent = cop(saldo);
  document.getElementById('filaAbono').hidden = !esCuotas || abono <= 0;
  document.getElementById('verAbono').textContent = cop(abono);
  document.getElementById('filaResto').hidden = !esCuotas || (saldo <= 0 && abono <= 0);
  document.getElementById('verResto').textContent = cop(resto);

  if (esCuotas) {
    const montoInputs = [...filasCuotas.querySelectorAll('.montoCuota')];
    const n = montoInputs.length + 1; // cuotas libres + la última calculada
    let sumaLibres = 0;
    montoInputs.forEach((input, i) => {
      const restantesDespues = n - 1 - i;
      const tope = Math.max(1, resto - sumaLibres - restantesDespues);
      input.max = tope;
      if (!+input.value) {
        input.value = Math.max(0, Math.round((resto / n) / 500) * 500);
      }
      const v = Math.max(0, Math.min(tope, +input.value || 0));
      sumaLibres += v;
    });
    const verUltima = document.getElementById('verUltimaCuota');
    if (verUltima) verUltima.textContent = cop(Math.max(0, resto - sumaLibres));
  }
}

document.getElementById('descuento').addEventListener('input', calcular);
document.getElementById('abono').addEventListener('input', calcular);
document.getElementById('saldoAplicado').addEventListener('input', calcular);

const filasCuotas  = document.getElementById('filasCuotas');
const numCuotasSel = document.getElementById('numCuotas');

function sumarDias(dias) {
  const d = new Date();
  d.setDate(d.getDate() + dias);
  return d.toISOString().slice(0, 10);
}

function pintarFilasCuotas() {
  const n = parseInt(numCuotasSel.value, 10);
  let html = '';
  for (let i = 0; i < n - 1; i++) {
    html +=
      '<div class="campo">' +
        '<label>Cuota ' + (i + 1) + '</label>' +
        '<input type="number" name="cuota_monto[]" min="0" step="500" value="0" class="montoCuota">' +
      '</div>' +
      '<div class="campo">' +
        '<label>Fecha de la cuota ' + (i + 1) + '</label>' +
        '<input type="date" name="cuota_vence[]" class="venceCuota" value="' + sumarDias(30 * i) + '">' +
      '</div>';
  }
  html +=
    '<p class="pista">Cuota ' + n + ' (última): <strong id="verUltimaCuota">$0</strong></p>' +
    '<div class="campo">' +
      '<label>Fecha de la cuota ' + n + '</label>' +
      '<input type="date" name="cuota_vence[]" class="venceCuota" value="' + sumarDias(30 * (n - 1)) + '">' +
    '</div>';
  filasCuotas.innerHTML = html;
  filasCuotas.querySelectorAll('.montoCuota').forEach(i => i.addEventListener('input', calcular));
  calcular();
}

numCuotasSel.addEventListener('change', pintarFilasCuotas);

document.querySelectorAll('input[name="plan"]').forEach(r =>
  r.addEventListener('change', e => {
    document.getElementById('bloqueCuotas').hidden  = (e.target.value !== 'cuotas');
    document.getElementById('bloqueContado').hidden = (e.target.value !== 'contado');
    if (e.target.value === 'cuotas' && !filasCuotas.children.length) pintarFilasCuotas();
    calcular();
  })
);
```

- [ ] **Step 6: Verificar sintaxis**

Run: `php -l facturar.php`
Expected: `No syntax errors detected in facturar.php`

- [ ] **Step 7: Verificación manual en navegador**

1. Abrir `facturar.php`, agregar al menos un producto.
2. Elegir "A cuotas" → confirmar que aparece el selector "Número de cuotas" con 2 filas de monto/fecha por defecto (2 cuotas) y el texto "Cuota 2 (última): $X".
3. Cambiar a 3 y luego a 4 cuotas → confirmar que aparecen más filas editables y la última siempre se recalcula sola.
4. Escribir un monto libre en la primera cuota (ej. distinto a la mitad) → confirmar que "Cuota N (última)" se recalcula en tiempo real.
5. Poner un "Abono recibido" y confirmar que el resto a repartir baja.
6. Enviar el formulario y confirmar que redirige a `factura.php` sin errores (la vista de esa página se revisa en la Task 5, puede verse "rota" temporalmente hasta esa tarea).

- [ ] **Step 8: Commitear**

```bash
git add facturar.php
git commit -m "Soportar de 2 a 4 cuotas de monto libre al crear la factura"
```

---

### Task 4: Cobro de abonos parciales (`cartera.php`)

**Files:**
- Modify: `cartera.php:10-34` (manejo de POST)
- Modify: `cartera.php:36-42` (mensajes de confirmación + WhatsApp del abono)
- Modify: `cartera.php:49-61` (lista de cuotas pendientes)
- Modify: `cartera.php:159-161` (bloque de aviso: agregar botón de WhatsApp del abono)
- Modify: `cartera.php:180-202` (tabla de cartera pendiente: campo de monto + botón)
- Modify: `cartera.php:235-253` (estado del historial)

**Interfaces:**
- Consumes: `cuota_pendiente()`, `cuota_esta_pagada()`, `registrar_abono()` (Task 1/2); lee `$f['cuotas']` (ya normalizado por `leer_facturas()`).
- Produces: el formulario de registrar abono en `cartera.php` ahora envía `numero`, `cuota_idx` (índice 0-based) y `monto` en vez de `numero` y `cuota` (1/2).

- [ ] **Step 1: Reemplazar el manejo de POST**

Reemplazar el bloque (línea 10-34):

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'La página estuvo abierta demasiado tiempo. Recárgala e intenta de nuevo.';
    } elseif (($_POST['accion'] ?? '') === 'anular') {
        if (anular_factura((string) ($_POST['numero'] ?? ''), (string) ($_POST['motivo'] ?? ''))) {
            header('Location: cartera.php?ok=anulada');
            exit;
        }
        $error = 'No pude anular esa factura.';
    } elseif (($_POST['accion'] ?? '') === 'reactivar') {
        if (reactivar_factura((string) ($_POST['numero'] ?? ''))) {
            header('Location: cartera.php?ok=reactivada');
            exit;
        }
        $error = 'No pude reactivar esa factura.';
    } else {
        $numero = (string) ($_POST['numero'] ?? '');
        $cuota  = (int) ($_POST['cuota'] ?? 0);
        if (marcar_cuota_pagada($numero, $cuota)) {
            header('Location: cartera.php?ok=pagada');
            exit;
        }
        $error = 'No pude marcar esa cuota como pagada.';
    }
}
```

por:

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'La página estuvo abierta demasiado tiempo. Recárgala e intenta de nuevo.';
    } elseif (($_POST['accion'] ?? '') === 'anular') {
        if (anular_factura((string) ($_POST['numero'] ?? ''), (string) ($_POST['motivo'] ?? ''))) {
            header('Location: cartera.php?ok=anulada');
            exit;
        }
        $error = 'No pude anular esa factura.';
    } elseif (($_POST['accion'] ?? '') === 'reactivar') {
        if (reactivar_factura((string) ($_POST['numero'] ?? ''))) {
            header('Location: cartera.php?ok=reactivada');
            exit;
        }
        $error = 'No pude reactivar esa factura.';
    } else {
        $numero = (string) ($_POST['numero'] ?? '');
        $idx    = (int) ($_POST['cuota_idx'] ?? -1);
        $monto  = (int) ($_POST['monto'] ?? 0);
        if (registrar_abono($numero, $idx, $monto)) {
            header('Location: cartera.php?ok=abono&numero=' . urlencode($numero) . '&cuota=' . $idx . '&monto=' . $monto);
            exit;
        }
        $error = 'No pude registrar ese abono. Revisa que el monto no supere lo pendiente.';
    }
}
```

- [ ] **Step 2: Armar el mensaje de WhatsApp del abono**

Reemplazar el bloque (línea 36-42):

```php
if (($_GET['ok'] ?? '') === 'pagada') {
    $ok = 'Cuota marcada como pagada.';
} elseif (($_GET['ok'] ?? '') === 'anulada') {
    $ok = 'Factura anulada. Ya no cuenta en los reportes ni en la cartera.';
} elseif (($_GET['ok'] ?? '') === 'reactivada') {
    $ok = 'Factura reactivada. Vuelve a contar en los reportes y la cartera.';
}
```

por:

```php
$waAbono = null;

if (($_GET['ok'] ?? '') === 'abono') {
    $ok     = 'Abono registrado.';
    $fNum   = (string) ($_GET['numero'] ?? '');
    $idx    = (int) ($_GET['cuota'] ?? -1);
    $monto  = (int) ($_GET['monto'] ?? 0);
    $fAbono = $fNum !== '' ? factura_por_numero($fNum) : null;

    if ($fAbono && $fAbono['telefono'] !== '' && isset($fAbono['cuotas'][$idx]) && $monto > 0) {
        $cuotaAbono = $fAbono['cuotas'][$idx];
        $pendiente  = cuota_pendiente($cuotaAbono);

        $msg  = "Hola " . $fAbono['cliente'] . " 💛\n";
        $msg .= "Recibimos tu abono de " . pesos($monto) . " (" . fecha_larga(date('Y-m-d')) . ").\n";
        $msg .= "Corresponde a la cuota de " . pesos((int) $cuotaAbono['monto'])
              . " con vencimiento " . fecha_larga($cuotaAbono['vence']) . ".\n";
        $msg .= $pendiente > 0
            ? "Saldo pendiente de esa cuota: " . pesos($pendiente) . "\n"
            : "Con esto, esa cuota queda completa. ¡Gracias!\n";

        $waAbono = 'https://wa.me/' . '57' . ltrim($fAbono['telefono'], '5')
            . '?text=' . rawurlencode($msg);
    }
} elseif (($_GET['ok'] ?? '') === 'anulada') {
    $ok = 'Factura anulada. Ya no cuenta en los reportes ni en la cartera.';
} elseif (($_GET['ok'] ?? '') === 'reactivada') {
    $ok = 'Factura reactivada. Vuelve a contar en los reportes y la cartera.';
}
```

- [ ] **Step 3: Generalizar la lista de cuotas pendientes**

Reemplazar el bloque (línea 49-61):

```php
$pendientes = [];
foreach ($todas as $f) {
    if (!empty($f['anulada']) || $f['plan'] !== 'cuotas') {
        continue;
    }
    if (empty($f['cuota1_pagada'])) {
        $pendientes[] = ['factura' => $f, 'cuota' => 1, 'rotulo' => 'Primera cuota', 'monto' => $f['cuota1'], 'vence' => $f['vence1']];
    }
    if (empty($f['cuota2_pagada'])) {
        $pendientes[] = ['factura' => $f, 'cuota' => 2, 'rotulo' => 'Segunda cuota', 'monto' => $f['cuota2'], 'vence' => $f['vence2']];
    }
}
usort($pendientes, fn($a, $b) => strcmp($a['vence'], $b['vence']));
```

por:

```php
$rotulosCuota = ['Primera cuota', 'Segunda cuota', 'Tercera cuota', 'Cuarta cuota'];

$pendientes = [];
foreach ($todas as $f) {
    if (!empty($f['anulada']) || $f['plan'] !== 'cuotas') {
        continue;
    }
    foreach ($f['cuotas'] as $idx => $cuota) {
        $pendiente = cuota_pendiente($cuota);
        if ($pendiente <= 0) {
            continue;
        }
        $pendientes[] = [
            'factura'   => $f,
            'idx'       => $idx,
            'rotulo'    => $rotulosCuota[$idx] ?? ('Cuota ' . ($idx + 1)),
            'pactado'   => (int) $cuota['monto'],
            'pendiente' => $pendiente,
            'vence'     => $cuota['vence'],
        ];
    }
}
usort($pendientes, fn($a, $b) => strcmp($a['vence'], $b['vence']));
```

- [ ] **Step 4: Mostrar el botón de WhatsApp del abono junto al aviso**

Reemplazar el bloque (línea 159-161):

```php
  <?php if ($ok !== ''): ?>
    <p class="aviso neutro"><?= e($ok) ?></p>
  <?php endif; ?>
```

por:

```php
  <?php if ($ok !== ''): ?>
    <p class="aviso neutro"><?= e($ok) ?></p>
  <?php endif; ?>
  <?php if ($waAbono): ?>
    <p class="aviso neutro">
      <a class="boton mini verde" href="<?= e($waAbono) ?>" target="_blank" rel="noopener">Enviar comprobante por WhatsApp</a>
    </p>
  <?php endif; ?>
```

- [ ] **Step 5: Reemplazar la fila de "Marcar pagada" por el campo de monto libre**

Reemplazar el bloque (línea 180-202):

```php
          <?php foreach ($pendientes as $p): $f = $p['factura']; $vencida = esta_vencida($p['vence']); ?>
            <tr>
              <td>
                <?= e($f['cliente']) ?>
                <?php if ($f['telefono'] !== ''): ?><span class="sku"><?= e($f['telefono']) ?></span><?php endif; ?>
              </td>
              <td><a class="salir" href="factura.php?n=<?= e($f['numero']) ?>"><?= e($f['numero']) ?></a></td>
              <td><?= e($p['rotulo']) ?></td>
              <td>
                <?= e(fecha_larga($p['vence'])) ?>
                <?php if ($vencida): ?><span class="etiqueta vencida">Vencida</span><?php endif; ?>
              </td>
              <td class="num"><?= pesos($p['monto']) ?></td>
              <td class="num">
                <form method="post" action="cartera.php">
                  <input type="hidden" name="csrf" value="<?= e($token) ?>">
                  <input type="hidden" name="numero" value="<?= e($f['numero']) ?>">
                  <input type="hidden" name="cuota" value="<?= (int) $p['cuota'] ?>">
                  <button type="submit" class="boton mini">Marcar pagada</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
```

por:

```php
          <?php foreach ($pendientes as $p): $f = $p['factura']; $vencida = esta_vencida($p['vence']); $abonado = $p['pactado'] - $p['pendiente']; ?>
            <tr>
              <td>
                <?= e($f['cliente']) ?>
                <?php if ($f['telefono'] !== ''): ?><span class="sku"><?= e($f['telefono']) ?></span><?php endif; ?>
              </td>
              <td><a class="salir" href="factura.php?n=<?= e($f['numero']) ?>"><?= e($f['numero']) ?></a></td>
              <td><?= e($p['rotulo']) ?></td>
              <td>
                <?= e(fecha_larga($p['vence'])) ?>
                <?php if ($vencida): ?><span class="etiqueta vencida">Vencida</span><?php endif; ?>
              </td>
              <td class="num">
                <?= pesos($p['pendiente']) ?>
                <?php if ($abonado > 0): ?><span class="sku">de <?= pesos($p['pactado']) ?></span><?php endif; ?>
              </td>
              <td class="num">
                <form method="post" action="cartera.php" class="en-linea">
                  <input type="hidden" name="csrf" value="<?= e($token) ?>">
                  <input type="hidden" name="numero" value="<?= e($f['numero']) ?>">
                  <input type="hidden" name="cuota_idx" value="<?= (int) $p['idx'] ?>">
                  <input type="number" name="monto" min="1" max="<?= (int) $p['pendiente'] ?>" step="500"
                         value="<?= (int) $p['pendiente'] ?>" aria-label="Monto del abono">
                  <button type="submit" class="boton mini">Registrar abono</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
```

- [ ] **Step 6: Generalizar el estado del historial**

Reemplazar el bloque (línea 235-253):

```php
          <?php foreach ($historial as $f):
            $anulada = !empty($f['anulada']);
            if ($anulada) {
                $estadoClase = 'anulada';
                $estadoTexto = 'Anulada';
            } elseif ($f['plan'] !== 'cuotas') {
                $estadoClase = '';
                $estadoTexto = 'Contado';
            } elseif (!empty($f['cuota1_pagada']) && !empty($f['cuota2_pagada'])) {
                $estadoClase = 'pagada';
                $estadoTexto = 'Pagada';
            } elseif ((empty($f['cuota1_pagada']) && esta_vencida($f['vence1']))
                   || (empty($f['cuota2_pagada']) && esta_vencida($f['vence2']))) {
                $estadoClase = 'vencida';
                $estadoTexto = 'Vencida';
            } else {
                $estadoClase = 'pendiente';
                $estadoTexto = 'Pendiente';
            }
          ?>
```

por:

```php
          <?php foreach ($historial as $f):
            $anulada = !empty($f['anulada']);
            if ($anulada) {
                $estadoClase = 'anulada';
                $estadoTexto = 'Anulada';
            } elseif ($f['plan'] !== 'cuotas') {
                $estadoClase = '';
                $estadoTexto = 'Contado';
            } else {
                $todasPagadas  = true;
                $algunaVencida = false;
                foreach ($f['cuotas'] as $cuota) {
                    if (!cuota_esta_pagada($cuota)) {
                        $todasPagadas = false;
                        if (esta_vencida($cuota['vence'])) {
                            $algunaVencida = true;
                        }
                    }
                }
                if ($todasPagadas) {
                    $estadoClase = 'pagada';
                    $estadoTexto = 'Pagada';
                } elseif ($algunaVencida) {
                    $estadoClase = 'vencida';
                    $estadoTexto = 'Vencida';
                } else {
                    $estadoClase = 'pendiente';
                    $estadoTexto = 'Pendiente';
                }
            }
          ?>
```

- [ ] **Step 7: Verificar sintaxis**

Run: `php -l cartera.php`
Expected: `No syntax errors detected in cartera.php`

- [ ] **Step 8: Verificación manual en navegador**

1. Crear (o usar una existente) una factura a cuotas.
2. Abrir `cartera.php` → confirmar que "Cartera pendiente" lista una fila por cada cuota pendiente, con el campo de monto precargado con el pendiente completo.
3. Cambiar el monto a un valor menor (abono parcial) y enviar → confirmar: la página recarga con "Abono registrado.", aparece el botón "Enviar comprobante por WhatsApp" (si la clienta tiene teléfono), y la fila de esa cuota en "Cartera pendiente" ahora muestra el nuevo pendiente con "de $pactado".
4. Repetir hasta completar el pendiente de esa cuota → confirmar que la fila desaparece de "Cartera pendiente".
5. En "Historial de facturas", confirmar que el estado ("Pendiente"/"Vencida"/"Pagada") sigue siendo correcto.
6. Probar con un monto mayor al pendiente → confirmar que se muestra el error y no se registra nada.

- [ ] **Step 9: Commitear**

```bash
git add cartera.php
git commit -m "Registrar abonos parciales de monto libre por cuota en cartera.php"
```

---

### Task 5: Vista de la factura y mensaje de WhatsApp de la factura completa (`factura.php`)

**Files:**
- Modify: `factura.php:16-40` (mensaje de WhatsApp de la factura)
- Modify: `factura.php:131-161` (sección "Plan de pago")

**Interfaces:**
- Consumes: `cuota_pagado()`, `cuota_pendiente()` (Task 1); lee `$f['cuotas']`.

- [ ] **Step 1: Generalizar el mensaje de WhatsApp**

Reemplazar el bloque (línea 16-40):

```php
/* Mensaje de WhatsApp */
$msg  = "Hola " . $f['cliente'] . " 💛\n";
$msg .= "Aquí está tu cuenta de " . APP_NOMBRE . " (No. " . $f['numero'] . "):\n\n";
foreach ($f['lineas'] as $l) {
    $msg .= "• " . $l['cant'] . " x " . $l['nombre'] . " — " . pesos($l['precio'] * $l['cant']) . "\n";
}
if ($f['descuento'] > 0) {
    $msg .= "\nDescuento: -" . pesos($f['descuento']);
}
$msg .= "\nTotal: " . pesos($f['total']) . "\n";
if ($f['plan'] === 'cuotas') {
    if (!empty($f['saldo_aplicado'])) {
        $msg .= "Saldo a favor aplicado: " . pesos($f['saldo_aplicado']) . "\n";
    }
    if (!empty($f['abono'])) {
        $msg .= "Abono recibido: " . pesos($f['abono']) . "\n";
    }
    $msg .= "\nQuedamos así:\n";
    $msg .= "Primera cuota (" . fecha_larga($f['vence1']) . "): " . pesos($f['cuota1']) . "\n";
    $msg .= "Segunda cuota (" . fecha_larga($f['vence2']) . "): " . pesos($f['cuota2']) . "\n";
}
if (!empty($f['nota'])) {
    $msg .= "\n" . $f['nota'];
}
$msg .= "\n¡Gracias por confiar en nosotros!";
```

por:

```php
/* Mensaje de WhatsApp */
$rotulosCuota = ['Primera cuota', 'Segunda cuota', 'Tercera cuota', 'Cuarta cuota'];

$msg  = "Hola " . $f['cliente'] . " 💛\n";
$msg .= "Aquí está tu cuenta de " . APP_NOMBRE . " (No. " . $f['numero'] . "):\n\n";
foreach ($f['lineas'] as $l) {
    $msg .= "• " . $l['cant'] . " x " . $l['nombre'] . " — " . pesos($l['precio'] * $l['cant']) . "\n";
}
if ($f['descuento'] > 0) {
    $msg .= "\nDescuento: -" . pesos($f['descuento']);
}
$msg .= "\nTotal: " . pesos($f['total']) . "\n";
if ($f['plan'] === 'cuotas') {
    if (!empty($f['saldo_aplicado'])) {
        $msg .= "Saldo a favor aplicado: " . pesos($f['saldo_aplicado']) . "\n";
    }
    if (!empty($f['abono'])) {
        $msg .= "Abono recibido: " . pesos($f['abono']) . "\n";
    }
    $msg .= "\nQuedamos así:\n";
    foreach ($f['cuotas'] as $idx => $cuota) {
        $rotulo = $rotulosCuota[$idx] ?? ('Cuota ' . ($idx + 1));
        $msg .= $rotulo . ' (' . fecha_larga($cuota['vence']) . '): ' . pesos((int) $cuota['monto']) . "\n";
    }
}
if (!empty($f['nota'])) {
    $msg .= "\n" . $f['nota'];
}
$msg .= "\n¡Gracias por confiar en nosotros!";
```

- [ ] **Step 2: Generalizar la sección "Plan de pago"**

Reemplazar el bloque (línea 131-161):

```php
  <?php if ($f['plan'] === 'cuotas'): ?>
    <section class="plan">
      <p class="rotulo">Plan de pago</p>
      <?php if (!empty($f['saldo_aplicado'])): ?>
        <p class="cuota-rot">Saldo a favor aplicado: <?= pesos($f['saldo_aplicado']) ?></p>
      <?php endif; ?>
      <?php if (!empty($f['abono'])): ?>
        <p class="cuota-rot">Abono recibido: <?= pesos($f['abono']) ?></p>
      <?php endif; ?>
      <div class="cuotas">
        <div class="cuota <?= !empty($f['cuota1_pagada']) ? 'pagada' : '' ?>">
          <p class="cuota-rot">Primera cuota · <?= e(fecha_larga($f['vence1'])) ?></p>
          <p class="cuota-val"><?= pesos($f['cuota1']) ?></p>
          <p class="cuota-est <?= empty($f['cuota1_pagada']) ? 'pendiente' : '' ?>">
            <?= !empty($f['cuota1_pagada']) ? 'Recibida' : 'Pendiente' ?>
          </p>
        </div>
        <div class="cuota <?= !empty($f['cuota2_pagada']) ? 'pagada' : '' ?>">
          <p class="cuota-rot">Segunda cuota · <?= e(fecha_larga($f['vence2'])) ?></p>
          <p class="cuota-val"><?= pesos($f['cuota2']) ?></p>
          <p class="cuota-est <?= empty($f['cuota2_pagada']) ? 'pendiente' : '' ?>">
            <?= !empty($f['cuota2_pagada']) ? 'Recibida' : 'Pendiente' ?>
          </p>
        </div>
      </div>
    </section>
  <?php else: ?>
```

por:

```php
  <?php if ($f['plan'] === 'cuotas'): ?>
    <section class="plan">
      <p class="rotulo">Plan de pago</p>
      <?php if (!empty($f['saldo_aplicado'])): ?>
        <p class="cuota-rot">Saldo a favor aplicado: <?= pesos($f['saldo_aplicado']) ?></p>
      <?php endif; ?>
      <?php if (!empty($f['abono'])): ?>
        <p class="cuota-rot">Abono recibido: <?= pesos($f['abono']) ?></p>
      <?php endif; ?>
      <div class="cuotas">
        <?php foreach ($f['cuotas'] as $idx => $cuota):
            $rotulo    = $rotulosCuota[$idx] ?? ('Cuota ' . ($idx + 1));
            $pagado    = cuota_pagado($cuota);
            $pendiente = cuota_pendiente($cuota);
            $pagada    = $pendiente <= 0;
        ?>
          <div class="cuota <?= $pagada ? 'pagada' : '' ?>">
            <p class="cuota-rot"><?= e($rotulo) ?> · <?= e(fecha_larga($cuota['vence'])) ?></p>
            <p class="cuota-val"><?= pesos((int) $cuota['monto']) ?></p>
            <p class="cuota-est <?= $pagada ? '' : 'pendiente' ?>">
              <?php if ($pagada): ?>
                Recibida
              <?php elseif ($pagado > 0): ?>
                Abonado <?= pesos($pagado) ?> · Pendiente <?= pesos($pendiente) ?>
              <?php else: ?>
                Pendiente
              <?php endif; ?>
            </p>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php else: ?>
```

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l factura.php`
Expected: `No syntax errors detected in factura.php`

- [ ] **Step 4: Verificación manual en navegador**

1. Abrir una factura a cuotas recién creada (2, 3 y 4 cuotas por separado) → confirmar que "Plan de pago" muestra todas las cuotas con su monto y fecha, todas "Pendiente".
2. Desde `cartera.php`, registrar un abono parcial de una cuota y volver a esta factura → confirmar que esa cuota ahora dice "Abonado $X · Pendiente $Y".
3. Completar el pendiente de una cuota → confirmar que pasa a "Recibida" y la tarjeta se pinta como pagada (mismo estilo visual que antes).
4. Confirmar que el botón "Enviar por WhatsApp" arma un mensaje con todas las cuotas listadas (usar `console.log` o inspeccionar el `href` si hace falta).
5. Abrir una factura vieja (creada antes de este cambio, desde el historial en `cartera.php`) → confirmar que también se ve bien (prueba de la migración en memoria).

- [ ] **Step 5: Commitear**

```bash
git add factura.php
git commit -m "Mostrar N cuotas y abonos parciales en la vista de factura y el mensaje de WhatsApp"
```

---

### Task 6: Verificación final end-to-end

**Files:** ninguno (solo verificación)

- [ ] **Step 1: Sintaxis de los 4 archivos tocados**

```bash
php -l includes/facturas.php
php -l facturar.php
php -l cartera.php
php -l factura.php
```

Expected: `No syntax errors detected` en los 4.

- [ ] **Step 2: Suite de scripts de datos**

```bash
php tests/cuotas_puras_test.php
php tests/facturas_cuotas_test.php
```

Expected: ambos terminan en `TODO OK` con código de salida 0. Confirmar con `git status` que `storage/facturas.json` no quedó modificado por el segundo script.

- [ ] **Step 3: Recorrido manual completo en navegador**

1. Crear una factura a cuotas con 3 cuotas de montos distintos (no repartidos a la mitad) y un abono inicial.
2. Desde `cartera.php`, registrar dos abonos parciales seguidos sobre la misma cuota hasta completarla; confirmar el mensaje de WhatsApp de cada uno.
3. Verificar `dashboard.php`: que "Por cobrar" y "Vencido" reflejen los pendientes reales (no los montos pactados completos) después de los abonos parciales.
4. Abrir una factura antigua (antes de este cambio) desde el historial de `cartera.php` y confirmar que se ve y se cobra normal.
5. Anular una factura a cuotas con abonos parciales y confirmar que sale de "Cartera pendiente" y de los reportes del `dashboard.php`.

- [ ] **Step 4: Confirmar con el usuario**

Avisar que la implementación está lista para que la revise en el navegador, señalando específicamente los puntos 2 (abono parcial + WhatsApp) y 4 (factura antigua migrada) del recorrido manual, que son los casos que motivaron el cambio.

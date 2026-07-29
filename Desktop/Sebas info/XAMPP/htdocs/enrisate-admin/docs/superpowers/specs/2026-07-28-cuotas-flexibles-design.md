# Diseño: cuotas flexibles (N cuotas + abonos parciales)

## Contexto

El panel Enrísate factura a cuotas con un modelo rígido de exactamente 2 cuotas
fijas (`cuota1`, `cuota2`) que se pagan de una sola vez ("Marcar pagada").
En la práctica, a veces se pactan hasta 3 cuotas, y a veces la clienta abona
menos de lo pactado en una cuota y el resto queda pendiente.

Este diseño generaliza el sistema para soportar ambos casos con un solo
modelo de datos.

## Alcance

- Soportar de 2 a 4 cuotas por factura, cada una de monto libre (no reparto
  automático a la mitad).
- Permitir abonos parciales dentro de una cuota (pagos que no cubren el
  monto pactado completo).
- Migración automática (en memoria, al leer) de facturas existentes en el
  formato viejo (`cuota1`/`cuota2`).
- **Fuera de alcance:** comprobantes numerados independientes por abono
  parcial (se usa solo mensaje de WhatsApp, sin documento imprimible
  aparte); cambios al flujo de "de contado"; cambios a `clientes.php`,
  `inventario.php`, `margenes.php`, `catalogo-publico.php`.

## Modelo de datos

Factura con `plan === 'cuotas'`:

```php
'cuotas' => [
  ['monto' => 120000, 'vence' => '2026-08-15', 'abonos' => []],
  ['monto' =>  80000, 'vence' => '2026-09-15', 'abonos' => [
      ['monto' => 50000, 'fecha' => '2026-08-20T10:00:00-05:00'],
  ]],
],
```

Reemplaza a `cuota1`, `cuota2`, `vence1`, `vence2`, `cuota1_pagada`,
`cuota2_pagada`, `cuota1_pagada_el`, `cuota2_pagada_el`.

Reglas derivadas (funciones nuevas en `includes/facturas.php`):

- `cuota_pagado(cuota): int` — suma de `abonos[].monto`.
- `cuota_pendiente(cuota): int` — `max(0, monto - cuota_pagado(cuota))`.
- `cuota_esta_pagada(cuota): bool` — `cuota_pendiente(cuota) <= 0`.
- El campo `pagado` de la factura (ya existente, usado en reportes) pasa a
  ser `saldo_aplicado + abono inicial + suma de todos los abonos de todas
  las cuotas`.

Número máximo de cuotas: **4** (validado en HTML y en servidor).

### Migración de facturas viejas

`leer_facturas()` y `factura_por_numero()` detectan el formato viejo
(presencia de `cuota1` sin `cuotas`) y lo traducen en memoria a la nueva
estructura antes de devolver la factura:

- `cuotas[0] = ['monto' => cuota1, 'vence' => vence1, 'abonos' => cuota1_pagada ? [['monto' => cuota1, 'fecha' => cuota1_pagada_el]] : []]`
- Igual para `cuotas[1]` con cuota2.

El archivo `storage/facturas.json` **no se reescribe** al migrar — la
traducción ocurre solo al leer. Las facturas nuevas se guardan directamente
en el formato nuevo.

## Componentes afectados

### `facturar.php` (creación)

- El radio "De contado" / "En 2 cuotas" cambia a "De contado" / selector de
  número de cuotas (2, 3, 4).
- Al elegir N cuotas: N campos de monto + N campos de fecha de vencimiento.
  Se editan libremente las primeras N−1 montos; el último se calcula solo
  como el resto (`resto a cuotas - suma de los anteriores`), igual que hoy
  funciona cuota1/cuota2 en JS.
- El campo "Abono recibido" (monto libre al crear la factura) no cambia:
  sigue restando del total antes de repartir en cuotas.
- Validación en servidor: número de cuotas entre 2 y 4; cada monto ≥ 1; la
  suma de todas las cuotas debe ser exactamente el resto a cuotas
  (recalculado en servidor, nunca se confía en los valores del navegador).

### `includes/facturas.php` (datos)

- Nuevas funciones: `cuota_pagado()`, `cuota_pendiente()`,
  `cuota_esta_pagada()`, y una función de migración interna usada por
  `leer_facturas()`/`factura_por_numero()`.
- Nueva función `registrar_abono(numero, indiceCuota, monto): bool` que
  reemplaza a `marcar_cuota_pagada()`: valida que la factura exista, no
  esté anulada, el índice de cuota exista, y `0 < monto <= cuota_pendiente`;
  agrega `{monto, fecha: date('c')}` al array `abonos` de esa cuota y
  actualiza el campo `pagado` de la factura.
- `metricas_panel()` generaliza su `foreach ([1, 2] as $i)` a recorrer el
  array `cuotas` de cada factura, usando `cuota_pendiente()`.

### `cartera.php` (cobro)

- La sección "Cartera pendiente" itera el array `cuotas` de cada factura
  (en vez de revisar cuota1/cuota2 a mano), listando una fila por cada
  cuota con `cuota_pendiente() > 0`.
- Cada fila muestra: rótulo ("Cuota 1", "Cuota 2"...), monto pendiente de
  esa cuota, y reemplaza el botón "Marcar pagada" por un campo numérico
  (precargado con el pendiente completo) + botón "Registrar abono", que
  llama a `registrar_abono()`.
- El cálculo de estado de la factura en el historial ("Pagada", "Vencida",
  "Pendiente") se generaliza a partir de `cuota_esta_pagada()` sobre todas
  las cuotas del array, en vez de revisar `cuota1_pagada`/`cuota2_pagada`.

### `factura.php` (vista + WhatsApp)

- La sección "Plan de pago" recorre el array `cuotas` con un `foreach` en
  vez de imprimir cuota1/cuota2 a mano. Por cada cuota muestra: monto
  pactado, fecha de vencimiento, y estado — si tiene abonos parciales
  (`cuota_pagado() > 0` pero `cuota_pendiente() > 0`), muestra "Abonado:
  $X · Pendiente: $Y"; si no tiene abonos, "Pendiente"; si está cubierta,
  "Recibida".
- El mensaje de WhatsApp de la factura completa generaliza su bloque de
  cuotas fijas a un `foreach` sobre `cuotas`.
- Nuevo mensaje de WhatsApp para un abono individual (generado desde
  `cartera.php` al registrar el abono): incluye monto abonado, fecha, a
  qué cuota corresponde, y el saldo que queda de esa cuota. No genera
  documento imprimible aparte ni número de factura propio.

## Manejo de errores

- Todos los POST siguen validando `csrf_valido()`.
- Montos siempre acotados server-side con `max()`/`min()`, igual que el
  resto del código existente — nunca se confía en valores del navegador.
- Registrar un abono mayor al pendiente de una cuota → rechazado, no se
  guarda, se muestra error.
- Registrar abono sobre una cuota ya pagada, o sobre una factura anulada
  → rechazado (mismo patrón que ya sigue `marcar_cuota_pagada` hoy).

## Verificación

El proyecto no tiene suite de pruebas automatizadas (PHP plano sin
framework de testing). Verificación:

1. `php -l` sobre cada archivo modificado.
2. Recorrido manual en navegador:
   - Crear factura con 2, 3 y 4 cuotas de montos libres.
   - Registrar un abono parcial (menor al pendiente) y confirmar que la
     cuota sigue pendiente con el saldo correcto.
   - Registrar un abono que complete el pendiente y confirmar que la
     cuota pasa a pagada.
   - Revisar `cartera.php` (pendientes + historial + estado), `factura.php`
     (plan de pago) y el mensaje de WhatsApp en cada caso.
   - Abrir una factura ya existente en `storage/facturas.json` (formato
     viejo) y confirmar que se sigue viendo y cobrando correctamente
     (migración en memoria).

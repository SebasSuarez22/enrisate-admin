# Diseño: cascada de abonos entre cuotas

## Contexto

El sistema de cuotas flexibles ([2026-07-28-cuotas-flexibles-design.md](2026-07-28-cuotas-flexibles-design.md))
permite abonos parciales dentro de una cuota, pero `registrar_abono()`
**rechaza** cualquier monto mayor al pendiente de esa cuota puntual.

En la práctica, las clientas no siempre pagan el valor exacto pactado por
cuota: a veces pagan de menos (ya soportado) y a veces de más — redondeos,
transferencias, o simplemente adelantan plata de la próxima cuota. Hoy ese
caso queda bloqueado tanto en el campo del formulario (`max` HTML) como en
el servidor, obligando a anular y rehacer facturas para registrar el pago
real (caso detectado con la factura 2026-0002 de Alina Torres).

## Alcance

- Un abono puede superar el pendiente de la cuota donde se registra; el
  sobrante se reparte automáticamente hacia la(s) próxima(s) cuota(s)
  pendientes de la misma factura, en orden.
- Un abono nunca se aplica "hacia atrás" a una cuota anterior ya cubierta.
- Un abono que supere el pendiente de **toda la factura** (todas las
  cuotas ya cubiertas) se sigue rechazando — no existe concepto de saldo
  a favor más allá de la factura (evaluado y descartado: ver alternativas
  B/C consideradas).
- **Fuera de alcance:** saldo a favor de la clienta entre facturas distintas;
  cambios al flujo de creación de facturas (`facturar.php`); cambios a
  `clientes.php`, `inventario.php`, `margenes.php`, `catalogo-publico.php`.

## Comportamiento

Dada una factura con cuotas `[C0, C1, C2, ...]` y un abono de monto `M`
registrado sobre el índice `i`:

1. Se aplica a `Ci` hasta completar su pendiente.
2. Si sobra, se aplica a `Ci+1` hasta completar su pendiente, y así
   sucesivamente hasta agotar `M` o llegar a la última cuota.
3. Si `M` supera la suma de pendientes de `Ci..Cn`, se rechaza el abono
   completo (no se aplica nada parcialmente) — mismo patrón de "todo o
   nada" que ya sigue `registrar_abono()` hoy.

## Componentes afectados

### `includes/facturas.php` → `registrar_abono()`

- Cambia la validación: en vez de `monto <= cuota_pendiente(cuotas[i])`,
  valida `monto <= suma(cuota_pendiente(cuotas[i..n]))`.
- Reparte `monto` cuota por cuota desde `i` hacia adelante, agregando un
  registro a `abonos[]` en cada cuota que reciba parte del pago (con su
  propio `{monto, fecha}` — así cada cuota conserva su propio historial de
  abonos, igual que hoy).
- Devuelve información de reparto (qué cuotas recibieron cuánto) para que
  `cartera.php` pueda armar el mensaje de WhatsApp. Cambia el tipo de
  retorno de `bool` a `array|false` (`false` si se rechaza; si no, un
  array con el detalle por cuota afectada: `[['idx' => i, 'monto' => x], ...]`).
- Todas las llamadas existentes a `registrar_abono()` se ajustan a chequear
  `!== false` en vez de `=== true`.

### `cartera.php`

- **Tope del campo de abono**: en vez de `max="pendiente de esa cuota"`,
  usa `max="pendiente de esa cuota + todas las siguientes de la factura"`.
- **Texto de ayuda**: si hay cuotas siguientes con saldo, se agrega una
  nota bajo el campo tipo "Puedes abonar hasta $X si quieres adelantar la
  próxima cuota."
- **Mensaje de WhatsApp tras registrar abono**: usa el detalle de reparto
  devuelto por `registrar_abono()`. Si solo afectó una cuota, mensaje igual
  al actual. Si afectó varias, lista cada una: "Cuota del 25 de julio:
  pagada completa. Cuota del 10 de agosto: abonaste $10.000, quedan
  $54.500 pendientes."

## Manejo de errores

- Igual que hoy: CSRF, factura anulada, índice de cuota inexistente, monto
  ≤ 0 → rechazado sin registrar nada.
- Nuevo: monto mayor a la suma de pendientes desde la cuota indicada hasta
  el final de la factura → rechazado completo, no se recorta ni se aplica
  parcialmente.

## Verificación

El proyecto no tiene suite de pruebas automatizada con framework, pero sí
scripts de verificación en `tests/` (convención: `verificar(cond, msg)`,
se corren con `php tests/archivo_test.php`).

1. Actualizar `tests/facturas_cuotas_test.php`:
   - El caso existente "rechaza un monto mayor al pendiente (no lo
     recorta)" pasa a probar que se rechaza solo cuando supera el pendiente
     de **toda la factura restante**, no solo de una cuota.
   - Nuevo: abono que cae exacto en el límite de dos cuotas (cubre la
     primera exacto, no sobra nada para la segunda).
   - Nuevo: abono que cascada y cubre completa la cuota actual + parcial
     la siguiente.
   - Nuevo: abono que cascada por 3+ cuotas.
   - Nuevo: abono que excede el total pendiente de la factura completa →
     rechazado, nada se modifica (verificar que `pagado` no cambió).
2. `php -l` sobre los archivos modificados.
3. Recorrido manual en navegador (con la factura real de Alina Torres,
   reactivada):
   - Registrar el abono real que pagó (mayor al pendiente de la cuota 2)
     y confirmar que se reparte correctamente.
   - Revisar `cartera.php` (fila de cartera pendiente actualizada o
     desaparecida si quedó todo pagado) y el mensaje de WhatsApp generado.

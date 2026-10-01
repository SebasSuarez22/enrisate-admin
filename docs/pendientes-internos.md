# Pendientes internos — enrisate-admin

_Nota privada de trabajo. No se muestra al cliente — solo para dar continuidad entre sesiones. Las entradas nuevas se agregan arriba._

## 1 de octubre, 2026 (auditoría · paso 4, parte 3: catálogo guardado por fila)

**Qué cambió en `includes/facturas.php`** (misma lógica de negocio, mismos mensajes):
- `guardar_producto($p, $esNuevo)`: `INSERT` (falla si el SKU existiera, no pisa) o `UPDATE ... WHERE sku` de UNA fila, con las columnas que existan (`productos_columnas()` respeta que `categoria`/`descripcion` puedan faltar). `guardar_catalogo()` masivo queda solo para sembrar la tabla vacía desde `datos/catalogo.php`.
- `leer_producto($sku)`: lee una fila directo de la base (no de la caché), con comparación exacta en PHP (la collation confunde `ACC-04`, `acc-04` y `ACC-04 `). Lo usan los que escriben, dentro del candado.
- `agregar_producto`, `actualizar_precio_costo`, `actualizar_descripcion`, `actualizar_producto`: leen solo ese producto y escriben solo su fila. `eliminar_productos`: borra solo los SKU que coinciden exacto, todos juntos o ninguno (`en_transaccion`).
- **Caché en memoria:** `catalogo_memoria()` guarda la copia; `catalogo_olvidar()` la marca como vieja después de cada escritura y la próxima lectura vuelve a la base. Antes se reemplazaba a mano con el arreglo armado en PHP (el producto recién agregado quedaba en memoria sin la clave `descripcion` — única diferencia que mostró la prueba, y es una mejora). `catalogo_cache(true)` sigue existiendo.
- **Siembra:** antes cualquier escritura con la tabla vacía sembraba los 92 productos porque leía todo el catálogo. Ahora los que escriben llaman `catalogo_sembrar_si_vacio()` (un `SELECT 1 ... LIMIT 1`) para conservar ese comportamiento. Ojo, comportamiento heredado sin cambiar: si alguien borra TODOS los productos, la siguiente lectura vuelve a sembrar los 92.
- Candado `con_bloqueo('catalogo')` igual (misma razón que pedidos/clientas). Comentario de `con_bloqueo()` en `db.php` actualizado: ya solo las facturas reescriben todo.
- Efecto secundario bueno: `creado_en` de los productos ya no se resetea en cada cambio (antes el DELETE+INSERT ponía la fecha de hoy a todos).

**Prueba diferencial nueva `tests/catalogo_por_fila_test.php`:** mismo guion con la implementación anterior (copiada en la prueba) y la nueva, empezando con la tabla vacía (siembra): agregar (SKU en minúsculas, repetido, inválido, vacío, sin marca, precio negativo, categoría inexistente, con espacios/Ñ/emoji, nombre demasiado largo, categoría deducida), precio/costo (negativos, SKU en minúsculas, con espacio, inexistente), descripción, marca/nombre/categoría (vacíos, categoría inválida o vacía, demasiado largo, inexistente), eliminar (repetidos, vacíos, inexistente, `acc-05`, `ACC-05 `) y lo que ve la misma petición justo después de cada guardado. Resultado: **43 respuestas y el catálogo final (93 productos, todos los campos y el orden) idénticos**, salvo la diferencia de `descripcion` ya explicada. Con la tabla vacía, editar o eliminar siembra igual que antes. Disparadores: cambiar un precio edita 1 fila (antes: 92 borradas y 92 reinsertadas); agregar inserta 1; eliminar dos borra 2.
- Mutaciones detectadas: no olvidar la caché (la petición vería el precio viejo), leer sin comparación exacta, eliminar sin filtro exacto (habría borrado `ACC-05` al pedir `acc-05`), sin siembra, UPDATE que toca otras filas, precio que no se aplica, eliminar sin contar, error de largo sin capturar.
- De extremo a extremo con el servidor local: login → Productos → agregar (`e2e-01` queda `E2E-01`, categoría deducida), repetido (mensaje correcto), editar nombre y precio, descripción (recortada), editar `e2e-01` en minúsculas (rechazado), eliminar `E2E-01,ACC-01,acc-02` → "Se eliminó 2 productos" y `ACC-02` intacto; el catálogo público muestra el nombre y la descripción nuevos.
- 13 pruebas pasan (concurrencia del catálogo incluida).

**Falta del paso 4:** facturas (`guardar_facturas` = DELETE de todas + reinsertar, con cascada en líneas, cuotas y abonos; los ids de cuotas se regeneran). Es la más delicada; hacerla en sesión aparte con Opus.

## 30 de septiembre, 2026 (auditoría · paso 4, parte 2: clientas guardadas por fila)

**Qué cambió en `includes/clientes.php`** (misma lógica de negocio):
- `guardar_cliente($c, $esNueva)`: `INSERT` (falla si el id existiera, no pisa) o `UPDATE ... WHERE id` de UNA fila. `guardar_clientes()` masivo queda solo para `tools/normalizar_telefonos_existentes.php`.
- `agregar_cliente()` inserta solo esa; `actualizar_cliente()` y `restar_saldo_favor()` leen solo esa clienta (`cliente_por_id()` con `WHERE id = ?` + comparación exacta en PHP) y actualizan su fila; `eliminar_clientes()` busca las filas pedidas, se queda solo con las que coinciden EXACTO y borra esas; `sincronizar_clientes_desde_facturas()` inserta solo las nuevas, todas juntas o ninguna, y sigue recordando las del mismo lote para no duplicar.
- **Se dejó a propósito leyendo la lista completa** la búsqueda de "¿ya existe esta clienta?" (`cliente_ya_existe`, `buscar_cliente_coincidente`, usada al facturar y al sincronizar): compara por teléfono o por nombre con `strcasecmp` + `trim`, y la collation de MySQL compararía distinto (ignora tildes: "María" = "Maria"). Pasarla a SQL cambiaría qué clientas se consideran repetidas. Leer es barato; lo caro era reescribir.
- Candado `con_bloqueo('clientes')` igual (misma razón que en pedidos). `guardar_cliente()` se une a la transacción de facturar → sigue siendo de todo o nada.

**Prueba diferencial nueva `tests/clientes_por_fila_test.php`:** mismo guion con la implementación anterior (copiada en la prueba) y la nueva — agregar (con espacios, +57, emoji, sin nombre), editar (saldo, saldo negativo, inexistente, sin nombre), descontar saldo (parcial, más de lo que tiene, sin saldo, 0, inexistente), agregar desde factura (ya existe por teléfono / por nombre con otra mayúscula / nueva / sin nombre), sincronizar un lote con la misma clienta dos veces, eliminar (repetidos, vacío, inexistente, id en MAYÚSCULAS, id con espacio) y buscar por id en todas esas variantes. Resultado: **21 respuestas y la lista final idénticas campo por campo, sin ninguna diferencia**. Con disparadores de MySQL que cuentan filas borradas/insertadas: editar o descontar saldo ya no borra ni reinserta ninguna fila (antes: las 7 borradas y reinsertadas por una sola edición); agregar inserta 1; eliminar borra 1. Mutaciones detectadas: UPDATE sin WHERE, búsqueda sin comparación exacta, eliminar sin filtro exacto (habría borrado otra clienta con el id en mayúsculas), sincronizar sin recordar el lote, descontar saldo sin tope.
- De extremo a extremo con el servidor real: login → agregar dos clientas → editar saldo → eliminar una, desde la pantalla de Clientes. (La "Ñ" se veía rara en la consola de MySQL; los bytes guardados son UTF-8 correctos — era la consola.)
- 12 pruebas pasan; concurrencia de clientas y saldo a favor 3 de 3.

## 30 de septiembre, 2026 (auditoría · paso 4, parte 1: pedidos guardados por fila)

Paso 4 = dejar de "leer todo → cambiar uno → borrar todo → reinsertar todo". Se hace de a una tabla, empezando por **pedidos** (la más expuesta: el formulario público reescribía TODOS los pedidos de la historia cada vez que entraba uno). Siguen: clientas, catálogo y al final facturas.

**Qué cambió en `includes/pedidos.php`:**
- `crear_pedido()`: `INSERT` solo del pedido nuevo y sus líneas. Si el número ya existiera falla (no pisa). El siguiente número se calcula en MySQL (`MAX(CAST(RIGHT(numero,4) AS UNSIGNED))`, misma regla de siempre) en vez de cargar todos.
- `actualizar_pedido()` (y con él confirmar/descartar): lee solo ese pedido, `UPDATE` de su fila, borra y reinserta solo SUS líneas.
- Lecturas: `leer_pedidos_donde(numero, estado)` filtra en SQL; `pedido_por_numero()` y `pedidos_pendientes()` ya no cargan la historia. Se conserva la comparación exacta en PHP (MySQL no distingue mayúsculas/espacios). Pedidos + líneas se leen en una misma transacción.
- `guardar_pedido($p, $esNuevo)` usa `en_transaccion()` → dentro de facturar (`crear_cuenta_desde_formulario`) sigue siendo parte de la misma transacción de todo o nada.
- `guardar_pedidos()` (masivo) queda solo para `tools/normalizar_telefonos_existentes.php`.
- **El candado `con_bloqueo('pedidos')` se dejó igual a propósito:** cada operación ahora toca una fila y dura milisegundos, así que la espera ya no pesa; pasarlo a bloqueo por fila agregaría riesgo (facturar depende de él para no confirmar dos veces el mismo pedido) sin ganancia real a este tamaño.

**Prueba diferencial nueva `tests/pedidos_por_fila_test.php`:** corre el mismo guion (5 pedidos variados con tildes/emoji, 0–4 líneas, cuotas o contado; confirmar, descartar con y sin motivo, descartar uno ya confirmado, actualizar, actualizar uno inexistente, cambiar líneas; más pedidos importados con números raros `P-2025-0042` y `RARO`) con la implementación ANTERIOR (copiada en la prueba) y con la nueva, y exige: mismos números, mismas respuestas, mismo siguiente número, base idéntica campo por campo y línea por línea, mismos pendientes en el mismo orden, misma búsqueda (inexistente, otra mayúscula, con espacio). **Única diferencia encontrada, y es la buscada:** la implementación anterior, al guardar CUALQUIER pedido, reescribía todos y de paso normalizaba el estado de pedidos que nadie tocó (un `Pendiente` importado pasaba a `pendiente`); la nueva deja intactas las filas ajenas. En producción no cambia nada: como la versión anterior normalizaba en cada guardado, los estados reales ya son válidos. Además comprueba que actualizar o crear un pedido deja intactas las filas (ids) de los demás, y que un INSERT con número repetido falla sin pisar.
- Mutaciones detectadas: no borrar las líneas viejas al actualizar; **UPDATE sin WHERE** (pisaría todos); buscar sin comparación exacta; numeración por el texto completo; crear con `ON DUPLICATE KEY UPDATE`.
- `tests/cuentas_test.php`: la falla simulada de "confirmar el pedido" era un disparador `BEFORE INSERT`; como confirmar ahora es un `UPDATE`, se agregó el `BEFORE UPDATE`. Con eso se re-verificó que facturar sigue siendo de todo o nada con el guardado por fila.
- 11 pruebas pasan; concurrencia repetida 3 veces (20/20 pedidos simultáneos, sin números repetidos); dos pedidos reales por `pedido-publico.php`.

## 30 de septiembre, 2026 (auditoría · paso 3: IP real y límite de pedidos)

**Corrección de la auditoría:** supuse que detrás del proxy todos los visitantes compartían UNA IP. Los registros de Apache en Railway muestran otra cosa: la app ve direcciones internas del proxy (`100.64.0.0/10`) y **una misma persona llega con una distinta en cada petición** (mismo navegador, mismo segundo: 100.64.0.1, .2, .13, .15, .22…). Con eso el límite de 8 pedidos/hora "por IP" era casi aleatorio: no frenaba a quien mandara muchos pedidos (se repartían entre decenas de direcciones) y podía bloquear a una clienta real que compartiera cupo al azar con otras. Además vivía en `storage/pedidos_intentos.json`, que se borraba en cada despliegue.

**Qué se hizo** (OWASP ASVS V4 · CWE-348 · CWE-799). **El login queda fuera** (decisión de Sebastián): `ip_cliente()` y los intentos de inicio de sesión siguen igual, con el mismo problema — anotado abajo.
- `includes/red.php`: `ip_visitante()` usa `X-Real-IP` (Railway la documenta como la IP del cliente) **solo si la conexión viene de `100.64.0.0/10`** y la cabecera es una IP válida; si no, `REMOTE_ADDR`. Así, en XAMPP o en una conexión directa nadie puede inventar su IP. `clave_limite_ip()` agrupa IPv6 por su `/64`.
- Límite en MySQL: tabla nueva `limite_pedidos` (`storage/esquema.sql`, se crea sola al arrancar). `pedido_ip_bloqueada()` cuenta la última hora; `registrar_pedido_ip()` inserta y borra lo de más de una hora. Se eliminó el JSON.
- `docker/seguridad-apache.conf`: el registro de visitas ahora dice `IP-real via IP-del-proxy …` (antes solo la del proxy).

**Pruebas:** nueva `tests/red_test.php` (18 comprobaciones): IP real detrás del proxy, borde del rango (100.127.x.x sí, 100.128.0.1 no), cabecera ignorada sin proxy o desde IP pública, cabecera inválida o con varias IPs descartada, IPv6, mismo /64 = misma persona; límite: la misma persona llegando por 9 direcciones distintas del proxy queda bloqueada en el pedido 9, otra IP sigue pudiendo, rotar IPv6 dentro del /64 no sirve, lo de hace más de 1 h no cuenta y se borra. Mutaciones (creerle siempre a la cabecera / usar solo REMOTE_ADDR = lo de antes / no agrupar IPv6 / rango mal calculado): las 4 se detectan. De extremo a extremo con `php -S`: 9 pedidos cada uno con una `X-Real-IP` falsa distinta → cabeceras ignoradas, 8 aceptados y el 9.º con 429. Formato del registro probado en Apache real. Las 10 pruebas pasan.

**Sin verificar (no hay acceso a producción desde aquí):** que el borde de Railway REEMPLACE una `X-Real-IP` enviada por el propio visitante (la documentación dice que la pone el borde, pero no dice explícitamente que pise la del cliente). Si no la reemplazara, alguien podría rotar IPs falsas para saltarse el límite: el mismo efecto que hoy, no peor. **Cómo comprobarlo tras el despliegue:** mandar `curl -H "X-Real-IP: 1.2.3.4" https://enrisate-admin-production.up.railway.app/catalogo-publico.php` y ver en los registros de Railway: si la línea empieza por la IP real de quien mandó el curl → bien; si empieza por `1.2.3.4` → hay que cambiar a otra cabecera.

**Pendiente (login, excluido):** `ip_cliente()` en `includes/auth.php` sigue con `REMOTE_ADDR`, así que el bloqueo tras 5 intentos fallidos de login también es casi aleatorio (se reparte entre las direcciones del proxy) y vive en `storage/intentos.json` (se borra al desplegar). Arreglarlo es cambiar `ip_cliente()` por `ip_visitante()` y moverlo a MySQL, cuando se decida trabajar el login.

## 30 de septiembre, 2026 (auditoría · paso 2: errores y enlace del PDF)

**Qué pasaba** (comparado contra master con la base apagada y con una consulta rota):
- La imagen `php:apache` no trae `php.ini`: PHP corría con `display_errors=1` y `log_errors=0`. Cualquier error se mostraba en la página y **no quedaba registrado** en ningún lado.
- `db()` le mostraba a cualquier visitante el nombre de la base, que era MySQL/XAMPP y el mensaje del driver (`SQLSTATE[HY000] [2002] …`).
- `factura-pdf.php` (público) cargaba TODAS las facturas antes de mirar el token: un enlace inventado ya consultaba la base entera (con la base caída respondía 500 con el detalle técnico).
- **Bug encontrado de paso:** con la base caída `tools/aplicar_esquema.php` salía con código 0 (`die()` con texto), así que el bucle de reintentos de `docker/entrypoint.sh` creía que había funcionado y nunca reintentaba.

**Qué se hizo** (OWASP A05 · CWE-209 · CWE-778):
- `docker/seguridad.ini`: `display_errors=Off`, `display_startup_errors=Off`, `log_errors=On`, `error_reporting=E_ALL`. El detalle va al registro de Apache (logs de Railway).
- `includes/errores.php` (cargado desde `config.php`): manejador de excepciones no controladas (solo web) que registra el detalle con la URL y la traza, descarta lo que se hubiera armado de la página y responde un aviso genérico; si la respuesta era JSON (envío de pedidos del catálogo) responde JSON para que el formulario se lo muestre a la clienta. `responder_error_generico()` es la salida común.
- `db()`: en la web, registro + 503 + `Retry-After: 60` + aviso genérico; en la terminal, el detalle completo por STDERR y `exit(1)` (arregla los reintentos del arranque).
- `factura_por_numero()` ahora lee solo esa factura (`leer_factura_raw()` / `leer_facturas_consistente()`, mismas 4 consultas con `WHERE`, preparadas, en una transacción). Mantiene la comparación exacta en PHP: MySQL (utf8mb4_general_ci) encontraría `"2026-0001 "` como `2026-0001` — una mutación lo confirmó.
- `factura-pdf.php`: primero el token (sin tocar la base), después la factura. Mismo 404 y mismo texto para número inexistente, token malo o sin parámetros.

**Pruebas:** nueva `tests/factura_por_numero_test.php` (13 comprobaciones: resultado idéntico a la implementación anterior para facturas a cuotas con abonos y descuento, contado, anulada y sin líneas; null para vacío, inexistente, con espacios, parecido, inyección SQL). Mutaciones: sin comparación exacta falla; sin filtrar la factura falla; sin filtrar los abonos NO falla (no cambia el resultado porque los abonos se asignan por id de cuota: ese filtro es solo de rendimiento). De extremo a extremo con `php -S` y los ajustes de producción: PDF válido 200 `application/pdf`; token malo/inexistente/con espacio/sin parámetros 404 idéntico; tabla rota → 500 JSON genérico; base apagada → 503 genérico en catálogo, PDF y pedido (JSON), token malo sigue en 404 sin tocar la base; ninguna respuesta contiene SQLSTATE, nombre de base, host, rutas ni trazas. Las 9 pruebas pasan.

**Sin verificar:** la imagen real (Railway la construye). Tras desplegar, en los logs de Railway ya deberían aparecer los errores con prefijo `[enrisate]`.

**Pendiente de este tema:** los errores fatales de PHP (no excepciones) ahora dan una página 500 en blanco en vez del detalle: seguro, pero sin aviso amable. Páginas del panel (con sesión) no se probaron con la base caída, pero pasan por el mismo `db()`.

## 30 de septiembre, 2026 (auditoría · paso 1: cerrar la exposición)

Primer paso del plan de la auditoría con graphify. **El login queda fuera a propósito** (decisión de Sebastián): no se tocó la clave inicial en `config.php`/`README.md`, `ADMIN_HASH` ni el conteo de intentos.

**Qué estaba expuesto** (medido con Apache real sirviendo lo que entra a la imagen, con las reglas de antes): `docs/pendientes-internos.md`, `README.md`, `Dockerfile`, `composer.json/lock`, `.gitignore`, `docker/`, `datos/`, `tests/`, los diagramas de `.archify/` y **`/.git/config`** (si `.git` está en el contexto de construcción, entra a la imagen). Además `/assets/` y `/assets/img/productos/` listaban todos sus archivos. La imagen llevaba 1.616 archivos.

**Qué se hizo** (OWASP A05 · CWE-538/527/548/200):
- `.dockerignore` (primera barrera): `.git`, docs, `*.md`, `.archify`, `graphify-out`, `tests`, `vendor` (se reinstala con composer), `Dockerfile`. La imagen pasa de 1.616 a 144 archivos; se verificó que siguen adentro todos los que usa el arranque (esquema, `aplicar_esquema.php`, `datos/catalogo.php`, `docker/*`, composer).
- `.htaccess` (segunda barrera, y la única en un XAMPP local): `Options -Indexes`; 404 para rutas con punto (`/.git`, `/.env`…), carpetas internas (`includes, storage, tools, vendor, docs, tests, datos, docker`), extensiones de configuración/datos/respaldo (`md, sql, json, lock, ini, sh, log, bak, old, orig, swp, dist, yml`) y `Dockerfile`/`config.php`. Probado también con el repositorio COMPLETO (con `.git`) y sin `.dockerignore`: todo 404.
- Encabezados: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin` (el token del PDF viaja en la URL y no debe filtrarse por Referer), `Permissions-Policy` (sin cámara/micrófono/ubicación/pagos) y HSTS 180 días **solo si la visita llegó por HTTPS** (`X-Forwarded-Proto`), para no afectar un XAMPP en http. Sin `includeSubDomains` (no sabemos qué subdominios tendrá un dominio propio).
- Sin versiones: `docker/seguridad.ini` (`expose_php = Off`) y `docker/seguridad-apache.conf` (`ServerTokens Prod`, `ServerSignature Off`, `TraceEnable Off`); el `Dockerfile` los copia y activa `mod_headers`.
- Guarda de terminal (`PHP_SAPI !== 'cli'` → 404) en `tools/prueba_escritura.php` (¡escribe en la base!), `tools/prueba_descuento.php`, `tools/verificar_migracion.php` y `tests/cuotas_puras_test.php`, las únicas que no la tenían. Probado con `php -S`: 404 sin ejecutar nada.

**Sin verificar:** la imagen completa no se pudo construir aquí (Docker Hub respondió 429 a `composer:2` y `php:8.4-apache`); cada pieza se probó por separado y Railway hace la construcción real. Tras desplegar, comprobar en el dominio real que `/README.md`, `/docs/pendientes-internos.md` y `/.git/config` dan 404 y que el panel y el catálogo abren normal.

**Pendiente de este tema (no se hizo):** Content-Security-Policy (hay mucho JS en línea: requiere sacarlo a archivos primero); `Cache-Control: no-store` en páginas del panel; `assets/img/productos/_referencia_no_usar/` sigue siendo público (fotos de producto, no sensibles; decisión en el paso 7); el repositorio de GitHub sigue teniendo la clave inicial en el README (login, excluido).

## 30 de septiembre, 2026 (cuenta de cobro de todo o nada)

**Resuelto — `facturar.php` guardaba en cuatro pasos sueltos** (crear factura → agregar clienta → descontar saldo a favor → confirmar pedido). Reproducido con el código de master forzando que falle el último paso: quedaba la factura creada y el saldo ya gastado, pero el pedido seguía "pendiente" (se veía otra vez en Pedidos por revisar y se podía facturar dos veces). Además el saldo y el estado del pedido se leían ANTES de guardar: dos personas podían gastar el mismo saldo o facturar el mismo pedido a la vez.
- Nuevo `includes/cuentas.php` → `crear_cuenta_desde_formulario(array $post): string`: la lógica que estaba dentro de `facturar.php` (movida tal cual), ahora dentro de `con_bloqueos(['facturas','clientes','pedidos'], …)` + `en_transaccion(…)`. `facturar.php` solo llama a la función y muestra el mensaje (`DomainException` = error de formulario, `RuntimeException` = no consiguió turno).
- `includes/db.php`: `con_bloqueos()` (varios candados siempre en el mismo orden: facturas, clientes, pedidos, catálogo → no hay deadlock) y `en_transaccion()` (se une a la transacción de afuera si ya hay una). `guardar_facturas/clientes/pedidos/catalogo` ahora usan la transacción de afuera si existe, en vez de abrir la suya (PDO no anida).
- **Orden que importa:** primero los candados y DESPUÉS la transacción. La transacción ve la base como estaba en su primera lectura; si esperara el candado ya dentro, leería una copia vieja.
- **Nuevo comportamiento:** facturar un pedido que ya está confirmado (doble clic) o descartado se rechaza con aviso y no crea nada.
- Prueba nueva `tests/cuentas_test.php` (+ `_worker_cuentas.php`): caso normal, cuotas + saldo + pedido, todo o nada (fallo forzado con un trigger de MySQL en el último paso: no queda factura, el saldo no se gasta, el pedido sigue pendiente y reintentar funciona), doble envío, validaciones y 10 procesos a la vez (6 con el mismo saldo, 4 con el mismo pedido). Mutaciones: sin transacción falla 4 comprobaciones; sin el chequeo del pedido, 3; sin candados falla siempre el del pedido (4 de 4 veces: 4 facturas del mismo pedido) y el del saldo en 2 de 4 corridas (la ventana es de milisegundos), así que la prueba en conjunto siempre lo detecta. Probado también de extremo a extremo con el servidor real (login → facturar desde pedido → doble clic).
- **Regla para el futuro:** una operación que toque varios recursos usa `con_bloqueos([...])` y `en_transaccion()`; nunca tomar un candado suelto mientras se tiene otro.
- Sigue igual: `catalogo_cache` etc. (ver entrada anterior); `tools/normalizar_telefonos_existentes.php` llama a `guardar_*` directo (migración única ya ejecutada).

## 30 de septiembre, 2026 (revisión con graphify)

Se construyó el grafo de conocimiento del proyecto con graphify (56 archivos: 330 nodos de código + 68 de los documentos, 47 comunidades; 88 % de las conexiones extraídas directamente del código, 12 % inferidas). Los nodos más conectados fueron `db()`, `guardar_catalogo()`, `leer_clientes()` y `con_bloqueo_facturas()`: la estructura se concentra en el acceso a MySQL y en los guardados que reescriben todo. De ahí salieron dos arreglos:

**1. Candado para clientas, pedidos y catálogo (antes solo facturas).** Reproducido primero con el código anterior: 20 clientas creadas a la vez → quedaban 8; 20 pedidos → 13 (y un deadlock); 20 productos agregados → 0; 20 descuentos de $1.000 al saldo a favor de una clienta → quedaba $94.000 en vez de $80.000 (se descontaba MENOS de lo usado: la clienta conservaba saldo ya gastado); editar el precio de dos productos a la vez → un cambio se perdía.
- `con_bloqueo($recurso, $fn)` en `includes/db.php` (genérico, `GET_LOCK('enrisate_<recurso>:<base>', 15)`, anidable por recurso). `con_bloqueo_facturas()` ahora solo delega en él (mismo nombre de candado que antes).
- Envuelven su ciclo leer→cambiar→guardar: clientas (`agregar_cliente`, `actualizar_cliente`, `eliminar_clientes`, `restar_saldo_favor`, `agregar_cliente_desde_factura`, `sincronizar_clientes_desde_facturas`), pedidos (`crear_pedido`, `actualizar_pedido`, `descartar_pedido`) y catálogo (`agregar_producto`, `actualizar_precio_costo`, `actualizar_descripcion`, `actualizar_producto`, `eliminar_productos`).
- El catálogo tiene una caché en memoria por petición: `catalogo_cache(true)` la recarga DENTRO del candado (si no, se escribe una copia vieja). Se comprobó con una mutación: sin esa recarga la prueba falla.
- Prueba nueva `tests/concurrencia_otros_test.php` (+ `_worker_concurrencia_otros.php`): 10 procesos en paralelo. Con el arreglo: 20/20 de todo, saldo exacto, 2/2 cambios de precio; repetida 8 veces sin fallar. Sin candado falla en 5 de 6 comprobaciones.
- **Regla:** no tomar un recurso mientras se tiene otro distinto reservado (evita bloqueos cruzados). Hoy ninguna función anida recursos distintos. `tools/normalizar_telefonos_existentes.php` (migración única ya ejecutada) llama a `guardar_*` directo sin candado.
- **Sigue sin cubrir:** `facturar.php` toca facturas, clientas y pedidos en pasos separados (crear factura → agregar clienta → restar saldo → confirmar pedido). Cada paso es seguro por sí solo, pero si la petición se cae a medias puede quedar la factura creada sin el pedido confirmado. Requeriría una transacción única, no solo candados.

**2. Bug anterior y más grave: a partir del segundo pedido con productos ya no se podía guardar nada.** `pedido_lineas` NO tiene borrado en cascada (las tablas de facturas sí), pero `guardar_pedidos()` solo hacía `DELETE FROM pedidos`; al reinsertar las líneas de un pedido existente chocaban con las viejas (clave única `pedido_numero + orden`) → error 1062. Reproducido con el código de master: el pedido 1 se guardaba, el 2 y el 3 fallaban; también fallaría confirmar o descartar un pedido con líneas. Arreglo: `guardar_pedidos()` borra `pedido_lineas` antes que `pedidos` (una línea, sin cambio de esquema; la migración vieja `migrar_json_a_mysql.php` ya lo hacía). Prueba nueva `tests/pedidos_test.php` (12 comprobaciones); sin el arreglo falla. **No se pudo verificar en producción** (sin acceso a la base de Railway desde aquí): si allá el esquema es el de `storage/esquema.sql`, el bug existía tal cual; conviene revisar en el panel si hay pedidos que "no llegaron" desde el catálogo público.

**Observado en el grafo, sin tocar (por riesgo o poco valor):**
- `e()` y `normalizar_telefono()` viven en `includes/auth.php` pero las usan clientas, facturas y páginas: helpers mal ubicados (mover a un `includes/util.php`).
- `includes/facturas.php` (~1.100 líneas) mezcla catálogo, facturas, formato de pesos/fechas, enlaces de WhatsApp y token del PDF: candidato a dividirse (catálogo aparte), refactor grande que pide su propia sesión.
- 70 nodos aislados son variables del DOM en JS (ruido, no huecos reales). Sin ciclos de importación.

## 30 de septiembre, 2026 (noche)

**Resuelto — pérdida de datos al guardar facturas a la vez** (el riesgo anotado esta mañana en `guardar_facturas()`). Reproducido primero con el código anterior: 2 procesos registrando 25 abonos cada uno en la misma factura dejaron **5 de 50**, 2 procesos creando 12 facturas cada uno dejaron **9 de 25**, y un lector vio **41 lecturas con una factura a medias** — y todos los procesos reportaron "ok", sin ningún error visible.
- Causa: cada escritura hace leer TODO → cambiar una factura → `DELETE FROM facturas` + reinsertar TODO. Dos escrituras cruzadas: la segunda guarda una copia vieja y borra lo de la primera. Además `leer_facturas_raw()` hacía 4 consultas sueltas y podía mezclar datos de antes y después de un guardado (los ids de cuota se regeneran en cada guardado).
- Arreglo (opción A, sin cambios de esquema ni migración): `con_bloqueo_facturas()` en `includes/facturas.php` usa `GET_LOCK('enrisate_facturas:<base>', 15)` y serializa las escrituras. Envuelve el ciclo completo leer→cambiar→guardar de `crear_factura`, `actualizar_factura`, `registrar_abono`, `aplicar_descuento_cuota`, `agregar_lineas_factura`, `anular_factura` y `reactivar_factura` (anidable; solo el nivel externo toma/suelta el candado; si no consigue turno en 15 s lanza error en vez de guardar a ciegas). `leer_facturas_raw()` ahora lee dentro de una transacción (foto consistente).
- Prueba nueva `tests/concurrencia_test.php` (+ `tests/_worker_concurrencia.php`, el guion bajo evita que `correr_todas.php` lo tome como prueba): 5 procesos PHP reales en paralelo. Con el arreglo: 50/50 abonos, 25/25 facturas, sin números repetidos, 0 lecturas incoherentes; repetida 5 veces seguidas, siempre OK.
- Mutaciones: sin el candado → la prueba falla (26/50 abonos, 6/25 facturas); sin la transacción de lectura → falla solo la verificación de lecturas. Ambas se revirtieron.
- **Regla a futuro:** toda función que LEA facturas para luego ESCRIBIRLAS debe hacerlo dentro de `con_bloqueo_facturas()`. `tools/normalizar_telefonos_existentes.php` llama a `guardar_facturas()` directo, pero es una migración de una sola vez ya ejecutada.
- **Sigue pendiente (mismo patrón, no tocado):** `guardar_clientes()` y `guardar_pedidos()` reescriben todo igual, sin candado. El más delicado es el saldo a favor (`restar_saldo_favor`). Y `facturar.php` toca facturas, clientes y pedidos en un mismo flujo, así que el candado de facturas no lo cubre entero. Opción B (actualizar solo la fila afectada) queda para cuando haya cientos de facturas.

## 30 de septiembre, 2026

**Resuelto — las 3 pruebas automáticas rotas:** `cuota_unica_test.php`, `facturas_cuotas_test.php` y `leer_facturas_raw_test.php` llamaban a `ruta_facturas()`, una función del almacenamiento en JSON que desapareció con la migración a MySQL (no tenía nada que ver con Railway ni con la conexión: fallaban igual con un MySQL sano — verificado corriéndolas contra un MariaDB local antes de tocarlas). Se reescribieron para usar las funciones reales contra MySQL.
- Nuevo `tests/bootstrap.php`: se niega a correr si `DB_NOMBRE` no termina en `_test` (las pruebas borran facturas; se verificó que contra una base llamada `railway` se detiene sin crear ni tocar nada), aplica `storage/esquema.sql` y deja las tablas vacías antes y después.
- Nuevo `tests/correr_todas.php`: corre las 4 y resume. Uso: `MYSQLDATABASE=enrisate_test php tests/correr_todas.php` (ver README, "Pruebas automáticas").
- `leer_facturas_raw_test.php` se rehízo entero, no solo se parchó: su premisa (que el JSON crudo no se "re-migraba") ya no existe con MySQL. Ahora comprueba que lo guardado es lo leído (líneas, cuotas, abonos vs descuentos con motivo, fecha_pago null, anulaciones, acentos/ñ/emoji) y que tocar una factura no altera otra — importante porque `guardar_facturas()` hace `DELETE FROM facturas` + reinserta TODO en cada cambio.
- Se comprobó que las pruebas de verdad detectan errores rompiendo el código a propósito (3 mutaciones). La del charset (`utf8mb4`→`latin1`) al principio NO se detectaba —el texto regresa igual pero queda corrupto en la base—, así que se agregó una verificación con `CHAR_LENGTH` directamente en MySQL.
- `cuotas_puras_test.php` no se tocó (solo prueba funciones puras, sin base de datos).

**Riesgo observado (RESUELTO esa misma noche, ver la entrada de arriba):** `guardar_facturas()` lee todo, cambia en memoria y reescribe todo (sin bloqueo). Si dos personas guardan cambios casi al mismo tiempo (p. ej. registrar un abono mientras llega un pedido y se factura), la segunda escritura puede pisar la primera. Con una sola administradora es poco probable, pero es el punto débil natural de este diseño; el arreglo sería actualizar solo la fila afectada (UPDATE/INSERT puntual) en vez de reescribir todo.

**Corrección de la entrada del 13 de septiembre:** el pendiente "Productos (`inventario.php`) todavía usa el patrón viejo" ya se resolvió ese mismo día (tabla proporcional + tarjeta en celular, igual que Pedidos/Cartera/Clientes); la nota nunca se actualizó.

## 19 de septiembre, 2026

**Aprendizaje / causa raíz encontrada — subir foto de producto fallaba (reportado con "Crema de Peinar Rizos", SKU LP-06):**

El Dockerfile NUNCA configuró `upload_max_filesize` ni `post_max_size` en PHP — así que en producción (imagen `php:8.4-apache` sin ningún `php.ini` propio) rigen los valores de fábrica de PHP: `upload_max_filesize = 2M`, `post_max_size = 8M`. Mientras tanto, `includes/imagenes.php` (`guardar_imagen_producto()`) y el texto del modal en `inventario.php` ("Elegir foto (JPG, PNG o WebP, máx. 8 MB)") siempre asumieron que el límite real era 8 MB. Resultado: cualquier foto de más de 2 MB — una foto de celular moderna normal, nada raro — nunca llegaba ni siquiera al código de la app: PHP la rechazaba antes, en el parseo del propio formulario multipart. **No es un problema de un producto específico** (no hay nada especial en LP-06/"Crema de Peinar Rizos"): es cualquier foto que pese más de 2 MB, para cualquier producto, y probablemente ya venía pasando antes sin que se asociara a esta causa.

Dos comportamientos distintos según cuánto se pase:
- Si SOLO el archivo supera `upload_max_filesize` (pero el resto del POST sigue bajo `post_max_size`): PHP sí entrega `$_POST` completo, pero `$_FILES['imagen']['error']` llega en `UPLOAD_ERR_INI_SIZE` (1) → el código ya mostraba "No se pudo recibir el archivo. Inténtalo de nuevo." (mensaje genérico pero no engañoso).
- Si el POST completo (foto incluida) supera `post_max_size`: PHP descarta TODO el formulario antes de que el código lo vea — `$_POST` y `$_FILES` llegan **vacíos**. Antes de hoy, eso caía en el chequeo de CSRF (`$_POST['csrf'] ?? null` = null) y mostraba "La página estuvo abierta demasiado tiempo. Recárgala e intenta de nuevo." — un mensaje que no tiene nada que ver con la causa real y hace perder tiempo buscando en el lugar equivocado.

**Corregido hoy** (rama `claude/tender-babbage-hggtl9`, commit `27ffb6e`, pendiente de llevar a producción):
- `docker/uploads.ini` (nuevo) + `Dockerfile`: sube los límites reales a `upload_max_filesize=10M` / `post_max_size=12M` — con margen sobre los 8 MB que ya valida el código.
- `inventario.php`: detecta el caso de `$_POST`/`$_FILES` vacíos con `Content-Length` > 0 (POST descartado por `post_max_size`) ANTES del chequeo de CSRF, y muestra el mensaje real ("La imagen pesa demasiado...") en vez del de sesión expirada.

**Para la próxima vez que algo similar no cuadre:** si un formulario con archivo adjunto falla "sin razón aparente" (sobre todo si el mensaje de error no tiene relación con lo que se hizo, tipo "sesión expirada" al subir un archivo), sospechar primero de `upload_max_filesize`/`post_max_size` de PHP antes de mirar el código de negocio — revisar que el Dockerfile (o el `php.ini` que corresponda) los tenga configurados explícitamente, porque los valores de fábrica de PHP (2M/8M) casi nunca alcanzan para una foto de celular actual.

## 13 de septiembre, 2026

**Hecho:** se corrigió el desborde horizontal / responsive roto en celular en 3 módulos del panel: `pedidos.php` ("Pedidos por revisar"), `cartera.php` (las 3 tablas: Cartera pendiente, Historial de facturas, Detalle por SKU) y `clientes.php`. Patrón aplicado en los tres: tabla con columnas proporcionales (`table-layout: fixed`) en escritorio/tablet ≥641px, tarjeta desplegable (componente `.fila-lista` / `.filas-esencial` en `assets/css/enrisate.css`) en celular ≤640px. Causa raíz encontrada en Pedidos y Cartera: `.acciones-pedido`/`.acciones-cartera`/`.acciones-factura` ponían `display:flex` directamente sobre un `<td>`, lo que rompe el cálculo de columnas de tabla en Chromium — se corrigió envolviendo esas acciones en un `<div>` interno. En Cartera pendiente, el formulario de descuento por fila (que se abre/cierra por id) se duplicó con sufijos `-t`/`-c` para evitar colisión de id entre la copia de tabla y la de tarjeta. En Clientes, el mecanismo de edición inline (`vista-<id>`/`edicion-<id>`, manejado por `seleccion-lote.js`) se adaptó igual con sufijo `-movil`, y las casillas de selección pasaron a compartir `value` en vez de tener `id` propio. A pedido de Sebastián también se quitó la sección "Historial reciente" de `pedidos.php` (tabla + cálculo de `$historial`, ya no se usa). Todo probado con Playwright headless (320–1280px, sin scroll de página, sin scroll de tabla, sin ids duplicados, capturas de pantalla, y clics simulados en el flujo de editar/cancelar de Clientes) y desplegado a producción en Railway (4 deploys, todos confirmados con logs de tráfico real).

**Pendiente para la próxima sesión:** el módulo de Productos (`inventario.php`) todavía usa el patrón viejo (tabla con `.tabla-scroll`, sin columnas proporcionales ni tarjeta en celular) — no se tocó hoy. Si Sebastián pide "lo mismo para Productos", aplicar el mismo patrón (`.tabla-clientes`/`.tabla-pedidos-revisar`/`.tabla-cartera-*` como referencia en `assets/css/enrisate.css`). Ojo: `inventario.php` tiene su propio mecanismo de edición por fila (modal compartido para editar/descripción/foto, más el carrusel de marcas y buscador) — revisar si hay el mismo riesgo de ids duplicados antes de duplicar filas para la tarjeta.

## 6 de septiembre, 2026

**Pendiente nuevo — acceso/seguridad del login:** se conversó con Sebastián sobre 3 opciones para mejorar el acceso al panel (de más a menos segura): 1) iniciar sesión con la cuenta de Google del correo oficial de Enrísate (gratis, pero mejor esperar a tener el dominio real para configurarlo bien), 2) código de verificación por correo además de la contraseña actual, 3) recuperar la contraseña por correo si se olvida (la más simple, la que menos aporta a seguridad real). Sebastián quiere la opción 1 como meta cuando haya dominio, pero de una vez quiere probar algo real ahora: implementar y probar de verdad el envío de recuperación de contraseña por correo (opción 3), enviando un correo real de prueba. Para eso hacen falta de él: el correo oficial de Enrísate, y una forma de enviar correos desde ese correo (por ejemplo una "contraseña de aplicación" de Gmail/Google Workspace). Sigue pendiente que él la dé.

## 5 de septiembre, 2026

**Pendiente nuevo:** antes de comprar un dominio y publicar el sitio en internet, cerrar los huecos de seguridad ya identificados: base de datos sin contraseña (usuario root), y la clave inicial del panel ("Enrisate2026*") visible en un comentario del código (`config.php`). Sebastián lo dejó para después porque ahora quiere mejorar unos mensajes automáticos de WhatsApp.

**Resuelto de la entrada del 4 de septiembre:** el problema del paso de 1000 en Márgenes ya no aplica — Márgenes se eliminó por completo (Productos ya cubre precio y costo).

**Resuelto de la entrada del 2 de septiembre:** el segundo grupo de fotos de referencia (DES01, LP-02, LP-05 a LP-09, POC-01 a POC-10) se archivó en `assets/img/productos/_referencia_no_usar/`, para que no reaparecieran solas al conectar la foto de cada producto directamente por SKU.

**Pendiente para la próxima sesión:**
- Si Sebastián ya subió alguna foto de producto antes del ajuste de tamaño, correr una vez `php tools/reoptimizar_fotos_productos.php` para que esas fotos se recompriman al tamaño correcto.
- Decidir qué uso se le va a dar a la herramienta Pepita (conectada hoy, sin definir para qué se va a usar todavía).
- Sigue pendiente desde antes: revisar la configuración de seguridad de la base de datos antes de publicar el sitio en internet (usuario root sin contraseña, clave inicial del panel visible en un comentario del código).
- Sigue pendiente desde antes: 3 de las 4 pruebas automáticas del proyecto están rotas desde la migración a MySQL de hace unas semanas (llaman a una función que ya no existe).

## 4 de septiembre, 2026

Pendiente para la próxima sesión (nota técnica interna):
- El mismo problema de los montos no-redondos (paso de 1000) sigue sin corregir en la página de Márgenes.
- Antes de publicar el sitio en internet hay que revisar la configuración de la base de datos (usuario root sin contraseña, clave inicial del panel visible en un comentario del código).
- 3 de las 4 pruebas automáticas del proyecto están rotas desde la migración a MySQL de hace unas semanas (llaman a una función que ya no existe).

## 2 de septiembre, 2026

**Hecho:** se borraron los 25 duplicados marcados como "seguro borrar" en `assets/img/productos/` (ACC-01.jpg/.png, ACC-02.jpg/.png, ACC-03.png, ACC-04.png, ACC-05.jpg/.png, ACC-07.jpg/.png, ACC-08.jpg/.png, ACC-09.png, CA-01.png, ET-01.png, ET-03.png, ET-04.png, ET-05.png, FEM-01.jpg, FEM-03.jpg, FEM-04.jpg, FEM-05.jpg, FEM-06.jpg, FEM-07.jpg, LP-04.png). Se verificó primero que ninguno estuviera referenciado en el código PHP. Los `.webp` activos de cada uno se conservan.

**Pendiente:** el segundo grupo (fotos de referencia desactivadas) sigue ahí, sin tocar — son productos que el código todavía referencia en `catalogo-publico.php` y `datos/catalogo.php` aunque no se muestran hoy en el catálogo. Es opcional borrarlos o archivarlos aparte; decidir cuando se sepa si esos productos se van a reactivar.

**Ruta:** `C:\Users\Virny\Desktop\Sebas info\XAMPP\htdocs\enrisate-admin\assets\img\productos\`

DES01.jpg, LP-02.webp, LP-05.png, LP-06.png, LP-07.png, LP-08.png, LP-09.png (suelto — no está referenciado en el código), POC-01.png, POC-02.png, POC-03.png, POC-04.png, POC-05.webp, POC-06.png, POC-07.png, POC-08.png, POC-09.png, POC-10.png

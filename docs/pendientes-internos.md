# Pendientes internos — enrisate-admin

_Nota privada de trabajo. No se muestra al cliente — solo para dar continuidad entre sesiones. Las entradas nuevas se agregan arriba._

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

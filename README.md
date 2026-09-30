# Enrísate · Panel de administración (capa 3: MySQL)

Pantalla de ingreso, facturación, clientas, pedidos, inventario y cartera —
ahora con MySQL (XAMPP) como almacenamiento en vez de los JSON de `storage/`.

## Subirlo a Railway

El proyecto trae un `Dockerfile`: en Railway basta con crear un servicio
"Deploy from GitHub repo" apuntando a este repositorio — Railway detecta el
Dockerfile solo y construye la imagen (PHP 8.4 + Apache + las extensiones
que la app necesita).

Además del servicio de la app, hace falta:

1. **Agregar un servicio de MySQL** al mismo proyecto de Railway ("New" →
   "Database" → "MySQL"). Railway ya deja listas las variables
   `MYSQLHOST`, `MYSQLPORT`, `MYSQLUSER`, `MYSQLPASSWORD`, `MYSQLDATABASE`
   — el servicio de la app las lee solo (ver `config.php`), no hay que
   escribir nada a mano. Solo falta enlazarlas: en el servicio de la app,
   pestaña "Variables", agrégalas como referencia al servicio de MySQL
   (Railway ofrece esto con un clic, "Add variable reference").
2. **Las tablas se crean solas** la primera vez que arranca el contenedor
   (`docker/entrypoint.sh` corre `tools/aplicar_esquema.php`, que aplica
   `storage/esquema.sql`) — no hay que entrar a phpMyAdmin ni importar nada
   a mano.
3. **Cambiar la clave del panel**: por defecto viene la clave de pruebas
   (`Enrisate2026*`, la misma de XAMPP). Antes de compartir el link real,
   corre `php tools/generar_hash.php "TuNuevaClave"` y pega el resultado
   como variable de entorno `ADMIN_HASH` en Railway (no hace falta tocar
   el código ni volver a subir nada).
4. **Fotos de producto**: se guardan como archivos en
   `assets/img/productos/`. Sin nada más, cualquier foto que se suba desde
   el panel *en Railway* se perdería en el próximo despliegue (el
   contenedor se reconstruye desde cero cada vez). Para que las fotos
   queden guardadas de verdad, hay que agregarle al servicio un
   **Volume** de Railway montado en esa misma carpeta
   (`/var/www/html/assets/img/productos`).

## Base de datos (MySQL / XAMPP)

Este proyecto ya no guarda los datos en `storage/*.json` — los guarda en
MySQL. Para dejarlo andando en un XAMPP nuevo (o para migrar los JSON que
ya tenías):

1. Enciende **Apache** y **MySQL** desde el panel de XAMPP.
2. Importa el esquema: abre phpMyAdmin (`http://localhost/phpmyadmin`),
   pestaña **Importar**, elige `storage/esquema.sql` y dale a Continuar.
   (Crea la base `enrisate_admin` y sus tablas; si ya existía, no borra nada
   porque usa `CREATE TABLE IF NOT EXISTS`.)
3. Desde una terminal, parado en la carpeta del proyecto, corre la
   migración — lee los JSON de `storage/` y llena las tablas:
   ```bash
   php tools/migrar_json_a_mysql.php
   ```
   Esto además guarda una copia de los JSON originales en
   `storage/respaldo-json/<fecha-hora>/` antes de tocar nada, y se puede
   correr varias veces sin duplicar datos (cada corrida reemplaza el
   contenido de las tablas con lo que haya en los JSON en ese momento).
4. Verifica que todo haya cuadrado:
   ```bash
   php tools/verificar_migracion.php
   ```
   Compara conteos y sumas entre los JSON y lo que quedó en MySQL. Si dice
   "TODO OK", ya puedes usar la app normalmente. Cuando confirmes que todo
   está bien, puedes borrar `tools/verificar_migracion.php` y
   `tools/prueba_escritura.php` (son solo herramientas de verificación, no
   las usa la app).

Si tu XAMPP tiene otro usuario, contraseña o puerto de MySQL, edítalo en
`config.php` (constantes `DB_HOST`, `DB_PORT`, `DB_NOMBRE`, `DB_USUARIO`,
`DB_CLAVE`). Por defecto asume lo típico de XAMPP: `127.0.0.1`, usuario
`root`, sin contraseña.

### Actualización 2026-08-22: descuentos en cartera

Si ya tenías el proyecto andando desde antes de esta fecha, falta un
cambio chiquito en la base de datos para poder usar el botón "Aplicar
descuento" de Cartera (perdonar el saldo pendiente de una cuota sin que
cuente como plata recibida en efectivo):

1. Abre phpMyAdmin, entra a la base `enrisate_admin`.
2. Pestaña **Importar**, elige `storage/actualizar-2026-08-22-descuentos.sql`
   y dale a Continuar.

No borra ni cambia ningún dato existente — solo le agrega a la tabla
`factura_abonos` las columnas `tipo` y `motivo` (todos los abonos que ya
tenías quedan como `tipo='abono'` automáticamente). Un proyecto nuevo que
importe `storage/esquema.sql` desde cero ya las trae de una vez.

### Actualización 2026-08-23: categoría de producto

Agrega la columna `categoria` a la tabla `productos` (shampoo, acondicionador,
crema, aceite...). El catálogo público la usa para darle a cada tarjeta sin foto
un ícono y un color propios, y para que el buscador encuentre "shampoo" en todas
las marcas a la vez.

1. Abre phpMyAdmin, entra a la base `enrisate_admin`.
2. Pestaña **Importar**, elige `storage/actualizar-2026-08-23-categorias.sql`
   y dale a Continuar.

No borra ni cambia ningún dato: agrega la columna y la rellena deduciéndola del
nombre de cada producto (90 de 102 quedan clasificados solos). Los que el nombre
no alcanza a identificar — "Divino Potao", "Ancestral", "Karsell" — quedan sin
categoría y se asignan a mano desde **Productos**, que ahora los lista arriba con
un aviso. Mientras no la tengan, el catálogo les muestra el nombre de la marca.

De paso corrige la marca `kerasolis` → `Kerasolis`, que por estar en minúscula
salía de última en el catálogo.

La lista de categorías, sus colores y sus íconos están en
`includes/categorias.php`: para agregar una nueva, añádela ahí y aparece sola en
el desplegable de Productos y en el catálogo.

Los archivos `storage/*.json` **no se borran** con la migración — quedan
como estaban, sirviendo de respaldo adicional. Los únicos archivos que
siguen usando JSON son `storage/intentos.json` y
`storage/pedidos_intentos.json` (control de fuerza bruta y de spam por IP:
son cachés de seguridad efímeras, no datos del negocio).

## Correr en local

```bash
cd enrisate-admin
composer install
php -S localhost:8000
```

`composer install` solo hace falta la primera vez (y cada vez que cambie
`composer.json`): descarga en `vendor/` las librerías de PHP que usa el
proyecto — hoy únicamente dompdf, para generar el PDF de cada factura. Sin
ese paso, `factura-pdf.php` se cae con un error de "clase no encontrada".

Abre http://localhost:8000

**Usuario:** `admin`
**Contraseña:** `Enrisate2026*`

## Pruebas automáticas

```bash
MYSQLDATABASE=enrisate_test php tests/correr_todas.php
```

Corre las 9 pruebas de `tests/` (cuotas, abonos, descuentos, pedidos del
catálogo, crear una cuenta de cobro de todo o nada, leer una sola factura, que lo que se guarda en
MySQL sea exactamente lo que se lee, y que varias personas guardando a la vez
—facturas, clientas, pedidos o catálogo— no se pisen; las de concurrencia lanzan
procesos en paralelo y tardan unos 10 segundos cada una). Necesitan una base **aparte y
vacía cuyo nombre termine en `_test`** (créala vacía en phpMyAdmin o con
`CREATE DATABASE enrisate_test CHARACTER SET utf8mb4`; el esquema se aplica
solo). Crean y borran facturas de verdad, así que se niegan a correr contra
cualquier otra base — nunca las apuntes a la de Gina ni a la de Railway. Si tu
MySQL no es el de XAMPP por defecto, agrega también `MYSQLHOST`, `MYSQLPORT`,
`MYSQLUSER` y `MYSQLPASSWORD`.

## Antes de subirlo a un servidor

1. Cambia la contraseña:
   ```bash
   php tools/generar_hash.php "TuClaveNueva2026"
   ```
   Pega el hash en `ADMIN_HASH` dentro de `config.php`.
2. `storage/` debe tener permiso de escritura (`chmod 775 storage`).
3. Usa HTTPS. La cookie de sesión se marca `secure` sola cuando detecta HTTPS.

## Qué ya trae

- Contraseña guardada como hash bcrypt (nunca en texto plano)
- Token CSRF en el formulario
- Bloqueo de 5 minutos tras 5 intentos fallidos (por IP)
- Cierre de sesión por 30 minutos de inactividad
- `session_regenerate_id()` al entrar
- `includes/`, `storage/` y `tools/` bloqueados vía `.htaccess`

## Facturación (capa 2)

`facturar.php` arma la cuenta, `factura.php` la muestra lista para imprimir.

- 92 productos del catálogo maestro, agrupados por marca
- Descuento opcional
- Pago **de contado** o **en 2 cuotas**: primera hoy, segunda con fecha de vencimiento
- Ganancia visible solo para ustedes — nunca sale en el documento
- Los precios se recalculan en el servidor, no se confía en el navegador
- PDF: botón "Guardar como PDF" (usa la impresión del navegador) para ustedes,
  y un PDF de verdad generado con dompdf (`factura-pdf.php`) para la clienta
- El mensaje de WhatsApp ya viene con el link de descarga del PDF incluido —
  no hay que adjuntar nada a mano. El link no pide iniciar sesión: lo protege
  un token que se calcula a partir del número de factura (no se guarda en
  ningún lado), así nadie puede armar el link de otra factura adivinando el
  número
- Cada factura queda guardada en `storage/facturas.json` — de ahí saldrá la cartera

Para cambiar precios: edita `datos/catalogo.php`.

## Archivos

```
config.php            identidad, credenciales, parámetros de seguridad
index.php             pantalla de ingreso
dashboard.php         cascarón protegido
logout.php            cierre de sesión
includes/auth.php     sesión, CSRF, control de intentos
includes/escena.php   fondo de hebras (SVG)
assets/css/           estilos
assets/img/logo.svg   isotipo (reemplázalo cuando tengan el definitivo)
facturar.php          armar la cuenta de cobro
factura.php            factura imprimible / PDF
factura-pdf.php        PDF de verdad de una factura (público, sin sesión, protegido por token)
composer.json          librerías de PHP del proyecto (hoy: dompdf)
includes/db.php       conexión PDO a MySQL
includes/facturas.php catálogo, facturas y numeración (ahora en MySQL)
includes/clientes.php clientas (ahora en MySQL)
includes/pedidos.php  pedidos del catálogo público (ahora en MySQL)
datos/catalogo.php    catálogo maestro original (solo semilla de arranque)
includes/categorias.php  lista de categorías, sus colores y sus íconos
storage/esquema.sql                            esquema de la base de datos
tools/aplicar_esquema.php      aplica storage/esquema.sql (a mano, o solo al arrancar en Railway)
Dockerfile, docker/entrypoint.sh   imagen y arranque del contenedor en Railway
storage/actualizar-2026-08-22-descuentos.sql   agrega tipo/motivo a factura_abonos (proyectos ya existentes)
storage/actualizar-2026-08-23-categorias.sql   agrega la columna categoria a productos
cartera-excel.php                              descarga en Excel de la cartera vencida/pendiente
tools/migrar_json_a_mysql.php  migra storage/*.json → MySQL
tools/verificar_migracion.php  compara JSON vs MySQL (herramienta, no la usa la app)
tools/prueba_escritura.php     prueba de escritura de extremo a extremo (herramienta, no la usa la app)
tools/prueba_descuento.php     prueba de aplicar_descuento_cuota() (herramienta, no la usa la app)
tools/generar_hash.php
includes/errores.php           errores no previstos: aviso genérico en pantalla, detalle al registro
includes/cuentas.php           crear una cuenta de cobro (factura + clienta + saldo + pedido) en una sola transacción
tests/correr_todas.php         corre todas las pruebas automáticas (ver "Pruebas automáticas")
tests/bootstrap.php            arranque común de las pruebas: exige una base _test, aplica el esquema y la deja vacía
storage/              intentos fallidos, pedidos_intentos.json y respaldo-json/ (se crean solos)
```

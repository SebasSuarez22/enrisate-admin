# Enrísate · Panel de administración (capa 1)

Pantalla de ingreso + cascarón del dashboard. Sin base de datos todavía.

## Correr en local

```bash
cd enrisate-admin
php -S localhost:8000
```

Abre http://localhost:8000

**Usuario:** `admin`
**Contraseña:** `Enrisate2026*`

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
- PDF: botón "Guardar como PDF" (usa la impresión del navegador, sin librerías)
- Botón de WhatsApp con el mensaje ya redactado
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
factura.php           factura imprimible / PDF
includes/facturas.php almacén de facturas y numeración
datos/catalogo.php    los 92 productos (precio y costo)
tools/generar_hash.php
storage/              intentos fallidos y facturas (se crean solos)
```

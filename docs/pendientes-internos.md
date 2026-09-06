# Pendientes internos — enrisate-admin

_Nota privada de trabajo. No se muestra al cliente — solo para dar continuidad entre sesiones. Las entradas nuevas se agregan arriba._

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

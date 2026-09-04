# Pendientes internos — enrisate-admin

_Nota privada de trabajo. No se muestra al cliente — solo para dar continuidad entre sesiones. Las entradas nuevas se agregan arriba._

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

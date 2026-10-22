# Enrísate · Panel de administración
# Imagen para Railway (o cualquier servidor que corra Docker).
#
# PHP + Apache con las extensiones que la app necesita:
#   - pdo_mysql/mysqli: para hablar con la base de datos.
#   - gd: para procesar las fotos de producto (recorte, compresión).
#
# Versión de PHP fijada a propósito (no "8.4-apache"): con la etiqueta
# flotante, Railway puede reusar la imagen base que ya tenía guardada y
# seguir corriendo una versión vieja sin avisar. 8.4.27 (21 oct 2026)
# corrige PHP2Shell (CVE-2026-109618 y CVE-2026-109619): ejecución remota
# de código SIN iniciar sesión, más grave con mod_php (el que usa esta
# imagen), con ataques ya reportados. Ante cada nueva versión de
# seguridad de PHP: subir este número y el mínimo de la revisión de abajo.
FROM php:8.4.27-apache

# Revisión al construir: si por error la imagen trae un PHP sin la
# corrección de PHP2Shell, la construcción se detiene acá y Railway deja
# andando la versión anterior, en vez de publicar un servidor vulnerable.
RUN php -r 'if (version_compare(PHP_VERSION, "8.4.27", "<")) { fwrite(STDERR, "PHP " . PHP_VERSION . " no tiene la corrección de PHP2Shell (hace falta 8.4.27 o más nueva).\n"); exit(1); }'

#
# Las librerías -dev (libpng-dev, etc.) NO se desinstalan después de
# compilar gd: "apt-get purge --auto-remove" se lleva de paso las
# librerías de verdad (libpng16, libjpeg, libwebp) porque quedaron
# marcadas como "instaladas solo por dependencia", y sin ellas gd no
# carga en tiempo de ejecución ("cannot open shared object file"). Pesa
# unos MB más la imagen, pero así no se rompe.
RUN docker-php-ext-install pdo_mysql mysqli \
    && apt-get update \
    && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev unzip \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install gd \
    && rm -rf /var/lib/apt/lists/*

# Sin esto, PHP corre con sus límites de fábrica (upload_max_filesize=2M,
# post_max_size=8M) — una foto de celular normal (2-6 MB) nunca llegaba
# ni siquiera a guardar_imagen_producto() (ver includes/imagenes.php, que
# sí valida hasta 8 MB): PHP la rechazaba antes, en el parseo del propio
# formulario, con un mensaje de error que no explica la verdadera razón.
COPY docker/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

# No anunciar versiones de PHP ni de Apache (ver los dos archivos).
COPY docker/seguridad.ini /usr/local/etc/php/conf.d/seguridad.ini
COPY docker/seguridad-apache.conf /etc/apache2/conf-available/zz-seguridad.conf

# Composer (solo para instalar las librerías de PHP listadas en
# composer.json — hoy únicamente dompdf, para generar el PDF de cada
# factura). No hace falta instalar el paquete completo de Composer, con
# copiar el ejecutable ya alcanza. "unzip" (arriba, junto a las
# librerías de gd) es aparte: sin eso, "composer install" se cae
# porque no tiene con qué descomprimir los paquetes que baja.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# El proyecto ya trae su propio .htaccess (bloquea carpetas y archivos
# internos, agrega encabezados de seguridad —por eso "headers"— y activa
# compresión y caché) — solo hace falta permitirle a Apache leerlo.
# La compresión (deflate) ya viene encendida en la imagen; el caché
# (expires) NO: sin "a2enmod expires" las reglas Expires del .htaccess
# se ignoraban en silencio y fotos, estilos y logos salían sin ninguna
# instrucción de caché.
#
# mpm_prefork es el único módulo de procesos compatible con mod_php.
# Se borran a mano los enlaces de mpm_event/mpm_worker (en vez de
# confiar en que "a2dismod" los detecte y los quite) para que quede
# garantizado sin importar cuál haya quedado activo de fábrica, y
# después se prende prefork.
RUN a2enmod rewrite headers expires \
    && a2enconf zz-seguridad \
    && rm -f /etc/apache2/mods-enabled/mpm_event.load /etc/apache2/mods-enabled/mpm_event.conf \
             /etc/apache2/mods-enabled/mpm_worker.load /etc/apache2/mods-enabled/mpm_worker.conf \
    && a2enmod mpm_prefork \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY . .

# Copia de respaldo de las fotos que ya vienen en el repositorio, ANTES
# de que Railway monte el Volume persistente sobre assets/img/productos
# (ver docker/entrypoint.sh). Un Volume nuevo siempre arranca vacío y
# tapa lo que la imagen traía en esa misma ruta — sin este respaldo en
# otra carpeta, las fotos que ya están guardadas en git desaparecerían
# la primera vez que el Volume se conecta.
RUN cp -r assets/img/productos assets/img/productos-semilla

# Instala dompdf (ver composer.json) sin las dependencias de desarrollo.
# vendor/ no se sube al repositorio (ver .gitignore) — se genera acá,
# como una capa más del build, igual que las extensiones de PHP de
# arriba.
RUN composer install --no-dev --no-interaction --optimize-autoloader

# "COPY" deja todo dueño de root, pero Apache atiende las visitas como
# el usuario www-data — sin esto, cosas como guardar el conteo de
# intentos de login o una foto de producto nueva fallan con "Permission
# denied" aunque la carpeta exista.
RUN mkdir -p storage assets/img/productos \
    && chown -R www-data:www-data storage assets/img/productos

# Railway asigna el puerto real en la variable $PORT; Apache por
# defecto solo escucha el 80, así que el arranque lo reconfigura antes
# de encender el servidor (ver docker/entrypoint.sh).
RUN chmod +x docker/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["docker/entrypoint.sh"]

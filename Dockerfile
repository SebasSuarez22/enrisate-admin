# Enrísate · Panel de administración
# Imagen para Railway (o cualquier servidor que corra Docker).
#
# PHP + Apache con las extensiones que la app necesita:
#   - pdo_mysql/mysqli: para hablar con la base de datos.
#   - gd: para procesar las fotos de producto (recorte, compresión).
FROM php:8.4-apache

RUN docker-php-ext-install pdo_mysql mysqli \
    && apt-get update \
    && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install gd \
    && apt-get purge -y --auto-remove libpng-dev libjpeg62-turbo-dev libwebp-dev \
    && rm -rf /var/lib/apt/lists/*

# El proyecto ya trae su propio .htaccess (bloquea /includes, /storage,
# /tools, y activa compresión y caché) — solo hace falta permitirle a
# Apache leerlo.
#
# mpm_prefork es el único módulo de procesos compatible con mod_php;
# instalar las librerías de arriba deja además mpm_event habilitado, y
# Apache se niega a arrancar con dos módulos de procesos a la vez
# ("More than one MPM loaded"). Se apaga event/worker y se deja solo
# prefork, sin importar cuál haya quedado prendido.
RUN a2enmod rewrite \
    && a2dismod mpm_event mpm_worker 2>/dev/null; \
    a2enmod mpm_prefork \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY . .

# Railway asigna el puerto real en la variable $PORT; Apache por
# defecto solo escucha el 80, así que el arranque lo reconfigura antes
# de encender el servidor (ver docker/entrypoint.sh).
RUN chmod +x docker/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["docker/entrypoint.sh"]

#!/bin/sh
# Arranque del contenedor en Railway.
set -e

# Railway le asigna a cada servicio un puerto distinto en $PORT; Apache
# viene configurado de fábrica para escuchar solo el 80.
PORT="${PORT:-80}"
sed -ri "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/:80/:${PORT}/" /etc/apache2/sites-enabled/000-default.conf

# La base de datos (servicio aparte en Railway) puede tardar unos
# segundos en aceptar conexiones la primera vez que todo arranca junto;
# reintenta un rato antes de rendirse.
echo "Preparando la base de datos..."
intentos=0
until php tools/aplicar_esquema.php; do
  intentos=$((intentos + 1))
  if [ "$intentos" -ge 10 ]; then
    echo "No se pudo conectar a la base de datos después de varios intentos." >&2
    exit 1
  fi
  sleep 3
done

exec apache2-foreground

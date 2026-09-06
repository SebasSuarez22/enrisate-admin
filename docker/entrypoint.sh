#!/bin/sh
# Arranque del contenedor en Railway.
set -e

# Railway le asigna a cada servicio un puerto distinto en $PORT; Apache
# viene configurado de fábrica para escuchar solo el 80.
PORT="${PORT:-80}"
sed -ri "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/:80/:${PORT}/" /etc/apache2/sites-enabled/000-default.conf

# La base de datos (servicio aparte en Railway) puede tardar más de lo
# esperado en aceptar conexiones, sobre todo si arrancó al mismo tiempo
# que esta app; se reintenta bastante antes de seguir sin ella.
echo "Preparando la base de datos..."
intentos=0
hasta=20
until php tools/aplicar_esquema.php; do
  intentos=$((intentos + 1))
  if [ "$intentos" -ge "$hasta" ]; then
    # No se logró en el tiempo dado: en vez de tumbar todo el
    # contenedor (lo que obligaba a un "Redeploy" a mano cada vez),
    # arranca igual. Cada página ya sabe mostrar un aviso claro de "no
    # se pudo conectar a la base de datos" en vez de un error críptico,
    # y en cuanto la base quede lista, la siguiente visita funciona
    # sola, sin reiniciar nada.
    echo "No se pudo aplicar el esquema tras $hasta intentos; sigue igual el arranque de Apache." >&2
    break
  fi
  sleep 5
done

# El Dockerfile ya intenta dejar solo mpm_prefork activo (el único que
# funciona con mod_php), pero eso pasa al construir la imagen — si por
# la razón que sea el contenedor real arranca con más de uno habilitado
# ("More than one MPM loaded"), se repite el mismo arreglo aquí, ya en
# el contenedor que de verdad va a correr, para que quede garantizado.
echo "Módulos MPM antes de arrancar Apache:"
ls /etc/apache2/mods-enabled/ 2>/dev/null | grep mpm || echo "(ninguno)"
rm -f /etc/apache2/mods-enabled/mpm_event.load /etc/apache2/mods-enabled/mpm_event.conf \
      /etc/apache2/mods-enabled/mpm_worker.load /etc/apache2/mods-enabled/mpm_worker.conf
a2enmod mpm_prefork >/dev/null 2>&1 || true
echo "Módulos MPM justo antes de arrancar Apache:"
ls /etc/apache2/mods-enabled/ 2>/dev/null | grep mpm || echo "(ninguno)"

exec apache2-foreground

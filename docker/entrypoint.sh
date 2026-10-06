#!/bin/sh
# Arranque del contenedor en Railway.
set -e

# Railway le asigna a cada servicio un puerto distinto en $PORT; Apache
# viene configurado de fábrica para escuchar solo el 80.
echo "Variable de entorno PORT tal como la puso Railway: '${PORT}'"
PORT="${PORT:-80}"
echo "Puerto que va a usar Apache: ${PORT}"
sed -ri "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/:80/:${PORT}/" /etc/apache2/sites-enabled/000-default.conf

# Fotos de producto: assets/img/productos ahora es un Volume persistente
# de Railway (antes cualquier foto subida desde el panel se perdía en el
# siguiente despliegue — vivía solo en el contenedor de esa sesión). Un
# Volume nuevo arranca vacío, así que en el primer arranque se rellena
# con las fotos que ya venían guardadas en el repositorio (ver el "cp"
# al respaldo en el Dockerfile) — "cp -n" no pisa nada que el Volume ya
# tenga (fotos subidas después del primer arranque), así que esto es
# seguro correrlo en cada arranque, no solo en el primero.
mkdir -p assets/img/productos
cp -rn assets/img/productos-semilla/. assets/img/productos/

# Versiones livianas y medidas de las fotos para el catálogo público (ver
# tools/preparar_fotos_catalogo.php). Si falla, el catálogo sigue funcionando
# con las fotos normales: por eso no detiene el arranque.
php tools/preparar_fotos_catalogo.php || echo "No se pudieron preparar las fotos del catálogo; se usan las normales." >&2

chown -R www-data:www-data assets/img/productos

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

echo "Apache va a escuchar en:"
grep -i listen /etc/apache2/ports.conf

exec apache2-foreground

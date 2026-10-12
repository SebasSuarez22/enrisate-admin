<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/clientes.php';
require_once __DIR__ . '/../includes/mensajes.php';

/* Módulo Mensajes: texto de la bienvenida (con la pregunta del permiso), quién
   está en la cola, y que nunca se le escriba dos veces ni a quien dijo NO. */

function limpiar_mensajes_prueba(): void
{
    db()->exec('DELETE FROM clientes');   // las filas de mensajes caen por ON DELETE CASCADE
}
limpiar_mensajes_prueba();
register_shutdown_function('limpiar_mensajes_prueba');

/* --- El texto --- */
$m = mensaje_bienvenida('Valentina Gómez');
verificar(str_starts_with($m, 'Hola Valentina'), 'saluda con el primer nombre');
verificar(str_contains($m, '+57 305 241 8417'), 'trae el número nuevo de WhatsApp con formato');
verificar(str_contains($m, APP_URL_CATALOGO), 'trae el enlace oficial del catálogo');
verificar(str_contains($m, 'mensajes automáticos con promociones y otras novedades'), 'pregunta si quiere promociones y novedades');
verificar(str_contains($m, 'Responde *SÍ* o *NO*'), 'pide responder SÍ o NO');
verificar(str_starts_with(mensaje_bienvenida(''), 'Hola 🌿'), 'sin nombre, saluda sin inventar uno');

/* --- El cobro de un pago vencido --- */
$mora = mensaje_mora('Valentina Gómez');
verificar(str_starts_with($mora, 'Hola Valentina 💛'), 'el cobro saluda con el primer nombre');
verificar(str_contains($mora, 'Te escribimos de ' . APP_NOMBRE . ' porque tienes un pago pendiente que ya venció'), 'dice que hay un pago vencido, de parte de Enrísate');
verificar(str_contains($mora, 'Nos encantaría que te pongas al día'), 'pide ponerse al día');
verificar(str_contains($mora, 'Si ya lo hiciste, cuéntanos para actualizar tu cuenta. ¡Gracias!'), 'cubre a quien ya pagó y agradece');
verificar(!str_contains($mora, '$') && !preg_match('/\d/', $mora), 'general: sin monto, cuota ni fecha (ningún número ni signo de pesos)');
verificar(str_starts_with(mensaje_mora(''), 'Hola 💛'), 'sin nombre, saluda sin inventar uno');
verificar(str_starts_with(mensaje_mora('  Ana  María Pérez '), 'Hola Ana 💛'), 'con espacios de más, toma bien el primer nombre');

/* --- Celulares válidos --- */
verificar(telefono_whatsapp_valido('3001234567'), 'celular de 10 dígitos válido');
verificar(telefono_whatsapp_valido('+57 300 123 4567'), 'celular con +57 y espacios válido');
verificar(!telefono_whatsapp_valido('6053001234'), 'fijo (empieza en 6) no sirve para WhatsApp');
verificar(!telefono_whatsapp_valido('30012345'), 'número corto no sirve');
verificar(!telefono_whatsapp_valido(''), 'vacío no sirve');

/* --- La cola --- */
foreach ([['Ana Uno', '3001110001'], ['Beatriz Dos', '3001110002'], ['Carla Tres', '3001110003'],
          ['Diana Cuatro', ''], ['Elena Cinco', '6051234567']] as [$n, $t]) {
    agregar_cliente($n, $t, '');
}
$id = [];
foreach (leer_clientes() as $c) { $id[$c['nombre']] = $c['id']; }

$e = mensajes_estado_clientas('bienvenida');
verificar(count(mensajes_cola($e)) === 3, 'la cola solo tiene a las 3 con celular válido');
$r = mensajes_resumen($e);
verificar($r['sin_celular'] === 2 && $r['pendientes'] === 3 && $r['elegibles'] === 3, 'el resumen cuenta 2 sin celular y 3 pendientes');

verificar(mensajes_marcar('bienvenida', $id['Ana Uno'], 'enviado', 'Gina'), 'marca a Ana como enviada');
verificar(mensajes_marcar('bienvenida', $id['Ana Uno'], 'enviado', 'Gina'), 'marcar dos veces no duplica ni falla');
verificar(count(mensajes_cola(mensajes_estado_clientas('bienvenida'))) === 2, 'Ana sale de la cola');
verificar(mensajes_marcar('bienvenida', $id['Beatriz Dos'], 'saltado'), 'se puede saltar a una clienta');
verificar(count(mensajes_cola(mensajes_estado_clientas('bienvenida'))) === 1, 'la saltada también sale de la cola');
verificar(mensajes_deshacer('bienvenida', $id['Beatriz Dos']), 'deshacer devuelve a la clienta a la cola');
verificar(count(mensajes_cola(mensajes_estado_clientas('bienvenida'))) === 2, 'Beatriz vuelve a la cola');

/* --- El permiso --- */
verificar(mensajes_poner_permiso($id['Carla Tres'], 'no', 'Gina'), 'se guarda que Carla dijo NO');
verificar(count(mensajes_cola(mensajes_estado_clientas('bienvenida'))) === 1, 'a quien dijo NO no se le escribe');
verificar(mensajes_poner_permiso($id['Carla Tres'], 'si'), 'Carla cambia de opinión: SÍ');
verificar(count(mensajes_cola(mensajes_estado_clientas('bienvenida'))) === 2, 'con SÍ vuelve a estar disponible');
mensajes_poner_permiso($id['Carla Tres'], '');
$carla = array_values(array_filter(mensajes_estado_clientas('bienvenida'), fn($c) => $c['nombre'] === 'Carla Tres'))[0];
verificar($carla['permiso'] === '', 'borrar la respuesta la deja en "sin responder"');

/* --- Datos inválidos --- */
verificar(!mensajes_marcar('bienvenida', 'no-existe', 'enviado'), 'una clienta que no existe se rechaza');
verificar(!mensajes_marcar('inventada', $id['Ana Uno'], 'enviado'), 'una campaña que no existe se rechaza');
verificar(!mensajes_marcar('bienvenida', $id['Ana Uno'], 'cualquiera'), 'un estado inválido se rechaza');
verificar(!mensajes_poner_permiso($id['Ana Uno'], 'quizás'), 'un permiso inválido se rechaza');
verificar(!mensajes_poner_permiso('no-existe', 'si'), 'permiso para una clienta que no existe se rechaza');

/* --- Modo "de 5 en 5": el grupo cuenta lo enviado desde que empezó --- */
verificar(MENSAJES_TAMANO_GRUPO === 5, 'el grupo es de 5 mensajes');
db()->exec('DELETE FROM mensajes_envios');
for ($i = 1; $i <= 7; $i++) {
    agregar_cliente('Grupo ' . $i, '30055500' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), '');
}
$ids = array_column(array_filter(leer_clientes(), fn($c) => str_starts_with($c['nombre'], 'Grupo ')), 'id');
foreach (array_slice($ids, 0, 3) as $cid) { mensajes_marcar('bienvenida', $cid, 'enviado', 'Gina'); }
mensajes_marcar('bienvenida', $ids[3], 'saltado', 'Gina');
$_SESSION = [];
$desde = mensajes_grupo_desde('bienvenida');
verificar($desde !== '' && mensajes_grupo_desde('bienvenida') === $desde, 'el grupo arranca una vez y no se mueve solo');
verificar(mensajes_enviadas_desde('bienvenida', '2000-01-01T00:00:00-05:00') === 3, 'cuenta las 3 enviadas (la saltada no cuenta)');
verificar(mensajes_enviadas_desde('bienvenida', '2999-01-01T00:00:00-05:00') === 0, 'un grupo que empieza después no cuenta las anteriores');
verificar(mensajes_nuevo_grupo('bienvenida'), 'empezar un grupo nuevo funciona');
verificar(!mensajes_nuevo_grupo('inventada'), 'no se puede empezar un grupo de una campaña que no existe');
verificar(mensajes_enviadas_desde('bienvenida', date('c', time() + 3600)) === 0, 'al empezar un grupo nuevo el contador vuelve a cero');

/* --- Borrar a la clienta limpia sus marcas --- */
eliminar_clientes([$id['Ana Uno']]);
$huerfanas = (int) db()->query("SELECT COUNT(*) FROM mensajes_envios WHERE cliente_id = " . db()->quote($id['Ana Uno']))->fetchColumn();
verificar($huerfanas === 0, 'al borrar a la clienta se borran sus marcas de envío');

terminar_pruebas();

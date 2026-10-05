<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/usuarios.php';

/* Ingreso en dos pasos: códigos TOTP (vectores oficiales de RFC 6238), Base32,
   cifrado del secreto, códigos de respaldo, límite de intentos, reglas de
   usuarios (mínimo de administradores) y contraseñas. */

function limpiar_usuarios(): void
{
    db()->exec('DELETE FROM usuario_codigos_respaldo');
    db()->exec('DELETE FROM usuarios');
    db()->exec('DELETE FROM intentos_ingreso');
    db()->exec('DELETE FROM registro_seguridad');
}
limpiar_usuarios();
register_shutdown_function('limpiar_usuarios');

/* --- Base32 (RFC 4648 §10) --- */
foreach (['' => '', 'f' => 'MY', 'fo' => 'MZXQ', 'foo' => 'MZXW6', 'foob' => 'MZXW6YQ', 'fooba' => 'MZXW6YTB', 'foobar' => 'MZXW6YTBOI'] as $plano => $b32) {
    if ($plano === '') {
        continue;
    }
    verificar(base32_codificar($plano) === $b32, "base32(\"$plano\") = $b32");
    verificar(base32_decodificar($b32) === $plano, "base32 inverso de $b32");
}
verificar(base32_decodificar('mzxw 6ytb-oi') === 'foobar', 'base32 ignora minúsculas, espacios y guiones');
verificar(base32_decodificar('MZXW1') === null, 'base32 rechaza caracteres fuera del alfabeto');

/* --- TOTP: vectores de RFC 6238, apéndice B (SHA1, secreto "12345678901234567890"), 6 de 8 dígitos --- */
$rfc = '12345678901234567890';
foreach ([59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'] as $t => $ocho) {
    verificar(totp_codigo($rfc, totp_paso($t), 'sha1', 8) === $ocho, "RFC 6238 T=$t → $ocho");
    verificar(totp_codigo($rfc, totp_paso($t)) === substr($ocho, -6), "6 dígitos T=$t → " . substr($ocho, -6));
}

/* --- Verificación con ventana y sin repetir códigos --- */
$s = totp_nuevo_secreto();
$t = 1_800_000_000;
$p = totp_paso($t);
verificar(strlen($s) === 20, 'el secreto nuevo es de 160 bits');
verificar(totp_verificar($s, totp_codigo($s, $p), 0, $t) === $p, 'acepta el código actual');
verificar(totp_verificar($s, totp_codigo($s, $p - 1), 0, $t) === $p - 1, 'acepta el código anterior (reloj 30 s atrasado)');
verificar(totp_verificar($s, totp_codigo($s, $p + 1), 0, $t) === $p + 1, 'acepta el siguiente (reloj 30 s adelantado)');
verificar(totp_verificar($s, totp_codigo($s, $p - 2), 0, $t) === null, 'rechaza un código de hace 1 minuto');
verificar(totp_verificar($s, totp_codigo($s, $p + 2), 0, $t) === null, 'rechaza un código de 1 minuto en el futuro');
verificar(totp_verificar($s, totp_codigo($s, $p), $p, $t) === null, 'un código ya usado no vuelve a servir');
$c = totp_codigo($s, $p);
verificar(totp_verificar($s, substr($c, 0, 3) . ' ' . substr($c, 3), 0, $t) === $p, 'acepta el código con espacio ("482 913")');
verificar(totp_verificar($s, '12345', 0, $t) === null && totp_verificar($s, 'abcdef', 0, $t) === null, 'rechaza códigos mal formados');
$uri = totp_uri('gina', base32_codificar($s));
verificar(str_starts_with($uri, 'otpauth://totp/Enr%C3%ADsate:gina?secret=') && str_contains($uri, 'issuer=Enr%C3%ADsate') && str_contains($uri, 'period=30'), 'enlace otpauth bien armado');
verificar(totp_clave_legible('JBSWY3DPEHPK3PXP') === 'JBSW Y3DP EHPK 3PXP', 'clave en grupos de 4');

/* --- Cifrado del secreto --- */
$cif = cifrar($s);
verificar($cif !== $s && !str_contains($cif, base32_codificar($s)), 'el secreto se guarda cifrado');
verificar(descifrar($cif) === $s, 'descifrar devuelve el mismo secreto');
verificar(cifrar($s) !== $cif, 'cada cifrado usa un nonce distinto');
$alterado = substr($cif, 0, -3) . (substr($cif, -3, 1) === 'A' ? 'B' : 'A') . substr($cif, -2);
verificar(descifrar($alterado) === null, 'un secreto alterado no se descifra');

/* --- Contraseñas --- */
verificar(problema_clave('corta') !== '', 'rechaza contraseñas de menos de 12');
verificar(problema_clave('mi perro come mango') === '', 'acepta una frase de 19 caracteres');
verificar(problema_clave('123456789012') !== '', 'rechaza una de las más usadas');
verificar(problema_clave('ginaginagina2026', 'gina') !== '', 'rechaza la que contiene el usuario');
verificar(problema_clave('aaaaaaaaaaaaaa') !== '', 'rechaza la que repite un carácter');
verificar(problema_clave('Enrisate2026*!!') !== '', 'rechaza la que contiene el nombre del negocio');

/* --- Usuarios y regla de administradores --- */
$adminA = crear_usuario('Sebastian', 'Sebastián Suárez', 'admin', 'frase larga numero uno', false, null);
$quien  = leer_usuario($adminA);
verificar($quien['usuario'] === 'sebastian', 'el usuario se guarda en minúsculas');
verificar(password_verify('frase larga numero uno', $quien['clave_hash']) && !str_contains($quien['clave_hash'], 'frase'), 'la contraseña se guarda como hash');
try { crear_usuario('sebastian', 'Otro', 'ventas', 'otra frase bien larga', false, $quien); $dup = false; } catch (InvalidArgumentException $e) { $dup = true; }
verificar($dup, 'no deja repetir usuario');
try { crear_usuario('ma ría', 'María', 'ventas', 'otra frase bien larga', false, $quien); $mal = false; } catch (InvalidArgumentException $e) { $mal = true; }
verificar($mal, 'no deja usuarios con espacios');
$adminB = crear_usuario('gina', 'Gina', 'admin', 'jardin de mangos 77', true, $quien);
$ventas = crear_usuario('laura', 'Laura', 'ventas', 'cafe con leche diario', true, $quien);
verificar(contar_admins_activos() === 2, 'dos administradores activos');
try { cambiar_activo(leer_usuario($adminB), false, $quien); $bloq = false; } catch (InvalidArgumentException $e) { $bloq = true; }
verificar($bloq, 'no deja desactivar si quedarían menos de 2 administradores');
try { cambiar_rol(leer_usuario($adminB), 'ventas', $quien); $bloq = false; } catch (InvalidArgumentException $e) { $bloq = true; }
verificar($bloq, 'no deja quitar el rol si quedarían menos de 2 administradores');
try { cambiar_activo($quien, false, $quien); $bloq = false; } catch (InvalidArgumentException $e) { $bloq = true; }
verificar($bloq, 'nadie se desactiva a sí mismo');
$v0 = (int) leer_usuario($ventas)['sesion_version'];
cambiar_activo(leer_usuario($ventas), false, $quien);
verificar((int) leer_usuario($ventas)['activo'] === 0 && (int) leer_usuario($ventas)['sesion_version'] === $v0 + 1, 'desactivar sube la versión de sesión (cierra sus sesiones)');
cambiar_activo(leer_usuario($ventas), true, $quien);
cambiar_rol(leer_usuario($ventas), 'admin', $quien);
verificar(contar_admins_activos() === 3, 'con 3 administradores…');
cambiar_activo(leer_usuario($adminB), false, $quien);
verificar(contar_admins_activos() === 2, '…sí se puede desactivar a uno');

/* --- Verificación del usuario: código una sola vez --- */
$u = leer_usuario($adminA);
$sec = totp_nuevo_secreto();
activar_verificacion($u, $sec, 0);
$u = leer_usuario($adminA);
verificar((int) $u['totp_activo'] === 1 && secreto_totp($u) === $sec, 'activa la verificación con el secreto cifrado');
$cod = totp_codigo($sec, totp_paso());
verificar(verificar_codigo_usuario($u, $cod), 'el código actual sirve');
verificar(!verificar_codigo_usuario(leer_usuario($adminA), $cod), 'el mismo código no sirve dos veces');

/* --- Códigos de respaldo --- */
$codigos = generar_codigos_respaldo($u);
verificar(count($codigos) === 10 && count(array_unique($codigos)) === 10, '10 códigos distintos');
verificar((bool) preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $codigos[0]), 'formato XXXX-XXXX');
$huellas = db()->query('SELECT huella FROM usuario_codigos_respaldo')->fetchAll(PDO::FETCH_COLUMN);
verificar(!in_array(str_replace('-', '', $codigos[0]), $huellas, true), 'se guardan como huella, no legibles');
verificar(usar_codigo_respaldo($u, strtolower(str_replace('-', ' ', $codigos[3]))), 'un código sirve (sin importar mayúsculas ni guion)');
verificar(!usar_codigo_respaldo($u, $codigos[3]), 'el código usado se quema');
verificar(respaldos_restantes($adminA) === 9, 'quedan 9');
verificar(!usar_codigo_respaldo(leer_usuario($ventas), $codigos[4]), 'el código de una persona no sirve para otra');
generar_codigos_respaldo($u);
verificar(!usar_codigo_respaldo($u, $codigos[5]) && respaldos_restantes($adminA) === 10, 'generar nuevos anula los anteriores');
restablecer_verificacion(leer_usuario($adminA), $quien);
$u = leer_usuario($adminA);
verificar((int) $u['totp_activo'] === 0 && $u['totp_secreto'] === null && respaldos_restantes($adminA) === 0, 'restablecer borra el autenticador y los códigos');

/* --- Límite de intentos --- */
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$claves = claves_limite('alguien');
verificar(espera_ingreso($claves) === 0, 'sin fallos no hay espera');
for ($i = 0; $i < INTENTOS_LIBRES_CUENTA - 1; $i++) {
    registrar_fallo_ingreso($claves);
}
verificar(espera_ingreso($claves) === 0, '4 fallos todavía no hacen esperar');
registrar_fallo_ingreso($claves);
$e1 = espera_ingreso($claves);
verificar($e1 > 0 && $e1 <= ESPERA_MINIMA, '5.º fallo: espera de 1 minuto');
registrar_fallo_ingreso($claves);
$e2 = espera_ingreso($claves);
verificar($e2 > ESPERA_MINIMA && $e2 <= 2 * ESPERA_MINIMA, 'cada fallo más duplica la espera');
for ($i = 0; $i < 10; $i++) {
    registrar_fallo_ingreso($claves);
}
verificar(espera_ingreso($claves) <= ESPERA_MAXIMA, 'la espera nunca pasa de 15 minutos');
verificar(espera_ingreso(claves_limite('otra-persona')) === 0, 'la espera de una cuenta no frena a otra (con menos de 30 fallos por IP)');
limpiar_fallos_cuenta('alguien');
verificar(espera_ingreso(['u:alguien']) === 0, 'un ingreso correcto limpia la cuenta');

/* --- Registro --- */
$reg = leer_registro(100);
$eventos = array_column($reg, 'evento');
verificar(in_array('usuario_creado', $eventos, true) && in_array('verificacion_restablecida', $eventos, true) && in_array('usuario_desactivado', $eventos, true), 'los cambios quedan en el registro');

echo "\n" . ($fallos === 0 ? "TODO OK" : "$fallos prueba(s) fallaron") . "\n";
exit($fallos === 0 ? 0 : 1);

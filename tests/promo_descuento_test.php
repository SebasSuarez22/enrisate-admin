<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/pedidos.php';

/* Promoción del catálogo público: 10 % en toda la compra desde 3 unidades.
   El carrito (JS) y el servidor hacen la misma cuenta; aquí se fija la del servidor,
   que es la que manda al guardar el pedido. */

verificar(PROMO_MINIMO_UNIDADES === 3 && PROMO_PORCENTAJE === 10, 'la promoción es 10 % desde 3 unidades');

verificar(descuento_promo(100000, 1) === 0, '1 unidad: sin descuento');
verificar(descuento_promo(100000, 2) === 0, '2 unidades: sin descuento');
verificar(descuento_promo(100000, 3) === 10000, '3 unidades: 10 % de toda la compra');
verificar(descuento_promo(250000, 12) === 25000, 'más unidades: sigue siendo 10 % (no escala)');

// El descuento es sobre TODA la compra, no solo sobre los productos que pasan de 3.
verificar(descuento_promo(38000 + 45000 + 75000, 3) === 15800, '3 productos distintos: 10 % de $158.000 = $15.800');

// Redondeo al peso: 10 % de un subtotal terminado en 5 sube al peso siguiente (igual que Math.round en el carrito).
verificar(descuento_promo(10005, 3) === 1001, 'redondea al peso (10 % de $10.005 = $1.001)');
verificar(descuento_promo(37995, 4) === 3800, 'redondea al peso (10 % de $37.995 = $3.800)');

verificar(descuento_promo(0, 5) === 0, 'subtotal 0: sin descuento');
verificar(descuento_promo(-5000, 5) === 0, 'subtotal negativo: sin descuento');
verificar(descuento_promo(100000, 0) === 0, '0 unidades: sin descuento');

// Nunca deja el total en negativo ni mayor al subtotal.
foreach ([1, 999, 12345, 100000, 7654321] as $sub) {
    $d = descuento_promo($sub, 3);
    verificar($d >= 0 && $d <= $sub, "descuento de $sub dentro de [0, subtotal]");
}

echo "\n" . ($fallos === 0 ? "TODO OK" : "$fallos prueba(s) fallaron") . "\n";
exit($fallos === 0 ? 0 : 1);

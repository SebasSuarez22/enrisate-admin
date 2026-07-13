<?php
/* Fondo compartido: hebras que caen y se mecen. Puramente decorativo. */
?>
<div class="escena" aria-hidden="true">
  <svg viewBox="0 0 1200 800" preserveAspectRatio="xMidYMid slice">
    <?php for ($i = 0; $i < 16; $i++):
        $x    = 40 + ($i * 76);
        $curv = 55 + (($i % 4) * 26);
    ?>
      <path class="hebra"
            d="M<?= $x ?> -40
               C<?= $x + $curv ?> 160, <?= $x - $curv ?> 300, <?= $x + ($curv / 2) ?> 460
               S<?= $x - $curv ?> 700, <?= $x ?> 860" />
    <?php endfor; ?>
  </svg>
</div>

<?php
$this->extend('layouts/admin');
$this->section('content');
$i = $ingresos;
$s = static fn ($n) => 'S/ ' . number_format((float) $n, 2);
$meses = ['01' => 'Ene', '02' => 'Feb', '03' => 'Mar', '04' => 'Abr', '05' => 'May', '06' => 'Jun', '07' => 'Jul', '08' => 'Ago', '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dic'];
$mesTxt = static fn (string $m) => ($meses[substr($m, 5, 2)] ?? '') . ' ' . substr($m, 0, 4);
$esteMes = date('Y-m');
$hoy = $i['por_mes'][$esteMes] ?? ['licencias' => 0, 'pagos' => 0, 'ias' => 0, 'ias_n' => 0];
$anio = $i['por_anio'][date('Y')] ?? ['licencias' => 0, 'pagos' => 0, 'ias' => 0, 'ias_n' => 0];
$total = array_sum(array_column($i['por_anio'], 'licencias')) + array_sum(array_column($i['por_anio'], 'ias'));
?>
<div class="space-y-5">
  <?= $this->include('Central\Views\_cabecera') ?>

  <div class="grid gap-3 sm:grid-cols-3">
    <div class="ce-card"><div class="ce-lbl">Este mes (<?= esc($mesTxt($esteMes)) ?>)</div><div class="text-2xl font-bold"><?= $s($hoy['licencias'] + $hoy['ias']) ?></div><div class="text-xs text-[#9CA3AF]">Licencias <?= $s($hoy['licencias']) ?> · IA <?= $s($hoy['ias']) ?></div></div>
    <div class="ce-card"><div class="ce-lbl">Año <?= date('Y') ?></div><div class="text-2xl font-bold"><?= $s($anio['licencias'] + $anio['ias']) ?></div><div class="text-xs text-[#9CA3AF]">Licencias <?= $s($anio['licencias']) ?> · IA <?= $s($anio['ias']) ?></div></div>
    <div class="ce-card"><div class="ce-lbl">Desde el inicio</div><div class="text-2xl font-bold"><?= $s($total) ?></div><div class="text-xs text-[#9CA3AF]"><?= $i['streamy'] ? 'IA = Recargas IA aplicadas (streamy)' : 'Sin conexión a Recargas IA: solo licencias' ?></div></div>
  </div>

  <div class="ce-card overflow-x-auto">
    <h2 class="text-sm font-semibold mb-2">Por mes</h2>
    <table class="ce-tabla">
      <thead><tr><th>Mes</th><th class="num">Licencias</th><th class="num">Pagos</th><th class="num">Recargas IA</th><th class="num">Recargas</th><th class="num">Total</th></tr></thead>
      <tbody>
      <?php if (! $i['por_mes']): ?><tr><td colspan="6" class="text-[#9CA3AF]">Todavía no hay ingresos.</td></tr><?php endif; ?>
      <?php foreach ($i['por_mes'] as $m => $x): ?>
        <tr><td><?= esc($mesTxt($m)) ?></td><td class="num"><?= $s($x['licencias']) ?></td><td class="num"><?= (int) $x['pagos'] ?></td><td class="num"><?= $s($x['ias']) ?></td><td class="num"><?= (int) $x['ias_n'] ?></td><td class="num font-bold"><?= $s($x['licencias'] + $x['ias']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="ce-card overflow-x-auto">
    <h2 class="text-sm font-semibold mb-2">Por año</h2>
    <table class="ce-tabla">
      <thead><tr><th>Año</th><th class="num">Licencias</th><th class="num">Pagos</th><th class="num">Recargas IA</th><th class="num">Recargas</th><th class="num">Total</th></tr></thead>
      <tbody>
      <?php foreach ($i['por_anio'] as $a => $x): ?>
        <tr><td><?= esc($a) ?></td><td class="num"><?= $s($x['licencias']) ?></td><td class="num"><?= (int) $x['pagos'] ?></td><td class="num"><?= $s($x['ias']) ?></td><td class="num"><?= (int) $x['ias_n'] ?></td><td class="num font-bold"><?= $s($x['licencias'] + $x['ias']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="ce-card overflow-x-auto">
    <h2 class="text-sm font-semibold mb-2">Por hija</h2>
    <table class="ce-tabla apilada">
      <thead><tr><th>Hija</th><th class="num">Pagó</th><th class="num">Pagos</th><th class="num">Meses renovados</th><th>Cliente desde</th><th>Vence</th><th class="num">IAs compradas</th><th class="num">Gastó en IA</th></tr></thead>
      <tbody>
      <?php foreach ($i['por_hija'] as $h): ?>
        <tr>
          <td class="font-semibold"><?= esc($h['hija']) ?></td>
          <td class="num"><?= $s($h['total']) ?></td>
          <td class="num"><?= (int) $h['pagos'] ?></td>
          <td class="num"><?= (int) $h['meses'] ?></td>
          <td class="text-xs"><?= $h['primero'] ? esc(date('d/m/Y', strtotime($h['primero']))) : '—' ?></td>
          <td class="text-xs"><?= $h['vence_en'] ? esc(date('d/m/Y', strtotime($h['vence_en']))) : '—' ?></td>
          <td class="num"><?= $h['con_cuenta_ia'] ? (int) $h['ias_n'] : '<span class="text-xs text-[#9CA3AF]">sin cuenta</span>' ?></td>
          <td class="num"><?= $h['con_cuenta_ia'] ? $s($h['ias_total']) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php $this->endSection(); ?>

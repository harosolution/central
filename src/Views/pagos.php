<?php
$this->extend('layouts/admin');
$this->section('content');
$central = rtrim(base_url('central'), '/');
$colorEstado = ['pagado' => 'verde', 'pendiente' => 'ambar', 'vencido' => 'rojo', 'anulado' => ''];
$conPlan = array_filter($hijas, static fn ($h) => (int) $h['plan_id'] > 0);
?>
<div class="space-y-5">
  <?= $this->include('Central\Views\_cabecera') ?>

  <div class="ce-card">
    <h2 class="text-sm font-semibold mb-1">Registrar un pago a mano</h2>
    <p class="text-xs text-[#9CA3AF] mb-3">Efectivo, transferencia o cortesía. Queda pagado al instante y la licencia avanza los meses del plan × periodos.</p>
    <?php if (! $conPlan): ?>
      <p class="text-sm text-[#F59E0B]">Primero asígnale un plan a la hija (pestaña Hijas → Editar).</p>
    <?php else: ?>
    <form method="post" action="<?= $central ?>/pagos" class="grid gap-2 sm:grid-cols-6 items-end">
      <?= csrf_field() ?>
      <label class="ce-lbl sm:col-span-2">Hija
        <select class="ce-in" name="hija_id" required>
          <?php foreach ($conPlan as $h): $p = $planes[(int) $h['plan_id']] ?? null; ?>
            <option value="<?= (int) $h['id'] ?>" data-precio="<?= $p ? (float) $p['precio'] : 0 ?>"><?= esc($h['nombre']) ?> · <?= esc($p['nombre'] ?? '?') ?></option>
          <?php endforeach; ?>
        </select></label>
      <label class="ce-lbl">Periodos<input class="ce-in" type="number" name="periodos" min="1" max="24" value="1" required></label>
      <label class="ce-lbl">Monto S/<input class="ce-in" type="number" step="0.01" min="0" name="monto" required></label>
      <label class="ce-lbl">Medio
        <select class="ce-in" name="metodo"><?php foreach (\Central\Libraries\Licencias::METODOS as $m): ?><option><?= $m ?></option><?php endforeach; ?></select></label>
      <button class="ce-btn bg-[#22C55E] text-[#0B1220]">Registrar</button>
      <label class="ce-lbl sm:col-span-6">Nota<input class="ce-in" name="nota" maxlength="255" placeholder="opcional"></label>
    </form>
    <script>
      (function () {
        var f = document.currentScript.previousElementSibling, s = f.hija_id, n = f.periodos, m = f.monto;
        function poner() { var o = s.options[s.selectedIndex]; m.value = (Number(o.getAttribute('data-precio')) * Number(n.value || 1)).toFixed(2); }
        s.addEventListener('change', poner); n.addEventListener('input', poner); poner();
      })();
    </script>
    <?php endif; ?>
  </div>

  <div class="ce-card overflow-x-auto">
    <table class="ce-tabla apilada">
      <thead><tr><th>#</th><th>Hija</th><th>Plan</th><th class="num">Monto</th><th>Medio</th><th>Estado</th><th>Licencia</th><th>Fecha</th><th></th></tr></thead>
      <tbody>
      <?php if (empty($pagos)): ?><tr><td colspan="9" class="text-[#9CA3AF]">Todavía no hay pagos.</td></tr><?php endif; ?>
      <?php foreach ($pagos ?? [] as $p): ?>
        <tr>
          <td class="text-xs text-[#9CA3AF]"><?= (int) $p['id'] ?></td>
          <td class="font-semibold"><?= esc($p['hija']) ?><div class="text-xs text-[#9CA3AF]"><?= $p['origen'] === 'hija' ? 'Lo pagó la hija' : 'Registrado aquí' ?></div></td>
          <td><?= esc($p['plan_nombre']) ?><div class="text-xs text-[#9CA3AF]"><?= (int) $p['meses'] ?> mes<?= (int) $p['meses'] === 1 ? '' : 'es' ?></div></td>
          <td class="num">S/ <?= number_format((float) $p['monto'], 2) ?></td>
          <td><?= esc($p['metodo']) ?><?php if ($p['codigo_seguridad']): ?><div class="text-xs text-[#9CA3AF]">cód. <?= esc($p['codigo_seguridad']) ?></div><?php endif; ?><?php if ($p['yape_nombre']): ?><div class="text-xs text-[#9CA3AF]"><?= esc($p['yape_nombre']) ?></div><?php endif; ?></td>
          <td><span class="ce-pieza <?= $colorEstado[$p['estado']] ?? '' ?>"><?= esc($p['estado']) ?></span><?php if ($p['detalle']): ?><div class="text-xs text-[#9CA3AF]" style="max-width:220px"><?= esc($p['detalle']) ?></div><?php endif; ?><?php if ($p['nota'] !== ''): ?><div class="text-xs text-[#9CA3AF]"><?= esc($p['nota']) ?></div><?php endif; ?></td>
          <td class="text-xs"><?= $p['vence_despues'] ? esc(($p['vence_antes'] ? date('d/m/Y', strtotime($p['vence_antes'])) : '—') . ' → ' . date('d/m/Y', strtotime($p['vence_despues']))) : '' ?></td>
          <td class="text-xs text-[#9CA3AF]"><?= esc(date('d/m/Y H:i', strtotime($p['pagado_en'] ?: $p['creado_en']))) ?></td>
          <td class="text-xs">
            <?php if (in_array($p['estado'], ['pendiente', 'vencido'], true)): ?>
              <form method="post" action="<?= $central ?>/pagos/<?= (int) $p['id'] ?>/reintentar" style="display:inline"><?= csrf_field() ?><button class="text-[#60A5FA]">Buscar Yape</button></form>
              · <form method="post" action="<?= $central ?>/pagos/<?= (int) $p['id'] ?>/anular" style="display:inline" onsubmit="return confirm('¿Anular este pago?')"><?= csrf_field() ?><button class="text-[#EF4444]">Anular</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php $this->endSection(); ?>

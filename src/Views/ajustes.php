<?php
$this->extend('layouts/admin');
$this->section('content');
$central = rtrim(base_url('central'), '/');
$a = $ajustes;
?>
<div class="space-y-5">
  <?= $this->include('Central\Views\_cabecera') ?>

  <form method="post" action="<?= $central ?>/ajustes" class="ce-card space-y-4" style="max-width:720px">
    <?= csrf_field() ?>
    <h2 class="text-sm font-semibold">A dónde pagan las hijas</h2>
    <p class="text-xs text-[#9CA3AF]">Lo ve cada hija en «Mi licencia». El Yape se valida contra la bandeja Yape del CRM de TECNOVA: el número tiene que ser el que llega a esa bandeja.</p>
    <div class="grid gap-3 sm:grid-cols-2">
      <label class="ce-lbl">Número de Yape<input class="ce-in" name="yape_numero" inputmode="numeric" value="<?= esc($a['yape_numero']) ?>" placeholder="9xx xxx xxx"></label>
      <label class="ce-lbl">Titular (como sale en Yape)<input class="ce-in" name="yape_titular" value="<?= esc($a['yape_titular']) ?>"></label>
      <label class="ce-lbl">Número de Plin (opcional)<input class="ce-in" name="plin_numero" inputmode="numeric" value="<?= esc($a['plin_numero']) ?>"></label>
    </div>
    <label class="ce-lbl">Nota para la hija (opcional)<input class="ce-in" name="nota_cliente" maxlength="300" value="<?= esc($a['nota_cliente']) ?>" placeholder="Ej. Si pagas por Plin, avísanos por WhatsApp"></label>

    <h2 class="text-sm font-semibold pt-2">Avisos</h2>
    <div class="grid gap-3 sm:grid-cols-2">
      <label class="ce-lbl">Avisar desde cuántos días antes de vencer<input class="ce-in" type="number" min="0" max="60" name="aviso_dias" value="<?= esc($a['aviso_dias']) ?>"></label>
      <label class="ce-lbl">Días de gracia después de vencer<input class="ce-in" type="number" min="0" max="60" name="gracia_dias" value="<?= esc($a['gracia_dias']) ?>"></label>
    </div>
    <p class="text-xs text-[#9CA3AF]">Por ahora vencer solo AVISA (punto ámbar/rojo bajo la ⚙️ de la hija). Cortar automatizaciones al terminar la gracia es la fase 4.</p>
    <button class="ce-btn bg-[#22C55E] text-[#0B1220]">Guardar</button>
  </form>
</div>
<?php $this->endSection(); ?>

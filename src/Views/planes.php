<?php
$this->extend('layouts/admin');
$this->section('content');
$central = rtrim(base_url('central'), '/');
$form = static function (?array $p) use ($central): string {
    $id = $p ? (int) $p['id'] : 0;
    $h = '<form method="post" action="' . $central . '/planes' . ($id ? '/' . $id : '') . '" class="space-y-3">' . csrf_field();
    $h .= '<div class="grid gap-2 sm:grid-cols-4">'
        . '<label class="ce-lbl sm:col-span-2">Nombre<input class="ce-in" name="nombre" required maxlength="60" value="' . esc($p['nombre'] ?? '') . '" placeholder="Básico, Profesional, Elite…"></label>'
        . '<label class="ce-lbl">Precio (S/)<input class="ce-in" name="precio" type="number" step="0.01" min="0" value="' . esc((string) ($p['precio'] ?? '')) . '"></label>'
        . '<label class="ce-lbl">Cada cuántos meses<input class="ce-in" name="meses" type="number" min="1" max="24" value="' . esc((string) ($p['meses'] ?? 1)) . '"></label>'
        . '</div>';
    $h .= '<fieldset><legend class="ce-lbl">Funciones incluidas</legend><div class="grid gap-1 sm:grid-cols-2">';
    foreach (\Central\Libraries\Licencias::FUNCIONES as $k => $t) {
        $h .= '<label class="text-xs flex items-center gap-2"><input type="checkbox" name="funciones[]" value="' . $k . '"' . (in_array($k, $p['funciones'] ?? [], true) ? ' checked' : '') . '> ' . esc($t) . '</label>';
    }
    $h .= '</div></fieldset><div class="grid gap-2 sm:grid-cols-3">';
    foreach (\Central\Libraries\Licencias::LIMITES as $k => $t) {
        $h .= '<label class="ce-lbl">' . esc($t) . ' <span class="opacity-70">(0 = sin límite)</span><input class="ce-in" type="number" min="0" name="lim_' . $k . '" value="' . (int) ($p['limites'][$k] ?? 0) . '"></label>';
    }
    $h .= '</div><label class="ce-lbl">Descripción (la ve la hija)<input class="ce-in" name="descripcion" maxlength="300" value="' . esc($p['descripcion'] ?? '') . '"></label>';
    $h .= '<div class="flex flex-wrap items-center gap-3"><label class="text-xs flex items-center gap-2"><input type="checkbox" name="activo" value="1"' . (($p['activo'] ?? 1) ? ' checked' : '') . '> Activo</label>'
        . '<label class="text-xs flex items-center gap-2">Orden <input class="ce-in" style="width:70px" type="number" name="orden" value="' . (int) ($p['orden'] ?? 0) . '"></label>'
        . '<button class="ce-btn ' . ($id ? 'bg-[#2563EB] text-white' : 'bg-[#22C55E] text-[#0B1220]') . '">' . ($id ? 'Guardar' : 'Crear plan') . '</button></div></form>';
    return $h;
};
?>
<div class="space-y-5">
  <?= $this->include('Central\Views\_cabecera') ?>
  <p class="text-xs text-[#9CA3AF]">Un plan = precio por periodo + las funciones automáticas que incluye + límites. Por ahora solo se muestran y se cobran: cortar lo que el plan no incluye es la fase 4.</p>

  <?php foreach ($planes as $p): ?>
    <div class="ce-card">
      <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
        <div class="font-bold"><?= esc($p['nombre']) ?> <?= (int) $p['activo'] ? '' : '<span class="text-xs text-[#9CA3AF]">(inactivo)</span>' ?></div>
        <div class="text-sm">S/ <b><?= number_format((float) $p['precio'], 2) ?></b> cada <?= (int) $p['meses'] ?> mes<?= (int) $p['meses'] === 1 ? '' : 'es' ?></div>
      </div>
      <details class="ce-edit"><summary>Editar</summary><div class="mt-2"><?= $form($p) ?></div></details>
    </div>
  <?php endforeach; ?>

  <div class="ce-card">
    <h2 class="text-sm font-semibold mb-3">Nuevo plan</h2>
    <?= $form(null) ?>
  </div>
</div>
<?php $this->endSection(); ?>

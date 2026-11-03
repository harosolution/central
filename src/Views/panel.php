<?php
$this->extend('layouts/admin');
$this->section('content');

$hijas   = $hijas ?? [];
$ultimas = $ultimas ?? [];
$colores = ['verde' => '#22C55E', 'ambar' => '#F59E0B', 'rojo' => '#EF4444', 'gris' => '#6B7280'];
$textos  = ['verde' => 'Al día', 'ambar' => 'Atrasada', 'rojo' => 'No responde', 'gris' => 'Sin agente'];
$cuenta  = ['verde' => 0, 'ambar' => 0, 'rojo' => 0, 'gris' => 0];
foreach ($hijas as $h) { if ((int) $h['activa'] === 1) { $cuenta[$h['estado']['color']]++; } }
$central = rtrim(base_url('central'), '/');
?>
<style>
  .ce-punto{display:inline-block;width:10px;height:10px;border-radius:50%;flex:0 0 10px}
  .ce-pieza{display:inline-flex;align-items:center;gap:6px;padding:3px 9px;border-radius:999px;font-size:12px;font-weight:600;background:rgba(148,163,184,.12)}
  .ce-pieza.atras{background:rgba(245,158,11,.16);color:#F59E0B}
  .ce-pieza.ok{background:rgba(34,197,94,.14);color:#22C55E}
  .ce-tabla td,.ce-tabla th{padding:10px 12px;vertical-align:top;text-align:left}
  .ce-tabla tr+tr td{border-top:1px solid rgba(148,163,184,.15)}
  .ce-in{width:100%;padding:8px 10px;border-radius:10px;background:#111827;border:1px solid #374151;font-size:13px}
  .ce-btn{padding:8px 14px;border-radius:10px;font-weight:700;font-size:13px;cursor:pointer;border:0}
  .ce-llave{font-family:ui-monospace,monospace;font-size:13px;word-break:break-all;user-select:all}
  details.ce-edit summary{cursor:pointer;font-size:12px;color:#60A5FA;list-style:none}
  @media (max-width:760px){.ce-tabla thead{display:none}.ce-tabla tr{display:block;padding:8px 0}.ce-tabla td{display:block;padding:4px 12px}}
</style>

<div class="space-y-6">
  <div class="flex flex-wrap items-end justify-between gap-3">
    <div>
      <h1 class="text-xl font-bold">Central de hijas</h1>
      <p class="text-sm text-[#9CA3AF]">Quién es hija, qué versión tiene cada una y si está viva. Solo existe en la madre.</p>
    </div>
    <div class="flex flex-wrap gap-2 text-sm">
      <?php foreach ($cuenta as $c => $n): ?>
        <span class="ce-pieza"><i class="ce-punto" style="background:<?= $colores[$c] ?>"></i><?= esc($textos[$c]) ?>: <b><?= $n ?></b></span>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (! empty($ok)): ?><div class="p-3 rounded-2xl bg-[#22C55E]/15 text-[#22C55E] text-sm"><?= esc($ok) ?></div><?php endif; ?>
  <?php if (! empty($error)): ?><div class="p-3 rounded-2xl bg-[#EF4444]/15 text-[#EF4444] text-sm"><?= esc($error) ?></div><?php endif; ?>

  <?php if (! empty($llave)): ?>
  <div class="p-4 rounded-2xl border border-[#F59E0B] bg-[#F59E0B]/10 space-y-2">
    <div class="text-sm font-bold text-[#F59E0B]">Llave de «<?= esc($llaveDe) ?>» — cópiala AHORA, no se vuelve a mostrar</div>
    <div class="ce-llave p-2 rounded-lg bg-[#111827]"><?= esc($llave) ?></div>
    <div class="text-xs text-[#9CA3AF]">Va en el agente de esa hija (<code>~/haro-agente/hijas/&lt;nombre&gt;.json</code>) y en su <code>.env</code> como <code>central.token</code>. Si se pierde, genera otra.</div>
  </div>
  <?php endif; ?>

  <div class="bg-[#1F2937] rounded-2xl shadow-lg p-4">
    <div class="flex flex-wrap items-center gap-3 text-sm mb-3">
      <b>Última versión publicada:</b>
      <?php foreach (['crm' => 'CRM', 'chat' => 'Chat', 'robot' => 'Robot'] as $k => $t): ?>
        <span class="ce-pieza"><?= $t ?> <b><?= esc($ultimas[$k] ?? '—') ?></b></span>
      <?php endforeach; ?>
    </div>
    <div class="overflow-x-auto">
      <table class="ce-tabla w-full text-sm">
        <thead class="text-xs uppercase text-[#9CA3AF]"><tr><th>Hija</th><th>Estado</th><th>Versiones</th><th>Servidor</th><th></th></tr></thead>
        <tbody>
        <?php if (! $hijas): ?>
          <tr><td colspan="5" class="text-[#9CA3AF]">Todavía no hay hijas registradas.</td></tr>
        <?php endif; ?>
        <?php foreach ($hijas as $h): $e = $h['estado']; $l = $h['latido']; ?>
          <tr style="<?= (int) $h['activa'] === 1 ? '' : 'opacity:.45' ?>">
            <td>
              <div class="font-bold"><?= esc($h['nombre']) ?><?= (int) $h['activa'] === 1 ? '' : ' <span class="text-xs">(de baja)</span>' ?></div>
              <?php if ($h['dominio'] !== ''): ?><a class="text-xs text-[#60A5FA]" href="https://<?= esc($h['dominio']) ?>" target="_blank" rel="noopener"><?= esc($h['dominio']) ?></a><?php endif; ?>
            </td>
            <td>
              <div class="flex items-center gap-2 font-semibold"><i class="ce-punto" style="background:<?= $colores[$e['color']] ?>"></i><?= esc($textos[$e['color']]) ?></div>
              <div class="text-xs text-[#9CA3AF]" style="max-width:280px"><?= esc($e['mensaje']) ?></div>
              <?php if ($l): ?><div class="text-xs text-[#9CA3AF]">Última señal: hace <?= esc(\Central\Libraries\Central::hace(max(0, time() - strtotime((string) $l['visto_en'])))) ?></div><?php endif; ?>
            </td>
            <td>
              <div class="flex flex-wrap gap-1">
              <?php foreach (['crm' => 'CRM', 'chat' => 'Chat', 'robot' => 'Robot'] as $k => $t): $p = $e['piezas'][$k]; if ($p['tiene'] === null) { continue; } ?>
                <span class="ce-pieza <?= $p['al_dia'] === false ? 'atras' : ($p['al_dia'] ? 'ok' : '') ?>" title="Publicada: <?= esc($p['ultima'] ?? '—') ?>"><?= $t ?> <?= esc($p['tiene']) ?></span>
              <?php endforeach; ?>
              </div>
            </td>
            <td class="text-xs text-[#9CA3AF]">
              <?= esc($h['servidor']) ?>
              <?php if ($l): ?>
                <div><?= esc($l['servidor']) ?> · PHP <?= esc($l['php_ver']) ?><?= $l['disco_libre_gb'] !== null ? ' · ' . esc($l['disco_libre_gb']) . ' GB libres' : '' ?></div>
              <?php endif; ?>
            </td>
            <td style="min-width:170px">
              <details class="ce-edit">
                <summary>Editar</summary>
                <form method="post" action="<?= $central ?>/hijas/<?= (int) $h['id'] ?>" class="space-y-2 mt-2">
                  <?= csrf_field() ?>
                  <input class="ce-in" name="nombre" value="<?= esc($h['nombre']) ?>" placeholder="Nombre">
                  <input class="ce-in" name="dominio" value="<?= esc($h['dominio']) ?>" placeholder="Dominio">
                  <input class="ce-in" name="servidor" value="<?= esc($h['servidor']) ?>" placeholder="Servidor">
                  <textarea class="ce-in" name="notas" rows="2" placeholder="Notas"><?= esc($h['notas']) ?></textarea>
                  <label class="text-xs flex items-center gap-2"><input type="checkbox" name="activa" value="1" <?= (int) $h['activa'] === 1 ? 'checked' : '' ?>> Activa</label>
                  <button class="ce-btn bg-[#2563EB] text-white">Guardar</button>
                </form>
                <form method="post" action="<?= $central ?>/hijas/<?= (int) $h['id'] ?>/llave" class="mt-2" onsubmit="return confirm('¿Generar una llave nueva? La actual deja de servir y habrá que ponerla en el agente de la hija.')">
                  <?= csrf_field() ?>
                  <button class="ce-btn bg-[#374151] text-white">Llave nueva</button>
                </form>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="bg-[#1F2937] rounded-2xl shadow-lg p-4">
    <h2 class="text-sm font-semibold mb-3">Registrar una hija</h2>
    <form method="post" action="<?= $central ?>/hijas" class="grid gap-2 md:grid-cols-4">
      <?= csrf_field() ?>
      <input class="ce-in" name="nombre" placeholder="Nombre (ej. VITAWEB)" required>
      <input class="ce-in" name="dominio" placeholder="Dominio (ej. crm.vitaweb.us)">
      <input class="ce-in" name="servidor" placeholder="Servidor (ej. 77.93.154.101)">
      <button class="ce-btn bg-[#22C55E] text-[#0B1220]">Registrar y dar llave</button>
      <textarea class="ce-in md:col-span-4" name="notas" rows="2" placeholder="Notas (opcional)"></textarea>
    </form>
  </div>
</div>
<?php $this->endSection(); ?>

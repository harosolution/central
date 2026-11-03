<?php
$this->extend('layouts/admin');
$this->section('content');
$colores = ['verde' => '#22C55E', 'ambar' => '#F59E0B', 'rojo' => '#EF4444', 'gris' => '#6B7280'];
$textos  = ['verde' => 'Al día', 'ambar' => 'Atrasada', 'rojo' => 'No responde', 'gris' => 'Sin agente'];
$cuenta  = ['verde' => 0, 'ambar' => 0, 'rojo' => 0, 'gris' => 0];
foreach ($hijas as $h) { if ((int) $h['activa'] === 1) { $cuenta[$h['estado']['color']]++; } }
$central = rtrim(base_url('central'), '/');
$cuentasIa = $cuentasIa ?? [];
?>
<div class="space-y-5">
  <?= $this->include('Central\Views\_cabecera') ?>

  <div class="flex flex-wrap gap-2 text-sm">
    <?php foreach ($cuenta as $c => $n): ?>
      <span class="ce-pieza"><i class="ce-punto" style="background:<?= $colores[$c] ?>"></i><?= esc($textos[$c]) ?>: <b><?= $n ?></b></span>
    <?php endforeach; ?>
    <span class="ce-pieza">Publicado: CRM <b><?= esc($ultimas['crm'] ?? '—') ?></b> · Chat <b><?= esc($ultimas['chat'] ?? '—') ?></b> · Robot <b><?= esc($ultimas['robot'] ?? '—') ?></b></span>
  </div>

  <div class="ce-card overflow-x-auto">
    <table class="ce-tabla apilada">
      <thead><tr><th>Hija</th><th>Versión</th><th>Licencia</th><th>Servidor</th><th></th></tr></thead>
      <tbody>
      <?php if (! $hijas): ?><tr><td colspan="5" class="text-[#9CA3AF]">Todavía no hay hijas registradas.</td></tr><?php endif; ?>
      <?php foreach ($hijas as $h): $e = $h['estado']; $l = $h['latido']; $li = $h['licencia']; ?>
        <tr style="<?= (int) $h['activa'] === 1 ? '' : 'opacity:.45' ?>">
          <td>
            <div class="font-bold"><?= esc($h['nombre']) ?><?= (int) $h['activa'] === 1 ? '' : ' <span class="text-xs">(de baja)</span>' ?></div>
            <?php if ($h['dominio'] !== ''): ?><a class="text-xs text-[#60A5FA]" href="https://<?= esc($h['dominio']) ?>" target="_blank" rel="noopener"><?= esc($h['dominio']) ?></a><?php endif; ?>
            <?php if ($h['contacto_nombre'] !== '' || $h['contacto_telefono'] !== ''): ?><div class="text-xs text-[#9CA3AF]"><?= esc(trim($h['contacto_nombre'] . ' ' . $h['contacto_telefono'])) ?></div><?php endif; ?>
          </td>
          <td>
            <div class="flex items-center gap-2 font-semibold"><i class="ce-punto" style="background:<?= $colores[$e['color']] ?>"></i><?= esc($textos[$e['color']]) ?></div>
            <div class="flex flex-wrap gap-1 mt-1">
            <?php foreach (['crm' => 'CRM', 'chat' => 'Chat', 'robot' => 'Robot'] as $k => $t): $p = $e['piezas'][$k]; if ($p['tiene'] === null) { continue; } ?>
              <span class="ce-pieza <?= $p['al_dia'] === false ? 'atras' : ($p['al_dia'] ? 'ok' : '') ?>" title="Publicada: <?= esc($p['ultima'] ?? '—') ?>"><?= $t ?> <?= esc($p['tiene']) ?></span>
            <?php endforeach; ?>
            </div>
            <?php if ($l): ?><div class="text-xs text-[#9CA3AF] mt-1">Señal: hace <?= esc(\Central\Libraries\Central::hace(max(0, time() - strtotime((string) $l['visto_en'])))) ?></div><?php endif; ?>
            <?php $ua = $h['ultima_act'] ?? null; if ($h['actualizar_pedido'] || ($ua && in_array($ua['estado'], ['pedida', 'enviada'], true))): ?>
              <div class="text-xs text-[#F59E0B] mt-1">⏳ Actualización a <?= esc($ua['hasta'] ?? '') ?> pedida por <?= esc($ua['por'] ?? $h['actualizar_por']) ?></div>
            <?php elseif ($ua): ?>
              <div class="text-xs mt-1 <?= $ua['estado'] === 'ok' ? 'text-[#22C55E]' : 'text-[#EF4444]' ?>" title="<?= esc($ua['mensaje']) ?>"><?= $ua['estado'] === 'ok' ? '✓' : '✗' ?> <?= esc($ua['desde'] . ' → ' . $ua['hasta']) ?> · <?= esc(date('d/m H:i', strtotime((string) ($ua['terminada_en'] ?: $ua['pedida_en'])))) ?> (<?= esc($ua['por']) ?>)</div>
            <?php endif; ?>
            <?php if ((int) $h['activa'] === 1 && ($e['piezas']['crm']['al_dia'] ?? null) === false && ! $h['actualizar_pedido']): ?>
              <form method="post" action="<?= $central ?>/hijas/<?= (int) $h['id'] ?>/actualizar" class="mt-1" onsubmit="return confirm('¿Actualizar <?= esc($h['nombre'], 'js') ?> a la <?= esc($e['piezas']['crm']['ultima'] ?? '', 'js') ?>? Su agente la instala en los próximos 10 minutos, con respaldo.')">
                <?= csrf_field() ?><button class="ce-btn bg-[#2563EB] text-white" style="padding:5px 10px;font-size:12px">Actualizar ahora</button>
              </form>
            <?php endif; ?>
          </td>
          <td style="max-width:260px">
            <span class="ce-pieza <?= esc($li['color']) ?>"><?= esc($li['plan']['nombre'] ?? 'Sin plan') ?></span>
            <div class="text-xs text-[#9CA3AF] mt-1"><?= esc($li['mensaje']) ?></div>
          </td>
          <td class="text-xs text-[#9CA3AF]">
            <?= esc($h['servidor']) ?>
            <?php if ($l): ?><div><?= esc($l['servidor']) ?> · PHP <?= esc($l['php_ver']) ?><?= $l['disco_libre_gb'] !== null ? ' · ' . esc($l['disco_libre_gb']) . ' GB libres' : '' ?></div><?php endif; ?>
          </td>
          <td style="min-width:190px">
            <details class="ce-edit">
              <summary>Editar</summary>
              <form method="post" action="<?= $central ?>/hijas/<?= (int) $h['id'] ?>" class="space-y-2 mt-2">
                <?= csrf_field() ?>
                <label class="ce-lbl">Nombre<input class="ce-in" name="nombre" value="<?= esc($h['nombre']) ?>"></label>
                <label class="ce-lbl">Dominio<input class="ce-in" name="dominio" value="<?= esc($h['dominio']) ?>"></label>
                <label class="ce-lbl">Servidor<input class="ce-in" name="servidor" value="<?= esc($h['servidor']) ?>"></label>
                <label class="ce-lbl">Plan
                  <select class="ce-in" name="plan_id"><option value="">— Sin plan —</option>
                    <?php foreach ($planes as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $h['plan_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= esc($p['nombre']) ?> · S/ <?= number_format((float) $p['precio'], 2) ?></option><?php endforeach; ?>
                  </select></label>
                <label class="ce-lbl">Vence el<input class="ce-in" type="date" name="vence_en" value="<?= esc((string) $h['vence_en']) ?>"></label>
                <label class="ce-lbl">Contacto (nombre)<input class="ce-in" name="contacto_nombre" value="<?= esc($h['contacto_nombre']) ?>"></label>
                <label class="ce-lbl">Contacto (celular)<input class="ce-in" name="contacto_telefono" inputmode="tel" value="<?= esc($h['contacto_telefono']) ?>"></label>
                <label class="ce-lbl">Su cuenta de Recargas IA
                  <select class="ce-in" name="streamy_cuenta_id"><option value="">— Ninguna —</option>
                    <?php foreach ($cuentasIa as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $h['streamy_cuenta_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= esc($c['nombre']) ?></option><?php endforeach; ?>
                  </select></label>
                <label class="ce-lbl">Notas<textarea class="ce-in" name="notas" rows="2"><?= esc($h['notas']) ?></textarea></label>
                <label class="text-xs flex items-center gap-2"><input type="checkbox" name="activa" value="1" <?= (int) $h['activa'] === 1 ? 'checked' : '' ?>> Activa</label>
                <button class="ce-btn bg-[#2563EB] text-white">Guardar</button>
              </form>
              <form method="post" action="<?= $central ?>/hijas/<?= (int) $h['id'] ?>/llave" class="mt-2" onsubmit="return confirm('¿Generar una llave nueva? La actual deja de servir y habrá que ponerla en el agente y el .env de la hija.')">
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

  <div class="ce-card">
    <h2 class="text-sm font-semibold mb-3">Registrar una hija</h2>
    <form method="post" action="<?= $central ?>/hijas" class="grid gap-2 md:grid-cols-4">
      <?= csrf_field() ?>
      <input class="ce-in" name="nombre" placeholder="Nombre (ej. VITAWEB)" required>
      <input class="ce-in" name="dominio" placeholder="Dominio (ej. crm.vitaweb.us)">
      <input class="ce-in" name="servidor" placeholder="Servidor (ej. 77.93.154.101)">
      <button class="ce-btn bg-[#22C55E] text-[#0B1220]">Registrar y dar llave</button>
    </form>
  </div>
</div>
<?php $this->endSection(); ?>

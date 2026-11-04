<?php
// Cabecera común de la central: estilos, pestañas y avisos. Variables: $vista, $ok, $error, $llave, $llaveDe, $comando, $comandoDe.
$central = rtrim(base_url('central'), '/');
$tabs = ['hijas' => 'Hijas', 'planes' => 'Planes', 'pagos' => 'Pagos', 'ingresos' => 'Ingresos', 'ajustes' => 'Ajustes de cobro'];
?>
<style>
  .ce-punto{display:inline-block;width:10px;height:10px;border-radius:50%;flex:0 0 10px}
  .ce-pieza{display:inline-flex;align-items:center;gap:6px;padding:3px 9px;border-radius:999px;font-size:12px;font-weight:600;background:rgba(148,163,184,.12)}
  .ce-pieza.atras,.ce-pieza.ambar{background:rgba(245,158,11,.16);color:#F59E0B}
  .ce-pieza.ok,.ce-pieza.verde{background:rgba(34,197,94,.14);color:#22C55E}
  .ce-pieza.rojo{background:rgba(239,68,68,.15);color:#EF4444}
  .ce-tabla{width:100%;font-size:13px}
  .ce-tabla td,.ce-tabla th{padding:9px 11px;vertical-align:top;text-align:left}
  .ce-tabla th{font-size:11px;text-transform:uppercase;color:#9CA3AF;font-weight:700}
  .ce-tabla tr+tr td{border-top:1px solid rgba(148,163,184,.15)}
  .ce-tabla td.num,.ce-tabla th.num{text-align:right;font-variant-numeric:tabular-nums}
  .ce-in{width:100%;padding:8px 10px;border-radius:10px;background:#111827;border:1px solid #374151;font-size:13px;color:inherit}
  .ce-btn{padding:8px 14px;border-radius:10px;font-weight:700;font-size:13px;cursor:pointer;border:0}
  .ce-llave{font-family:ui-monospace,monospace;font-size:13px;word-break:break-all;user-select:all}
  .ce-tabs{display:flex;gap:4px;flex-wrap:wrap;border-bottom:1px solid #374151}
  .ce-tabs a{padding:9px 14px;font-size:13px;font-weight:700;color:#9CA3AF;border-bottom:2px solid transparent;text-decoration:none}
  .ce-tabs a.on{color:#60A5FA;border-color:#60A5FA}
  details.ce-edit summary{cursor:pointer;font-size:12px;color:#60A5FA;list-style:none}
  .ce-card{background:#1F2937;border-radius:16px;padding:16px;box-shadow:0 10px 25px rgba(0,0,0,.15)}
  .ce-lbl{font-size:11px;color:#9CA3AF;display:block;margin-bottom:3px}
  @media (max-width:760px){.ce-tabla.apilada thead{display:none}.ce-tabla.apilada tr{display:block;padding:8px 0}.ce-tabla.apilada td{display:block;padding:4px 12px}}
</style>
<div class="space-y-1">
  <h1 class="text-xl font-bold">Central de hijas</h1>
  <p class="text-sm text-[#9CA3AF]">Quién es hija, su versión, su licencia y lo que paga. Solo existe en la madre.</p>
</div>
<nav class="ce-tabs mt-3" aria-label="Secciones de la central">
  <?php foreach ($tabs as $k => $t): ?>
    <a href="<?= $central . '/' . $k ?>" class="<?= $vista === $k ? 'on' : '' ?>"<?= $vista === $k ? ' aria-current="page"' : '' ?>><?= esc($t) ?></a>
  <?php endforeach; ?>
</nav>
<?php if (! empty($ok)): ?><div class="p-3 rounded-2xl bg-[#22C55E]/15 text-[#22C55E] text-sm mt-3"><?= esc($ok) ?></div><?php endif; ?>
<?php if (! empty($error)): ?><div class="p-3 rounded-2xl bg-[#EF4444]/15 text-[#EF4444] text-sm mt-3"><?= esc($error) ?></div><?php endif; ?>
<?php if (! empty($llave)): ?>
<div class="p-4 rounded-2xl border border-[#F59E0B] bg-[#F59E0B]/10 space-y-2 mt-3">
  <div class="text-sm font-bold text-[#F59E0B]">Llave de «<?= esc($llaveDe) ?>» — cópiala AHORA, no se vuelve a mostrar</div>
  <div class="ce-llave p-2 rounded-lg bg-[#111827]"><?= esc($llave) ?></div>
  <div class="text-xs text-[#9CA3AF]">Va en el agente de esa hija (<code>~/haro-agente/hijas/&lt;nombre&gt;.json</code>) y en su <code>.env</code> como <code>central.token</code>.</div>
</div>
<?php endif; ?>
<?php if (! empty($comando)): ?>
<div class="p-4 rounded-2xl border border-[#22C55E] bg-[#22C55E]/10 space-y-2 mt-3">
  <div class="text-sm font-bold text-[#22C55E]">Instalador de «<?= esc($comandoDe ?? '') ?>» — cópialo AHORA y pégalo en el servidor nuevo</div>
  <div class="ce-llave p-2 rounded-lg bg-[#111827]"><?= esc($comando) ?></div>
  <div class="text-xs text-[#9CA3AF]">Vale 48 h y sirve hasta que una instalación termine bien. Necesita Ubuntu o Debian con sudo; instala lo que falte. El SSL sale solo si el dominio ya apunta a ese servidor (con Cloudflare, nube en gris). Al terminar muestra UNA vez la clave del administrador. Opciones al final: <code>| sudo bash -s -- --sin-ssl</code> · <code>--sin-paquetes</code> · <code>--sin-apache</code> · <code>--carpeta /ruta</code>.</div>
</div>
<?php endif; ?>

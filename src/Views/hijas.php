<?php
$this->extend('layouts/admin');
$this->section('content');
$colores = ['verde' => '#22C55E', 'ambar' => '#F59E0B', 'rojo' => '#EF4444', 'gris' => '#6B7280'];
$textos  = ['verde' => 'Al día', 'ambar' => 'Atrasada', 'rojo' => 'No responde', 'gris' => 'Sin agente'];
$cuenta  = ['verde' => 0, 'ambar' => 0, 'rojo' => 0, 'gris' => 0];
foreach ($hijas as $h) { if ((int) $h['activa'] === 1) { $cuenta[$h['estado']['color']]++; } }
$central = rtrim(base_url('central'), '/');
$cuentasIa = $cuentasIa ?? [];
$nombresPieza = \Central\Libraries\Central::NOMBRES;
// Fase 7: cuántas hijas tienen algo que se pueda actualizar a distancia (para «Actualizar todas»).
$porActualizar = array_values(array_filter($hijas, static fn ($h) => (int) $h['activa'] === 1 && ! empty($h['actualizables']) && ! $h['actualizar_pedido']));
?>
<div class="space-y-5">
  <?= $this->include('Central\Views\_cabecera') ?>

  <div class="flex flex-wrap gap-2 text-sm">
    <?php foreach ($cuenta as $c => $n): ?>
      <span class="ce-pieza"><i class="ce-punto" style="background:<?= $colores[$c] ?>"></i><?= esc($textos[$c]) ?>: <b><?= $n ?></b></span>
    <?php endforeach; ?>
    <span class="ce-pieza">Publicado: CRM <b><?= esc($ultimas['crm'] ?? '—') ?></b> · Chat <b><?= esc($ultimas['chat'] ?? '—') ?></b> · Robot <b><?= esc($ultimas['robot'] ?? '—') ?></b> · Agente <b><?= esc($ultimas['agente'] ?? '—') ?></b></span>
    <?php if ($porActualizar): ?>
      <form method="post" action="<?= $central ?>/hijas/actualizar-todas" class="ml-auto" onsubmit="return confirm('¿Actualizar <?= count($porActualizar) ?> hija(s) (<?= esc(implode(', ', array_map(static fn ($h) => $h['nombre'], $porActualizar)), 'js') ?>)? Cada agente instala lo que le falta en los próximos 10 minutos, con respaldo y vuelta atrás si algo falla.')">
        <?= csrf_field() ?><button class="ce-btn bg-[#2563EB] text-white" style="padding:5px 12px;font-size:12px">Actualizar todas (<?= count($porActualizar) ?>)</button>
      </form>
    <?php endif; ?>
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
            <?php $in = $h['instalacion'] ?? null; if ($in && $in['estado'] !== 'anulado'):
                $vencido = in_array($in['estado'], ['pendiente', 'canjeado', 'fallo'], true) && strtotime((string) $in['vence_en']) < time();
                $fecha = static fn ($f) => $f ? date('d/m H:i', strtotime((string) $f)) : '';
                if ($vencido) { $t = ['#9CA3AF', 'Instalador vencido el ' . $fecha($in['vence_en'])]; }
                elseif ($in['estado'] === 'pendiente') { $t = ['#F59E0B', 'Instalador listo (CRM ' . $in['version'] . ') · vence ' . $fecha($in['vence_en'])]; }
                elseif ($in['estado'] === 'canjeado') { $t = ['#F59E0B', '⏳ Instalando en ' . trim($in['servidor'] . ' ' . $in['ip']) . ' desde las ' . $fecha($in['canjeado_en'])]; }
                elseif ($in['estado'] === 'instalada' && ($in['tipo'] ?? 'nueva') === 'chat') { $t = ['#22C55E', '✓ Chat y Robot agregados el ' . $fecha($in['terminada_en']) . ' en ' . $in['dominio_chat']]; }
                elseif ($in['estado'] === 'instalada') { $t = ['#22C55E', '✓ Instalada el ' . $fecha($in['terminada_en']) . ' en ' . trim($in['servidor'] . ' ' . $in['ip']) . ' (CRM ' . $in['version'] . ((int) ($in['con_chat'] ?? 0) === 1 ? ' + Chat y Robot en ' . $in['dominio_chat'] : '') . ')']; }
                else { $t = ['#EF4444', '✗ La instalación falló (' . $fecha($in['terminada_en']) . '): ' . $in['mensaje'] . ' — se puede volver a correr']; } ?>
              <div class="text-xs mt-1" style="color:<?= $t[0] ?>"><?= esc($t[1]) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <div class="flex items-center gap-2 font-semibold"><i class="ce-punto" style="background:<?= $colores[$e['color']] ?>"></i><?= esc($textos[$e['color']]) ?></div>
            <div class="flex flex-wrap gap-1 mt-1">
            <?php foreach (['crm' => 'CRM', 'chat' => 'Chat', 'robot' => 'Robot', 'agente' => 'Agente'] as $k => $t): $p = $e['piezas'][$k] ?? null; if (! $p || $p['tiene'] === null) { continue; } ?>
              <span class="ce-pieza <?= $p['al_dia'] === false ? 'atras' : ($p['al_dia'] ? 'ok' : '') ?>" title="Publicada: <?= esc($p['ultima'] ?? '—') ?>"<?= $k === 'agente' ? ' style="opacity:.75;font-size:11px"' : '' ?>><?= $t ?> <?= esc($p['tiene']) ?></span>
            <?php endforeach; ?>
            </div>
            <?php if ($l): ?><div class="text-xs text-[#9CA3AF] mt-1">Señal: hace <?= esc(\Central\Libraries\Central::hace(max(0, time() - strtotime((string) $l['visto_en'])))) ?></div><?php endif; ?>
            <?php // Fase 7: la última tanda, una línea por pieza (⏳ pedida/enviada · ✓ ok · ✗ fallo, con el motivo al pasar el mouse).
              foreach (($h['acts'] ?? []) as $ua): $pz = $nombresPieza[$ua['pieza'] ?: 'crm'] ?? 'CRM';
                $cuando = date('d/m H:i', strtotime((string) ($ua['terminada_en'] ?: $ua['pedida_en'])));
                if (in_array($ua['estado'], ['pedida', 'enviada'], true)): ?>
              <div class="text-xs text-[#F59E0B] mt-1">⏳ <?= esc($pz . ' → ' . $ua['hasta']) ?> <?= $ua['estado'] === 'enviada' ? 'instalándose' : 'pedida' ?> (<?= esc($ua['por']) ?>)</div>
            <?php else: ?>
              <div class="text-xs mt-1 <?= $ua['estado'] === 'ok' ? 'text-[#22C55E]' : 'text-[#EF4444]' ?>" title="<?= esc($ua['mensaje']) ?>"><?= $ua['estado'] === 'ok' ? '✓' : '✗' ?> <?= esc($pz . ' ' . $ua['desde'] . ' → ' . $ua['hasta']) ?> · <?= esc($cuando) ?> (<?= esc($ua['por']) ?>)<?= $ua['estado'] === 'fallo' ? ' — ' . esc(mb_substr((string) $ua['mensaje'], 0, 120)) : '' ?></div>
            <?php endif; endforeach; ?>
            <?php if ((int) $h['activa'] === 1 && ! empty($h['actualizables']) && ! $h['actualizar_pedido']):
              $queda = implode(', ', array_map(static fn ($k) => $nombresPieza[$k] . ' ' . ($e['piezas'][$k]['ultima'] ?? ''), $h['actualizables'])); ?>
              <form method="post" action="<?= $central ?>/hijas/<?= (int) $h['id'] ?>/actualizar" class="mt-1" onsubmit="return confirm('¿Actualizar <?= esc($h['nombre'], 'js') ?> (<?= esc($queda, 'js') ?>)? Su agente lo instala en los próximos 10 minutos, con respaldo y vuelta atrás si algo falla.')">
                <?= csrf_field() ?><button class="ce-btn bg-[#2563EB] text-white" style="padding:5px 10px;font-size:12px" title="<?= esc($queda) ?>">Actualizar ahora</button>
              </form>
            <?php endif; ?>
            <?php if ((int) ($h['auto_actualizar'] ?? 0) === 1): ?><div class="text-xs text-[#9CA3AF] mt-1">🌙 Se actualiza sola de 4 a 5 am</div><?php endif; ?>
          </td>
          <td style="max-width:260px">
            <span class="ce-pieza <?= esc($li['color']) ?>"><?= esc($li['plan']['nombre'] ?? 'Sin plan') ?></span>
            <div class="text-xs text-[#9CA3AF] mt-1"><?= esc($li['mensaje']) ?></div>
            <div class="text-xs mt-1 <?= ! empty($h['terminos']) ? 'text-[#9CA3AF]' : 'text-[#F59E0B]' ?>"><?= ! empty($h['terminos'])
                ? 'Términos aceptados el ' . esc(date('d/m/Y', strtotime((string) $h['terminos']['aceptado_en']))) . ' por ' . esc($h['terminos']['usuario'] ?: '—')
                : 'Términos sin aceptar' ?></div>
          </td>
          <td class="text-xs text-[#9CA3AF]">
            <?= esc($h['servidor']) ?>
            <?php if (trim((string) ($h['soporte'] ?? '')) !== ''): ?><div class="mt-1" title="Para entrar a revisar (con la llave de HARO). Lo que haya que cambiar, se cambia en la madre."><span class="text-[#E5E7EB]">Soporte:</span> <code class="select-all text-[#22C55E]"><?= esc($h['soporte']) ?></code></div><?php endif; ?>
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
                <label class="ce-lbl" title="Lo llena solo el instalador. Corrígelo si el servidor está detrás de un proxy del proveedor (el puerto de afuera es otro).">Soporte (cómo entrar)<input class="ce-in" name="soporte" maxlength="160" placeholder="ssh -p 22 haro-soporte@1.2.3.4" value="<?= esc((string) ($h['soporte'] ?? '')) ?>"></label>
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
                <label class="text-xs flex items-center gap-2" title="Si está marcada, su agente instala lo publicado de madrugada (4 a 5 am, de a pocas), con respaldo y vuelta atrás. Si no, solo cuando tú o el cliente lo piden."><input type="checkbox" name="auto_actualizar" value="1" <?= (int) ($h['auto_actualizar'] ?? 0) === 1 ? 'checked' : '' ?>> Actualizar sola de madrugada</label>
                <button class="ce-btn bg-[#2563EB] text-white">Guardar</button>
              </form>
              <form method="post" action="<?= $central ?>/hijas/<?= (int) $h['id'] ?>/llave" class="mt-2" onsubmit="return confirm('¿Generar una llave nueva? La actual deja de servir y habrá que ponerla en el agente y el .env de la hija.')">
                <?= csrf_field() ?>
                <button class="ce-btn bg-[#374151] text-white">Llave nueva</button>
              </form>
            </details>
            <?php if (\Central\Libraries\Instalador::ofrecerChat($h)): $ic = $h['instalacion'] ?? []; ?>
            <details class="ce-edit mt-2">
              <summary>Agregar Chat y Robot</summary>
              <form method="post" action="<?= $central ?>/hijas/<?= (int) $h['id'] ?>/agregar-chat" class="space-y-2 mt-2">
                <?= csrf_field() ?>
                <label class="ce-lbl">Dominio del chat (el robot va en /tecnobot/)<input class="ce-in" name="dominio_chat" maxlength="120" placeholder="<?= esc($h['dominio'] !== '' ? \Central\Libraries\Instalador::dominioChat($h['dominio']) : 'chat.empresa.com') ?>" value="<?= esc(($ic['tipo'] ?? '') === 'chat' ? $ic['dominio_chat'] : '') ?>"></label>
                <label class="ce-lbl">Nombre de la vendedora IA<input class="ce-in" name="nombre_robot" maxlength="40" placeholder="Asistente" value="<?= esc(($ic['tipo'] ?? '') === 'chat' ? $ic['nombre_robot'] : '') ?>"></label>
                <label class="text-xs flex items-center gap-2" title="Para auditar y configurar el robot desde Claude (solo edita su borrador). La clave va a nombre del administrador."><input type="checkbox" name="con_conector" value="1" <?= ($ic['tipo'] ?? '') !== 'chat' || (int) ($ic['con_conector'] ?? 1) === 1 ? 'checked' : '' ?>> Con conector de Claude</label>
                <label class="text-xs flex items-center gap-2" title="Usuario haro-soporte que solo entra con las llaves de HARO: mirar registros, reiniciar los servicios de esta tienda y correr el agente. No es sudo completo; se quita con poner-soporte.sh --quitar."><input type="checkbox" name="con_soporte" value="1" <?= ($ic['tipo'] ?? '') !== 'chat' || (int) ($ic['con_soporte'] ?? 1) === 1 ? 'checked' : '' ?>> Con acceso de soporte HARO</label>
                <div class="text-xs text-[#9CA3AF]">Para una hija ya instalada con el instalador de una línea. El comando se pega en SU servidor (usa la llave de su agente) y no toca sus ventas ni su CRM, salvo el enlace con el chat. El dominio del chat debe apuntar a ese servidor.</div>
                <button class="ce-btn bg-[#22C55E] text-[#0B1220]">Crear comando</button>
              </form>
            </details>
            <?php endif; ?>
            <?php if (\Central\Libraries\Instalador::ofrecer($h)): $in = $h['instalacion'] ?? []; ?>
            <details class="ce-edit mt-2">
              <summary>Instalar en un servidor nuevo</summary>
              <form method="post" action="<?= $central ?>/hijas/<?= (int) $h['id'] ?>/instalador" class="space-y-2 mt-2">
                <?= csrf_field() ?>
                <?php // Fase 7f (5-nov-2026, Joe): cada dato con una ayuda breve de para qué sirve. $ay = estilo de esa ayuda.
                $ay = 'block text-[11px] leading-snug text-[#9CA3AF] font-normal mt-0.5';
                $mx = \Central\Libraries\Instalador::MAX_TIENDA; $tv = (string) ($in['tienda'] ?? ''); ?>
                <label class="ce-lbl">Nombre de la tienda <span class="text-[#6B7280]">(hasta <?= $mx ?>)</span>
                  <input class="ce-in" name="tienda" maxlength="<?= $mx ?>" placeholder="Ej.: SIETE DESEOS" value="<?= esc($tv) ?>" oninput="this.parentNode.querySelector('[data-cuenta]').textContent = this.value.length + '/<?= $mx ?>'">
                  <span class="<?= $ay ?>">Sale arriba del menú del CRM, en el chat y en el saludo del robot. Corto, para que se vea completo. Vacío = se arma con la razón social. <b data-cuenta class="text-[#E5E7EB]"><?= mb_strlen($tv) ?>/<?= $mx ?></b></span></label>
                <label class="ce-lbl">Razón social<input class="ce-in" name="empresa" required maxlength="120" placeholder="Ej.: SIETE DESEOS S.A.C." value="<?= esc($in['empresa'] ?? '') ?>">
                  <span class="<?= $ay ?>">El nombre legal de la empresa: sale en boletas, documentos y su página de privacidad.</span></label>
                <label class="ce-lbl">Administrador: nombre<input class="ce-in" name="admin_nombre" required maxlength="120" value="<?= esc($in['admin_nombre'] ?? $h['contacto_nombre']) ?>">
                  <span class="<?= $ay ?>">Quien manda en la tienda. Con su primer nombre se crea su clave del conector de Claude.</span></label>
                <label class="ce-lbl">Administrador: correo<input class="ce-in" type="email" name="admin_email" required maxlength="120" value="<?= esc($in['admin_email'] ?? '') ?>">
                  <span class="<?= $ay ?>">Con este correo entra al CRM. Su clave sale UNA sola vez al terminar la instalación: anótala. Al entrar, el CRM le abre «Configura tu tienda».</span></label>
                <label class="text-xs flex items-start gap-2"><input type="checkbox" class="mt-0.5" name="con_shalom" value="1" <?= ! $in || (int) ($in['con_shalom'] ?? 1) === 1 ? 'checked' : '' ?>><span>Con API de SHALOM (agencias y guías)
                  <span class="<?= $ay ?>">Para buscar agencias y emitir guías con SU propia cuenta de SHALOM (la sesión la sube la empresa con la extensión).</span></span></label>
                <label class="text-xs flex items-start gap-2"><input type="checkbox" class="mt-0.5" name="con_chat" value="1" <?= ! $in || (int) ($in['con_chat'] ?? 1) === 1 ? 'checked' : '' ?>><span>Instalar también Chat y Robot
                  <span class="<?= $ay ?>">El chat de WhatsApp y la vendedora IA, en el dominio del chat. Sin marcar: solo el CRM.</span></span></label>
                <label class="ce-lbl">Dominio del chat (el robot va en /tecnobot/)<input class="ce-in" name="dominio_chat" maxlength="120" placeholder="<?= esc($h['dominio'] !== '' ? \Central\Libraries\Instalador::dominioChat($h['dominio']) : 'chat.empresa.com') ?>" value="<?= esc($in['dominio_chat'] ?? '') ?>">
                  <span class="<?= $ay ?>">Debe apuntar a este mismo servidor antes de pegar el comando. Vacío = chat.&lt;su dominio&gt;.</span></label>
                <label class="ce-lbl">Nombre de la vendedora IA<input class="ce-in" name="nombre_robot" maxlength="40" placeholder="Asistente" value="<?= esc($in['nombre_robot'] ?? '') ?>">
                  <span class="<?= $ay ?>">Cómo se presenta el robot con los clientes (ej. Sofía). Nace apagado y sin IA: se enciende cuando esté listo.</span></label>
                <label class="text-xs flex items-start gap-2"><input type="checkbox" class="mt-0.5" name="con_conector" value="1" <?= ! $in || (int) ($in['con_conector'] ?? 1) === 1 ? 'checked' : '' ?>><span>Con conector de Claude
                  <span class="<?= $ay ?>">Va con el robot: para auditarlo y armar sus reglas desde Claude. Solo cambia el borrador; se publica en su panel.</span></span></label>
                <label class="text-xs flex items-start gap-2"><input type="checkbox" class="mt-0.5" name="con_soporte" value="1" <?= ! $in || (int) ($in['con_soporte'] ?? 1) === 1 ? 'checked' : '' ?>><span>Con acceso de soporte HARO
                  <span class="<?= $ay ?>">Para que HARO entre a revisar sin contraseñas: mira registros y reinicia; no edita nada (lo que se arregla, se arregla en la madre).</span></span></label>
                <div class="text-xs text-[#9CA3AF]">Dominio del CRM: <b><?= esc($h['dominio'] !== '' ? $h['dominio'] : 'falta: ponlo en Editar') ?></b>. Los dos dominios deben apuntar al servidor. Te doy un comando de una línea que vale 48 h.</div>
                <button class="ce-btn bg-[#22C55E] text-[#0B1220]">Crear instalador</button>
              </form>
            </details>
            <?php endif; ?>
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

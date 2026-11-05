<?php
/**
 * AGENTE DE LA HIJA · actualizar (fase 2, 3-nov-2026: el CRM · fase 7, 5-nov-2026: también Chat, Robot y el propio agente).
 *
 *   php actualizar.php <hija>                          → el CRM a la última versión publicada
 *   php actualizar.php <hija> --pieza chat|robot       → el Chat o el Robot (y la puerta de su conector de Claude, si tiene)
 *   php actualizar.php <hija> --pieza agente           → este mismo agente (latido.php, actualizar.php, poner-conector.sh)
 *   … [--seco] (solo baja y comprueba la firma, no toca nada) · [--version X.Y.Z]
 *
 * Siempre: pedir a la central la última versión → bajar el paquete → comprobar sha256 + firma ed25519 (llave pública escrita
 * aquí: un paquete que no firmó la madre NO se instala) → respaldo → copiar encima → prueba de salud → si algo falla después
 * de copiar, vuelve solo a lo anterior.
 *   CRM: nunca .env, writable/, ni datos de la hija (fotos, landings, tienda.json…); migraciones aditivas.
 *   Chat: nunca panel.env, usuarios.json, cuentas.json, respuestas.json, datos/, la sesión de WhatsApp ni mensajes.db.
 *   Robot: nunca .env ni datos/. Si el robot tiene su propio actualizador (rodobot-actualizar@…), se le pide a él.
 *   node_modules solo se cambia si cambió el package-lock.json.
 * Chat y Robot se reinician con systemctl: el agente corre como root (instalador de una línea) o con `sudo -n`.
 * Prueba del «vuelve solo»: HARO_ACTUALIZAR_FALLAR_EN=salud php actualizar.php <hija> --pieza chat
 */
const AGENTE_VER = '1.3.0';
const LLAVE_PUBLICA = 'b8f6c1675fe4fbe201e4d9b8dab96e97403409184336884484356e4853ea710a';
// Archivos que trae el paquete del CRM pero son de la hija (si ya existen, no se pisan).
const CONSERVAR = [
    'chatbot/config.json', 'chatbot/config.backup.json', 'chatbot/config.backup_promo.json', 'chatbot/lid_map.json',
    'chatbot/no_archivar.json', 'chatbot/anuncio_cliente.json', 'chatbot/anuncios_map.json', 'chatbot/ia.json',
    'chatbot/ia2.json', 'chatbot/ia2_atendidos.json', 'public/robots.txt', 'public/favicon.ico',
];
// Chat y Robot: lo de la hija, que el paquete NUNCA pisa (rutas de primer nivel dentro de su carpeta).
const PROPIO = [
    'chat'  => ['.env', 'panel.env', 'usuarios.json', 'cuentas.json', 'respuestas.json', 'datos', 'sesion', 'mensajes.db',
                'mensajes.db-wal', 'mensajes.db-shm', 'hijas.conf', '.git', '.versiones'],
    'robot' => ['.env', 'datos', 'hijas.conf', 'instalar', '.git', '.versiones'],
];
// Lo que el agente se reemplaza a sí mismo (nada más: sus hijas/*.json, respaldos y paquetes se quedan).
const ARCHIVOS_AGENTE = ['latido.php', 'actualizar.php', 'poner-conector.sh'];

$args = array_slice($argv, 1);
$hija = $args[0] ?? '';
$seco = in_array('--seco', $args, true);
$opt = static function (string $k) use ($args): string { $i = array_search($k, $args, true); return $i !== false ? (string) ($args[$i + 1] ?? '') : ''; };
$verPedida = $opt('--version');
$pieza = $opt('--pieza') ?: 'crm';
$base = __DIR__;
$FALLAR = (string) getenv('HARO_ACTUALIZAR_FALLAR_EN');

function say(string $m): void { echo '[' . date('H:i:s') . "] $m\n"; }
function fin(string $m, int $c = 1): void { say(($c ? 'ERROR: ' : '') . $m); exit($c); }
function sh(string $cmd, ?int &$rc = null): string { exec($cmd . ' 2>&1', $o, $rc); return implode("\n", $o); }
function esRoot(): bool { return function_exists('posix_geteuid') && posix_geteuid() === 0; }
function verPkg(string $dir): string { $j = json_decode((string) @file_get_contents("$dir/package.json"), true); return is_array($j) ? trim((string) ($j['version'] ?? '')) : ''; }
function envDe(string $f, string $k): string { return preg_match('/^\s*' . preg_quote($k, '/') . '\s*=\s*(.*)$/m', (string) @file_get_contents($f), $m) ? trim(trim($m[1]), "\"'") : ''; }
function http200(string $url, int $intentos, string $debeTener = ''): bool
{
    for ($n = 0; $n < $intentos; $n++) {
        $r = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]));
        $cod = 0;
        foreach ($http_response_header ?? [] as $h) { if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $cod = (int) $m[1]; } }
        if ($cod === 200 && $r !== false && ($debeTener === '' || strpos($r, $debeTener) !== false)) { return true; }
        sleep(1);
    }
    return false;
}

if (! preg_match('/^[a-z0-9_-]+$/', $hija) || ! is_file("$base/hijas/$hija.json")) {
    fin("Uso: php actualizar.php <hija> [--pieza crm|chat|robot|agente] [--seco] [--version X.Y.Z]  (hijas: " . implode(', ', array_map(fn ($f) => basename($f, '.json'), glob("$base/hijas/*.json") ?: [])) . ')');
}
if (! in_array($pieza, ['crm', 'chat', 'robot', 'agente'], true)) {
    fin("--pieza: crm, chat, robot o agente.");
}
if (! function_exists('sodium_crypto_sign_verify_detached')) {
    fin('Este PHP no tiene sodium: no puedo comprobar la firma, no instalo nada.');
}
$c = json_decode((string) file_get_contents("$base/hijas/$hija.json"), true);
$central = rtrim((string) $c['central'], '/');
$auth = "Authorization: Bearer {$c['llave']}\r\n";

// Un solo actualizador a la vez por hija (sea la pieza que sea)
$lock = fopen("$base/actualizar-$hija.lock", 'c');
if (! flock($lock, LOCK_EX | LOCK_NB)) {
    fin('Ya hay una actualización corriendo para esta hija.');
}

/** La última versión publicada de una pieza, según la central. */
function ultimaDe(string $pieza): string
{
    global $central, $auth;
    $e = json_decode((string) @file_get_contents("$central/api/estado", false, stream_context_create(['http' => ['header' => $auth, 'timeout' => 20, 'ignore_errors' => true]])), true);
    return (string) ($e['piezas'][$pieza]['ultima'] ?? '');
}

/** Baja el paquete firmado de la central y lo comprueba (sha256 + firma de la madre). Devuelve la ruta. */
function bajar(string $pieza, string $ver): string
{
    global $central, $auth, $base;
    @mkdir("$base/paquetes", 0700, true);
    $paq = "$base/paquetes/$pieza-$ver.tgz";
    $in = @fopen("$central/api/paquete?pieza=$pieza&ver=$ver", 'r', false, stream_context_create(['http' => ['header' => $auth, 'timeout' => 600, 'ignore_errors' => true]]));
    if (! $in) {
        fin('No pude conectar con la central.');
    }
    $cab = [];
    foreach (stream_get_meta_data($in)['wrapper_data'] ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $cab['codigo'] = (int) $m[1]; }
        if (preg_match('/^x-sha256:\s*([0-9a-f]{64})/i', $h, $m)) { $cab['sha'] = strtolower($m[1]); }
        if (preg_match('/^x-firma:\s*([0-9a-f]{128})/i', $h, $m)) { $cab['firma'] = strtolower($m[1]); }
    }
    if (($cab['codigo'] ?? 0) !== 200) {
        fin('La central no entregó el paquete (HTTP ' . ($cab['codigo'] ?? '?') . '): ' . substr((string) stream_get_contents($in), 0, 200));
    }
    $out = fopen("$paq.parcial", 'w');
    stream_copy_to_stream($in, $out);
    fclose($out);
    fclose($in);
    rename("$paq.parcial", $paq);
    $sha = hash_file('sha256', $paq);
    if (empty($cab['sha']) || ! hash_equals($cab['sha'], $sha)) {
        @unlink($paq);
        fin('El paquete llegó dañado (sha256 no coincide).');
    }
    if (empty($cab['firma']) || ! sodium_crypto_sign_verify_detached(sodium_hex2bin($cab['firma']), $sha, sodium_hex2bin(LLAVE_PUBLICA))) {
        @unlink($paq);
        fin('FIRMA INVÁLIDA: este paquete no lo firmó la madre. No se instala.');
    }
    say(sprintf('Paquete OK: %.1f MB, sha256 y firma de la madre comprobados.', filesize($paq) / 1048576));
    return $paq;
}

/** Quedan los 2 paquetes más nuevos de cada pieza. */
function podar(string $pieza): void
{
    global $base;
    $viejos = glob("$base/paquetes/$pieza-*.tgz") ?: [];
    usort($viejos, fn ($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($viejos, 2) as $v) { @unlink($v); }
}

/** La unidad de systemd que corre una carpeta (por WorkingDirectory o ExecStart). */
function servicioDe(string $dir): string
{
    foreach (glob('/etc/systemd/system/*.service') ?: [] as $f) {
        if (strpos(basename($f), '@') !== false) { continue; }
        $t = (string) @file_get_contents($f);
        if (preg_match('#^WorkingDirectory=' . preg_quote($dir, '#') . '/?\s*$#m', $t) || preg_match('#^ExecStart=\S+\s+' . preg_quote($dir, '#') . '/\S+\.js#m', $t)) {
            return basename($f);
        }
    }
    return '';
}

/** El node que usa ese servicio (el instalador puede haber puesto uno propio en /opt/haro-node). */
function nodeDe(string $svc): string
{
    $t = (string) @file_get_contents("/etc/systemd/system/$svc");
    return preg_match('#^ExecStart=(\S*node)\s#m', $t, $m) && is_file($m[1]) ? $m[1] : 'node';
}

// ================================================================== EL AGENTE (se reemplaza a sí mismo)
if ($pieza === 'agente') {
    $tiene = preg_match("/const AGENTE_VER = '([\\d.]+)'/", (string) @file_get_contents("$base/latido.php"), $m) ? $m[1] : '';
    $ver = $verPedida ?: ultimaDe('agente');
    if (! preg_match('/^\d+\.\d+\.\d+$/', $ver)) {
        fin('La central no dijo qué versión del agente instalar.');
    }
    say("Agente instalado $tiene · a instalar $ver");
    if (! $verPedida && $tiene !== '' && version_compare($tiene, $ver, '>=')) {
        fin('Ya está al día.', 0);
    }
    $paq = bajar('agente', $ver);
    $stage = "$base/tmp/agente-$ver";
    sh('rm -rf ' . escapeshellarg($stage));
    @mkdir($stage, 0700, true);
    sh('tar xzf ' . escapeshellarg($paq) . ' -C ' . escapeshellarg($stage), $rc);
    foreach (['latido.php', 'actualizar.php'] as $f) {
        if ($rc !== 0 || ! is_file("$stage/$f")) {
            fin("Al paquete del agente le falta $f.");
        }
        sh(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg("$stage/$f"), $rcl);
        if ($rcl !== 0) {
            fin("$f del paquete no compila: no se toca nada.");
        }
    }
    if (! preg_match("/const AGENTE_VER = '" . preg_quote($ver, '/') . "'/", (string) file_get_contents("$stage/latido.php"))) {
        fin("El latido.php del paquete no es la versión $ver.");
    }
    if ($seco) {
        sh('rm -rf ' . escapeshellarg($stage));
        fin("ENSAYO: agente $ver comprobado. No se tocó nada.", 0);
    }
    $resp = "$base/respaldos/agente-" . date('Ymd_His');
    @mkdir($resp, 0700, true);
    foreach (ARCHIVOS_AGENTE as $f) {
        if (is_file("$base/$f")) { copy("$base/$f", "$resp/$f"); }
    }
    // Cada archivo se escribe aparte y se cambia de golpe (rename): nunca queda uno a medias.
    foreach (ARCHIVOS_AGENTE as $f) {
        if (! is_file("$stage/$f")) { continue; }
        $modo = is_file("$base/$f") ? (fileperms("$base/$f") & 0777) : 0700;
        copy("$stage/$f", "$base/.$f.nuevo");
        chmod("$base/.$f.nuevo", $modo);
        rename("$base/.$f.nuevo", "$base/$f");
    }
    sh('rm -rf ' . escapeshellarg($stage));
    podar('agente');
    say("LISTO: Agente $tiene → $ver (respaldo del anterior en " . basename($resp) . ').');
    exit(0);
}

// ================================================================== CHAT Y ROBOT
if ($pieza === 'chat' || $pieza === 'robot') {
    $Nombre = $pieza === 'chat' ? 'Chat' : 'Robot';
    $dir = rtrim((string) ($c[$pieza] ?? ''), '/');
    $main = $pieza === 'chat' ? 'servidor.js' : 'server.js';
    if ($dir === '' || ! is_file("$dir/$main") || ! is_file("$dir/package.json")) {
        fin("Esta hija no tiene $Nombre (o su carpeta no es válida: «$dir»).");
    }
    if (is_dir("$dir/.git")) {
        fin("$dir se maneja con git (es una madre o una copia de trabajo): no la toco desde aquí.");
    }
    $tiene = verPkg($dir);
    $ver = $verPedida ?: ultimaDe($pieza);
    if (! preg_match('/^\d+\.\d+\.\d+$/', $ver)) {
        fin('La central no dijo qué versión instalar.');
    }
    say("Hija $hija · $Nombre instalado $tiene · a instalar $ver");
    if (! $verPedida && $tiene !== '' && version_compare($tiene, $ver, '>=')) {
        fin('Ya está al día.', 0);
    }

    // Robot con su propio actualizador (RODOBOT 1.8+: rodobot-actualizar@<carpeta>.path): se le pide a él, que valida,
    // respalda, reinicia, vuelve atrás solo y actualiza también la puerta de su conector (RODOBOT_PUERTAS).
    $unidad = basename($dir);
    if ($pieza === 'robot' && trim(sh('systemctl is-active ' . escapeshellarg("rodobot-actualizar@$unidad.path"))) === 'active' && is_dir("$dir/datos/.rodobot")) {
        if ($seco) {
            fin("ENSAYO: este robot tiene su propio actualizador; se le pediría la $ver.", 0);
        }
        $ts = gmdate('Y-m-d\TH:i:s') . '.000Z';   // mismo formato que su «inicio» (con milisegundos) para comparar
        $ped = "$dir/datos/.rodobot/pedido.json";
        file_put_contents("$ped.tmp", json_encode(['accion' => 'aplicar', 'version' => $ver, 'forzar' => false, 'quien' => 'central (agente)', 'ts' => $ts]));
        rename("$ped.tmp", $ped);
        say("Se lo pedí a su actualizador (rodobot-actualizar@$unidad); espero a que termine…");
        $fin = time() + 600; $e = [];
        while (time() < $fin) {
            sleep(5);
            $e = json_decode((string) @file_get_contents("$dir/datos/.rodobot/estado.json"), true) ?: [];
            if (($e['inicio'] ?? '') < $ts || in_array($e['estado'] ?? '', ['validando', 'aplicando', 'reiniciando'], true)) {
                continue;
            }
            $pasos = is_array($e['pasos'] ?? null) ? $e['pasos'] : [];
            $ult = $pasos ? (array) end($pasos) : [];
            if (($e['estado'] ?? '') === 'ok' && ($e['version_nueva'] ?? '') === $ver) {
                fin("LISTO: Robot $tiene → $ver (con su actualizador; salud OK).", 0);
            }
            fin("Su actualizador terminó en «" . ($e['estado'] ?? '?') . "»: " . ($e['motivo'] ?? ($ult['texto'] ?? '')) . ' (el robot quedó en ' . verPkg($dir) . ').');
        }
        fin('Su actualizador no terminó en 10 minutos (va en «' . ($e['estado'] ?? '?') . '»). Mira datos/.rodobot/estado.json.');
    }

    // Instalar el paquete aquí mismo.
    $sudo = esRoot() ? '' : 'sudo -n ';
    if ($sudo !== '') {
        sh('sudo -n true', $rcs);
        if ($rcs !== 0) {
            fin("Sin permiso para reiniciar el $Nombre: el agente no es root ni tiene sudo sin clave. Hace falta una vez darle ese permiso. No se tocó nada.");
        }
    }
    if (! esRoot() && ! is_writable($dir)) {
        fin("No puedo escribir en $dir. No se tocó nada.");
    }
    $svc = trim((string) ($c[$pieza . '_servicio'] ?? '')) ?: servicioDe($dir);
    if ($svc === '' || ! preg_match('/^[A-Za-z0-9@_.-]+\.service$/', $svc)) {
        fin("No encuentro el servicio de systemd que corre $dir. No se tocó nada.");
    }
    if ($pieza === 'chat') {
        $puerto = envDe("$dir/panel.env", 'PORT');
        $salud = "http://127.0.0.1:$puerto/login";
    } else {
        $puerto = envDe("$dir/.env", 'PUERTO');
        $salud = "http://127.0.0.1:$puerto/tecnobot/api/equipo";
    }
    if (! ctype_digit($puerto)) {
        fin("No sé en qué puerto escucha el $Nombre. No se tocó nada.");
    }
    $node = nodeDe($svc);

    $paq = bajar($pieza, $ver);
    $stage = "$base/tmp/$pieza-$ver";
    sh('rm -rf ' . escapeshellarg($stage));
    @mkdir($stage, 0700, true);
    sh('tar xzf ' . escapeshellarg($paq) . ' -C ' . escapeshellarg($stage), $rc);
    if ($rc !== 0 || ! is_file("$stage/$main") || ! is_file("$stage/package.json")) {
        fin('El paquete no se pudo descomprimir bien.');
    }
    foreach (PROPIO[$pieza] as $p) {
        sh('rm -rf ' . escapeshellarg("$stage/$p"));
    }
    // node_modules: si el lock es el mismo, se queda el instalado (ya probado en este servidor).
    $nmNuevo = ! (is_file("$dir/package-lock.json") && is_file("$stage/package-lock.json") && hash_file('sha256', "$dir/package-lock.json") === hash_file('sha256', "$stage/package-lock.json") && is_dir("$dir/node_modules"));
    if (! $nmNuevo) {
        sh('rm -rf ' . escapeshellarg("$stage/node_modules"));
    } elseif (! is_dir("$stage/node_modules")) {
        fin('El paquete cambia las dependencias pero no las trae. No se tocó nada.');
    }
    if ($seco) {
        sh('rm -rf ' . escapeshellarg($stage));
        fin("ENSAYO: $Nombre $ver comprobado y aplicable (servicio $svc, salud $salud" . ($nmNuevo ? ', cambia node_modules' : '') . '). No se tocó nada.', 0);
    }

    // Respaldo del código (sin datos, sesión ni node_modules)
    @mkdir("$base/respaldos", 0700, true);
    $ts = date('Ymd_His');
    $resp = "$base/respaldos/$hija-$pieza-$ts.tgz";
    $ex = '';
    foreach (array_merge(PROPIO[$pieza], ['node_modules', 'vendor-causa/node_modules']) as $p) { $ex .= ' --exclude=' . escapeshellarg("./$p"); }
    sh('tar czf ' . escapeshellarg($resp) . ' -C ' . escapeshellarg($dir) . $ex . ' .', $rc);
    if ($rc > 1) {
        fin('No se pudo respaldar el código. No se tocó nada.');
    }
    say('Respaldo: ' . basename($resp));
    $duenoDe = static function (string $d) use ($sudo): void {
        if (esRoot() || $sudo !== '') { sh($sudo . 'chown -R --reference=' . escapeshellarg($d) . ' ' . escapeshellarg($d)); }
    };
    $volver = function (string $motivo) use ($dir, $resp, $nmNuevo, $ts, $svc, $sudo, $salud, $duenoDe, $Nombre): void {
        say("Algo falló ($motivo): vuelvo al $Nombre anterior…");
        if ($nmNuevo && is_dir("$dir/node_modules.viejo-$ts")) {
            sh('rm -rf ' . escapeshellarg("$dir/node_modules") . ' && mv ' . escapeshellarg("$dir/node_modules.viejo-$ts") . ' ' . escapeshellarg("$dir/node_modules"));
        }
        sh('tar xzf ' . escapeshellarg($resp) . ' -C ' . escapeshellarg($dir), $rc);
        $duenoDe($dir);
        sh($sudo . 'systemctl restart ' . escapeshellarg($svc));
        $arriba = http200($salud, 40);
        fin($rc === 0 ? "Revertido: el $Nombre quedó como estaba" . ($arriba ? '' : ' (OJO: no responde, revisar ' . $svc . ')') . " ($motivo)." : "¡NO SE PUDO REVERTIR! Restaurar a mano desde $resp");
    };
    if ($nmNuevo && is_dir("$dir/node_modules") && ! rename("$dir/node_modules", "$dir/node_modules.viejo-$ts")) {
        fin('No pude apartar node_modules/. No se tocó nada.');
    }
    // cp -R (NO -a): lo que existe conserva dueño y permisos; lo nuevo se le da al dueño de la carpeta.
    sh('cp -R ' . escapeshellarg("$stage/.") . ' ' . escapeshellarg("$dir/"), $rc);
    if ($rc !== 0) {
        $volver('copia de archivos');
    }
    $duenoDe($dir);
    say('Archivos copiados.');
    $carga = $pieza === 'robot' ? "require('./lib/motor'); require('./lib/vivo')" : '';
    sh(escapeshellarg($node) . ' --check ' . escapeshellarg("$dir/$main"), $rc);
    if ($rc === 0 && $carga !== '') {
        sh('cd ' . escapeshellarg($dir) . ' && timeout 60 ' . escapeshellarg($node) . ' -e ' . escapeshellarg($carga), $rc);
    }
    if ($rc !== 0 || $FALLAR === 'carga') {
        $volver('el código nuevo no carga');
    }
    sh($sudo . 'systemctl restart ' . escapeshellarg($svc), $rc);
    if ($rc !== 0 || ! http200($salud, 40) || $FALLAR === 'salud') {
        $volver("salud: $salud no respondió después de reiniciar $svc");
    }
    sh('rm -rf ' . escapeshellarg("$dir/node_modules.viejo-$ts") . ' ' . escapeshellarg($stage));
    podar($pieza);
    $extra = '';

    // La puerta del conector de Claude de este robot (poner-conector.sh): misma versión del código.
    if ($pieza === 'robot') {
        $pd = rtrim((string) ($c['conector'] ?? "/var/www/$hija-conector/tecnobot"), '/');
        $ps = trim((string) ($c['conector_servicio'] ?? "haro-$hija-conector.service"));
        if (is_file("$pd/server.js") && is_file("/etc/systemd/system/$ps") && envDe("$pd/.env", 'CONECTOR_ACTIVO') === '1') {
            $pp = envDe("$pd/.env", 'PUERTO');
            $presp = "$base/respaldos/$hija-conector-$ts.tgz";
            sh('tar czf ' . escapeshellarg($presp) . ' -C ' . escapeshellarg($pd) . ' --exclude=./datos --exclude=./.env --exclude=./node_modules .');
            sh('tar -C ' . escapeshellarg($dir) . ' --exclude=./datos --exclude=./.env --exclude=./.versiones -cf - . | tar -C ' . escapeshellarg($pd) . ' -xf - --no-same-owner', $rc);
            $duenoDe(dirname($pd));
            sh($sudo . 'systemctl restart ' . escapeshellarg($ps));
            if ($rc === 0 && http200("http://127.0.0.1:$pp/.well-known/oauth-protected-resource/tecnobot/mcp", 30, '"resource"')) {
                $extra = ' · conector de Claude también en ' . $ver;
            } else {
                sh('tar xzf ' . escapeshellarg($presp) . ' -C ' . escapeshellarg($pd));
                $duenoDe(dirname($pd));
                sh($sudo . 'systemctl restart ' . escapeshellarg($ps));
                $extra = ' · OJO: el conector de Claude no arrancó con la nueva y quedó en la anterior';
            }
        }
    }
    say("LISTO: $Nombre $tiene → $ver (salud OK en $salud$extra).");
    exit(0);
}

// ================================================================== CRM (fase 2, sin cambios)
$crm = rtrim((string) ($c['crm'] ?? ''), '/');
if ($crm === '' || ! is_file("$crm/spark") || ! is_file("$crm/.env")) {
    fin("La carpeta del CRM no es válida: $crm");
}
// «dueno» (ej. "www-data:www-data"): para un CRM cuyos archivos NO son del usuario del agente. Se corre con sudo y,
// al terminar (o al revertir), todo vuelve a ese dueño. Sin «dueno» no se cambia ningún dueño.
$dueno = trim((string) ($c['dueno'] ?? ''));
if ($dueno !== '' && ! preg_match('/^[a-z_][a-z0-9_-]*:[a-z_][a-z0-9_-]*$/', $dueno)) {
    fin("«dueno» inválido: $dueno");
}
if ($dueno !== '' && function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fin("Esta hija es de $dueno: corre el actualizador con sudo.");
}
$devolverDueno = function () use ($dueno, $crm): void {
    if ($dueno !== '') {
        sh('chown -R ' . escapeshellarg($dueno) . ' ' . escapeshellarg($crm));
    }
};

// 1) ¿Qué versión?
$tiene = trim((string) (json_decode((string) @file_get_contents("$crm/deploy/version.json"), true)['stable'] ?? ''));
$ver = $verPedida ?: ultimaDe('crm');
if (! preg_match('/^\d+\.\d+\.\d+$/', $ver)) {
    fin('La central no dijo qué versión instalar.');
}
say("Hija $hija · CRM instalado $tiene · a instalar $ver");
if (! $verPedida && $tiene !== '' && version_compare($tiene, $ver, '>=')) {
    fin('Ya está al día.', 0);
}

// 2) Bajar y comprobar
$paq = bajar('crm', $ver);

// 3) Preparar (descomprimir aparte)
$stage = "$base/tmp/crm-$ver";
sh('rm -rf ' . escapeshellarg($stage));
@mkdir($stage, 0700, true);
sh('tar xzf ' . escapeshellarg($paq) . ' -C ' . escapeshellarg($stage), $rc);
if ($rc !== 0 || ! is_file("$stage/spark") || ! is_file("$stage/vendor/autoload.php")) {
    fin('El paquete no se pudo descomprimir bien.');
}
foreach (CONSERVAR as $p) {
    if (is_file("$crm/$p")) {
        @unlink("$stage/$p");
    }
}
// Carpetas de DATOS de la hija: si ya existen, el paquete no entra en ellas (fotos subidas, imágenes de productos…).
foreach (['uploaded_images', 'public/uploads', 'public/img/productos', 'public/data'] as $p) {
    if (is_dir("$crm/$p")) {
        sh('rm -rf ' . escapeshellarg("$stage/$p"));
    }
}
@unlink("$stage/.env");
sh('rm -rf ' . escapeshellarg("$stage/writable"));
// Antes de tocar nada: ¿puedo escribir todo lo que el paquete va a pisar?
$noPuedo = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($stage) + 1);
    $dst = "$crm/$rel";
    if ((file_exists($dst) && ! is_writable($dst)) || (! file_exists($dst) && is_dir(dirname($dst)) && ! is_writable(dirname($dst)))) {
        $noPuedo[] = $rel;
    }
}
if ($noPuedo) {
    fin(count($noPuedo) . ' archivo(s) del CRM no se pueden escribir sin permisos (ej. ' . implode(', ', array_slice($noPuedo, 0, 5)) . '). No se tocó nada.');
}
if ($seco) {
    sh('rm -rf ' . escapeshellarg($stage));
    fin("ENSAYO: paquete $ver comprobado y aplicable. No se tocó nada.", 0);
}

// 4) Respaldos (base + código)
@mkdir("$base/respaldos", 0700, true);
$ts = date('Ymd_His');
$env = (string) file_get_contents("$crm/.env");
$ev = function (string $k) use ($env): string {
    return preg_match('/^\s*' . preg_quote($k, '/') . '\s*=\s*(.*)$/m', $env, $m) ? trim(trim($m[1]), "\"'") : '';
};
$dbHost = $ev('database.default.hostname') ?: 'localhost';
$dbName = $ev('database.default.database');
$dbUser = $ev('database.default.username');
putenv('MYSQL_PWD=' . $ev('database.default.password'));
$dump = "$base/respaldos/$hija-$ts-base.sql.gz";
sh('bash -c ' . escapeshellarg('set -o pipefail; mysqldump --single-transaction --routines --no-tablespaces -h ' . escapeshellarg($dbHost) . ' -u ' . escapeshellarg($dbUser) . ' ' . escapeshellarg($dbName) . ' | gzip > ' . escapeshellarg($dump)), $rc);
if ($rc !== 0 || filesize($dump) < 1000) {
    fin('No se pudo respaldar la base. No se tocó nada.');
}
$codigo = "$base/respaldos/$hija-$ts-codigo.tgz";
sh('tar czf ' . escapeshellarg($codigo) . ' -C ' . escapeshellarg($crm) . ' --exclude=./writable --exclude=./public/uploads --exclude=./public/img --exclude=./uploaded_images --exclude=./public/data --exclude="*.bak*" .', $rc);
if ($rc > 1) {   // 1 = «archivo cambió mientras se leía»: aceptable
    fin('No se pudo respaldar el código. No se tocó nada.');
}
say('Respaldos: ' . basename($dump) . ' y ' . basename($codigo));

// 5) Aplicar
$volver = function (string $motivo) use ($codigo, $crm, $devolverDueno): void {
    say("Algo falló ($motivo): devuelvo el código anterior…");
    sh('rm -rf ' . escapeshellarg("$crm/vendor"));
    sh('tar xzf ' . escapeshellarg($codigo) . ' -C ' . escapeshellarg($crm), $rc);
    $devolverDueno();
    fin($rc === 0 ? "Revertido. El CRM quedó como estaba ($motivo)." : "¡NO SE PUDO REVERTIR! Restaurar a mano desde $codigo");
};
// vendor se reemplaza entero (no quedan librerías viejas mezcladas)
if (is_dir("$crm/vendor") && ! rename("$crm/vendor", "$crm/vendor.viejo-$ts")) {
    fin('No pude apartar vendor/. No se tocó nada.');
}
// cp -R (NO -a): lo que ya existe conserva dueño, grupo y permisos (la carpeta del CRM es del grupo www-data);
// lo nuevo nace con los permisos normales. Con -a la carpeta raíz heredaría el 700 del temporal y Apache no entraría.
sh('cp -R ' . escapeshellarg("$stage/.") . ' ' . escapeshellarg("$crm/"), $rc);
if ($rc !== 0) {
    sh('rm -rf ' . escapeshellarg("$crm/vendor") . ' && mv ' . escapeshellarg("$crm/vendor.viejo-$ts") . ' ' . escapeshellarg("$crm/vendor"));
    $volver('copia de archivos');
}
$devolverDueno();
say('Archivos copiados' . ($dueno !== '' ? " (dueño: $dueno)" : '') . '.');

// 6) Migraciones (aditivas, idempotentes)
$salida = sh('HARO_APP_DIR=' . escapeshellarg($crm) . ' bash ' . escapeshellarg("$crm/deploy/aplicar-migraciones.sh"), $rc);
echo $salida, "\n";
if ($rc !== 0) {
    sh('rm -rf ' . escapeshellarg("$crm/vendor") . ' && mv ' . escapeshellarg("$crm/vendor.viejo-$ts") . ' ' . escapeshellarg("$crm/vendor"));
    $volver('migraciones');
}

// 7) Salud: el login del CRM debe responder sin error
sleep(3);   // opcache revisa los archivos cada pocos segundos
$url = rtrim($ev('app.baseURL') ?: ('https://' . ($c['dominio'] ?? 'localhost') . '/'), '/') . '/admin/dashboard';
$ok = false;
for ($n = 0; $n < 3 && ! $ok; $n++) {
    $r = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true, 'follow_location' => 1]]));
    $cod = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $cod = (int) $m[1]; }
    }
    $ok = $cod === 200 && $r !== false && stripos($r, 'Whoops') === false && stripos($r, 'ErrorException') === false;
    if (! $ok) { sleep(4); }
}
if (! $ok) {
    sh('rm -rf ' . escapeshellarg("$crm/vendor") . ' && mv ' . escapeshellarg("$crm/vendor.viejo-$ts") . ' ' . escapeshellarg("$crm/vendor"));
    $volver("salud: $url respondió $cod");
}
sh('rm -rf ' . escapeshellarg("$crm/vendor.viejo-$ts") . ' ' . escapeshellarg($stage));
podar('crm');
say("LISTO: CRM $tiene → $ver (salud OK en $url).");
passthru('php ' . escapeshellarg("$base/latido.php"));
exit(0);

<?php
/**
 * Arma el PAQUETE firmado de una versión publicada (fase 2 de la Central, 3-nov-2026; chat y robot: fase 6, 4-nov).
 *
 *   php herramientas/empaquetar.php crm 2.2.0
 *   php herramientas/empaquetar.php chat 1.1.2
 *   php herramientas/empaquetar.php robot 1.23.1
 *   php herramientas/empaquetar.php agente 1.3.0     (fase 7: el agente de las hijas, desde agente/ de ESTE repo)
 *
 * Siempre el tag vX.Y.Z del repo de la madre (git archive, NUNCA la carpeta de trabajo) + las dependencias ya
 * instaladas en la madre, para que la hija no necesite composer ni npm:
 *   crm   → + vendor/ (debe coincidir con el composer.lock del tag). Sin writable/ (es de la hija).
 *           + deploy/instalar/esquema.sql + semilla.sql (herramientas/semilla.php, con sudo: la estructura de la base
 *             de la madre, nunca sus datos) para instalar una hija NUEVA.
 *   chat  → + node_modules/ (debe coincidir con el package-lock.json del tag; Node 20: better-sqlite3 va compilado).
 *   robot → + node_modules/ (ídem) + instalar/robot-inicial.json (herramientas/robot-inicial.js: la configuración de
 *             fábrica SIN los datos de TECNOVA, sin productos, sin medios de pago y la IA sin key).
 * Deja en datos/paquetes/:  <pieza>-X.Y.Z.tgz · .sha256 · .firma (ed25519 del sha256, llave datos/firma.sk)
 *      · .instalable: el paquete sirve para instalar una hija nueva (lo usa Instalador::versionInstalable()).
 *   agente → latido.php + actualizar.php + poner-conector.sh tal como están en git (HEAD) en agente/; la versión debe
 *            ser la AGENTE_VER de latido.php. Los agentes 1.3.0+ se reemplazan solos con él cuando se les pide.
 * La llave privada se crea la primera vez y NUNCA sale de la madre; la pública va escrita en el agente.
 */
if (PHP_SAPI !== 'cli') {
    exit("Solo por consola.\n");
}
[$_, $pieza, $ver] = $argv + [null, null, null];
if (! in_array($pieza, ['crm', 'chat', 'robot', 'agente'], true) || ! preg_match('/^\d+\.\d+\.\d+$/', (string) $ver)) {
    exit("Uso: php empaquetar.php crm|chat|robot|agente X.Y.Z\n");
}
$base = dirname(__DIR__);
$cfg = require $base . '/config.php';
$repo = $pieza === 'agente' ? $base : ($cfg['repos'][$pieza] ?? ['crm' => '/var/www/tecnovaplus/crm', 'chat' => '/var/www/botpruebas', 'robot' => '/var/www/tecnobot'][$pieza]);
$dir = $base . '/datos/paquetes';
@mkdir($dir, 0750, true);

// Llave de firma (una sola vez)
$sk = $base . '/datos/firma.sk';
if (! is_file($sk)) {
    $par = sodium_crypto_sign_keypair();
    file_put_contents($sk, sodium_bin2hex(sodium_crypto_sign_secretkey($par)));
    chmod($sk, 0600);
    file_put_contents($base . '/datos/firma.pk', sodium_bin2hex(sodium_crypto_sign_publickey($par)) . "\n");
    echo "Llave de firma creada. Pública: " . trim(file_get_contents($base . '/datos/firma.pk')) . "\n";
}

/** sha256 + firma ed25519 (+ .instalable si sirve para instalar una hija nueva) y grupo www-data para que la web lo sirva. */
function firmar(string $final, string $sk, bool $instalable): void
{
    chmod($final, 0640);
    $sha = hash_file('sha256', $final);
    $firma = sodium_bin2hex(sodium_crypto_sign_detached($sha, sodium_hex2bin(trim(file_get_contents($sk)))));
    file_put_contents("$final.sha256", $sha . "\n");
    file_put_contents("$final.firma", $firma . "\n");
    $extras = ['.sha256', '.firma'];
    if ($instalable) {
        file_put_contents("$final.instalable", date('c') . "\n");   // fase 6: sirve para instalar una hija nueva
        $extras[] = '.instalable';
    }
    @chgrp($final, 'www-data');
    foreach ($extras as $e) {
        chmod($final . $e, 0640);
        @chgrp($final . $e, 'www-data');
    }
    // La web (www-data) sirve el paquete: si el chgrp no se pudo (administrator no es del grupo), con sudo.
    clearstatcache();
    if (function_exists('posix_getgrgid') && (posix_getgrgid(filegroup($final))['name'] ?? '') !== 'www-data') {
        exec('sudo -n chgrp www-data ' . implode(' ', array_map('escapeshellarg', array_merge([$final], array_map(fn ($e) => $final . $e, $extras)))), $o, $rcg);
        if ($rcg !== 0) {
            echo "OJO: no pude darle el grupo www-data al paquete; la central no podrá servirlo.\n";
        }
    }
    printf("Paquete %s  (%.1f MB)\nsha256 %s\n", $final, filesize($final) / 1048576, $sha);
}

// Fase 7: el AGENTE de las hijas (sale de agente/ de este repo, tal como está en git).
if ($pieza === 'agente') {
    $vf = preg_match("/const AGENTE_VER = '([\d.]+)'/", (string) file_get_contents("$base/agente/latido.php"), $m) ? $m[1] : '';
    if ($vf !== $ver) {
        exit("agente/latido.php dice AGENTE_VER = '$vf', no $ver.\n");
    }
    $va = preg_match("/const AGENTE_VER = '([\d.]+)'/", (string) file_get_contents("$base/agente/actualizar.php"), $m) ? $m[1] : '';
    if ($va !== $ver) {
        exit("agente/actualizar.php dice AGENTE_VER = '$va', no $ver.\n");
    }
    exec('git -C ' . escapeshellarg($base) . ' diff --quiet HEAD -- agente/', $o, $rc);
    if ($rc !== 0) {
        exit("Hay cambios sin commit en agente/: el paquete sale de lo que está en git (haz commit primero).\n");
    }
    $tmp = sys_get_temp_dir() . '/paquete-agente-' . $ver . '-' . getmypid();
    @mkdir($tmp, 0700, true);
    passthru('git -C ' . escapeshellarg($base) . ' archive --format=tar HEAD agente/latido.php agente/actualizar.php agente/poner-conector.sh | tar -x -C ' . escapeshellarg($tmp) . ' --strip-components=1', $rc);
    foreach (['latido.php', 'actualizar.php', 'poner-conector.sh'] as $f) {
        if ($rc !== 0 || ! is_file("$tmp/$f")) {
            exec('rm -rf ' . escapeshellarg($tmp));
            exit("No pude sacar agente/$f de git.\n");
        }
    }
    $final = "$dir/agente-$ver.tgz";
    passthru('tar -czf ' . escapeshellarg($final) . ' -C ' . escapeshellarg($tmp) . ' latido.php actualizar.php poner-conector.sh', $rc);
    exec('rm -rf ' . escapeshellarg($tmp));
    if ($rc !== 0) {
        exit("Falló el tar del agente.\n");
    }
    firmar($final, $sk, false);
    exit(0);
}

$tag = 'v' . $ver;
exec('git -C ' . escapeshellarg($repo) . ' rev-parse -q --verify ' . escapeshellarg($tag . '^{commit}'), $o, $rc);
if ($rc !== 0) {
    exit("No existe el tag $tag en $repo\n");
}
// Las dependencias que se empaquetan deben corresponder al lock del tag.
$lock = $pieza === 'crm' ? 'composer.lock' : 'package-lock.json';
$deps = $pieza === 'crm' ? 'vendor' : 'node_modules';
$lockTag = shell_exec('git -C ' . escapeshellarg($repo) . ' show ' . escapeshellarg($tag . ':' . $lock));
if (hash('sha256', (string) $lockTag) !== hash_file('sha256', $repo . '/' . $lock)) {
    exit("El $lock de $tag no es el instalado en la madre: no puedo empaquetar su $deps.\n");
}
if (! is_dir("$repo/$deps")) {
    exit("La madre no tiene $deps/ en $repo.\n");
}

$tmp = sys_get_temp_dir() . '/paquete-' . $pieza . '-' . $ver . '-' . getmypid();
@mkdir($tmp, 0700, true);
$tar = "$tmp/$pieza.tar";
$src = "$tmp/src";
@mkdir($src, 0700, true);
// git archive sale en ustar (no admite nombres largos de vendor/google): se extrae y se re-empaqueta en formato GNU.
$cmds = ['git -C ' . escapeshellarg($repo) . ' archive --format=tar ' . escapeshellarg($tag) . ' | tar -x -C ' . escapeshellarg($src)];
if ($pieza === 'crm') {
    $cmds[] = 'rm -rf ' . escapeshellarg("$src/writable");
    // Fase 6: base inicial para instalar hijas NUEVAS, dentro del paquete firmado.
    $cmds[] = 'mkdir -p ' . escapeshellarg("$src/deploy/instalar");
    $cmds[] = 'sudo -n php ' . escapeshellarg(__DIR__ . '/semilla.php') . ' ' . escapeshellarg((string) ($cfg['crm_db'] ?? 'haro_tecnova')) . ' '
        . escapeshellarg("$src/deploy/instalar") . ' ' . escapeshellarg("$src/deploy/migrations");
} elseif ($pieza === 'robot') {
    $cmds[] = 'node ' . escapeshellarg(__DIR__ . '/robot-inicial.js') . ' ' . escapeshellarg($src) . ' ' . escapeshellarg("$src/instalar/robot-inicial.json");
}
$cmds[] = 'tar --format=gnu -cf ' . escapeshellarg($tar) . ' -C ' . escapeshellarg($src) . ' . -C ' . escapeshellarg($repo) . ' ' . $deps;
$cmds[] = 'gzip -6 ' . escapeshellarg($tar);
$cmds[] = 'rm -rf ' . escapeshellarg($src);
foreach ($cmds as $c) {
    passthru($c, $rc);
    if ($rc !== 0) {
        exec('rm -rf ' . escapeshellarg($tmp));
        exit("Falló: $c\n");
    }
}
$final = "$dir/$pieza-$ver.tgz";
rename("$tar.gz", $final);
@rmdir($tmp);
firmar($final, $sk, true);

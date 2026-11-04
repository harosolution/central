<?php
/**
 * Arma el PAQUETE firmado de una versión publicada (fase 2 de la Central, 3-nov-2026; chat y robot: fase 6, 4-nov).
 *
 *   php herramientas/empaquetar.php crm 2.2.0
 *   php herramientas/empaquetar.php chat 1.1.2
 *   php herramientas/empaquetar.php robot 1.23.1
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
 * La llave privada se crea la primera vez y NUNCA sale de la madre; la pública va escrita en el agente.
 */
if (PHP_SAPI !== 'cli') {
    exit("Solo por consola.\n");
}
[$_, $pieza, $ver] = $argv + [null, null, null];
if (! in_array($pieza, ['crm', 'chat', 'robot'], true) || ! preg_match('/^\d+\.\d+\.\d+$/', (string) $ver)) {
    exit("Uso: php empaquetar.php crm|chat|robot X.Y.Z\n");
}
$base = dirname(__DIR__);
$cfg = require $base . '/config.php';
$repo = $cfg['repos'][$pieza] ?? ['crm' => '/var/www/tecnovaplus/crm', 'chat' => '/var/www/botpruebas', 'robot' => '/var/www/tecnobot'][$pieza];
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
chmod($final, 0640);

$sha = hash_file('sha256', $final);
$firma = sodium_bin2hex(sodium_crypto_sign_detached($sha, sodium_hex2bin(trim(file_get_contents($sk)))));
file_put_contents("$final.sha256", $sha . "\n");
file_put_contents("$final.firma", $firma . "\n");
file_put_contents("$final.instalable", date('c') . "\n");   // fase 6: sirve para instalar una hija nueva
@chgrp($final, 'www-data');
foreach (['.sha256', '.firma', '.instalable'] as $e) {
    chmod($final . $e, 0640);
    @chgrp($final . $e, 'www-data');
}
// La web (www-data) sirve el paquete: si el chgrp no se pudo (administrator no es del grupo), con sudo.
clearstatcache();
if (function_exists('posix_getgrgid') && (posix_getgrgid(filegroup($final))['name'] ?? '') !== 'www-data') {
    exec('sudo -n chgrp www-data ' . implode(' ', array_map('escapeshellarg', [$final, "$final.sha256", "$final.firma", "$final.instalable"])), $o, $rcg);
    if ($rcg !== 0) {
        echo "OJO: no pude darle el grupo www-data al paquete; la central no podrá servirlo.\n";
    }
}
printf("Paquete %s  (%.1f MB)\nsha256 %s\n", $final, filesize($final) / 1048576, $sha);

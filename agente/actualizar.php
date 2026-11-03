<?php
/**
 * AGENTE DE LA HIJA · actualizar el CRM (fase 2 de la Central de hijas, 3-nov-2026).
 *
 *   php ~/haro-agente/actualizar.php <hija>            → a la última versión publicada
 *   php ~/haro-agente/actualizar.php <hija> --seco     → solo baja y comprueba la firma, no toca nada
 *   php ~/haro-agente/actualizar.php <hija> --version 2.2.0
 *
 * Pasos: pedir a la central la última versión → bajar el paquete → comprobar sha256 + firma ed25519 (llave pública
 * escrita aquí: un paquete que no firmó la madre NO se instala) → respaldo de la base y del código → copiar encima
 * (nunca .env, writable/, ni lo que no viene en el paquete: fotos, landings, tienda.json…) → migraciones aditivas →
 * prueba de salud. Si algo falla después de copiar: se devuelve el código anterior (la base se queda: las migraciones
 * solo agregan).
 * Sin sudo: corre como el dueño de la carpeta del CRM.
 */
const AGENTE_VER = '1.1.0';
const LLAVE_PUBLICA = 'b8f6c1675fe4fbe201e4d9b8dab96e97403409184336884484356e4853ea710a';
// Archivos que trae el paquete pero son de la hija (si ya existen, no se pisan).
const CONSERVAR = [
    'chatbot/config.json', 'chatbot/config.backup.json', 'chatbot/config.backup_promo.json', 'chatbot/lid_map.json',
    'chatbot/no_archivar.json', 'chatbot/anuncio_cliente.json', 'chatbot/anuncios_map.json', 'chatbot/ia.json',
    'chatbot/ia2.json', 'chatbot/ia2_atendidos.json', 'public/robots.txt', 'public/favicon.ico',
];

$args = array_slice($argv, 1);
$hija = $args[0] ?? '';
$seco = in_array('--seco', $args, true);
$verPedida = ($i = array_search('--version', $args, true)) !== false ? ($args[$i + 1] ?? '') : '';
$base = __DIR__;

function say(string $m): void { echo '[' . date('H:i:s') . "] $m\n"; }
function fin(string $m, int $c = 1): void { say(($c ? 'ERROR: ' : '') . $m); exit($c); }
function sh(string $cmd, ?int &$rc = null): string { exec($cmd . ' 2>&1', $o, $rc); return implode("\n", $o); }

if (! preg_match('/^[a-z0-9_-]+$/', $hija) || ! is_file("$base/hijas/$hija.json")) {
    fin("Uso: php actualizar.php <hija> [--seco] [--version X.Y.Z]  (hijas: " . implode(', ', array_map(fn ($f) => basename($f, '.json'), glob("$base/hijas/*.json") ?: [])) . ')');
}
if (! function_exists('sodium_crypto_sign_verify_detached')) {
    fin('Este PHP no tiene sodium: no puedo comprobar la firma, no instalo nada.');
}
$c = json_decode((string) file_get_contents("$base/hijas/$hija.json"), true);
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
$central = rtrim((string) $c['central'], '/');
$auth = "Authorization: Bearer {$c['llave']}\r\n";

// Un solo actualizador a la vez
$lock = fopen("$base/actualizar-$hija.lock", 'c');
if (! flock($lock, LOCK_EX | LOCK_NB)) {
    fin('Ya hay una actualización corriendo para esta hija.');
}

// 1) ¿Qué versión?
$tiene = trim((string) (json_decode((string) @file_get_contents("$crm/deploy/version.json"), true)['stable'] ?? ''));
$ver = $verPedida;
if ($ver === '') {
    $e = json_decode((string) @file_get_contents("$central/api/estado", false, stream_context_create(['http' => ['header' => $auth, 'timeout' => 20, 'ignore_errors' => true]])), true);
    $ver = (string) ($e['piezas']['crm']['ultima'] ?? '');
}
if (! preg_match('/^\d+\.\d+\.\d+$/', $ver)) {
    fin('La central no dijo qué versión instalar.');
}
say("Hija $hija · CRM instalado $tiene · a instalar $ver");
if (! $verPedida && $tiene !== '' && version_compare($tiene, $ver, '>=')) {
    fin('Ya está al día.', 0);
}

// 2) Bajar y comprobar
@mkdir("$base/paquetes", 0700, true);
$paq = "$base/paquetes/crm-$ver.tgz";
$in = @fopen("$central/api/paquete?pieza=crm&ver=$ver", 'r', false, stream_context_create(['http' => ['header' => $auth, 'timeout' => 600, 'ignore_errors' => true]]));
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
sh('tar czf ' . escapeshellarg($codigo) . ' -C ' . escapeshellarg($crm) . ' --exclude=./writable --exclude=./public/uploads --exclude=./public/img --exclude="*.bak*" .', $rc);
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
// Paquetes viejos: quedan los 2 últimos
$viejos = glob("$base/paquetes/crm-*.tgz") ?: [];
usort($viejos, fn ($a, $b) => filemtime($b) <=> filemtime($a));
foreach (array_slice($viejos, 2) as $v) { @unlink($v); }
say("LISTO: CRM $tiene → $ver (salud OK en $url).");
passthru('php ' . escapeshellarg("$base/latido.php"));
exit(0);

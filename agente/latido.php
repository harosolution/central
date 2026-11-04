<?php
/**
 * AGENTE DE LA HIJA · latido (fase 1 de la Central de hijas, 3-nov-2026).
 *
 * Cada 10 minutos le dice a la central «estoy viva» y qué versión tiene de CRM, Chat y Robot.
 * NO manda nada del negocio (ni ventas, ni clientes, ni chats, ni llaves): solo versiones y salud del servidor.
 *
 * Instalación (sin sudo, como el usuario dueño de las carpetas):
 *   mkdir -p ~/haro-agente/hijas && cp latido.php ~/haro-agente/
 *   ~/haro-agente/hijas/<nombre>.json  →  {"central":"https://crm.tecnovaplus.online/central","llave":"hc_…",
 *                                          "crm":"/var/www/x-crm","chat":"/var/www/bandeja-x","robot":"/var/www/tecnobot-x"}
 *   crontab:  *\/10 * * * * php ~/haro-agente/latido.php >/dev/null 2>&1
 * Un servidor con varias hijas = un .json por hija; un solo cron.
 * Una hija puesta con el instalador de una línea (fase 6) lo tiene en /opt/haro-agente, con /etc/cron.d/haro-agente (root).
 * Prueba a mano:  php ~/haro-agente/latido.php -v
 */
const AGENTE_VER = '1.2.1';   // 1.2.1: sin sudo cuando ya corre como root (el instalador lo deja en /opt/haro-agente)

$verbose = in_array('-v', $argv ?? [], true);
$base = __DIR__;
$archivos = glob($base . '/hijas/*.json') ?: [];
if (! $archivos) {
    fwrite(STDERR, "No hay hijas configuradas en {$base}/hijas/*.json\n");
    exit(1);
}

function ver_json(string $f, string $clave): string
{
    if (! is_file($f)) {
        return '';
    }
    $j = json_decode((string) @file_get_contents($f), true);
    return is_array($j) && isset($j[$clave]) ? trim((string) $j[$clave]) : '';
}

function ver_crm(string $dir): string
{
    if ($dir === '' || ! is_dir($dir)) {
        return '';
    }
    // La versión instalada: el tag exacto si es un git (MIOX), si no la del deploy/version.json de esa copia.
    $head = @file_get_contents($dir . '/.git/HEAD');
    if ($head !== false && preg_match('/^[0-9a-f]{40}$/', trim($head))) {
        $sha = trim($head);
        foreach ((array) @scandir($dir . '/.git/refs/tags') as $t) {
            if (preg_match('/^v?\d+\.\d+\.\d+$/', (string) $t) && trim((string) @file_get_contents($dir . '/.git/refs/tags/' . $t)) === $sha) {
                return ltrim((string) $t, 'v');
            }
        }
    }
    return ver_json($dir . '/deploy/version.json', 'stable');
}

$errores = 0;
$pendientes = [];
foreach ($archivos as $f) {
    $nombre = basename($f, '.json');
    $c = json_decode((string) file_get_contents($f), true);
    if (! is_array($c) || empty($c['central']) || empty($c['llave'])) {
        fwrite(STDERR, "[$nombre] config incompleta (central, llave)\n");
        $errores++;
        continue;
    }
    $disco = @disk_free_space('/');
    $cuerpo = [
        'agente'   => AGENTE_VER,
        'crm'      => ver_crm((string) ($c['crm'] ?? '')),
        'chat'     => ver_json(rtrim((string) ($c['chat'] ?? ''), '/') . '/package.json', 'version'),
        'robot'    => ver_json(rtrim((string) ($c['robot'] ?? ''), '/') . '/package.json', 'version'),
        'servidor' => (string) gethostname(),
        'php'      => PHP_VERSION,
        'disco_libre_gb' => $disco !== false ? round($disco / 1073741824, 1) : null,
        'extra'    => ['carga' => function_exists('sys_getloadavg') ? (sys_getloadavg()[0] ?? null) : null],
    ];
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\nAuthorization: Bearer {$c['llave']}\r\nUser-Agent: haro-agente/" . AGENTE_VER . "\r\n",
        'content'       => json_encode($cuerpo),
        'timeout'       => 15,
        'ignore_errors' => true,
    ]]);
    $url = rtrim((string) $c['central'], '/') . '/api/latido';
    $resp = @file_get_contents($url, false, $ctx);
    $codigo = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $codigo = (int) $m[1];
        }
    }
    $r = json_decode((string) $resp, true);
    $estado = ['hora' => date('c'), 'codigo' => $codigo, 'enviado' => $cuerpo, 'respuesta' => is_array($r) ? $r : null];
    @file_put_contents($base . '/estado-' . $nombre . '.json', json_encode($estado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($codigo !== 200 || empty($r['ok'])) {
        $errores++;
        fwrite(STDERR, "[$nombre] fallo HTTP $codigo: " . substr((string) $resp, 0, 200) . "\n");
        continue;
    }
    if ($verbose) {
        echo "[$nombre] OK · {$r['color']} · {$r['mensaje']}  (CRM {$cuerpo['crm']} · Chat {$cuerpo['chat']} · Robot {$cuerpo['robot']})\n";
    }
    // Actualizar A PEDIDO (Joe desde la central o el cliente desde su CRM). Nunca sola.
    if (! empty($r['actualizar'])) {
        $pendientes[] = ['nombre' => $nombre, 'c' => $c, 'desde' => $cuerpo['crm']];
    }
}

foreach ($pendientes ?? [] as $p) {
    $nombre = $p['nombre'];
    $esRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
    $sudo = ! empty($p['c']['dueno']) && ! $esRoot ? 'sudo -n ' : '';
    $log = $base . '/actualizar-' . $nombre . '.log';
    exec($sudo . 'php ' . escapeshellarg($base . '/actualizar.php') . ' ' . escapeshellarg($nombre) . ' > ' . escapeshellarg($log) . ' 2>&1', $o, $rc);
    $lineas = array_values(array_filter(array_map('trim', (array) @file($log))));
    $clave = array_values(array_filter($lineas, fn ($l) => preg_match('/LISTO|ERROR|Revertido|al día|ENSAYO/u', $l)));
    $ultima = (string) ($clave ? end($clave) : end($lineas));
    $hasta = preg_match('/→ (\d+\.\d+\.\d+)/u', $ultima, $m) ? $m[1] : '';
    if ($verbose) {
        echo "[$nombre] actualización: $ultima\n";
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'timeout' => 15, 'ignore_errors' => true,
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$p['c']['llave']}\r\n",
        'content' => json_encode(['ok' => $rc === 0, 'desde' => $p['desde'], 'hasta' => $hasta, 'mensaje' => preg_replace('/^\[[^\]]*\]\s*/', '', $ultima)]),
    ]]);
    @file_get_contents(rtrim((string) $p['c']['central'], '/') . '/api/actualizacion', false, $ctx);
}
exit($errores ? 1 : 0);

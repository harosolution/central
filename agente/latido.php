<?php
/**
 * AGENTE DE LA HIJA · latido (fase 1 de la Central de hijas, 3-nov-2026).
 *
 * Cada 10 minutos le dice a la central «estoy viva» y qué versión tiene de CRM, Chat, Robot y del propio agente.
 * NO manda nada del negocio (ni ventas, ni clientes, ni chats, ni llaves): solo versiones y salud del servidor.
 *
 * Instalación (sin sudo, como el usuario dueño de las carpetas):
 *   mkdir -p ~/haro-agente/hijas && cp latido.php actualizar.php ~/haro-agente/
 *   ~/haro-agente/hijas/<nombre>.json  →  {"central":"https://crm.tecnovaplus.online/central","llave":"hc_…",
 *                                          "crm":"/var/www/x-crm","chat":"/var/www/bandeja-x","robot":"/var/www/tecnobot-x"}
 *   crontab:  *\/10 * * * * php ~/haro-agente/latido.php >/dev/null 2>&1
 * Un servidor con varias hijas = un .json por hija; un solo cron.
 * Una hija puesta con el instalador de una línea (fase 6) lo tiene en /opt/haro-agente, con /etc/cron.d/haro-agente (root).
 * Prueba a mano:  php ~/haro-agente/latido.php -v
 *
 * Fase 7 (5-nov-2026): la central puede pedir actualizar VARIAS piezas (agente, crm, robot, chat). Primero el agente se
 * actualiza a sí mismo (una vez por servidor) y después cada pieza con actualizar.php (ya el nuevo); cada resultado se
 * avisa a la central. Nunca se actualiza sola: solo lo que pidió Joe, el cliente o la casilla «actualizar sola».
 */
const AGENTE_VER = '1.3.0';   // 1.3.0: también Chat, Robot y el propio agente (fase 7)
// Lo que sabe hacer este agente: la central solo le pide eso (los agentes viejos solo saben el CRM).
const AGENTE_SABE = ['piezas', 'agente', 'chat', 'robot'];

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

/** La versión del agente que está en disco (después de actualizarse a sí mismo ya es la nueva, aunque este proceso sea el viejo). */
function agenteVer(): string
{
    global $base;
    return preg_match("/const AGENTE_VER = '([\d.]+)'/", (string) @file_get_contents($base . '/latido.php'), $m) ? $m[1] : AGENTE_VER;
}

/** La unidad de systemd del Chat o el Robot de una hija (la que guardó el instalador, o la que corre esa carpeta). */
function servicio_de(array $c, string $pieza): string
{
    $dir = rtrim((string) ($c[$pieza] ?? ''), '/');
    if ($dir === '') {
        return '';
    }
    $svc = trim((string) ($c[$pieza . '_servicio'] ?? ''));
    if ($svc === '') {
        foreach (glob('/etc/systemd/system/*.service') ?: [] as $f) {
            if (strpos(basename($f), '@') !== false) {
                continue;
            }
            $t = (string) @file_get_contents($f);
            if (preg_match('#^WorkingDirectory=' . preg_quote($dir, '#') . '/?\s*$#m', $t) || preg_match('#^ExecStart=\S+\s+' . preg_quote($dir, '#') . '/\S+\.js#m', $t)) {
                $svc = basename($f);
                break;
            }
        }
    }
    return ($svc !== '' && substr($svc, -8) !== '.service') ? $svc . '.service' : $svc;
}

/** Chat/Robot APAGADOS a propósito (servicio detenido): la central no los actualiza, porque reiniciarlos los prendería. */
function apagados(array $c): array
{
    $out = [];
    foreach (['chat', 'robot'] as $p) {
        $svc = servicio_de($c, $p);
        if ($svc === '' || ! preg_match('/^[A-Za-z0-9@_.-]+$/', $svc)) {
            continue;
        }
        $o = [];
        exec('systemctl is-active ' . escapeshellarg($svc) . ' 2>/dev/null', $o);
        if (trim((string) ($o[0] ?? '')) !== 'active') {
            $out[] = $p;
        }
    }
    return $out;
}

/** Un latido de una hija: [código HTTP, respuesta, lo enviado]. Deja estado-<nombre>.json con la última respuesta. */
function latir(string $nombre, array $c): array
{
    global $base;
    $disco = @disk_free_space('/');
    $cuerpo = [
        'agente'   => agenteVer(),
        'crm'      => ver_crm((string) ($c['crm'] ?? '')),
        'chat'     => ver_json(rtrim((string) ($c['chat'] ?? ''), '/') . '/package.json', 'version'),
        'robot'    => ver_json(rtrim((string) ($c['robot'] ?? ''), '/') . '/package.json', 'version'),
        'servidor' => (string) gethostname(),
        'php'      => PHP_VERSION,
        'disco_libre_gb' => $disco !== false ? round($disco / 1073741824, 1) : null,
        'extra'    => ['carga' => function_exists('sys_getloadavg') ? (sys_getloadavg()[0] ?? null) : null, 'sabe' => AGENTE_SABE,
                       'root' => function_exists('posix_geteuid') && posix_geteuid() === 0, 'apagados' => apagados($c)],
    ];
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\nAuthorization: Bearer {$c['llave']}\r\nUser-Agent: haro-agente/" . AGENTE_VER . "\r\n",
        'content'       => json_encode($cuerpo),
        'timeout'       => 15,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents(rtrim((string) $c['central'], '/') . '/api/latido', false, $ctx);
    $codigo = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $codigo = (int) $m[1];
        }
    }
    $r = json_decode((string) $resp, true);
    $estado = ['hora' => date('c'), 'codigo' => $codigo, 'enviado' => $cuerpo, 'respuesta' => is_array($r) ? $r : null];
    @file_put_contents($base . '/estado-' . $nombre . '.json', json_encode($estado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return [$codigo, is_array($r) ? $r : [], $cuerpo, (string) $resp];
}

/** Corre actualizar.php para una pieza y le avisa a la central cómo terminó. Devuelve true si salió bien. */
function actualizarPieza(string $nombre, array $c, string $pieza, string $desde, bool $verbose): bool
{
    global $base;
    $esRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
    // El CRM de una hija con «dueno» (archivos de otro usuario) necesita sudo; Chat y Robot lo piden solos para reiniciar.
    $sudo = $pieza === 'crm' && ! empty($c['dueno']) && ! $esRoot ? 'sudo -n ' : '';
    $log = $base . '/actualizar-' . $nombre . ($pieza === 'crm' ? '' : '-' . $pieza) . '.log';
    exec($sudo . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($base . '/actualizar.php') . ' ' . escapeshellarg($nombre)
        . ($pieza === 'crm' ? '' : ' --pieza ' . escapeshellarg($pieza)) . ' > ' . escapeshellarg($log) . ' 2>&1', $o, $rc);
    $lineas = array_values(array_filter(array_map('trim', (array) @file($log))));
    $clave = array_values(array_filter($lineas, fn ($l) => preg_match('/LISTO|ERROR|Revertido|al día|ENSAYO/u', $l)));
    $ultima = (string) ($clave ? end($clave) : end($lineas));
    $hasta = preg_match('/→ (\d+\.\d+\.\d+)/u', $ultima, $m) ? $m[1] : '';
    if ($verbose) {
        echo "[$nombre] $pieza: $ultima\n";
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'timeout' => 15, 'ignore_errors' => true,
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$c['llave']}\r\n",
        'content' => json_encode(['ok' => $rc === 0, 'pieza' => $pieza, 'desde' => $desde, 'hasta' => $hasta, 'mensaje' => preg_replace('/^\[[^\]]*\]\s*/', '', $ultima)]),
    ]]);
    @file_get_contents(rtrim((string) $c['central'], '/') . '/api/actualizacion', false, $ctx);
    return $rc === 0;
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
    [$codigo, $r, $cuerpo, $resp] = latir($nombre, $c);
    if ($codigo !== 200 || empty($r['ok'])) {
        $errores++;
        fwrite(STDERR, "[$nombre] fallo HTTP $codigo: " . substr($resp, 0, 200) . "\n");
        continue;
    }
    if ($verbose) {
        echo "[$nombre] OK · {$r['color']} · {$r['mensaje']}  (Agente " . AGENTE_VER . " · CRM {$cuerpo['crm']} · Chat {$cuerpo['chat']} · Robot {$cuerpo['robot']})\n";
    }
    // Actualizar A PEDIDO (Joe desde la central, el cliente desde su CRM o la casilla «actualizar sola»).
    // Central nueva: «piezas_actualizar» = [agente, crm, robot, chat]. Central vieja: «actualizar» = solo el CRM.
    $piezas = isset($r['piezas_actualizar']) && is_array($r['piezas_actualizar']) ? array_values(array_intersect(['agente', 'crm', 'robot', 'chat'], $r['piezas_actualizar']))
        : (! empty($r['actualizar']) ? ['crm'] : []);
    if ($piezas) {
        $pendientes[] = ['nombre' => $nombre, 'c' => $c, 'piezas' => $piezas, 'cuerpo' => $cuerpo];
    }
}

// El agente primero y UNA vez por servidor (sus archivos son los mismos para todas las hijas de aquí); después cada pieza
// con el actualizar.php que quedó (ya el nuevo).
$agenteHecho = null;
foreach ($pendientes as $p) {
    foreach ($p['piezas'] as $pieza) {
        if ($pieza === 'agente') {
            if ($agenteHecho === null) {
                $agenteHecho = actualizarPieza($p['nombre'], $p['c'], 'agente', AGENTE_VER, $verbose);
            } else {
                // Ya se hizo con otra hija de este servidor: solo se avisa el resultado.
                $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 15, 'ignore_errors' => true,
                    'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$p['c']['llave']}\r\n",
                    'content' => json_encode(['ok' => $agenteHecho, 'pieza' => 'agente', 'desde' => AGENTE_VER, 'hasta' => '', 'mensaje' => 'Hecho junto con otra hija de este servidor.'])]]);
                @file_get_contents(rtrim((string) $p['c']['central'], '/') . '/api/actualizacion', false, $ctx);
            }
            continue;
        }
        actualizarPieza($p['nombre'], $p['c'], $pieza, (string) ($p['cuerpo'][$pieza] ?? ''), $verbose);
    }
    // Latido otra vez: la central ve enseguida las versiones nuevas.
    latir($p['nombre'], $p['c']);
}
exit($errores ? 1 : 0);

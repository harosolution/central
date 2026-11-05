<?php

namespace Central\Libraries;

/**
 * FASE 6 · Instalador de una línea (4-nov-2026).
 *
 * Joe crea en la central el instalador de una hija (empresa, administrador; el dominio es el de la hija) y en el
 * servidor nuevo se pega:   curl -fsSL https://crm.tecnovaplus.online/central/instalar/hi_… | sudo bash
 *
 * - El código vale 48 h y sirve hasta que una instalación termine bien: si falla, se puede volver a correr.
 * - Cada canje le da a la hija una LLAVE NUEVA (la anterior deja de servir). Por eso no se ofrece a una hija viva.
 * - La central guarda solo el sha256 del código. El script no lleva secretos; la llave viaja al canjear.
 * - El paquete que se instala es el último que tenga base inicial (`crm-X.tgz.instalable`, ver empaquetar.php).
 */
class Instalador
{
    public const VIGENCIA_H = 48;
    /** Con un latido más reciente que esto, la hija está viva y NO se le genera instalador (le cambiaría la llave). */
    public const VIVA_SEG = 86400;

    /** Nombre corto de carpetas, base y sitio: «PRUEBA Instalador» → prueba-instalador (máx. 24, cabe en un usuario MySQL). */
    public static function slug(string $nombre): string
    {
        $s = mb_strtolower(trim($nombre));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $s = preg_replace('/\(.*?\)/u', '', $s);
        $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
        return trim(substr($s, 0, 24), '-');
    }

    public static function urlCentral(): string
    {
        $u = trim((string) (Central::cfg()['central_url'] ?? ''));
        return rtrim($u !== '' ? $u : base_url('central'), '/');
    }

    public static function comando(string $codigo): string
    {
        return 'curl -fsSL ' . self::urlCentral() . '/instalar/' . $codigo . ' | sudo bash';
    }

    private static function dirPaquetes(): string
    {
        return dirname(__DIR__, 2) . '/datos/paquetes';
    }

    /** Última versión de una pieza (crm | chat | robot) con paquete completo e instalable (o null). */
    public static function versionInstalable(string $pieza = 'crm'): ?string
    {
        if (! in_array($pieza, ['crm', 'chat', 'robot'], true)) {
            return null;
        }
        $mejor = null;
        foreach (glob(self::dirPaquetes() . '/' . $pieza . '-*.tgz.instalable') ?: [] as $f) {
            if (! preg_match('/' . $pieza . '-(\d+\.\d+\.\d+)\.tgz\.instalable$/', $f, $m)) {
                continue;
            }
            $tgz = substr($f, 0, -strlen('.instalable'));
            if (is_file($tgz) && is_file("$tgz.sha256") && is_file("$tgz.firma") && ($mejor === null || version_compare($m[1], $mejor, '>'))) {
                $mejor = $m[1];
            }
        }
        return $mejor;
    }

    private static function llavePublica(): string
    {
        return trim((string) @file_get_contents(dirname(__DIR__, 2) . '/datos/firma.pk'));
    }

    /**
     * Agregar Chat y Robot a una hija YA instalada (con su agente vivo y sin chat). El código solo sirve en el
     * servidor de esa hija: al canjearlo hay que mandar la llave que ya tiene su agente. NO le cambia la llave.
     * [ok, mensaje, código]
     */
    public static function crearCodigoChat(int $hijaId, array $d, string $por = 'joe'): array
    {
        $db = Central::db();
        $h = $db->table('hijas')->where('id', $hijaId)->get()->getRowArray();
        if (! $h || (int) $h['activa'] !== 1) {
            return [false, 'No existe esa hija o está de baja.', null];
        }
        $lat = $db->table('latidos')->where('hija_id', $hijaId)->get()->getRowArray();
        if (! $lat || time() - strtotime((string) $lat['visto_en']) >= self::VIVA_SEG) {
            return [false, 'Esa hija no está enviando señal: el Chat y el Robot se agregan a una hija instalada y viva.', null];
        }
        if (trim((string) $lat['chat_ver']) !== '' || trim((string) $lat['robot_ver']) !== '') {
            return [false, 'Esa hija ya tiene Chat o Robot.', null];
        }
        $dom = strtolower(trim((string) $h['dominio']));
        $domChat = mb_strtolower(trim((string) preg_replace('#^https?://#', '', rtrim((string) ($d['dominio_chat'] ?? ''), '/'))));
        if ($domChat === '') {
            $domChat = self::dominioChat($dom);
        }
        if (! preg_match('/^(?=.{4,120}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $domChat) || $domChat === $dom) {
            return [false, 'El dominio del chat no es válido (ej. chat.empresa.com) o es igual al del CRM.', null];
        }
        $nombreRobot = trim((string) preg_replace('/[^\p{L}\p{N} .\'-]/u', '', (string) ($d['nombre_robot'] ?? '')));
        $nombreRobot = mb_substr($nombreRobot !== '' ? $nombreRobot : 'Asistente', 0, 40);
        $vChat = self::versionInstalable('chat');
        $vRobot = self::versionInstalable('robot');
        if ($vChat === null || $vRobot === null) {
            return [false, 'Faltan los paquetes del Chat o del Robot: ármalos con herramientas/empaquetar.php chat|robot X.Y.Z.', null];
        }
        $db->table('instalaciones')->where('hija_id', $hijaId)->whereIn('estado', ['pendiente', 'canjeado', 'fallo'])->update(['estado' => 'anulado']);
        $codigo = 'hi_' . bin2hex(random_bytes(16));
        $ahora = time();
        $db->table('instalaciones')->insert([
            'hija_id' => $hijaId, 'tipo' => 'chat', 'codigo_hash' => hash('sha256', $codigo), 'empresa' => (string) $h['nombre'],
            'estado' => 'pendiente', 'version' => (string) self::limpiaVer($lat['crm_ver']), 'por' => mb_substr($por, 0, 30),
            'creada_en' => date('Y-m-d H:i:s', $ahora), 'vence_en' => date('Y-m-d H:i:s', $ahora + self::VIGENCIA_H * 3600),
            'con_chat' => 1, 'dominio_chat' => $domChat, 'nombre_robot' => $nombreRobot,
            'con_conector' => ! empty($d['con_conector']) ? 1 : 0,   // fase 7b
        ]);
        return [true, "Comando para agregar Chat $vChat y Robot $vRobot" . (! empty($d['con_conector']) ? ' (con conector de Claude)' : '') . " a «{$h['nombre']}» en $domChat. Vale " . self::VIGENCIA_H . ' horas.', $codigo];
    }

    private static function limpiaVer($v): string
    {
        return Central::limpia((string) $v) ?? '';
    }

    /** ¿Se le puede ofrecer «Agregar Chat y Robot»? (activa, viva y sin chat ni robot) */
    public static function ofrecerChat(array $hija): bool
    {
        $l = $hija['latido'] ?? null;
        return (int) $hija['activa'] === 1 && $l && time() - strtotime((string) $l['visto_en']) < self::VIVA_SEG
            && trim((string) $l['chat_ver']) === '' && trim((string) $l['robot_ver']) === '';
    }

    /** Crea el código de instalación de una hija. [ok, mensaje, código] */
    public static function crearCodigo(int $hijaId, array $d, string $por = 'joe'): array
    {
        $db = Central::db();
        $h = $db->table('hijas')->where('id', $hijaId)->get()->getRowArray();
        if (! $h || (int) $h['activa'] !== 1) {
            return [false, 'No existe esa hija o está de baja.', null];
        }
        if (strlen(self::slug($h['nombre'])) < 2) {
            return [false, 'El nombre de la hija necesita letras o números: con él se nombran su carpeta y su base.', null];
        }
        $dom = strtolower(trim((string) $h['dominio']));
        if (! preg_match('/^(?=.{4,120}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $dom)) {
            return [false, 'Primero ponle a la hija su dominio (ej. crm.empresa.com) en «Editar».', null];
        }
        $lat = $db->table('latidos')->where('hija_id', $hijaId)->get()->getRowArray();
        if ($lat && time() - strtotime((string) $lat['visto_en']) < self::VIVA_SEG) {
            return [false, 'Esa hija ya está instalada y enviando señal: el instalador es solo para una hija nueva.', null];
        }
        $empresa = mb_substr(trim((string) ($d['empresa'] ?? '')), 0, 120);
        $nombre = mb_substr(trim((string) ($d['admin_nombre'] ?? '')), 0, 120);
        $email = mb_strtolower(trim((string) ($d['admin_email'] ?? '')));
        if ($empresa === '' || $nombre === '') {
            return [false, 'Faltan el nombre de la empresa y el del administrador.', null];
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) {
            return [false, 'El correo del administrador no es válido.', null];
        }
        $ver = self::versionInstalable();
        if ($ver === null) {
            return [false, 'Todavía no hay un paquete del CRM con base inicial: ármalo con herramientas/empaquetar.php.', null];
        }
        // Chat y Robot (opcional, marcado por defecto en el formulario): van en chat.<dominio>, el robot en /tecnobot/.
        $conChat = ! empty($d['con_chat']);
        $domChat = '';
        $nombreRobot = '';
        if ($conChat) {
            $domChat = mb_strtolower(trim((string) ($d['dominio_chat'] ?? '')));
            $domChat = preg_replace('#^https?://#', '', rtrim($domChat, '/'));
            if ($domChat === '') {
                $domChat = self::dominioChat($dom);
            }
            if (! preg_match('/^(?=.{4,120}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $domChat) || $domChat === $dom) {
                return [false, 'El dominio del chat no es válido (ej. chat.empresa.com) o es igual al del CRM.', null];
            }
            $nombreRobot = trim((string) preg_replace('/[^\p{L}\p{N} .\'-]/u', '', (string) ($d['nombre_robot'] ?? '')));
            $nombreRobot = mb_substr($nombreRobot !== '' ? $nombreRobot : 'Asistente', 0, 40);
            if (self::versionInstalable('chat') === null || self::versionInstalable('robot') === null) {
                return [false, 'Faltan los paquetes del Chat o del Robot: ármalos con herramientas/empaquetar.php chat|robot X.Y.Z.', null];
            }
        }
        // Un solo instalador vigente por hija.
        $db->table('instalaciones')->where('hija_id', $hijaId)->whereIn('estado', ['pendiente', 'canjeado', 'fallo'])->update(['estado' => 'anulado']);
        $codigo = 'hi_' . bin2hex(random_bytes(16));
        $ahora = time();
        $db->table('instalaciones')->insert([
            'hija_id' => $hijaId, 'codigo_hash' => hash('sha256', $codigo), 'empresa' => $empresa, 'admin_nombre' => $nombre,
            'admin_email' => $email, 'estado' => 'pendiente', 'version' => $ver, 'por' => mb_substr($por, 0, 30),
            'creada_en' => date('Y-m-d H:i:s', $ahora), 'vence_en' => date('Y-m-d H:i:s', $ahora + self::VIGENCIA_H * 3600),
            'con_chat' => $conChat ? 1 : 0, 'dominio_chat' => $domChat, 'nombre_robot' => $nombreRobot,
            'con_conector' => $conChat && ! empty($d['con_conector']) ? 1 : 0,   // fase 7b: el conector de Claude (va con el Robot)
            'con_shalom' => ! empty($d['con_shalom']) ? 1 : 0,                    // fase 7c: la API de SHALOM del CRM (agencias y guías)
        ]);
        $que = $conChat ? "CRM $ver + Chat " . self::versionInstalable('chat') . ' y Robot ' . self::versionInstalable('robot')
            . (! empty($d['con_conector']) ? ' con conector de Claude' : '') . " en $domChat" : "CRM $ver";
        $que .= ! empty($d['con_shalom']) ? ', con API de SHALOM' : '';
        return [true, "Instalador de «{$h['nombre']}» listo ($que). Vale " . self::VIGENCIA_H . ' horas.', $codigo];
    }

    /** crm.empresa.com → chat.empresa.com; empresa.com → chat.empresa.com. */
    public static function dominioChat(string $dominioCrm): string
    {
        $d = strtolower(trim($dominioCrm));
        return 'chat.' . (str_starts_with($d, 'crm.') ? substr($d, 4) : $d);
    }

    /** La instalación y la hija de un código vigente (o null). */
    private static function vigente(string $codigo): ?array
    {
        if (! preg_match('/^hi_[0-9a-f]{32}$/', $codigo)) {
            return null;
        }
        $db = Central::db();
        $i = $db->table('instalaciones')->where('codigo_hash', hash('sha256', $codigo))
            ->whereIn('estado', ['pendiente', 'canjeado', 'fallo'])->where('vence_en >', date('Y-m-d H:i:s'))->get()->getRowArray();
        if (! $i) {
            return null;
        }
        $h = $db->table('hijas')->where('id', (int) $i['hija_id'])->where('activa', 1)->get()->getRowArray();
        return $h ? ['inst' => $i, 'hija' => $h] : null;
    }

    /** El texto del instalador para un código (o uno que solo explica por qué no sirve). */
    public static function script(string $codigo): string
    {
        $v = self::vigente($codigo);
        $ver = self::versionInstalable();
        $pk = self::llavePublica();
        $tpl = (string) @file_get_contents(dirname(__DIR__, 2) . '/agente/instalar.sh');
        $latido = (string) @file_get_contents(dirname(__DIR__, 2) . '/agente/latido.php');
        $actualizar = (string) @file_get_contents(dirname(__DIR__, 2) . '/agente/actualizar.php');
        $conector = (string) @file_get_contents(dirname(__DIR__, 2) . '/agente/poner-conector.sh');   // fase 7b
        $shalom = (string) @file_get_contents(dirname(__DIR__, 2) . '/agente/poner-shalom.sh');       // fase 7c
        if (! $v) {
            return self::scriptError('Este código de instalación no existe, ya se usó o venció (duran ' . self::VIGENCIA_H . ' h). Pide uno nuevo en la central.');
        }
        if ($ver === null || ! preg_match('/^[0-9a-f]{64}$/', $pk) || $tpl === '' || $latido === '' || $actualizar === '' || $conector === '' || $shalom === '') {
            return self::scriptError('La central no tiene listo el paquete del CRM. Avísale a soporte.');
        }
        $conChat = (int) ($v['inst']['con_chat'] ?? 0) === 1;
        $agregar = ($v['inst']['tipo'] ?? 'nueva') === 'chat';
        $vChat = $conChat ? self::versionInstalable('chat') : null;
        $vRobot = $conChat ? self::versionInstalable('robot') : null;
        if ($conChat && ($vChat === null || $vRobot === null)) {
            return self::scriptError('La central no tiene listos los paquetes del Chat y el Robot. Avísale a soporte.');
        }
        return strtr($tpl, [
            '__MODO__'            => $agregar ? 'agregar' : 'nueva',
            '__CON_CHAT__'        => $conChat ? '1' : '0',
            '__CON_CONECTOR__'    => $conChat && (int) ($v['inst']['con_conector'] ?? 0) === 1 ? '1' : '0',
            '__CONECTOR_B64__'    => base64_encode($conector),
            '__CON_SHALOM__'      => ! $agregar && (int) ($v['inst']['con_shalom'] ?? 0) === 1 ? '1' : '0',
            '__SHALOM_B64__'      => base64_encode($shalom),
            '__DOMINIO_CHAT__'    => $conChat ? (string) $v['inst']['dominio_chat'] : '',
            '__VERSION_CHAT__'    => (string) $vChat,
            '__VERSION_ROBOT__'   => (string) $vRobot,
            '__CENTRAL__'         => self::urlCentral(),
            '__CODIGO__'          => $codigo,
            '__HIJA__'            => self::slug($v['hija']['nombre']),
            '__DOMINIO__'         => strtolower((string) $v['hija']['dominio']),
            '__VERSION__'         => $ver,
            '__LLAVE_PUBLICA__'   => $pk,
            '__LATIDO_B64__'      => base64_encode($latido),
            '__ACTUALIZAR_B64__'  => base64_encode($actualizar),
        ]);
    }

    private static function scriptError(string $msg): string
    {
        return "#!/usr/bin/env bash\necho " . escapeshellarg('ERROR: ' . $msg) . " >&2\nexit 1\n";
    }

    /**
     * El instalador canjea el código: la hija recibe una llave NUEVA. [ok, mensaje, datos]
     * Se puede canjear de nuevo mientras ninguna instalación termine bien (cada vez, otra llave).
     */
    public static function canjear(string $codigo, string $servidor, string $ip, string $authorization = ''): array
    {
        $v = self::vigente($codigo);
        if (! $v) {
            return [false, 'Este código de instalación no existe, ya se usó o venció. Pide uno nuevo en la central.', null];
        }
        if (($v['inst']['tipo'] ?? 'nueva') === 'chat') {
            return self::canjearChat($v, $servidor, $ip, $authorization);
        }
        $ver = self::versionInstalable();
        if ($ver === null) {
            return [false, 'La central no tiene listo el paquete del CRM.', null];
        }
        $db = Central::db();
        $h = $v['hija'];
        $i = $v['inst'];
        $llave = Central::nuevaLlave();
        $servidor = mb_substr((string) preg_replace('/[^A-Za-z0-9._-]/', '', $servidor), 0, 80);
        $cambios = ['llave_hash' => hash('sha256', $llave)];
        if (trim((string) $h['servidor']) === '') {
            $cambios['servidor'] = trim($servidor . ' ' . $ip);
        }
        $db->table('hijas')->where('id', (int) $h['id'])->update($cambios);
        $db->table('instalaciones')->where('id', (int) $i['id'])->set('intentos', 'intentos+1', false)->update([
            'estado' => 'canjeado', 'canjeado_en' => date('Y-m-d H:i:s'), 'servidor' => $servidor, 'ip' => mb_substr($ip, 0, 45), 'version' => $ver,
        ]);
        return [true, 'ok', [
            'hija' => self::slug($h['nombre']), 'nombre' => $h['nombre'], 'llave' => $llave, 'dominio' => strtolower((string) $h['dominio']),
            'empresa' => $i['empresa'], 'admin_nombre' => $i['admin_nombre'], 'admin_email' => $i['admin_email'],
            'version' => $ver, 'ip' => $ip, 'central' => self::urlCentral(),
            'con_chat' => (int) ($i['con_chat'] ?? 0) === 1, 'dominio_chat' => (string) ($i['dominio_chat'] ?? ''),
            'nombre_robot' => (string) ($i['nombre_robot'] ?? ''),
            'version_chat' => (int) ($i['con_chat'] ?? 0) === 1 ? (string) self::versionInstalable('chat') : '',
            'version_robot' => (int) ($i['con_chat'] ?? 0) === 1 ? (string) self::versionInstalable('robot') : '',
        ]];
    }

    /** Agregar Chat y Robot: solo con la llave que ya tiene el agente de ESA hija. No se rota la llave. */
    private static function canjearChat(array $v, string $servidor, string $ip, string $authorization): array
    {
        $h = $v['hija'];
        $i = $v['inst'];
        $deLlave = Central::hijaPorLlave($authorization);
        if (! $deLlave || (int) $deLlave['id'] !== (int) $h['id']) {
            return [false, 'Este comando es para el servidor de «' . $h['nombre'] . '»: córrelo ahí (no encontré su agente o su llave no coincide).', null];
        }
        $vChat = self::versionInstalable('chat');
        $vRobot = self::versionInstalable('robot');
        if ($vChat === null || $vRobot === null) {
            return [false, 'La central no tiene listos los paquetes del Chat y el Robot.', null];
        }
        $servidor = mb_substr((string) preg_replace('/[^A-Za-z0-9._-]/', '', $servidor), 0, 80);
        Central::db()->table('instalaciones')->where('id', (int) $i['id'])->set('intentos', 'intentos+1', false)->update([
            'estado' => 'canjeado', 'canjeado_en' => date('Y-m-d H:i:s'), 'servidor' => $servidor, 'ip' => mb_substr($ip, 0, 45),
        ]);
        return [true, 'ok', [
            'hija' => self::slug($h['nombre']), 'nombre' => $h['nombre'], 'tipo' => 'chat', 'dominio' => strtolower((string) $h['dominio']),
            'con_chat' => true, 'dominio_chat' => (string) $i['dominio_chat'], 'nombre_robot' => (string) $i['nombre_robot'],
            'version_chat' => $vChat, 'version_robot' => $vRobot, 'ip' => $ip, 'central' => self::urlCentral(),
        ]];
    }

    /**
     * Fase 6d: la cuenta de Recargas IA (streamy) del robot de una hija que se está instalando: así compra IA desde su
     * panel sin pasos aparte. Solo con una instalación CON ROBOT en curso (canjeada) de esa hija. Se crea con las
     * funciones del panel de streamy, se enlaza a la hija y la clave va UNA vez al instalador. [ok, mensaje, token]
     */
    public static function tiendaIa(array $hija): array
    {
        $db = Central::db();
        $i = $db->table('instalaciones')->where('hija_id', (int) $hija['id'])->where('estado', 'canjeado')->where('con_chat', 1)
            ->orderBy('id', 'DESC')->get()->getRowArray();
        if (! $i) {
            return [false, 'No hay una instalación con Robot en curso para esta hija.', null];
        }
        // Reintento: la cuenta que creó un intento anterior de ESTA instalación se desactiva (su clave se fue con el robot borrado).
        if (! empty($i['ia_cuenta_id'])) {
            self::cuentaIa('desactivar', (string) (int) $i['ia_cuenta_id']);
        }
        $nombre = strtoupper(mb_substr((string) preg_replace('/[^A-Za-z0-9 _-]/', '', strtr((string) $hija['nombre'], ['(' => '', ')' => ''])), 0, 60));
        $r = self::cuentaIa('crear', trim($nombre));
        if (empty($r['ok']) || empty($r['token'])) {
            return [false, (string) ($r['error'] ?? 'No pude crear la cuenta de Recargas IA.'), null];
        }
        $db->table('instalaciones')->where('id', (int) $i['id'])->update(['ia_cuenta_id' => (int) $r['id']]);
        $db->table('hijas')->where('id', (int) $hija['id'])->update(['streamy_cuenta_id' => (int) $r['id']]);
        return [true, 'Cuenta «' . $r['nombre'] . '» creada en Recargas IA.', (string) $r['token']];
    }

    /** Llama a herramientas/cuenta-ia.php (las funciones del panel de streamy) por consola. */
    private static function cuentaIa(string $accion, string $arg): array
    {
        $dir = (string) (Central::cfg()['streamy_dir'] ?? '/var/www/streamy-v2');
        $php = is_executable('/usr/bin/php') ? '/usr/bin/php' : 'php';
        $cmd = 'timeout 40 ' . $php . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/herramientas/cuenta-ia.php') . ' '
            . escapeshellarg($dir) . ' ' . escapeshellarg($accion) . ' ' . escapeshellarg($arg) . ' 2>/dev/null';
        $j = json_decode(trim((string) shell_exec($cmd)), true);
        return is_array($j) ? $j : ['ok' => false, 'error' => 'La tienda de IA (streamy) no respondió.'];
    }

    /** El instalador avisa cómo terminó (con la llave recién canjeada). */
    public static function reportar(int $hijaId, bool $ok, string $mensaje, string $version): void
    {
        $db = Central::db();
        $i = $db->table('instalaciones')->where('hija_id', $hijaId)->where('estado', 'canjeado')->orderBy('id', 'DESC')->get()->getRowArray();
        if (! $i) {
            return;
        }
        $db->table('instalaciones')->where('id', (int) $i['id'])->update([
            'estado' => $ok ? 'instalada' : 'fallo', 'mensaje' => mb_substr($mensaje, 0, 500), 'terminada_en' => date('Y-m-d H:i:s'),
            'version' => preg_match('/^\d+\.\d+\.\d+$/', $version) ? $version : $i['version'],
        ]);
        // Falló: el robot se borró y su clave con él → la cuenta de Recargas IA que creó este intento se desactiva.
        if (! $ok && ! empty($i['ia_cuenta_id'])) {
            self::cuentaIa('desactivar', (string) (int) $i['ia_cuenta_id']);
            $db->table('hijas')->where('id', $hijaId)->where('streamy_cuenta_id', (int) $i['ia_cuenta_id'])->update(['streamy_cuenta_id' => null]);
        }
    }

    /** Última instalación de una hija, para la lista (o null). */
    public static function ultima(int $hijaId): ?array
    {
        return Central::db()->table('instalaciones')->where('hija_id', $hijaId)->orderBy('id', 'DESC')->limit(1)->get()->getRowArray() ?: null;
    }

    /** ¿Se le puede ofrecer el instalador? (activa y sin señal en las últimas 24 h) */
    public static function ofrecer(array $hija): bool
    {
        $l = $hija['latido'] ?? null;
        return (int) $hija['activa'] === 1 && (! $l || time() - strtotime((string) $l['visto_en']) >= self::VIVA_SEG);
    }
}

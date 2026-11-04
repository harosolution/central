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

    /** Última versión del CRM con paquete completo y base inicial (o null). */
    public static function versionInstalable(): ?string
    {
        $mejor = null;
        foreach (glob(self::dirPaquetes() . '/crm-*.tgz.instalable') ?: [] as $f) {
            if (! preg_match('/crm-(\d+\.\d+\.\d+)\.tgz\.instalable$/', $f, $m)) {
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
        // Un solo instalador vigente por hija.
        $db->table('instalaciones')->where('hija_id', $hijaId)->whereIn('estado', ['pendiente', 'canjeado', 'fallo'])->update(['estado' => 'anulado']);
        $codigo = 'hi_' . bin2hex(random_bytes(16));
        $ahora = time();
        $db->table('instalaciones')->insert([
            'hija_id' => $hijaId, 'codigo_hash' => hash('sha256', $codigo), 'empresa' => $empresa, 'admin_nombre' => $nombre,
            'admin_email' => $email, 'estado' => 'pendiente', 'version' => $ver, 'por' => mb_substr($por, 0, 30),
            'creada_en' => date('Y-m-d H:i:s', $ahora), 'vence_en' => date('Y-m-d H:i:s', $ahora + self::VIGENCIA_H * 3600),
        ]);
        return [true, "Instalador de «{$h['nombre']}» listo (CRM $ver). Vale " . self::VIGENCIA_H . ' horas.', $codigo];
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
        if (! $v) {
            return self::scriptError('Este código de instalación no existe, ya se usó o venció (duran ' . self::VIGENCIA_H . ' h). Pide uno nuevo en la central.');
        }
        if ($ver === null || ! preg_match('/^[0-9a-f]{64}$/', $pk) || $tpl === '' || $latido === '' || $actualizar === '') {
            return self::scriptError('La central no tiene listo el paquete del CRM. Avísale a soporte.');
        }
        return strtr($tpl, [
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
    public static function canjear(string $codigo, string $servidor, string $ip): array
    {
        $v = self::vigente($codigo);
        if (! $v) {
            return [false, 'Este código de instalación no existe, ya se usó o venció. Pide uno nuevo en la central.', null];
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
        ]];
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

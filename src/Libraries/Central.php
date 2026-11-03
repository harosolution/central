<?php

namespace Central\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * Lo común de la central: su base propia, las versiones publicadas y el color de cada hija.
 * La configuración (usuario y clave de la base, rutas de la madre) va en config.php, FUERA de git.
 */
class Central
{
    /** Sin latido en este tiempo, la hija sale en rojo («no responde»). El agente late cada 10 min. */
    public const SIN_SENAL_SEG = 3600;

    private static ?BaseConnection $db = null;
    private static ?array $cfg = null;
    private static ?array $ultimas = null;

    public static function cfg(): array
    {
        if (self::$cfg === null) {
            $f = dirname(__DIR__, 2) . '/config.php';
            self::$cfg = is_file($f) ? (array) require $f : [];
        }
        return self::$cfg;
    }

    public static function db(): BaseConnection
    {
        if (self::$db === null) {
            $c = self::cfg();
            self::$db = \Config\Database::connect([
                'DSN'      => '',
                'hostname' => $c['db_host'] ?? 'localhost',
                'username' => $c['db_user'] ?? 'central',
                'password' => $c['db_pass'] ?? '',
                'database' => $c['db_name'] ?? 'central',
                'DBDriver' => 'MySQLi',
                'DBPrefix' => '',
                'pConnect' => false,
                'DBDebug'  => true,
                'charset'  => 'utf8mb4',
                'DBCollat' => 'utf8mb4_unicode_ci',
                'port'     => (int) ($c['db_port'] ?? 3306),
            ], false);
        }
        return self::$db;
    }

    /**
     * Última versión PUBLICADA de cada pieza = el tag vX.Y.Z más alto del repo de la madre
     * (lo crea `publicar`). Se lee el .git a mano: sin ejecutar git y sin depender de permisos de www-data.
     */
    public static function ultimas(): array
    {
        if (self::$ultimas !== null) {
            return self::$ultimas;
        }
        $rutas = self::cfg()['repos'] ?? [
            'crm'   => '/var/www/tecnovaplus/crm',
            'chat'  => '/var/www/botpruebas',
            'robot' => '/var/www/tecnobot',
        ];
        $out = [];
        foreach ($rutas as $pieza => $dir) {
            $out[$pieza] = self::tagMayor((string) $dir);
        }
        return self::$ultimas = $out;
    }

    private static function tagMayor(string $dir): ?string
    {
        $tags = [];
        $refs = $dir . '/.git/refs/tags';
        if (is_dir($refs)) {
            foreach ((array) @scandir($refs) as $t) {
                $tags[] = (string) $t;
            }
        }
        $packed = $dir . '/.git/packed-refs';
        if (is_file($packed)) {
            foreach ((array) @file($packed, FILE_IGNORE_NEW_LINES) as $l) {
                if (preg_match('#refs/tags/(\S+)$#', (string) $l, $m)) {
                    $tags[] = $m[1];
                }
            }
        }
        $mejor = null;
        foreach ($tags as $t) {
            if (! preg_match('/^v?(\d+\.\d+\.\d+)$/', $t, $m)) {
                continue;
            }
            if ($mejor === null || version_compare($m[1], $mejor, '>')) {
                $mejor = $m[1];
            }
        }
        return $mejor;
    }

    public static function limpia(?string $v): ?string
    {
        $v = trim((string) $v);
        return preg_match('/(\d+\.\d+\.\d+)/', $v, $m) ? $m[1] : null;
    }

    /**
     * Estado de una hija a partir de su último latido.
     * Devuelve color (verde | ambar | rojo | gris), mensaje y, por pieza, lo que tiene y lo publicado.
     */
    public static function estado(?array $latido): array
    {
        $ult = self::ultimas();
        $piezas = [];
        $atras = [];
        foreach (['crm', 'chat', 'robot'] as $p) {
            $tiene = $latido ? self::limpia($latido[$p . '_ver'] ?? null) : null;
            $ultima = $ult[$p] ?? null;
            $al = null;
            if ($tiene !== null && $ultima !== null) {
                $al = version_compare($tiene, $ultima, '>=');
                if (! $al) {
                    $atras[] = strtoupper($p) . ' ' . $tiene . ' → ' . $ultima;
                }
            }
            $piezas[$p] = ['tiene' => $tiene, 'ultima' => $ultima, 'al_dia' => $al];
        }

        if (! $latido) {
            return ['color' => 'gris', 'mensaje' => 'Todavía no envía señal (falta instalar el agente).', 'piezas' => $piezas];
        }
        $hace = time() - strtotime((string) $latido['visto_en']);
        if ($hace > self::SIN_SENAL_SEG) {
            return ['color' => 'rojo', 'mensaje' => 'Sin señal hace ' . self::hace($hace) . '.', 'piezas' => $piezas];
        }
        if ($atras) {
            return ['color' => 'ambar', 'mensaje' => 'Hay actualización: ' . implode(' · ', $atras) . '.', 'piezas' => $piezas];
        }
        return ['color' => 'verde', 'mensaje' => 'Al día.', 'piezas' => $piezas];
    }

    public static function hace(int $seg): string
    {
        if ($seg < 90) {
            return $seg . ' s';
        }
        if ($seg < 5400) {
            return round($seg / 60) . ' min';
        }
        if ($seg < 172800) {
            return round($seg / 3600) . ' h';
        }
        return round($seg / 86400) . ' días';
    }

    /** La hija que corresponde a la llave del encabezado Authorization: Bearer … (o null). */
    public static function hijaPorLlave(string $authorization): ?array
    {
        if (! preg_match('/^Bearer\s+([A-Za-z0-9_-]{20,128})$/', trim($authorization), $m)) {
            return null;
        }
        $row = self::db()->table('hijas')->where('llave_hash', hash('sha256', $m[1]))->get()->getRowArray();
        return $row ?: null;
    }

    /**
     * Pide actualizar el CRM de una hija (Joe desde la central o el cliente desde su CRM). La instala su agente en el
     * próximo latido (≤ 10 min). Nunca se actualiza sola. [ok, mensaje]
     */
    public static function pedirActualizacion(int $hijaId, string $por): array
    {
        $db = self::db();
        $h = $db->table('hijas')->where('id', $hijaId)->get()->getRowArray();
        $lat = $db->table('latidos')->where('hija_id', $hijaId)->get()->getRowArray();
        if (! $h || ! $lat) {
            return [false, 'Esa instalación todavía no tiene agente: no se puede actualizar a distancia.'];
        }
        $tiene = self::limpia($lat['crm_ver']);
        $ultima = self::ultimas()['crm'] ?? null;
        if (! $tiene || ! $ultima || version_compare($tiene, $ultima, '>=')) {
            return [false, 'Ya tiene la última versión (' . ($tiene ?: '?') . ').'];
        }
        if ($h['actualizar_pedido']) {
            return [true, 'La actualización ya estaba pedida: se instala en los próximos minutos.'];
        }
        $ahora = date('Y-m-d H:i:s');
        $db->table('hijas')->where('id', $hijaId)->update(['actualizar_pedido' => $ahora, 'actualizar_por' => $por]);
        $db->table('actualizaciones')->insert(['hija_id' => $hijaId, 'desde' => $tiene, 'hasta' => $ultima, 'por' => $por, 'estado' => 'pedida', 'pedida_en' => $ahora]);
        return [true, "Actualización a la $ultima pedida: se instala en los próximos 10 minutos, con respaldo y vuelta atrás si algo falla."];
    }

    /** Última actualización registrada de una hija (o null). */
    public static function ultimaActualizacion(int $hijaId): ?array
    {
        return self::db()->table('actualizaciones')->where('hija_id', $hijaId)->orderBy('id', 'DESC')->limit(1)->get()->getRowArray() ?: null;
    }

    public static function nuevaLlave(): string
    {
        return 'hc_' . bin2hex(random_bytes(24));
    }
}

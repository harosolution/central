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
    /** Fase 7: lo que se actualiza a distancia, en el orden en que lo hace el agente (él primero). */
    public const PIEZAS = ['agente', 'crm', 'robot', 'chat'];
    public const NOMBRES = ['agente' => 'Agente', 'crm' => 'CRM', 'chat' => 'Chat', 'robot' => 'Robot'];
    /** «Actualizar sola»: de 4:00 a 4:59 (Lima) y como mucho tantas hijas que empiezan en 10 minutos. */
    public const AUTO_HORA = 4;
    public const AUTO_TANDA = 10;

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
        // El agente no tiene repo propio: su última versión es el paquete firmado más nuevo (empaquetar.php agente X.Y.Z).
        $out['agente'] = null;
        foreach (glob(self::dirPaquetes() . '/agente-*.tgz') ?: [] as $f) {
            if (preg_match('/agente-(\d+\.\d+\.\d+)\.tgz$/', $f, $m) && is_file("$f.firma") && ($out['agente'] === null || version_compare($m[1], $out['agente'], '>'))) {
                $out['agente'] = $m[1];
            }
        }
        return self::$ultimas = $out;
    }

    public static function dirPaquetes(): string
    {
        return dirname(__DIR__, 2) . '/datos/paquetes';
    }

    /** ¿Ya está armado y firmado el paquete de esa versión? (si no, el agente no tendría qué bajar) */
    public static function paqueteListo(string $pieza, ?string $ver): bool
    {
        $f = self::dirPaquetes() . "/$pieza-$ver.tgz";
        return $ver !== null && is_file($f) && is_file("$f.sha256") && is_file("$f.firma");
    }

    /** Lo que sabe hacer el agente de ese latido (los de antes de la 1.3.0 solo saben actualizar el CRM). */
    public static function sabe(?array $latido): array
    {
        $d = json_decode((string) ($latido['datos'] ?? ''), true);
        return is_array($d['sabe'] ?? null) ? array_map('strval', $d['sabe']) : [];
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
        foreach (['crm', 'chat', 'robot', 'agente'] as $p) {
            $tiene = $latido ? self::limpia($latido[$p . '_ver'] ?? null) : null;
            $ultima = $ult[$p] ?? null;
            $al = null;
            if ($tiene !== null && $ultima !== null) {
                $al = version_compare($tiene, $ultima, '>=');
                // El agente es de la central, no del cliente: no pone a la hija en «atrasada» (su CRM no lo ve).
                if (! $al && $p !== 'agente') {
                    $atras[] = self::NOMBRES[$p] . ' ' . $tiene . ' → ' . $ultima;
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
     * Fase 7: qué piezas de una hija se pueden actualizar a distancia AHORA, en orden (el agente primero).
     * Solo las atrasadas, con su paquete firmado listo, y Chat/Robot/Agente solo si su agente ya sabe (1.3.0+).
     * Devuelve [piezas, estado, notas para Joe].
     */
    public static function paraActualizar(?array $latido): array
    {
        $est = self::estado($latido);
        $sabe = self::sabe($latido);
        $piezas = [];
        $notas = [];
        $faltaAgente = false;
        foreach (self::PIEZAS as $p) {
            $x = $est['piezas'][$p] ?? null;
            if (! $x || $x['al_dia'] !== false) {
                continue;
            }
            if (! self::paqueteListo($p, $x['ultima'])) {
                $notas[] = self::NOMBRES[$p] . ' ' . $x['ultima'] . ' todavía no tiene paquete firmado (empaquetar.php).';
                continue;
            }
            if ($p !== 'crm' && ! in_array($p, $sabe, true)) {
                $faltaAgente = true;
                continue;
            }
            $piezas[] = $p;
        }
        if ($faltaAgente) {
            $notas[] = 'Chat y Robot: a ese servidor le falta una vez el agente nuevo (1.3.0); hasta entonces solo se actualiza el CRM.';
        }
        return [$piezas, $est, $notas];
    }

    /**
     * Pide actualizar una hija: TODAS sus piezas atrasadas (o solo las de $solo). La instala su agente en el próximo latido
     * (≤ 10 min), con respaldo y vuelta atrás. Lo piden Joe (central), el cliente (su CRM) o «actualizar sola». [ok, mensaje]
     */
    public static function pedirActualizacion(int $hijaId, string $por, ?array $solo = null): array
    {
        $db = self::db();
        $h = $db->table('hijas')->where('id', $hijaId)->get()->getRowArray();
        $lat = $db->table('latidos')->where('hija_id', $hijaId)->get()->getRowArray();
        if (! $h || ! $lat) {
            return [false, 'Esa instalación todavía no tiene agente: no se puede actualizar a distancia.'];
        }
        if ($h['actualizar_pedido']) {
            return [true, 'La actualización ya estaba pedida: se instala en los próximos minutos.'];
        }
        [$piezas, $est, $notas] = self::paraActualizar($lat);
        if ($solo !== null) {
            $piezas = array_values(array_intersect($piezas, $solo));
        }
        if (! $piezas) {
            return [false, trim('Ya tiene la última versión de lo que se puede actualizar. ' . implode(' ', $notas))];
        }
        $ahora = date('Y-m-d H:i:s');
        $db->table('hijas')->where('id', $hijaId)->update(['actualizar_pedido' => $ahora, 'actualizar_por' => $por, 'actualizar_piezas' => implode(',', $piezas)]);
        $lista = [];
        foreach ($piezas as $p) {
            $x = $est['piezas'][$p];
            $db->table('actualizaciones')->insert(['hija_id' => $hijaId, 'pieza' => $p, 'desde' => (string) $x['tiene'], 'hasta' => (string) $x['ultima'],
                'por' => $por, 'estado' => 'pedida', 'pedida_en' => $ahora]);
            $lista[] = self::NOMBRES[$p] . ' ' . $x['tiene'] . ' → ' . $x['ultima'];
        }
        return [true, trim('Actualización pedida (' . implode(', ', $lista) . '): se instala en los próximos 10 minutos, con respaldo y vuelta atrás si algo falla. ' . implode(' ', $notas))];
    }

    /** «Actualizar todas»: lo mismo para cada hija activa con agente. [pedidas, líneas para mostrar] */
    public static function actualizarTodas(string $por): array
    {
        $n = 0;
        $lineas = [];
        foreach (self::db()->table('hijas')->where('activa', 1)->orderBy('nombre')->get()->getResultArray() as $h) {
            [$ok, $msg] = self::pedirActualizacion((int) $h['id'], $por);
            if ($ok) {
                $n++;
                $lineas[] = $h['nombre'] . ': ' . $msg;
            } elseif (strpos($msg, 'Ya tiene') !== 0 && strpos($msg, 'todavía no tiene agente') === false) {
                $lineas[] = $h['nombre'] . ': ' . $msg;
            }
        }
        return [$n, $lineas];
    }

    /** ¿Le toca a una hija con «actualizar sola»? De 4 a 5 am (Lima) y de a pocas (AUTO_TANDA cada 10 minutos). */
    public static function tocaAuto(): bool
    {
        $hora = (int) (new \DateTime('now', new \DateTimeZone('America/Lima')))->format('G');
        if ($hora !== self::AUTO_HORA) {
            return false;
        }
        $recientes = self::db()->table('actualizaciones')->select('hija_id')->distinct()->where('por', 'auto')
            ->where('pedida_en >=', date('Y-m-d H:i:s', time() - 600))->get()->getResultArray();
        return count($recientes) < self::AUTO_TANDA;
    }

    /** La última tanda de actualizaciones de una hija (una fila por pieza, del mismo pedido). */
    public static function ultimasActualizaciones(int $hijaId): array
    {
        $db = self::db();
        $u = $db->table('actualizaciones')->where('hija_id', $hijaId)->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        if (! $u) {
            return [];
        }
        $filas = $db->table('actualizaciones')->where('hija_id', $hijaId)->where('pedida_en', $u['pedida_en'])->orderBy('id')->get()->getResultArray();
        return $filas ?: [$u];
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

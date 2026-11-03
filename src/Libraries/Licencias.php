<?php

namespace Central\Libraries;

/**
 * Licencias de las hijas (fase 3, 3-nov-2026).
 *
 * Pago: la hija yapea al número de los Ajustes y escribe los 3 dígitos del código de seguridad. Se busca ese Yape en la
 * bandeja Yape del CRM de TECNOVA (solo lectura, `api/v1/bot/pagos`, igual que las Recargas IA de streamy): mismo monto,
 * mismo código, sin usar. Si calza, el pago queda «pagado» y el vencimiento avanza los meses comprados.
 * Fase 3 SOLO avisa. Cortar automatizaciones es la fase 4.
 */
class Licencias
{
    public const FUNCIONES = [
        'robot_ia'        => 'Robot de IA (responde solo)',
        'chat_automatico' => 'Chat: respuestas y envíos automáticos',
        'courier_shalom'  => 'API de SHALOM (guías, seguimiento)',
        'courier_otros'   => 'APIs de otros couriers (Aliclik, Dinsides…)',
        'facturacion'     => 'Facturación electrónica',
    ];
    public const LIMITES = ['usuarios' => 'Usuarios', 'whatsapp' => 'Números de WhatsApp', 'pedidos_mes' => 'Pedidos al mes'];
    public const METODOS = ['YAPE', 'PLIN', 'EFECTIVO', 'TRANSFERENCIA', 'CORTESIA'];
    public const VENCE_PEDIDO_MIN = 60;

    private static ?array $ajustes = null;

    // ── Ajustes ──────────────────────────────────────────────────────────────
    public static function ajustes(): array
    {
        if (self::$ajustes === null) {
            self::$ajustes = ['aviso_dias' => '7', 'gracia_dias' => '3', 'yape_numero' => '', 'yape_titular' => '', 'plin_numero' => '', 'nota_cliente' => ''];
            foreach (Central::db()->table('ajustes')->get()->getResultArray() as $r) {
                self::$ajustes[$r['k']] = (string) $r['v'];
            }
        }
        return self::$ajustes;
    }

    public static function guardarAjustes(array $d): void
    {
        $max = ['yape_numero' => 20, 'yape_titular' => 80, 'plin_numero' => 20, 'nota_cliente' => 300, 'aviso_dias' => 3, 'gracia_dias' => 3];
        foreach ($max as $k => $n) {
            if (! array_key_exists($k, $d)) {
                continue;
            }
            $v = mb_substr(trim((string) $d[$k]), 0, $n);
            if (in_array($k, ['yape_numero', 'plin_numero'], true)) {
                $v = preg_replace('/[^\d ]/', '', $v);
            }
            if (in_array($k, ['aviso_dias', 'gracia_dias'], true)) {
                $v = (string) max(0, min(60, (int) $v));
            }
            Central::db()->query('REPLACE INTO ajustes (k, v) VALUES (?, ?)', [$k, $v]);
        }
        self::$ajustes = null;
    }

    // ── Planes ───────────────────────────────────────────────────────────────
    public static function planes(bool $soloActivos = false): array
    {
        $q = Central::db()->table('planes')->orderBy('orden')->orderBy('precio');
        if ($soloActivos) {
            $q->where('activo', 1);
        }
        $out = [];
        foreach ($q->get()->getResultArray() as $p) {
            $out[(int) $p['id']] = self::planDecodificado($p);
        }
        return $out;
    }

    public static function plan(?int $id): ?array
    {
        if (! $id) {
            return null;
        }
        $p = Central::db()->table('planes')->where('id', $id)->get()->getRowArray();
        return $p ? self::planDecodificado($p) : null;
    }

    private static function planDecodificado(array $p): array
    {
        $p['funciones'] = array_values(array_intersect(array_keys(self::FUNCIONES), (array) (json_decode((string) $p['funciones'], true) ?: [])));
        $l = (array) (json_decode((string) $p['limites'], true) ?: []);
        $p['limites'] = [];
        foreach (self::LIMITES as $k => $_) {
            $p['limites'][$k] = max(0, (int) ($l[$k] ?? 0));
        }
        return $p;
    }

    public static function guardarPlan(array $d, ?int $id = null): array
    {
        $nombre = mb_substr(trim((string) ($d['nombre'] ?? '')), 0, 60);
        if ($nombre === '') {
            return [false, 'Falta el nombre del plan.'];
        }
        $lim = [];
        foreach (self::LIMITES as $k => $_) {
            $lim[$k] = max(0, (int) ($d['lim_' . $k] ?? 0));
        }
        $fila = [
            'nombre'      => $nombre,
            'precio'      => max(0, round((float) ($d['precio'] ?? 0), 2)),
            'meses'       => max(1, min(24, (int) ($d['meses'] ?? 1))),
            'funciones'   => json_encode(array_values(array_intersect(array_keys(self::FUNCIONES), (array) ($d['funciones'] ?? [])))),
            'limites'     => json_encode($lim),
            'descripcion' => mb_substr(trim((string) ($d['descripcion'] ?? '')), 0, 300),
            'activo'      => ! empty($d['activo']) ? 1 : 0,
            'orden'       => (int) ($d['orden'] ?? 0),
        ];
        $t = Central::db()->table('planes');
        $id ? $t->where('id', $id)->update($fila) : $t->insert($fila);
        return [true, 'Plan «' . $nombre . '» guardado.'];
    }

    // ── Estado de la licencia de una hija ────────────────────────────────────
    public static function estado(array $hija): array
    {
        $a = self::ajustes();
        $plan = self::plan((int) ($hija['plan_id'] ?? 0));
        $base = ['plan' => $plan ? ['id' => (int) $plan['id'], 'nombre' => $plan['nombre'], 'precio' => (float) $plan['precio'], 'meses' => (int) $plan['meses'],
                                    'funciones' => $plan['funciones'], 'limites' => $plan['limites']] : null,
                 'vence_en' => $hija['vence_en'] ?? null, 'dias' => null];
        if (! $plan || empty($hija['vence_en'])) {
            return $base + ['estado' => 'sin_licencia', 'color' => 'gris', 'mensaje' => 'Sin licencia asignada.'];
        }
        $hoy = new \DateTimeImmutable('today');
        $vence = new \DateTimeImmutable((string) $hija['vence_en']);
        $dias = (int) $hoy->diff($vence)->format('%r%a');   // >0 faltan · <0 ya pasó
        $base['dias'] = $dias;
        $f = $vence->format('d/m/Y');
        $aviso = (int) $a['aviso_dias'];
        $gracia = (int) $a['gracia_dias'];
        if ($dias > $aviso) {
            return $base + ['estado' => 'activa', 'color' => 'verde', 'mensaje' => "Licencia activa hasta el $f."];
        }
        if ($dias >= 0) {
            return $base + ['estado' => 'por_vencer', 'color' => 'ambar', 'mensaje' => $dias === 0 ? "La licencia vence HOY ($f)." : "La licencia vence en $dias día" . ($dias === 1 ? '' : 's') . " ($f)."];
        }
        if (-$dias <= $gracia) {
            $quedan = $gracia + $dias;
            return $base + ['estado' => 'gracia', 'color' => 'rojo', 'mensaje' => "La licencia venció el $f. Quedan $quedan día" . ($quedan === 1 ? '' : 's') . ' de gracia para pagar.'];
        }
        return $base + ['estado' => 'vencida', 'color' => 'rojo', 'mensaje' => "La licencia venció el $f."];
    }

    /** Lo que ve la hija para pagar (sin datos internos). */
    public static function paraHija(array $hija): array
    {
        $e = self::estado($hija);
        $a = self::ajustes();
        $e['pagar'] = [
            'yape_numero'  => $a['yape_numero'], 'yape_titular' => $a['yape_titular'], 'plin_numero' => $a['plin_numero'],
            'nota'         => $a['nota_cliente'], 'vence_min' => self::VENCE_PEDIDO_MIN,
            'disponible'   => $e['plan'] !== null && (float) $e['plan']['precio'] > 0 && ($a['yape_numero'] !== '' || $a['plin_numero'] !== '') && self::crmConfigurado(),
        ];
        $e['pagos'] = array_map([self::class, 'pagoParaHija'], Central::db()->table('pagos')->where('hija_id', (int) $hija['id'])
            ->orderBy('id', 'DESC')->limit(10)->get()->getResultArray());
        return $e;
    }

    public static function pagoParaHija(array $p): array
    {
        $txt = ['pendiente' => 'Buscando tu Yape…', 'pagado' => 'Pagado ✅', 'vencido' => 'No encontramos el Yape', 'anulado' => 'Anulado'];
        return ['id' => (int) $p['id'], 'estado' => $p['estado'], 'texto' => $txt[$p['estado']] ?? $p['estado'], 'monto' => (float) $p['monto'],
                'meses' => (int) $p['meses'], 'metodo' => $p['metodo'], 'creado' => $p['creado_en'], 'pagado' => $p['pagado_en'],
                'vence_despues' => $p['vence_despues'], 'detalle' => $p['estado'] === 'pendiente' ? (string) $p['detalle'] : ''];
    }

    // ── Pagos ────────────────────────────────────────────────────────────────
    public static function crmConfigurado(): bool
    {
        $c = Central::cfg();
        return trim((string) ($c['crm_url'] ?? '')) !== '' && trim((string) ($c['crm_key'] ?? '')) !== '';
    }

    /** La hija avisa que yapeó. [ok, mensaje, pago|null] */
    public static function crearPagoHija(array $hija, int $periodos, string $codigo): array
    {
        $plan = self::plan((int) ($hija['plan_id'] ?? 0));
        if (! $plan || (float) $plan['precio'] <= 0) {
            return [false, 'Tu instalación no tiene un plan con precio: escríbele al administrador.', null];
        }
        $codigo = preg_replace('/\D/', '', $codigo);
        if (strlen($codigo) !== 3) {
            return [false, 'El código de seguridad del Yape son 3 números.', null];
        }
        $periodos = max(1, min(12, $periodos));
        $db = Central::db();
        if ($db->table('pagos')->where('hija_id', (int) $hija['id'])->where('estado', 'pendiente')->countAllResults() >= 3) {
            return [false, 'Ya tienes 3 pagos esperando su Yape: espera a que se validen.', null];
        }
        $db->table('pagos')->insert([
            'hija_id' => (int) $hija['id'], 'plan_id' => (int) $plan['id'], 'plan_nombre' => $plan['nombre'], 'periodos' => $periodos,
            'meses' => (int) $plan['meses'] * $periodos, 'monto' => round((float) $plan['precio'] * $periodos, 2),
            'metodo' => 'YAPE', 'codigo_seguridad' => $codigo, 'origen' => 'hija', 'creado_en' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        self::validar($id);
        return [true, 'Pago registrado: estamos buscando tu Yape.', self::pagoParaHija($db->table('pagos')->where('id', $id)->get()->getRowArray())];
    }

    /** Joe registra un pago a mano (efectivo, transferencia, cortesía…). Queda pagado y extiende la licencia. */
    public static function registrarManual(int $hijaId, int $periodos, float $monto, string $metodo, string $nota): array
    {
        $hija = Central::db()->table('hijas')->where('id', $hijaId)->get()->getRowArray();
        $plan = $hija ? self::plan((int) $hija['plan_id']) : null;
        if (! $hija || ! $plan) {
            return [false, 'Esa hija no tiene plan asignado: asígnale uno primero.'];
        }
        $metodo = in_array($metodo, self::METODOS, true) ? $metodo : 'EFECTIVO';
        $periodos = max(1, min(24, $periodos));
        $db = Central::db();
        $db->table('pagos')->insert([
            'hija_id' => $hijaId, 'plan_id' => (int) $plan['id'], 'plan_nombre' => $plan['nombre'], 'periodos' => $periodos,
            'meses' => (int) $plan['meses'] * $periodos, 'monto' => max(0, round($monto, 2)), 'metodo' => $metodo, 'estado' => 'pendiente',
            'nota' => mb_substr($nota, 0, 255), 'origen' => 'central', 'creado_en' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        self::marcarPagado($id, null);
        return [true, 'Pago registrado y licencia extendida.'];
    }

    /** Busca el Yape de un pago pendiente. Devuelve el estado final. */
    public static function validar(int $id, bool $forzar = false): string
    {
        $db = Central::db();
        $p = $db->table('pagos')->where('id', $id)->get()->getRowArray();
        if (! $p || $p['estado'] !== 'pendiente') {
            return $p['estado'] ?? 'no_existe';
        }
        if (! $forzar && time() - strtotime($p['creado_en']) > self::VENCE_PEDIDO_MIN * 60) {
            $db->table('pagos')->where('id', $id)->where('estado', 'pendiente')->update(['estado' => 'vencido', 'detalle' => 'No apareció el Yape en ' . self::VENCE_PEDIDO_MIN . ' min.']);
            return 'vencido';
        }
        if (! $forzar && $p['ultimo_intento'] && time() - strtotime($p['ultimo_intento']) < 15) {
            return 'pendiente';
        }
        $db->table('pagos')->where('id', $id)->update(['ultimo_intento' => date('Y-m-d H:i:s'), 'intentos' => (int) $p['intentos'] + 1]);
        try {
            $lista = self::yapesDelCrm((float) $p['monto'], date('Y-m-d H:i:s', strtotime($p['creado_en']) - 24 * 3600));
        } catch (\Throwable $e) {
            $db->table('pagos')->where('id', $id)->update(['detalle' => 'CRM: ' . mb_substr($e->getMessage(), 0, 200)]);
            return 'pendiente';
        }
        // Un Yape sirve UNA vez: ni otro pago de licencia ni una recarga IA de streamy pueden haberlo usado.
        $usados = array_map('intval', array_column($db->query('SELECT yape_id FROM pagos WHERE yape_id IS NOT NULL')->getResultArray(), 'yape_id'));
        $usados = array_merge($usados, self::yapesUsadosEnStreamy());
        $calza = null;
        $hayMonto = false;
        foreach ($lista as $y) {
            if (abs((float) $y['monto'] - (float) $p['monto']) >= 0.01) {
                continue;
            }
            $hayMonto = true;
            if ((string) $y['codigo'] === (string) $p['codigo_seguridad'] && ! in_array((int) $y['id'], $usados, true)) {
                $calza = $y;
                break;
            }
        }
        if (! $calza) {
            $db->table('pagos')->where('id', $id)->update(['detalle' => $hayMonto
                ? 'Hay Yapes de S/ ' . number_format((float) $p['monto'], 2) . ' pero ninguno libre con el código ' . $p['codigo_seguridad'] . '.'
                : 'Todavía no llega un Yape de S/ ' . number_format((float) $p['monto'], 2) . '.']);
            return 'pendiente';
        }
        return self::marcarPagado($id, $calza) ? 'pagado' : 'pendiente';
    }

    /** Marca pagado (una sola vez) y extiende el vencimiento desde lo que sea mayor: hoy o el vencimiento actual. */
    private static function marcarPagado(int $id, ?array $yape): bool
    {
        $db = Central::db();
        $db->transStart();
        $p = $db->query('SELECT * FROM pagos WHERE id = ? FOR UPDATE', [$id])->getRowArray();
        $h = $p ? $db->query('SELECT * FROM hijas WHERE id = ? FOR UPDATE', [(int) $p['hija_id']])->getRowArray() : null;
        if (! $p || $p['estado'] !== 'pendiente' || ! $h) {
            $db->transComplete();
            return false;
        }
        $hoy = new \DateTimeImmutable('today');
        $antes = $h['vence_en'] ? new \DateTimeImmutable($h['vence_en']) : null;
        $desde = ($antes && $antes > $hoy) ? $antes : $hoy;
        $despues = $desde->modify('+' . (int) $p['meses'] . ' months');
        $db->table('pagos')->where('id', $id)->update([
            'estado' => 'pagado', 'pagado_en' => date('Y-m-d H:i:s'), 'detalle' => null,
            'yape_id' => $yape ? (int) $yape['id'] : null, 'yape_nombre' => $yape ? mb_substr((string) $yape['nombre'], 0, 120) : null,
            'yape_fecha' => $yape && $yape['fecha'] ? $yape['fecha'] : null,
            'vence_antes' => $antes ? $antes->format('Y-m-d') : null, 'vence_despues' => $despues->format('Y-m-d'),
        ]);
        $db->table('hijas')->where('id', (int) $h['id'])->update(['vence_en' => $despues->format('Y-m-d')]);
        $db->transComplete();
        return $db->transStatus() !== false;
    }

    public static function anular(int $id): array
    {
        $n = Central::db()->table('pagos')->where('id', $id)->whereIn('estado', ['pendiente', 'vencido'])->update(['estado' => 'anulado']);
        return [Central::db()->affectedRows() > 0, Central::db()->affectedRows() > 0 ? 'Pago anulado.' : 'Solo se anulan pagos pendientes o vencidos (uno pagado se corrige cambiando el vencimiento de la hija).'];
    }

    /** Yapes NUEVOS de ese monto desde una fecha, de la bandeja Yape del CRM de TECNOVA (solo lectura). */
    private static function yapesDelCrm(float $monto, string $desde): array
    {
        $c = Central::cfg();
        $base = rtrim((string) ($c['crm_url'] ?? ''), '/');
        $key = (string) ($c['crm_key'] ?? '');
        if ($base === '' || $key === '') {
            throw new \RuntimeException('falta crm_url / crm_key en config.php');
        }
        $url = $base . '/api/v1/bot/pagos?estado=NUEVO&limite=50&monto=' . rawurlencode(number_format($monto, 2, '.', '')) . '&desde=' . rawurlencode($desde);
        $ctx = stream_context_create(['http' => ['header' => "X-Bot-Key: $key\r\nAccept: application/json\r\n", 'timeout' => 20, 'ignore_errors' => true]]);
        $j = json_decode((string) @file_get_contents($url, false, $ctx), true);
        if (! is_array($j) || ($j['status'] ?? '') === 'error' || ! isset($j['data'])) {
            throw new \RuntimeException('el CRM no respondió bien');
        }
        $out = [];
        foreach (($j['data']['pagos'] ?? []) as $x) {
            $raw = (string) ($x['raw_message'] ?? '');
            if (preg_match('/tu pago en|pagaste|yapeaste|enviaste/i', $raw)) {
                continue;   // pagos que HIZO tecnova, no que recibió
            }
            $out[] = ['id' => (int) $x['id'], 'monto' => (float) $x['amount'], 'nombre' => (string) ($x['payer_name'] ?? ''),
                      'fecha' => (string) ($x['occurred_at'] ?? ''),
                      'codigo' => preg_match('/c[oó]d(?:igo)?\.?\s*de\s*seguridad\s*(?:es)?\s*:?\s*(\d{3})\b/iu', $raw, $m) ? $m[1] : null];
        }
        return $out;
    }

    // ── Streamy (Recargas IA), solo lectura ─────────────────────────────────
    private static function streamy(): ?\mysqli
    {
        static $c = false;
        if ($c !== false) {
            return $c;
        }
        $cfg = Central::cfg()['streamy_db'] ?? null;
        if (! is_array($cfg)) {
            return $c = null;
        }
        try {
            mysqli_report(MYSQLI_REPORT_OFF);
            $m = @new \mysqli($cfg['host'] ?? 'localhost', $cfg['user'] ?? '', $cfg['pass'] ?? '', $cfg['db'] ?? '');
            $c = $m->connect_errno ? null : $m;
            $c?->set_charset('utf8mb4');
        } catch (\Throwable $e) {
            $c = null;
        }
        return $c;
    }

    private static function yapesUsadosEnStreamy(): array
    {
        $s = self::streamy();
        if (! $s) {
            return [];
        }
        $r = $s->query('SELECT yape_id FROM v2_ia_pedidos WHERE yape_id IS NOT NULL');
        return $r ? array_map('intval', array_column($r->fetch_all(MYSQLI_ASSOC), 'yape_id')) : [];
    }

    public static function cuentasStreamy(): array
    {
        $s = self::streamy();
        $r = $s ? $s->query('SELECT id, nombre FROM v2_ia_cuentas ORDER BY nombre') : false;
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }

    /** Recargas IA aplicadas: por cuenta y por mes. */
    private static function iasStreamy(): array
    {
        $s = self::streamy();
        $out = ['por_cuenta' => [], 'por_mes' => []];
        if (! $s) {
            return $out;
        }
        $r = $s->query("SELECT cuenta, COUNT(*) n, SUM(monto) total, MAX(COALESCE(aplicado, pagado, creado)) ultima FROM v2_ia_pedidos WHERE estado = 'aplicado' GROUP BY cuenta");
        foreach ($r ? $r->fetch_all(MYSQLI_ASSOC) : [] as $x) {
            $out['por_cuenta'][(int) $x['cuenta']] = $x;
        }
        $r = $s->query("SELECT DATE_FORMAT(COALESCE(pagado, creado), '%Y-%m') mes, COUNT(*) n, SUM(monto) total FROM v2_ia_pedidos WHERE estado = 'aplicado' GROUP BY mes");
        foreach ($r ? $r->fetch_all(MYSQLI_ASSOC) : [] as $x) {
            $out['por_mes'][$x['mes']] = $x;
        }
        return $out;
    }

    // ── Ingresos ─────────────────────────────────────────────────────────────
    public static function ingresos(): array
    {
        $db = Central::db();
        $porMes = [];
        foreach ($db->query("SELECT DATE_FORMAT(pagado_en, '%Y-%m') mes, COUNT(*) n, SUM(monto) total FROM pagos WHERE estado = 'pagado' GROUP BY mes")->getResultArray() as $r) {
            $porMes[$r['mes']] = ['licencias' => (float) $r['total'], 'pagos' => (int) $r['n'], 'ias' => 0.0, 'ias_n' => 0];
        }
        $ias = self::iasStreamy();
        foreach ($ias['por_mes'] as $mes => $x) {
            $porMes[$mes] ??= ['licencias' => 0.0, 'pagos' => 0, 'ias' => 0.0, 'ias_n' => 0];
            $porMes[$mes]['ias'] = (float) $x['total'];
            $porMes[$mes]['ias_n'] = (int) $x['n'];
        }
        krsort($porMes);
        $porAnio = [];
        foreach ($porMes as $mes => $x) {
            $a = substr($mes, 0, 4);
            $porAnio[$a] ??= ['licencias' => 0.0, 'pagos' => 0, 'ias' => 0.0, 'ias_n' => 0];
            foreach ($x as $k => $v) {
                $porAnio[$a][$k] += $v;
            }
        }
        $porHija = [];
        foreach ($db->table('hijas')->orderBy('nombre')->get()->getResultArray() as $h) {
            $t = $db->query("SELECT COUNT(*) n, COALESCE(SUM(monto),0) total, COALESCE(SUM(meses),0) meses, MIN(pagado_en) primero, MAX(pagado_en) ultimo
                               FROM pagos WHERE estado = 'pagado' AND hija_id = ?", [(int) $h['id']])->getRowArray();
            $ia = $h['streamy_cuenta_id'] ? ($ias['por_cuenta'][(int) $h['streamy_cuenta_id']] ?? null) : null;
            $porHija[] = ['hija' => $h['nombre'], 'pagos' => (int) $t['n'], 'total' => (float) $t['total'], 'meses' => (int) $t['meses'],
                          'primero' => $t['primero'], 'ultimo' => $t['ultimo'], 'vence_en' => $h['vence_en'],
                          'ias_n' => (int) ($ia['n'] ?? 0), 'ias_total' => (float) ($ia['total'] ?? 0), 'ia_ultima' => $ia['ultima'] ?? null,
                          'con_cuenta_ia' => (bool) $h['streamy_cuenta_id']];
        }
        return ['por_mes' => $porMes, 'por_anio' => $porAnio, 'por_hija' => $porHija, 'streamy' => self::streamy() !== null];
    }
}

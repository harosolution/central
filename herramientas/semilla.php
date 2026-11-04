<?php
/**
 * FASE 6 · Base inicial de una hija NUEVA (4-nov-2026). La llama empaquetar.php; corre con sudo porque lee la base
 * de la madre como root (socket, sin clave).
 *
 *   sudo php herramientas/semilla.php haro_tecnova <carpeta_salida> <carpeta_migraciones>
 *
 * Deja en <carpeta_salida>:
 *   esquema.sql   la ESTRUCTURA de la base de la madre: ni una fila, sin tablas *_bak_*, contadores en cero.
 *   semilla.sql   lo mínimo para arrancar, todo GENÉRICO: roles y permisos, tipos de documento, partidas
 *                 arancelarias, categorías y rubros de gasto, canales, couriers SIN credenciales, una empresa y un
 *                 almacén con nombres neutros, ajustes de plantilla (lista blanca) y las migraciones ya aplicadas.
 * NUNCA: ventas, clientes, leads, usuarios, cuentas de pago, llaves o tokens, píxel ni teléfonos de la madre.
 * Si la semilla llega a tener algo que parezca de tecnova, se detiene y el paquete no se arma.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
[$_, $base, $out, $migDir] = $argv + [null, null, null, null];
function falla(string $m): void { fwrite(STDERR, "semilla: $m\n"); exit(1); }
if (! preg_match('/^[A-Za-z0-9_]+$/', (string) $base) || ! is_dir((string) $out) || ! is_dir((string) $migDir)) {
    falla('Uso: sudo php semilla.php <base_madre> <carpeta_salida> <carpeta_migraciones>');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('localhost', 'root', '', $base);
$db->set_charset('utf8mb4');

// Couriers de Perú que sirven a cualquiera (sin usuario, clave ni negocio de la madre).
const COURIERS = ['DINSIDES', 'SHALOM', 'OLVA', 'GOPACK', 'ALIDRIVE', 'PRIVADO', 'INTERNO'];
// Ajustes que son plantilla o regla de trabajo, no dato de la madre. Lo demás (llaves, cuentas, teléfonos, píxel,
// razón social, URLs…) NO va.
const AJUSTES = [
    'alertCourierPaidNotDeliveredHours', 'alertDeliveredNoAbonoHours', 'alertReprogramNextDay', 'aliclik_timeout',
    'allowSellersUseCalculator', 'billing_anticipo_item', 'courierStockAlertHours', 'igvPct', 'lead_reassign_enabled',
    'lead_reassign_timeout_minutes', 'leads_visibilidad_total', 'lockTTLMinutes', 'payDelayHours',
    'payment_template_bank', 'payment_template_mobile', 'roasMin', 'sale_advance_mode_json',
    'sale_checklist_required_json', 'seller_grace_minutes', 'seller_online_window_minutes', 'shalom_aereo_zones',
    'shalom_api_timeout', 'shalom_default_content', 'whatsapp_access_key_enable_copy', 'whatsapp_access_key_enable_link',
    'whatsapp_access_key_template', 'whatsapp_data_request_template', 'whatsapp_payment_template_provincia',
    'whatsapp_track_payment_note',
];
// Lo que delataría datos de la madre en la semilla.
const PROHIBIDO = '/tecnova|joel|v[aá]squez|julbany|miox|vitaweb|lumtec|haro solution|@gmail\.|@hotmail\.|sk-[A-Za-z0-9]{8}|hc_[0-9a-f]{16}|\b9\d{8}\b/iu';

// ── 1) Estructura ────────────────────────────────────────────────────────────
$tablas = [];
foreach ($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetch_all() as [$t]) {
    if (! preg_match('/_bak(_|$)|^tmp_|_old$/i', $t)) {
        $tablas[] = $t;
    }
}
$cmd = 'mysqldump --no-data --skip-add-drop-table --skip-comments --skip-triggers --no-tablespaces --default-character-set=utf8mb4 '
    . escapeshellarg($base) . ' ' . implode(' ', array_map('escapeshellarg', $tablas));
$esquema = (string) shell_exec($cmd . ' 2>/dev/null');
if (substr_count($esquema, 'CREATE TABLE') !== count($tablas)) {
    falla('mysqldump no devolvió la estructura completa (' . substr_count($esquema, 'CREATE TABLE') . ' de ' . count($tablas) . ' tablas).');
}
$esquema = preg_replace('/ AUTO_INCREMENT=\d+/', '', $esquema);
$esquema = preg_replace('#/\*!50013 DEFINER=[^*]*\*/#', '', $esquema);
$esquema = "-- Estructura de la base de una hija nueva, tomada de la madre el " . date('Y-m-d H:i') . " (" . count($tablas) . " tablas, sin datos).\n" . $esquema;

// ── 2) Semilla ───────────────────────────────────────────────────────────────
$sem = ['-- Semilla de una hija nueva, generada desde la madre el ' . date('Y-m-d H:i') . '. Solo datos genéricos.',
    'SET NAMES utf8mb4;', 'SET FOREIGN_KEY_CHECKS=0;'];
$cuenta = [];

$valor = static fn ($v) => $v === null ? 'NULL' : "'" . $db->real_escape_string((string) $v) . "'";
$insertar = static function (string $tabla, array $filas) use (&$sem, $valor): int {
    if (! $filas) {
        return 0;
    }
    $campos = array_keys($filas[0]);
    $vals = array_map(static fn ($r) => '(' . implode(',', array_map($valor, array_values($r))) . ')', $filas);
    foreach (array_chunk($vals, 300) as $lote) {
        $sem[] = "INSERT INTO `$tabla` (`" . implode('`,`', $campos) . "`) VALUES\n" . implode(",\n", $lote) . ';';
    }
    return count($filas);
};
$copiar = static function (string $tabla, string $where = '1=1', array $forzar = []) use ($db, $insertar, &$cuenta): void {
    $filas = [];
    foreach ($db->query("SELECT * FROM `$tabla` WHERE $where ORDER BY 1")->fetch_all(MYSQLI_ASSOC) as $r) {
        foreach ($forzar as $k => $v) {
            if (array_key_exists($k, $r)) {
                $r[$k] = $v;
            }
        }
        $filas[] = $r;
    }
    $cuenta[$tabla] = $insertar($tabla, $filas);
};

$cuenta['companies'] = $insertar('companies', [['id' => 1, 'name' => 'Mi empresa', 'is_active' => 1]]);
$cuenta['warehouses'] = $insertar('warehouses', [['id' => 1, 'company_id' => 1, 'name' => 'Almacén principal', 'is_active' => 1]]);
$copiar('roles');
$copiar('role_permissions');
$copiar('document_types');
$copiar('hs_codes');
$copiar('categories', 'company_id = 1');
$copiar('cost_categories', 'company_id = 1');
$copiar('marketing_channels', "company_id = 1 AND name NOT REGEXP '^[0-9]+$' AND LOWER(name) <> 'fb'");
$lista = implode(',', array_map($valor, COURIERS));
$copiar('couriers', "company_id = 1 AND name IN ($lista)", ['api_user' => null, 'api_pass' => null, 'api_negocio' => null]);
$copiar('migrations');   // las migraciones de CI4 ya están en la estructura

$ajustes = [];
$lista = implode(',', array_map($valor, AJUSTES));
foreach ($db->query("SELECT k, v FROM system_settings WHERE company_id = 1 AND k IN ($lista) ORDER BY k")->fetch_all(MYSQLI_ASSOC) as $r) {
    if (preg_match(PROHIBIDO, (string) $r['v'])) {
        falla("el ajuste «{$r['k']}» de la madre trae un dato propio (teléfono, nombre o correo): quítalo de la lista o límpialo.");
    }
    $ajustes[] = ['company_id' => 1, 'k' => $r['k'], 'v' => $r['v']];
}
$cuenta['system_settings'] = $insertar('system_settings', $ajustes);

// La estructura ya es la de la madre: todas las migraciones del paquete cuentan como aplicadas (si no, una hija nueva
// correría migraciones viejas que siembran datos de la madre, como el token de Yape «HARO (original)»).
$mig = array_map(static fn ($f) => ['filename' => basename($f)], glob(rtrim($migDir, '/') . '/*.sql') ?: []);
$sem[] = "CREATE TABLE IF NOT EXISTS schema_migrations (\n  filename VARCHAR(191) PRIMARY KEY,\n  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$cuenta['schema_migrations'] = $insertar('schema_migrations', $mig);
$sem[] = 'SET FOREIGN_KEY_CHECKS=1;';
$semilla = implode("\n", $sem) . "\n";

// ── 3) Revisión final: nada de la madre ──────────────────────────────────────
foreach (['semilla' => $semilla, 'esquema' => $esquema] as $n => $txt) {
    $sinCabecera = preg_replace('/^--.*$/m', '', $txt);
    if (preg_match(PROHIBIDO, $sinCabecera, $m)) {
        falla("la $n contiene «{$m[0]}», que parece de la madre. No armo el paquete.");
    }
}

file_put_contents("$out/esquema.sql", $esquema);
file_put_contents("$out/semilla.sql", $semilla);
chmod("$out/esquema.sql", 0644);
chmod("$out/semilla.sql", 0644);
$resumen = [];
foreach ($cuenta as $t => $n) {
    $resumen[] = "$t $n";
}
printf("Base inicial: %d tablas · semilla: %s\n", count($tablas), implode(', ', $resumen));

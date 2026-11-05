<?php
/**
 * FASE 6d · Cuenta de Recargas IA (streamy) para el robot de una hija que se instala (4-nov-2026), con LAS MISMAS
 * funciones del panel de streamy (RecargasIa::crearCuenta / desactivarCuenta): queda en su lista como las demás.
 * La llama la central (Instalador::tiendaIa) por consola, como www-data.
 *
 *   php herramientas/cuenta-ia.php <carpeta_streamy> crear "NOMBRE"   → {"ok":true,"id":6,"nombre":"…","token":"ria_…"}
 *   php herramientas/cuenta-ia.php <carpeta_streamy> desactivar 6     → {"ok":true}
 *
 * La clave sale UNA vez por stdout (para el instalador que la pidió); streamy guarda solo su sha256.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
[$_, $dir, $accion, $arg] = $argv + [null, null, null, null];
$fin = static function (array $r): void {
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit(! empty($r['ok']) ? 0 : 1);
};
if (! is_dir((string) $dir) || ! is_file("$dir/vendor/codeigniter4/framework/system/Test/bootstrap.php") || ! is_file("$dir/app/Libraries/RecargasIa.php")) {
    $fin(['ok' => false, 'error' => 'No encuentro la tienda de IA (streamy).']);
}
chdir($dir);
ob_start();
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
$cfg = config(\Config\Database::class);
$cfg->tests = $cfg->default;   // el bootstrap de pruebas cambia a la base «tests»: aquí va la de verdad
db_connect()->initialize();
ob_end_clean();

$ia = new \App\Libraries\RecargasIa();
if ($accion === 'crear') {
    [$ok, $msg, $token] = $ia->crearCuenta((string) $arg);
    if (! $ok || ! preg_match('/^ria_[0-9a-f]{48}$/', (string) $token)) {
        $fin(['ok' => false, 'error' => (string) $msg]);
    }
    $row = db_connect()->table('v2_ia_cuentas')->select('id, nombre')->where('token_hash', hash('sha256', $token))->get()->getRowArray();
    $fin(['ok' => true, 'id' => (int) ($row['id'] ?? 0), 'nombre' => (string) ($row['nombre'] ?? ''), 'token' => $token]);
}
if ($accion === 'desactivar' && ctype_digit((string) $arg)) {
    $ia->desactivarCuenta((int) $arg);
    $fin(['ok' => true]);
}
$fin(['ok' => false, 'error' => 'Uso: crear "NOMBRE" | desactivar ID']);

<?php

namespace Central\Controllers;

use Central\Libraries\Central;
use Central\Libraries\Instalador;
use Central\Libraries\Licencias;
use CodeIgniter\Controller;

/**
 * Lo que llama cada hija, con SU llave (no hay sesión).
 * Nunca se recibe ni se guarda información del negocio de la hija: solo versiones y salud del servidor.
 */
class Api extends Controller
{
    public function latido()
    {
        $hija = Central::hijaPorLlave($this->request->getHeaderLine('Authorization'));
        if (! $hija) {
            return $this->response->setStatusCode(401)->setJSON(['ok' => false, 'error' => 'Llave no válida.']);
        }
        if ((int) $hija['activa'] !== 1) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false, 'error' => 'Hija dada de baja.']);
        }

        $b = $this->request->getJSON(true);
        $b = is_array($b) ? $b : [];
        $corto = static fn ($v, int $n = 60) => mb_substr(trim((string) $v), 0, $n);

        $fila = [
            'hija_id'    => (int) $hija['id'],
            'visto_en'   => date('Y-m-d H:i:s'),
            'ip'         => $corto($this->request->getIPAddress(), 45),
            'crm_ver'    => $corto($b['crm'] ?? ''),
            'chat_ver'   => $corto($b['chat'] ?? ''),
            'robot_ver'  => $corto($b['robot'] ?? ''),
            'agente_ver' => $corto($b['agente'] ?? ''),
            'servidor'   => $corto($b['servidor'] ?? '', 100),
            'php_ver'    => $corto($b['php'] ?? '', 20),
            'disco_libre_gb' => is_numeric($b['disco_libre_gb'] ?? null) ? round((float) $b['disco_libre_gb'], 1) : null,
            'datos'      => json_encode(array_slice((array) ($b['extra'] ?? []), 0, 30), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $db = Central::db();
        $existe = $db->table('latidos')->where('hija_id', $fila['hija_id'])->countAllResults() > 0;
        if ($existe) {
            $db->table('latidos')->where('hija_id', $fila['hija_id'])->set('latidos_total', 'latidos_total+1', false)->update($fila);
        } else {
            $fila['primer_latido'] = $fila['visto_en'];
            $fila['latidos_total'] = 1;
            $db->table('latidos')->insert($fila);
        }

        $est = Central::estado($fila);
        // Fase 7 · «actualizar sola» (casilla de la hija, apagada salvo que Joe la prenda): de 4 a 5 am de Lima, de a pocas.
        if (empty($hija['actualizar_pedido']) && (int) ($hija['auto_actualizar'] ?? 0) === 1 && Central::tocaAuto()) {
            [$okAuto] = Central::pedirActualizacion((int) $hija['id'], 'auto');
            if ($okAuto) {
                $hija = $db->table('hijas')->where('id', (int) $hija['id'])->get()->getRowArray();
            }
        }
        // ¿Hay una actualización pedida (Joe, el cliente o «sola»)? Se le entrega al agente UNA vez y se borra el pedido.
        // Solo las piezas que siguen atrasadas y que ese agente sabe hacer; las demás se cierran como «al día».
        $enviar = [];
        if (! empty($hija['actualizar_pedido'])) {
            [$posibles] = Central::paraActualizar($fila);
            $pedidas = array_filter(explode(',', (string) ($hija['actualizar_piezas'] ?? ''))) ?: ['crm'];
            $enviar = array_values(array_intersect($posibles, $pedidas));
            $db->table('hijas')->where('id', (int) $hija['id'])->update(['actualizar_pedido' => null, 'actualizar_piezas' => '']);
            foreach ($db->table('actualizaciones')->where('hija_id', (int) $hija['id'])->where('estado', 'pedida')->get()->getResultArray() as $a) {
                $va = in_array($a['pieza'] ?: 'crm', $enviar, true);
                $db->table('actualizaciones')->where('id', (int) $a['id'])->update($va ? ['estado' => 'enviada']
                    : ['estado' => 'ok', 'mensaje' => 'Ya estaba al día (o ese agente todavía no sabe actualizarla).', 'terminada_en' => date('Y-m-d H:i:s')]);
            }
        }
        return $this->response->setJSON([
            'ok'         => true,
            'hija'       => $hija['nombre'],
            'color'      => $est['color'],
            'mensaje'    => $est['mensaje'],
            'piezas'     => $est['piezas'],
            'actualizar' => in_array('crm', $enviar, true),   // los agentes de antes de la 1.3.0 solo miran esto (CRM)
            'piezas_actualizar' => $enviar,                    // 1.3.0+: agente, crm, robot, chat (en ese orden)
            'hora'       => date('c'),
        ]);
    }

    /** POST /central/api/actualizar — el cliente pide actualizar desde su CRM. */
    public function actualizar()
    {
        $hija = $this->hija();
        if (! is_array($hija)) {
            return $hija;
        }
        [$ok, $msg] = Central::pedirActualizacion((int) $hija['id'], 'cliente');
        return $this->response->setStatusCode($ok ? 200 : 422)->setJSON(['ok' => $ok, 'mensaje' => $msg]);
    }

    /** POST /central/api/actualizacion {ok, pieza, desde, hasta, mensaje} — el agente informa cómo le fue (una pieza). */
    public function actualizacion()
    {
        $hija = $this->hija();
        if (! is_array($hija)) {
            return $hija;
        }
        $b = $this->request->getJSON(true) ?: [];
        $db = Central::db();
        $pieza = in_array($b['pieza'] ?? 'crm', Central::PIEZAS, true) ? (string) ($b['pieza'] ?? 'crm') : 'crm';
        $fila = $db->table('actualizaciones')->where('hija_id', (int) $hija['id'])->where('pieza', $pieza)->where('estado', 'enviada')->orderBy('id', 'DESC')->get()->getRowArray();
        $datos = ['estado' => ! empty($b['ok']) ? 'ok' : 'fallo', 'mensaje' => mb_substr((string) ($b['mensaje'] ?? ''), 0, 500), 'terminada_en' => date('Y-m-d H:i:s')];
        if ($fila) {
            $db->table('actualizaciones')->where('id', (int) $fila['id'])->update($datos + ['hasta' => mb_substr((string) ($b['hasta'] ?? $fila['hasta']), 0, 20)]);
        } else {
            $db->table('actualizaciones')->insert($datos + ['hija_id' => (int) $hija['id'], 'pieza' => $pieza, 'desde' => mb_substr((string) ($b['desde'] ?? ''), 0, 20),
                'hasta' => mb_substr((string) ($b['hasta'] ?? ''), 0, 20), 'por' => 'agente', 'pedida_en' => date('Y-m-d H:i:s')]);
        }
        return $this->response->setJSON(['ok' => true]);
    }

    /**
     * GET /central/api/paquete?pieza=crm|chat|robot|agente&ver=2.2.0 — el paquete firmado (lo arma herramientas/empaquetar.php).
     * Cabeceras X-Sha256 y X-Firma: el agente las comprueba con la llave pública que lleva escrita.
     */
    public function paquete()
    {
        $hija = Central::hijaPorLlave($this->request->getHeaderLine('Authorization'));
        if (! $hija || (int) $hija['activa'] !== 1) {
            return $this->response->setStatusCode(401)->setJSON(['ok' => false, 'error' => 'Llave no válida.']);
        }
        $pieza = (string) $this->request->getGet('pieza');
        $ver = (string) $this->request->getGet('ver');
        if (! in_array($pieza, Central::PIEZAS, true) || ! preg_match('/^\d+\.\d+\.\d+$/', $ver)) {
            return $this->response->setStatusCode(400)->setJSON(['ok' => false, 'error' => 'Pieza o versión inválida.']);
        }
        $f = dirname(__DIR__, 2) . "/datos/paquetes/{$pieza}-{$ver}.tgz";
        if (! is_file($f) || ! is_file("$f.sha256") || ! is_file("$f.firma")) {
            return $this->response->setStatusCode(404)->setJSON(['ok' => false, 'error' => "Todavía no hay paquete de $pieza $ver."]);
        }
        return $this->response->download($f, null)->setFileName("{$pieza}-{$ver}.tgz")
            ->setHeader('X-Sha256', trim((string) file_get_contents("$f.sha256")))
            ->setHeader('X-Firma', trim((string) file_get_contents("$f.firma")));
    }

    public function estado()
    {
        $hija = Central::hijaPorLlave($this->request->getHeaderLine('Authorization'));
        if (! $hija) {
            return $this->response->setStatusCode(401)->setJSON(['ok' => false, 'error' => 'Llave no válida.']);
        }
        $lat = Central::db()->table('latidos')->where('hija_id', (int) $hija['id'])->get()->getRowArray();
        $est = Central::estado($lat ?: null);
        $lic = Licencias::estado($hija);
        $ua = Central::ultimaActualizacion((int) $hija['id']);
        return $this->response->setJSON([
            'actualizacion' => [
                'pedida'  => ! empty($hija['actualizar_pedido']) || ($ua && in_array($ua['estado'], ['pedida', 'enviada'], true)),
                'ultima'  => $ua ? ['estado' => $ua['estado'], 'desde' => $ua['desde'], 'hasta' => $ua['hasta'], 'por' => $ua['por'],
                                    'pedida_en' => $ua['pedida_en'], 'terminada_en' => $ua['terminada_en'], 'mensaje' => $ua['mensaje']] : null,
            ],
            'ok'       => true,
            'hija'     => $hija['nombre'],
            'color'    => $est['color'],
            'mensaje'  => $est['mensaje'],
            'piezas'   => $est['piezas'],
            'visto_en' => $lat['visto_en'] ?? null,
            'licencia' => ['estado' => $lic['estado'], 'color' => $lic['color'], 'mensaje' => $lic['mensaje'], 'vence_en' => $lic['vence_en'], 'dias' => $lic['dias'],
                           'plan' => $lic['plan']['nombre'] ?? null],
        ]);
    }

    /** GET /central/api/licencia — todo lo de «Mi licencia» de la hija: plan, vencimiento, cómo pagar y sus últimos pagos. */
    public function licencia()
    {
        $hija = $this->hija();
        if (! is_array($hija)) {
            return $hija;
        }
        $e = Licencias::paraHija($hija);
        // Fase 4: estado firmado; la hija corta automatizaciones SOLO con esto (y si tiene menos de 72 h).
        $e['firmado'] = Licencias::firmado($hija, $e);
        $ac = Licencias::aceptacion((int) $hija['id']);
        $e['terminos'] = ['version' => Licencias::TERMINOS_VERSION, 'aceptado_en' => $ac['aceptado_en'] ?? null, 'usuario' => $ac['usuario'] ?? ''];
        return $this->response->setJSON(['ok' => true, 'hija' => $hija['nombre']] + $e);
    }

    /** POST /central/api/pagos  {periodos, codigo} — la hija avisa que yapeó. */
    public function pagar()
    {
        $hija = $this->hija();
        if (! is_array($hija)) {
            return $hija;
        }
        $b = $this->request->getJSON(true) ?: [];
        [$ok, $msg, $pago] = Licencias::crearPagoHija($hija, (int) ($b['periodos'] ?? 1), (string) ($b['codigo'] ?? ''));
        return $this->response->setStatusCode($ok ? 200 : 422)->setJSON(['ok' => $ok, 'mensaje' => $msg, 'pago' => $pago]);
    }

    /** GET /central/api/pagos/{id} — cómo va un pago (si sigue pendiente, se vuelve a buscar el Yape). */
    public function pago(int $id)
    {
        $hija = $this->hija();
        if (! is_array($hija)) {
            return $hija;
        }
        $db = Central::db();
        $p = $db->table('pagos')->where('id', $id)->where('hija_id', (int) $hija['id'])->get()->getRowArray();
        if (! $p) {
            return $this->response->setStatusCode(404)->setJSON(['ok' => false, 'error' => 'No existe ese pago.']);
        }
        if ($p['estado'] === 'pendiente') {
            Licencias::validar($id);
            $p = $db->table('pagos')->where('id', $id)->get()->getRowArray();
        }
        $h = $db->table('hijas')->where('id', (int) $hija['id'])->get()->getRowArray();
        $lic = Licencias::estado($h);
        return $this->response->setJSON(['ok' => true, 'pago' => Licencias::pagoParaHija($p), 'licencia' => ['estado' => $lic['estado'], 'color' => $lic['color'], 'mensaje' => $lic['mensaje'], 'vence_en' => $lic['vence_en']]]);
    }

    /** POST /central/api/instalar {codigo, servidor} — fase 6: el instalador canjea su código por la llave NUEVA de la hija. */
    public function instalar()
    {
        $b = $this->request->getJSON(true);
        $b = is_array($b) ? $b : [];
        // Agregar Chat y Robot (fase 6c): el comando manda la llave del agente de la hija (Authorization), que se comprueba.
        [$ok, $msg, $datos] = Instalador::canjear((string) ($b['codigo'] ?? ''), (string) ($b['servidor'] ?? ''), (string) $this->request->getIPAddress(),
            $this->request->getHeaderLine('Authorization'));
        $this->response->setHeader('Cache-Control', 'no-store');
        return $this->response->setStatusCode($ok ? 200 : 422)->setJSON($ok ? ['ok' => true] + $datos : ['ok' => false, 'error' => $msg]);
    }

    /** POST /central/api/instalacion {ok, mensaje, version} — el instalador avisa cómo terminó (con la llave recién canjeada). */
    public function instalacionTerminada()
    {
        $hija = $this->hija();
        if (! is_array($hija)) {
            return $hija;
        }
        $b = $this->request->getJSON(true) ?: [];
        Instalador::reportar((int) $hija['id'], ! empty($b['ok']), (string) ($b['mensaje'] ?? ''), (string) ($b['version'] ?? ''));
        return $this->response->setJSON(['ok' => true]);
    }

    /**
     * POST /central/api/tienda-ia — fase 6d: el instalador pide la cuenta de Recargas IA (streamy) del robot que está
     * poniendo. Solo con una instalación con Robot en curso de esa hija; la clave sale UNA vez.
     */
    public function tiendaIa()
    {
        $hija = $this->hija();
        if (! is_array($hija)) {
            return $hija;
        }
        [$ok, $msg, $token] = Instalador::tiendaIa($hija);
        $this->response->setHeader('Cache-Control', 'no-store');
        return $this->response->setStatusCode($ok ? 200 : 422)->setJSON($ok ? ['ok' => true, 'mensaje' => $msg, 'token' => $token] : ['ok' => false, 'error' => $msg]);
    }

    /** La hija de la llave, activa; si no, la respuesta de error. */
    /** POST /central/api/terminos {version, usuario, correo, empresa, ruc, ip} — fase 5. */
    public function terminos()
    {
        $hija = $this->hija();
        if (! is_array($hija)) {
            return $hija;
        }
        $b = $this->request->getJSON(true) ?: [];
        if ((string) ($b['version'] ?? '') !== Licencias::TERMINOS_VERSION) {
            return $this->response->setStatusCode(409)->setJSON(['ok' => false, 'mensaje' => 'Los términos cambiaron: recarga la página y vuelve a leerlos.', 'version' => Licencias::TERMINOS_VERSION]);
        }
        Licencias::aceptar((int) $hija['id'], $b);
        return $this->response->setJSON(['ok' => true, 'aceptacion' => Licencias::aceptacion((int) $hija['id'])]);
    }

    private function hija()
    {
        $hija = Central::hijaPorLlave($this->request->getHeaderLine('Authorization'));
        if (! $hija || (int) $hija['activa'] !== 1) {
            return $this->response->setStatusCode(401)->setJSON(['ok' => false, 'error' => 'Llave no válida.']);
        }
        return $hija;
    }
}

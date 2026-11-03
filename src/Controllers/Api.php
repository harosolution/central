<?php

namespace Central\Controllers;

use Central\Libraries\Central;
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
        return $this->response->setJSON([
            'ok'      => true,
            'hija'    => $hija['nombre'],
            'color'   => $est['color'],
            'mensaje' => $est['mensaje'],
            'piezas'  => $est['piezas'],
            'hora'    => date('c'),
        ]);
    }

    public function estado()
    {
        $hija = Central::hijaPorLlave($this->request->getHeaderLine('Authorization'));
        if (! $hija) {
            return $this->response->setStatusCode(401)->setJSON(['ok' => false, 'error' => 'Llave no válida.']);
        }
        $lat = Central::db()->table('latidos')->where('hija_id', (int) $hija['id'])->get()->getRowArray();
        $est = Central::estado($lat ?: null);
        return $this->response->setJSON([
            'ok'       => true,
            'hija'     => $hija['nombre'],
            'color'    => $est['color'],
            'mensaje'  => $est['mensaje'],
            'piezas'   => $est['piezas'],
            'visto_en' => $lat['visto_en'] ?? null,
        ]);
    }
}

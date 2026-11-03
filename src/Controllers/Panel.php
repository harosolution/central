<?php

namespace Central\Controllers;

use Central\Libraries\Central;
use CodeIgniter\Controller;

/** Panel de la central: solo el super_admin de la empresa de la madre. */
class Panel extends Controller
{
    private function permitido(): bool
    {
        $u = (array) (session('admin_user') ?? []);
        $empresa = (int) (Central::cfg()['empresa_madre'] ?? 1);
        return ($u['role'] ?? '') === 'super_admin' && (int) ($u['company_id'] ?? 0) === $empresa && ! session('dios_real');
    }

    private function fuera()
    {
        return $this->response->setStatusCode(403)->setBody('Solo el administrador de la madre entra a la central.');
    }

    public function index()
    {
        if (! $this->permitido()) {
            return $this->fuera();
        }
        $db = Central::db();
        $hijas = $db->table('hijas')->orderBy('activa', 'DESC')->orderBy('nombre')->get()->getResultArray();
        $lat = [];
        foreach ($db->table('latidos')->get()->getResultArray() as $l) {
            $lat[(int) $l['hija_id']] = $l;
        }
        foreach ($hijas as &$h) {
            $h['latido'] = $lat[(int) $h['id']] ?? null;
            $h['estado'] = Central::estado($h['latido']);
        }
        unset($h);

        return view('Central\Views\panel', [
            'title'   => 'Central de hijas',
            'hijas'   => $hijas,
            'ultimas' => Central::ultimas(),
            'llave'   => session()->getFlashdata('central_llave'),
            'llaveDe' => session()->getFlashdata('central_llave_de'),
            'ok'      => session()->getFlashdata('central_ok'),
            'error'   => session()->getFlashdata('central_error'),
        ]);
    }

    private function datos(): array
    {
        $p = fn (string $k, int $n = 120) => mb_substr(trim((string) $this->request->getPost($k)), 0, $n);
        $dom = strtolower(preg_replace('#^https?://#i', '', rtrim($p('dominio'), '/')));
        return [
            'nombre'   => $p('nombre', 80),
            'dominio'  => $dom,
            'servidor' => $p('servidor', 120),
            'notas'    => $p('notas', 500),
        ];
    }

    public function crear()
    {
        if (! $this->permitido()) {
            return $this->fuera();
        }
        $d = $this->datos();
        if ($d['nombre'] === '') {
            return redirect()->to(base_url('central'))->with('central_error', 'Falta el nombre.');
        }
        $llave = Central::nuevaLlave();
        $d['llave_hash'] = hash('sha256', $llave);
        $d['activa'] = 1;
        $d['creada_en'] = date('Y-m-d H:i:s');
        Central::db()->table('hijas')->insert($d);

        return redirect()->to(base_url('central'))
            ->with('central_llave', $llave)->with('central_llave_de', $d['nombre'])
            ->with('central_ok', 'Hija «' . $d['nombre'] . '» registrada.');
    }

    public function editar(int $id)
    {
        if (! $this->permitido()) {
            return $this->fuera();
        }
        $d = $this->datos();
        $d['activa'] = $this->request->getPost('activa') ? 1 : 0;
        if ($d['nombre'] === '') {
            return redirect()->to(base_url('central'))->with('central_error', 'Falta el nombre.');
        }
        Central::db()->table('hijas')->where('id', $id)->update($d);
        return redirect()->to(base_url('central'))->with('central_ok', 'Guardado.');
    }

    /** Nueva llave: la anterior deja de servir al instante (hay que ponerla en el agente de la hija). */
    public function llave(int $id)
    {
        if (! $this->permitido()) {
            return $this->fuera();
        }
        $h = Central::db()->table('hijas')->where('id', $id)->get()->getRowArray();
        if (! $h) {
            return redirect()->to(base_url('central'))->with('central_error', 'No existe esa hija.');
        }
        $llave = Central::nuevaLlave();
        Central::db()->table('hijas')->where('id', $id)->update(['llave_hash' => hash('sha256', $llave)]);
        return redirect()->to(base_url('central'))
            ->with('central_llave', $llave)->with('central_llave_de', $h['nombre'])
            ->with('central_ok', 'Llave nueva para «' . $h['nombre'] . '». La anterior ya no sirve.');
    }
}

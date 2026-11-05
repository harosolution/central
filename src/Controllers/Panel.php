<?php

namespace Central\Controllers;

use Central\Libraries\Central;
use Central\Libraries\Instalador;
use Central\Libraries\Licencias;
use CodeIgniter\Controller;

/** Panel de la central: solo el super_admin de la empresa de la madre. */
class Panel extends Controller
{
    private const VISTAS = ['hijas', 'planes', 'pagos', 'ingresos', 'ajustes'];

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

    private function volver(string $vista, ?string $ok = null, ?string $error = null)
    {
        $r = redirect()->to(base_url('central/' . $vista));
        if ($ok) { $r = $r->with('central_ok', $ok); }
        if ($error) { $r = $r->with('central_error', $error); }
        return $r;
    }

    public function index(string $vista = 'hijas')
    {
        if (! $this->permitido()) {
            return $this->fuera();
        }
        $vista = in_array($vista, self::VISTAS, true) ? $vista : 'hijas';
        $db = Central::db();
        $datos = [
            'title' => 'Central de hijas', 'vista' => $vista,
            'ok' => session()->getFlashdata('central_ok'), 'error' => session()->getFlashdata('central_error'),
            'llave' => session()->getFlashdata('central_llave'), 'llaveDe' => session()->getFlashdata('central_llave_de'),
            'comando' => session()->getFlashdata('central_comando'), 'comandoDe' => session()->getFlashdata('central_comando_de'),
            'planes' => Licencias::planes(), 'ajustes' => Licencias::ajustes(),
        ];
        $hijas = $db->table('hijas')->orderBy('activa', 'DESC')->orderBy('nombre')->get()->getResultArray();
        $lat = [];
        foreach ($db->table('latidos')->get()->getResultArray() as $l) {
            $lat[(int) $l['hija_id']] = $l;
        }
        foreach ($hijas as &$h) {
            $h['latido'] = $lat[(int) $h['id']] ?? null;
            $h['estado'] = Central::estado($h['latido']);
            $h['licencia'] = Licencias::estado($h);
            $h['ultima_act'] = Central::ultimaActualizacion((int) $h['id']);
            $h['acts'] = Central::ultimasActualizaciones((int) $h['id']);   // fase 7: una fila por pieza
            [$h['actualizables']] = Central::paraActualizar($h['latido']);
            $h['terminos'] = Licencias::aceptacion((int) $h['id']);   // fase 5
            $h['instalacion'] = Instalador::ultima((int) $h['id']);   // fase 6
        }
        unset($h);
        $datos['hijas'] = $hijas;

        if ($vista === 'hijas') {
            $datos['ultimas'] = Central::ultimas();
            $datos['cuentasIa'] = Licencias::cuentasStreamy();
        } elseif ($vista === 'pagos') {
            // Los pendientes se vuelven a buscar al abrir la pantalla.
            foreach ($db->table('pagos')->select('id')->where('estado', 'pendiente')->get()->getResultArray() as $p) {
                Licencias::validar((int) $p['id']);
            }
            $datos['pagos'] = $db->query('SELECT p.*, h.nombre AS hija FROM pagos p JOIN hijas h ON h.id = p.hija_id ORDER BY p.id DESC LIMIT 300')->getResultArray();
        } elseif ($vista === 'ingresos') {
            $datos['ingresos'] = Licencias::ingresos();
        }
        return view('Central\Views\\' . $vista, $datos);
    }

    // ── Hijas ────────────────────────────────────────────────────────────────
    private function datosHija(): array
    {
        $p = fn (string $k, int $n = 120) => mb_substr(trim((string) $this->request->getPost($k)), 0, $n);
        $vence = $p('vence_en', 10);
        return [
            'nombre'   => $p('nombre', 80),
            'dominio'  => strtolower(preg_replace('#^https?://#i', '', rtrim($p('dominio'), '/'))),
            'servidor' => $p('servidor', 120),
            'notas'    => $p('notas', 500),
            'plan_id'  => (int) $this->request->getPost('plan_id') ?: null,
            'vence_en' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $vence) ? $vence : null,
            'contacto_nombre'   => $p('contacto_nombre', 100),
            'contacto_telefono' => preg_replace('/[^\d+ ]/', '', $p('contacto_telefono', 20)),
            'streamy_cuenta_id' => (int) $this->request->getPost('streamy_cuenta_id') ?: null,
            'auto_actualizar'   => $this->request->getPost('auto_actualizar') ? 1 : 0,   // fase 7: «actualizar sola»
        ];
    }

    public function crear()
    {
        if (! $this->permitido()) {
            return $this->fuera();
        }
        $d = $this->datosHija();
        if ($d['nombre'] === '') {
            return $this->volver('hijas', null, 'Falta el nombre.');
        }
        $llave = Central::nuevaLlave();
        Central::db()->table('hijas')->insert($d + ['llave_hash' => hash('sha256', $llave), 'activa' => 1, 'creada_en' => date('Y-m-d H:i:s')]);
        return $this->volver('hijas', 'Hija «' . $d['nombre'] . '» registrada.')->with('central_llave', $llave)->with('central_llave_de', $d['nombre']);
    }

    public function editar(int $id)
    {
        if (! $this->permitido()) {
            return $this->fuera();
        }
        $d = $this->datosHija();
        $d['activa'] = $this->request->getPost('activa') ? 1 : 0;
        if ($d['nombre'] === '') {
            return $this->volver('hijas', null, 'Falta el nombre.');
        }
        Central::db()->table('hijas')->where('id', $id)->update($d);
        return $this->volver('hijas', 'Guardado.');
    }

    public function llave(int $id)
    {
        if (! $this->permitido()) {
            return $this->fuera();
        }
        $h = Central::db()->table('hijas')->where('id', $id)->get()->getRowArray();
        if (! $h) {
            return $this->volver('hijas', null, 'No existe esa hija.');
        }
        $llave = Central::nuevaLlave();
        Central::db()->table('hijas')->where('id', $id)->update(['llave_hash' => hash('sha256', $llave)]);
        return $this->volver('hijas', 'Llave nueva para «' . $h['nombre'] . '». La anterior ya no sirve.')->with('central_llave', $llave)->with('central_llave_de', $h['nombre']);
    }

    /** Joe pide actualizar una hija: la instala su agente en el próximo latido (≤ 10 min). */
    public function actualizar(int $id)
    {
        if (! $this->permitido()) {
            return $this->fuera();
        }
        [$ok, $msg] = Central::pedirActualizacion($id, 'joe');
        return $this->volver('hijas', $ok ? $msg : null, $ok ? null : $msg);
    }

    /** Fase 7: «Actualizar todas» — cada hija activa con agente, todas sus piezas atrasadas. */
    public function actualizarTodas()
    {
        if (! $this->permitido()) {
            return $this->fuera();
        }
        [$n, $lineas] = Central::actualizarTodas('joe');
        $msg = $n ? "Pedida la actualización de $n hija(s): sus agentes la instalan en los próximos 10 minutos, con respaldo y vuelta atrás. " . implode(' · ', $lineas)
            : 'No hay nada que actualizar. ' . implode(' · ', $lineas);
        return $this->volver('hijas', mb_substr($msg, 0, 1500));
    }

    /** Fase 6: el comando de una línea para instalar esta hija en un servidor nuevo (se muestra UNA vez). */
    public function instalador(int $id)
    {
        if (! $this->permitido()) {
            return $this->fuera();
        }
        $r = $this->request;
        [$ok, $msg, $codigo] = Instalador::crearCodigo($id, ['empresa' => (string) $r->getPost('empresa'),
            'admin_nombre' => (string) $r->getPost('admin_nombre'), 'admin_email' => (string) $r->getPost('admin_email'),
            'con_chat' => (bool) $r->getPost('con_chat'), 'dominio_chat' => (string) $r->getPost('dominio_chat'),
            'nombre_robot' => (string) $r->getPost('nombre_robot'), 'con_conector' => (bool) $r->getPost('con_conector')], 'joe');
        if (! $ok) {
            return $this->volver('hijas', null, $msg);
        }
        $h = Central::db()->table('hijas')->where('id', $id)->get()->getRowArray();
        return $this->volver('hijas', $msg)->with('central_comando', Instalador::comando($codigo))->with('central_comando_de', (string) ($h['nombre'] ?? ''));
    }

    /** Fase 6c: el comando para agregar Chat y Robot a una hija YA instalada (se muestra UNA vez). */
    public function agregarChat(int $id)
    {
        if (! $this->permitido()) {
            return $this->fuera();
        }
        $r = $this->request;
        [$ok, $msg, $codigo] = Instalador::crearCodigoChat($id, ['dominio_chat' => (string) $r->getPost('dominio_chat'),
            'nombre_robot' => (string) $r->getPost('nombre_robot'), 'con_conector' => (bool) $r->getPost('con_conector')], 'joe');
        if (! $ok) {
            return $this->volver('hijas', null, $msg);
        }
        $h = Central::db()->table('hijas')->where('id', $id)->get()->getRowArray();
        return $this->volver('hijas', $msg)->with('central_comando', Instalador::comando($codigo))->with('central_comando_de', (string) ($h['nombre'] ?? ''));
    }

    // ── Planes, pagos, ajustes ──────────────────────────────────────────────
    public function plan(?int $id = null)
    {
        if (! $this->permitido()) {
            return $this->fuera();
        }
        [$ok, $msg] = Licencias::guardarPlan($this->request->getPost(), $id);
        return $this->volver('planes', $ok ? $msg : null, $ok ? null : $msg);
    }

    public function pagoManual()
    {
        if (! $this->permitido()) {
            return $this->fuera();
        }
        $r = $this->request;
        [$ok, $msg] = Licencias::registrarManual((int) $r->getPost('hija_id'), (int) $r->getPost('periodos'), (float) $r->getPost('monto'),
            (string) $r->getPost('metodo'), trim((string) $r->getPost('nota')));
        return $this->volver('pagos', $ok ? $msg : null, $ok ? null : $msg);
    }

    public function pagoReintentar(int $id)
    {
        if (! $this->permitido()) {
            return $this->fuera();
        }
        // Un vencido vuelve a pendiente para buscarlo sin el límite de una hora.
        Central::db()->table('pagos')->where('id', $id)->where('estado', 'vencido')->update(['estado' => 'pendiente']);
        $e = Licencias::validar($id, true);
        return $this->volver('pagos', $e === 'pagado' ? 'Yape encontrado: pago confirmado y licencia extendida.' : null,
            $e === 'pagado' ? null : 'Todavía no aparece el Yape.');
    }

    public function pagoAnular(int $id)
    {
        if (! $this->permitido()) {
            return $this->fuera();
        }
        [$ok, $msg] = Licencias::anular($id);
        return $this->volver('pagos', $ok ? $msg : null, $ok ? null : $msg);
    }

    public function ajustes()
    {
        if (! $this->permitido()) {
            return $this->fuera();
        }
        Licencias::guardarAjustes($this->request->getPost());
        return $this->volver('ajustes', 'Ajustes guardados.');
    }
}

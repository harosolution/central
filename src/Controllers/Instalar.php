<?php

namespace Central\Controllers;

use Central\Libraries\Instalador;
use CodeIgniter\Controller;

/**
 * GET /central/instalar/{código} — fase 6: el script del instalador de una línea (curl -fsSL … | sudo bash).
 * Sin sesión ni llave: el código de un solo uso es la autorización. El script no lleva secretos.
 */
class Instalar extends Controller
{
    public function script(string $codigo = '')
    {
        return $this->response
            ->setHeader('Content-Type', 'text/plain; charset=utf-8')
            ->setHeader('Cache-Control', 'no-store, private')
            ->setHeader('X-Robots-Tag', 'noindex')
            ->setBody(Instalador::script($codigo));
    }
}

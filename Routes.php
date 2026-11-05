<?php
/**
 * CENTRAL DE HIJAS (3-nov-2026, Joe).
 *
 * Vive SOLO en la madre tecnova (repo privado harosolution/central) y NO forma parte del CRM:
 * el CRM la carga con App\Libraries\Extensiones solo si su .env la nombra (extensiones.rutas).
 * Ninguna hija la tiene.
 *
 *   /central                 panel (super_admin de la empresa de la madre): hijas, planes, pagos, ingresos, ajustes
 *   POST /central/api/latido el agente de cada hija avisa que está viva y qué versiones tiene
 *   GET  /central/api/estado  la ⚙️ de cada hija pregunta si está al día (versión y licencia)
 *   /central/api/licencia, /central/api/pagos   «Mi licencia» de cada hija (pagar con Yape)
 *   GET  /central/instalar/<código>   fase 6: instalador de una línea de una hija NUEVA (curl … | sudo bash)
 *
 * @var \CodeIgniter\Router\RouteCollection $routes
 */
service('autoloader')->addNamespace('Central', __DIR__ . '/src');

$routes->group('central', ['namespace' => 'Central\Controllers'], static function ($routes) {
    // Con llave de la hija (Authorization: Bearer …), sin sesión.
    $routes->post('api/latido', 'Api::latido');
    $routes->get('api/estado', 'Api::estado');
    $routes->get('api/paquete', 'Api::paquete');
    $routes->get('api/licencia', 'Api::licencia');
    $routes->post('api/pagos', 'Api::pagar');
    $routes->get('api/pagos/(:num)', 'Api::pago/$1');
    $routes->post('api/actualizar', 'Api::actualizar');          // el cliente pide actualizar desde su CRM
    $routes->post('api/actualizacion', 'Api::actualizacion');    // el agente informa el resultado
    $routes->post('api/terminos', 'Api::terminos');              // fase 5: el admin de la hija acepta los términos
    // Fase 6: instalador de una línea. Sin llave ni sesión: el código de un solo uso (48 h) es la autorización.
    $routes->get('instalar/(:segment)', 'Instalar::script/$1');
    $routes->post('api/instalar', 'Api::instalar');               // el instalador canjea el código por la llave
    $routes->post('api/instalacion', 'Api::instalacionTerminada'); // y avisa cómo terminó

    // Panel: sesión del CRM + super_admin de la madre (lo revisa el controlador).
    $f = ['filter' => 'adminauth'];
    $routes->get('/', 'Panel::index', $f);
    $routes->get('(:segment)', 'Panel::index/$1', $f);
    $routes->post('hijas', 'Panel::crear', $f);
    $routes->post('hijas/(:num)', 'Panel::editar/$1', $f);
    $routes->post('hijas/(:num)/llave', 'Panel::llave/$1', $f);
    $routes->post('hijas/(:num)/actualizar', 'Panel::actualizar/$1', $f);
    $routes->post('hijas/(:num)/instalador', 'Panel::instalador/$1', $f);
    $routes->post('hijas/(:num)/agregar-chat', 'Panel::agregarChat/$1', $f);   // fase 6c: Chat y Robot a una hija ya instalada
    $routes->post('planes', 'Panel::plan', $f);
    $routes->post('planes/(:num)', 'Panel::plan/$1', $f);
    $routes->post('pagos', 'Panel::pagoManual', $f);
    $routes->post('pagos/(:num)/reintentar', 'Panel::pagoReintentar/$1', $f);
    $routes->post('pagos/(:num)/anular', 'Panel::pagoAnular/$1', $f);
    $routes->post('ajustes', 'Panel::ajustes', $f);
});

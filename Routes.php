<?php
/**
 * CENTRAL DE HIJAS (3-nov-2026, Joe).
 *
 * Vive SOLO en la madre tecnova (repo privado harosolution/central) y NO forma parte del CRM:
 * el CRM la carga con App\Libraries\Extensiones solo si su .env la nombra (extensiones.rutas).
 * Ninguna hija la tiene.
 *
 *   /central                 panel (super_admin de la empresa de la madre)
 *   POST /central/api/latido el agente de cada hija avisa que está viva y qué versiones tiene
 *   GET  /central/api/estado  la ⚙️ de cada hija pregunta si está al día
 *
 * @var \CodeIgniter\Router\RouteCollection $routes
 */
service('autoloader')->addNamespace('Central', __DIR__ . '/src');

$routes->group('central', ['namespace' => 'Central\Controllers'], static function ($routes) {
    // Con llave de la hija (Authorization: Bearer …), sin sesión.
    $routes->post('api/latido', 'Api::latido');
    $routes->get('api/estado', 'Api::estado');

    // Panel: sesión del CRM + super_admin de la madre (lo revisa el controlador).
    $routes->get('/', 'Panel::index', ['filter' => 'adminauth']);
    $routes->post('hijas', 'Panel::crear', ['filter' => 'adminauth']);
    $routes->post('hijas/(:num)', 'Panel::editar/$1', ['filter' => 'adminauth']);
    $routes->post('hijas/(:num)/llave', 'Panel::llave/$1', ['filter' => 'adminauth']);
});

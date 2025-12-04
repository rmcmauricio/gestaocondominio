<?php
/**
 * Application Routes
 * 
 * Define your application routes here using the router instance.
 * 
 * Example routes:
 * $router->get('/', 'App\Controllers\HomeController@index');
 * $router->post('/submit', 'App\Controllers\ExampleController@submit');
 */

// Home route
$router->get('/', 'App\Controllers\HomeController@index');

// Demo route
$router->get('/demo', 'App\Controllers\DemoController@index');

// About route
$router->get('/about', 'App\Controllers\AboutController@index');

// Language routes
$router->get('/lang/{lang}', 'App\Controllers\LanguageController@switch');

// Authentication routes
$router->get('/login', 'App\Controllers\AuthController@login');
$router->post('/login/process', 'App\Controllers\AuthController@processLogin');
$router->get('/logout', 'App\Controllers\AuthController@logout');

// Add your routes here


<?php
/**
 * Support Tickets addon routes.
 */
if (!isset($router)) {
    return;
}
$router->get('/admin/tickets', 'Addons\SupportTickets\Controllers\TicketController@index');
$router->get('/admin/tickets/create', 'Addons\SupportTickets\Controllers\TicketController@create');
$router->post('/admin/tickets', 'Addons\SupportTickets\Controllers\TicketController@store');
$router->post('/admin/tickets/upload-image', 'Addons\SupportTickets\Controllers\TicketController@uploadInlineImage');
$router->get('/admin/tickets/{id}', 'Addons\SupportTickets\Controllers\TicketController@show');
$router->post('/admin/tickets/{id}/reply', 'Addons\SupportTickets\Controllers\TicketController@reply');
$router->post('/admin/tickets/{id}/status', 'Addons\SupportTickets\Controllers\TicketController@updateStatus');

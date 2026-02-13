<?php
/**
 * Help Chatbot addon routes.
 * $router is available in scope (required from main routes.php).
 */
if (!isset($router)) {
    return;
}
$router->get('/admin/help', 'Addons\HelpChatbot\Controllers\HelpController@index');
$router->get('/admin/help/search', 'Addons\HelpChatbot\Controllers\HelpController@search');
$router->post('/admin/help/search', 'Addons\HelpChatbot\Controllers\HelpController@search');

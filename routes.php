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
$router->get('/register', 'App\Controllers\AuthController@register');
$router->post('/register/process', 'App\Controllers\AuthController@processRegister');
$router->get('/forgot-password', 'App\Controllers\AuthController@forgotPassword');
$router->post('/forgot-password/process', 'App\Controllers\AuthController@processForgotPassword');
$router->get('/reset-password', 'App\Controllers\AuthController@resetPassword');
$router->post('/reset-password/process', 'App\Controllers\AuthController@processResetPassword');
$router->get('/logout', 'App\Controllers\AuthController@logout');

// Subscription routes
$router->get('/subscription', 'App\Controllers\SubscriptionController@index');
$router->get('/subscription/choose-plan', 'App\Controllers\SubscriptionController@choosePlan');
$router->post('/subscription/start-trial', 'App\Controllers\SubscriptionController@startTrial');
$router->post('/subscription/upgrade', 'App\Controllers\SubscriptionController@upgrade');
$router->post('/subscription/cancel', 'App\Controllers\SubscriptionController@cancel');
$router->post('/subscription/change-plan', 'App\Controllers\SubscriptionController@changePlan');
$router->post('/subscription/reactivate', 'App\Controllers\SubscriptionController@reactivate');

// Dashboard routes (to be implemented)
$router->get('/dashboard', 'App\Controllers\DashboardController@index');
$router->get('/admin', 'App\Controllers\DashboardController@admin');

// Condominium routes
$router->get('/condominiums', 'App\Controllers\CondominiumController@index');
$router->get('/condominiums/create', 'App\Controllers\CondominiumController@create');
$router->post('/condominiums', 'App\Controllers\CondominiumController@store');
$router->get('/condominiums/{id}', 'App\Controllers\CondominiumController@show');
$router->get('/condominiums/{id}/edit', 'App\Controllers\CondominiumController@edit');
$router->post('/condominiums/{id}', 'App\Controllers\CondominiumController@update');
$router->post('/condominiums/{id}/delete', 'App\Controllers\CondominiumController@delete');

// Fraction routes
$router->get('/condominiums/{condominium_id}/fractions', 'App\Controllers\FractionController@index');
$router->get('/condominiums/{condominium_id}/fractions/create', 'App\Controllers\FractionController@create');
$router->post('/condominiums/{condominium_id}/fractions', 'App\Controllers\FractionController@store');
$router->get('/condominiums/{condominium_id}/fractions/{id}/edit', 'App\Controllers\FractionController@edit');
$router->post('/condominiums/{condominium_id}/fractions/{id}', 'App\Controllers\FractionController@update');
$router->post('/condominiums/{condominium_id}/fractions/{id}/delete', 'App\Controllers\FractionController@delete');
$router->post('/condominiums/{condominium_id}/fractions/{id}/assign-self', 'App\Controllers\FractionController@assignToSelf');

// Invitation routes
$router->get('/condominiums/{condominium_id}/invitations/create', 'App\Controllers\InvitationController@create');
$router->post('/condominiums/{condominium_id}/invitations', 'App\Controllers\InvitationController@store');
$router->get('/invitation/accept', 'App\Controllers\InvitationController@accept');
$router->post('/invitation/accept', 'App\Controllers\InvitationController@processAccept');

// Payment routes
$router->get('/payments', 'App\Controllers\PaymentController@index');
$router->get('/payments/{subscription_id}/create', 'App\Controllers\PaymentController@create');
$router->post('/payments/{subscription_id}/process', 'App\Controllers\PaymentController@process');
$router->get('/payments/{subscription_id}/multibanco', 'App\Controllers\PaymentController@showMultibanco');
$router->get('/payments/{subscription_id}/mbway', 'App\Controllers\PaymentController@showMBWay');
$router->get('/payments/{subscription_id}/sepa', 'App\Controllers\PaymentController@showSEPA');

// Webhook routes
$router->post('/webhooks/payment', 'App\Controllers\WebhookController@payment');

// Finance routes
$router->get('/condominiums/{condominium_id}/finances', 'App\Controllers\FinanceController@index');
$router->get('/condominiums/{condominium_id}/budgets/create', 'App\Controllers\FinanceController@createBudget');
$router->post('/condominiums/{condominium_id}/budgets', 'App\Controllers\FinanceController@storeBudget');
$router->get('/condominiums/{condominium_id}/budgets/{id}', 'App\Controllers\FinanceController@showBudget');
$router->get('/condominiums/{condominium_id}/budgets/{id}/edit', 'App\Controllers\FinanceController@editBudget');
$router->post('/condominiums/{condominium_id}/budgets/{id}', 'App\Controllers\FinanceController@updateBudget');
$router->post('/condominiums/{condominium_id}/budgets/{id}/approve', 'App\Controllers\FinanceController@approveBudget');
$router->get('/condominiums/{condominium_id}/expenses/create', 'App\Controllers\FinanceController@createExpense');
$router->post('/condominiums/{condominium_id}/expenses', 'App\Controllers\FinanceController@storeExpense');
$router->get('/condominiums/{condominium_id}/fees', 'App\Controllers\FinanceController@fees');
$router->post('/condominiums/{condominium_id}/fees/generate', 'App\Controllers\FinanceController@generateFees');
$router->post('/condominiums/{condominium_id}/fees/{id}/mark-paid', 'App\Controllers\FinanceController@markFeeAsPaid');
$router->post('/condominiums/{condominium_id}/fees/{id}/add-payment', 'App\Controllers\FinanceController@addPayment');
$router->get('/condominiums/{condominium_id}/fees/{id}/details', 'App\Controllers\FinanceController@getFeeDetails');

// Report routes
$router->get('/condominiums/{condominium_id}/finances/historical-debts', 'App\Controllers\FinanceController@historicalDebts');
$router->post('/condominiums/{condominium_id}/finances/historical-debts', 'App\Controllers\FinanceController@storeHistoricalDebts');
$router->get('/condominiums/{condominium_id}/finances/reports', 'App\Controllers\ReportController@index');
$router->post('/condominiums/{condominium_id}/finances/reports/balance-sheet', 'App\Controllers\ReportController@balanceSheet');
$router->post('/condominiums/{condominium_id}/finances/reports/fees', 'App\Controllers\ReportController@feesReport');
$router->post('/condominiums/{condominium_id}/finances/reports/expenses', 'App\Controllers\ReportController@expensesReport');

// Document routes
$router->get('/condominiums/{condominium_id}/documents', 'App\Controllers\DocumentController@index');
$router->get('/condominiums/{condominium_id}/documents/create', 'App\Controllers\DocumentController@create');
$router->post('/condominiums/{condominium_id}/documents', 'App\Controllers\DocumentController@store');
$router->get('/condominiums/{condominium_id}/documents/{id}/download', 'App\Controllers\DocumentController@download');
$router->post('/condominiums/{condominium_id}/documents/{id}/delete', 'App\Controllers\DocumentController@delete');

// Occurrence routes
$router->get('/condominiums/{condominium_id}/occurrences', 'App\Controllers\OccurrenceController@index');
$router->get('/condominiums/{condominium_id}/occurrences/create', 'App\Controllers\OccurrenceController@create');
$router->post('/condominiums/{condominium_id}/occurrences', 'App\Controllers\OccurrenceController@store');
$router->get('/condominiums/{condominium_id}/occurrences/{id}', 'App\Controllers\OccurrenceController@show');
$router->post('/condominiums/{condominium_id}/occurrences/{id}/status', 'App\Controllers\OccurrenceController@updateStatus');
$router->post('/condominiums/{condominium_id}/occurrences/{id}/assign', 'App\Controllers\OccurrenceController@assign');

// Supplier routes
$router->get('/condominiums/{condominium_id}/suppliers', 'App\Controllers\SupplierController@index');
$router->get('/condominiums/{condominium_id}/suppliers/create', 'App\Controllers\SupplierController@create');
$router->post('/condominiums/{condominium_id}/suppliers', 'App\Controllers\SupplierController@store');
$router->get('/condominiums/{condominium_id}/suppliers/contracts', 'App\Controllers\SupplierController@contracts');
$router->get('/condominiums/{condominium_id}/suppliers/contracts/create', 'App\Controllers\SupplierController@createContract');
$router->post('/condominiums/{condominium_id}/suppliers/contracts', 'App\Controllers\SupplierController@storeContract');

// Space routes
$router->get('/condominiums/{condominium_id}/spaces', 'App\Controllers\SpaceController@index');
$router->get('/condominiums/{condominium_id}/spaces/create', 'App\Controllers\SpaceController@create');
$router->post('/condominiums/{condominium_id}/spaces', 'App\Controllers\SpaceController@store');
$router->get('/condominiums/{condominium_id}/spaces/{id}/edit', 'App\Controllers\SpaceController@edit');
$router->post('/condominiums/{condominium_id}/spaces/{id}', 'App\Controllers\SpaceController@update');
$router->post('/condominiums/{condominium_id}/spaces/{id}/delete', 'App\Controllers\SpaceController@delete');

// Reservation routes
$router->get('/condominiums/{condominium_id}/reservations', 'App\Controllers\ReservationController@index');
$router->get('/condominiums/{condominium_id}/reservations/create', 'App\Controllers\ReservationController@create');
$router->post('/condominiums/{condominium_id}/reservations', 'App\Controllers\ReservationController@store');
$router->post('/condominiums/{condominium_id}/reservations/{id}/approve', 'App\Controllers\ReservationController@approve');
$router->post('/condominiums/{condominium_id}/reservations/{id}/reject', 'App\Controllers\ReservationController@reject');

// Assembly routes
$router->get('/condominiums/{condominium_id}/assemblies', 'App\Controllers\AssemblyController@index');
$router->get('/condominiums/{condominium_id}/assemblies/create', 'App\Controllers\AssemblyController@create');
$router->post('/condominiums/{condominium_id}/assemblies', 'App\Controllers\AssemblyController@store');
$router->get('/condominiums/{condominium_id}/assemblies/{id}', 'App\Controllers\AssemblyController@show');
$router->post('/condominiums/{condominium_id}/assemblies/{id}/send-convocation', 'App\Controllers\AssemblyController@sendConvocation');
$router->post('/condominiums/{condominium_id}/assemblies/{id}/attendance', 'App\Controllers\AssemblyController@registerAttendance');
$router->get('/condominiums/{condominium_id}/assemblies/{id}/minutes', 'App\Controllers\AssemblyController@generateMinutes');

// Vote routes
$router->get('/condominiums/{condominium_id}/assemblies/{assembly_id}/votes/create-topic', 'App\Controllers\VoteController@createTopic');
$router->post('/condominiums/{condominium_id}/assemblies/{assembly_id}/votes/topics', 'App\Controllers\VoteController@storeTopic');
$router->post('/condominiums/{condominium_id}/assemblies/{assembly_id}/votes/{topic_id}', 'App\Controllers\VoteController@vote');
$router->get('/condominiums/{condominium_id}/assemblies/{assembly_id}/votes/{topic_id}/results', 'App\Controllers\VoteController@results');

// Message routes
$router->get('/condominiums/{condominium_id}/messages', 'App\Controllers\MessageController@index');
$router->get('/condominiums/{condominium_id}/messages/create', 'App\Controllers\MessageController@create');
$router->post('/condominiums/{condominium_id}/messages', 'App\Controllers\MessageController@store');
$router->get('/condominiums/{condominium_id}/messages/{id}', 'App\Controllers\MessageController@show');

// API Key management routes
$router->get('/api-keys', 'App\Controllers\ApiKeyController@index');
$router->get('/api/documentation', 'App\Controllers\ApiKeyController@documentation');
$router->post('/api-keys/generate', 'App\Controllers\ApiKeyController@generate');
$router->post('/api-keys/revoke', 'App\Controllers\ApiKeyController@revoke');

// API REST routes
$router->get('/api/condominiums', 'App\Controllers\Api\CondominiumApiController@index');
$router->get('/api/condominiums/{id}', 'App\Controllers\Api\CondominiumApiController@show');
$router->get('/api/condominiums/{condominium_id}/fees', 'App\Controllers\Api\FeeApiController@index');
$router->get('/api/fractions/{fraction_id}/fees', 'App\Controllers\Api\FeeApiController@byFraction');

// Add your routes here


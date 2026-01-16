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

// Storage route (must be before other routes to catch storage requests)
// Use catch-all pattern: /storage/{path...} where path can contain slashes
$router->get('/storage/{path}', 'App\Controllers\StorageController@serve');

// Home route
$router->get('/', 'App\Controllers\HomeController@index');

// Demo route
$router->get('/demo', 'App\Controllers\DemoController@index');
$router->get('/demo/access', 'App\Controllers\AuthController@demoAccess');
$router->get('/demo/switch-profile', 'App\Controllers\DemoController@switchProfile');

// About route
$router->get('/about', 'App\Controllers\AboutController@index');

// Legal pages routes
$router->get('/faq', 'App\Controllers\LegalController@faq');
$router->get('/termos', 'App\Controllers\LegalController@terms');
$router->get('/privacidade', 'App\Controllers\LegalController@privacy');
$router->get('/cookies', 'App\Controllers\LegalController@cookies');

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

// Google OAuth routes
$router->get('/auth/google', 'App\Controllers\AuthController@googleAuth');
$router->get('/auth/google/callback', 'App\Controllers\AuthController@googleCallback');

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

// Profile routes
$router->get('/profile', 'App\Controllers\ProfileController@show');
$router->post('/profile/update', 'App\Controllers\ProfileController@update');
$router->post('/profile/password', 'App\Controllers\ProfileController@updatePassword');

// Notification routes
$router->get('/notifications', 'App\Controllers\NotificationController@index');
$router->post('/notifications/{id}/mark-read', 'App\Controllers\NotificationController@markAsRead');
$router->post('/notifications/mark-all-read', 'App\Controllers\NotificationController@markAllAsRead');
$router->post('/notifications/{id}/delete', 'App\Controllers\NotificationController@delete');
$router->get('/notifications/unread-count', 'App\Controllers\NotificationController@getUnreadCount');

// Condominium routes - redirect to dashboard (dashboard now shows condominiums list)
// $router->get('/condominiums', 'App\Controllers\CondominiumController@index'); // Removed - dashboard replaces this
$router->get('/condominiums/create', 'App\Controllers\CondominiumController@create');
$router->post('/condominiums', 'App\Controllers\CondominiumController@store');
$router->get('/condominiums/{id}', 'App\Controllers\CondominiumController@show');
$router->get('/condominiums/{id}/edit', 'App\Controllers\CondominiumController@edit');
$router->post('/condominiums/{id}', 'App\Controllers\CondominiumController@update');
$router->post('/condominiums/{id}/delete', 'App\Controllers\CondominiumController@delete');
$router->get('/condominiums/switch/{id}', 'App\Controllers\CondominiumController@switch');
$router->post('/condominiums/{id}/set-default', 'App\Controllers\CondominiumController@setDefault');

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
$router->post('/condominiums/{condominium_id}/fees/bulk-mark-paid', 'App\Controllers\FinanceController@bulkMarkFeesAsPaid');
$router->post('/condominiums/{condominium_id}/fees/{id}/add-payment', 'App\Controllers\FinanceController@addPayment');
$router->get('/condominiums/{condominium_id}/fees/{fee_id}/payments/{payment_id}', 'App\Controllers\FinanceController@getPayment');
$router->post('/condominiums/{condominium_id}/fees/{fee_id}/payments/{payment_id}/update', 'App\Controllers\FinanceController@updatePayment');
$router->post('/condominiums/{condominium_id}/fees/{fee_id}/payments/{payment_id}/delete', 'App\Controllers\FinanceController@deletePayment');
$router->get('/condominiums/{condominium_id}/fees/{id}/details', 'App\Controllers\FinanceController@getFeeDetails');
$router->get('/condominiums/{condominium_id}/fees/{id}/edit', 'App\Controllers\FinanceController@editFee');
$router->post('/condominiums/{condominium_id}/fees/{id}/update', 'App\Controllers\FinanceController@updateFee');
$router->post('/condominiums/{condominium_id}/fees/{id}/delete', 'App\Controllers\FinanceController@deleteFee');

// Receipt routes
$router->get('/condominiums/{condominium_id}/receipts', 'App\Controllers\ReceiptController@index');
$router->get('/receipts', 'App\Controllers\ReceiptController@myReceipts');
$router->get('/condominiums/{condominium_id}/receipts/{id}', 'App\Controllers\ReceiptController@show');
$router->get('/condominiums/{condominium_id}/receipts/{id}/download', 'App\Controllers\ReceiptController@download');

// Revenue routes
$router->get('/condominiums/{condominium_id}/finances/revenues', 'App\Controllers\FinanceController@revenues');
$router->get('/condominiums/{condominium_id}/finances/revenues/create', 'App\Controllers\FinanceController@createRevenue');
$router->post('/condominiums/{condominium_id}/finances/revenues/store', 'App\Controllers\FinanceController@storeRevenue');
$router->get('/condominiums/{condominium_id}/finances/revenues/{id}/edit', 'App\Controllers\FinanceController@editRevenue');
$router->post('/condominiums/{condominium_id}/finances/revenues/{id}/update', 'App\Controllers\FinanceController@updateRevenue');
$router->post('/condominiums/{condominium_id}/finances/revenues/{id}/delete', 'App\Controllers\FinanceController@deleteRevenue');

// Report routes
$router->get('/condominiums/{condominium_id}/finances/historical-debts', 'App\Controllers\FinanceController@historicalDebts');
$router->post('/condominiums/{condominium_id}/finances/historical-debts', 'App\Controllers\FinanceController@storeHistoricalDebts');
$router->get('/condominiums/{condominium_id}/finances/reports', 'App\Controllers\ReportController@index');
$router->post('/condominiums/{condominium_id}/finances/reports/balance-sheet', 'App\Controllers\ReportController@balanceSheet');
$router->post('/condominiums/{condominium_id}/finances/reports/fees', 'App\Controllers\ReportController@feesReport');
$router->post('/condominiums/{condominium_id}/finances/reports/expenses', 'App\Controllers\ReportController@expensesReport');
$router->post('/condominiums/{condominium_id}/finances/reports/cash-flow', 'App\Controllers\ReportController@cashFlow');
$router->post('/condominiums/{condominium_id}/finances/reports/budget-vs-actual', 'App\Controllers\ReportController@budgetVsActual');
$router->post('/condominiums/{condominium_id}/finances/reports/delinquency', 'App\Controllers\ReportController@delinquencyReport');
$router->post('/condominiums/{condominium_id}/finances/reports/occurrences', 'App\Controllers\ReportController@occurrenceReport');
$router->post('/condominiums/{condominium_id}/finances/reports/occurrences-by-supplier', 'App\Controllers\ReportController@occurrenceBySupplierReport');

// Document routes
$router->get('/condominiums/{condominium_id}/documents', 'App\Controllers\DocumentController@index');
$router->get('/condominiums/{condominium_id}/documents/create', 'App\Controllers\DocumentController@create');
$router->post('/condominiums/{condominium_id}/documents', 'App\Controllers\DocumentController@store');
$router->get('/condominiums/{condominium_id}/documents/{id}/view', 'App\Controllers\DocumentController@view');
$router->get('/condominiums/{condominium_id}/documents/{id}/edit', 'App\Controllers\DocumentController@edit');
$router->post('/condominiums/{condominium_id}/documents/{id}/update', 'App\Controllers\DocumentController@update');
$router->get('/condominiums/{condominium_id}/documents/{id}/versions', 'App\Controllers\DocumentController@versions');
$router->get('/condominiums/{condominium_id}/documents/{id}/upload-version', 'App\Controllers\DocumentController@uploadVersion');
$router->get('/condominiums/{condominium_id}/documents/{id}/download', 'App\Controllers\DocumentController@download');
$router->post('/condominiums/{condominium_id}/documents/{id}/delete', 'App\Controllers\DocumentController@delete');
$router->get('/condominiums/{condominium_id}/documents/manage-folders', 'App\Controllers\DocumentController@manageFolders');
$router->post('/condominiums/{condominium_id}/documents/folders/create', 'App\Controllers\DocumentController@createFolder');
$router->post('/condominiums/{condominium_id}/documents/folders/rename', 'App\Controllers\DocumentController@renameFolder');
$router->post('/condominiums/{condominium_id}/documents/folders/delete', 'App\Controllers\DocumentController@deleteFolder');

// Occurrence routes
$router->get('/condominiums/{condominium_id}/occurrences', 'App\Controllers\OccurrenceController@index');
$router->get('/condominiums/{condominium_id}/occurrences/create', 'App\Controllers\OccurrenceController@create');
$router->post('/condominiums/{condominium_id}/occurrences', 'App\Controllers\OccurrenceController@store');
$router->get('/condominiums/{condominium_id}/occurrences/{id}', 'App\Controllers\OccurrenceController@show');
$router->post('/condominiums/{condominium_id}/occurrences/{id}/status', 'App\Controllers\OccurrenceController@updateStatus');
$router->post('/condominiums/{condominium_id}/occurrences/{id}/assign', 'App\Controllers\OccurrenceController@assign');
$router->post('/condominiums/{condominium_id}/occurrences/{id}/comments', 'App\Controllers\OccurrenceController@addComment');
$router->post('/condominiums/{condominium_id}/occurrences/{id}/comments/{comment_id}/delete', 'App\Controllers\OccurrenceController@deleteComment');
$router->get('/condominiums/{condominium_id}/occurrences/{id}/attachments/{attachment_path}', 'App\Controllers\OccurrenceController@downloadAttachment');
$router->post('/condominiums/{condominium_id}/occurrences/upload-image', 'App\Controllers\OccurrenceController@uploadInlineImage');

// Supplier routes
$router->get('/condominiums/{condominium_id}/suppliers', 'App\Controllers\SupplierController@index');
$router->get('/condominiums/{condominium_id}/suppliers/create', 'App\Controllers\SupplierController@create');
$router->post('/condominiums/{condominium_id}/suppliers', 'App\Controllers\SupplierController@store');
$router->get('/condominiums/{condominium_id}/suppliers/{id}/edit', 'App\Controllers\SupplierController@edit');
$router->post('/condominiums/{condominium_id}/suppliers/{id}', 'App\Controllers\SupplierController@update');
$router->post('/condominiums/{condominium_id}/suppliers/{id}/delete', 'App\Controllers\SupplierController@delete');
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
$router->get('/condominiums/{condominium_id}/assemblies/{id}/edit', 'App\Controllers\AssemblyController@edit');
$router->post('/condominiums/{condominium_id}/assemblies/{id}/update', 'App\Controllers\AssemblyController@update');
$router->post('/condominiums/{condominium_id}/assemblies/{id}/start', 'App\Controllers\AssemblyController@start');
$router->post('/condominiums/{condominium_id}/assemblies/{id}/close', 'App\Controllers\AssemblyController@close');
$router->post('/condominiums/{condominium_id}/assemblies/{id}/cancel', 'App\Controllers\AssemblyController@cancel');
$router->post('/condominiums/{condominium_id}/assemblies/{id}/send-convocation', 'App\Controllers\AssemblyController@sendConvocation');
$router->post('/condominiums/{condominium_id}/assemblies/{id}/attendance', 'App\Controllers\AssemblyController@registerAttendance');
$router->get('/condominiums/{condominium_id}/assemblies/{id}/minutes', 'App\Controllers\AssemblyController@generateMinutes');
$router->get('/condominiums/{condominium_id}/assemblies/{id}/minutes/view', 'App\Controllers\AssemblyController@viewMinutes');
$router->get('/condominiums/{condominium_id}/assemblies/{id}/minutes-template/generate', 'App\Controllers\AssemblyController@generateMinutesTemplatePage');
$router->get('/condominiums/{condominium_id}/assemblies/{id}/minutes-template/edit', 'App\Controllers\AssemblyController@editMinutesTemplate');
$router->post('/condominiums/{condominium_id}/assemblies/{id}/minutes-template/update', 'App\Controllers\AssemblyController@updateMinutesTemplate');
$router->post('/condominiums/{condominium_id}/assemblies/{id}/minutes-template/approve', 'App\Controllers\AssemblyController@approveMinutes');
$router->get('/condominiums/{condominium_id}/assemblies/{id}/minutes-template/signatures', 'App\Controllers\AssemblyController@manageSignatures');
$router->post('/condominiums/{condominium_id}/assemblies/{id}/minutes-template/signatures/mark', 'App\Controllers\AssemblyController@markSignature');
$router->post('/condominiums/{condominium_id}/assemblies/{id}/change-status', 'App\Controllers\AssemblyController@changeStatus');

// Bank Accounts routes
$router->get('/condominiums/{condominium_id}/bank-accounts', 'App\Controllers\BankAccountController@index');
$router->get('/condominiums/{condominium_id}/bank-accounts/create', 'App\Controllers\BankAccountController@create');
$router->post('/condominiums/{condominium_id}/bank-accounts', 'App\Controllers\BankAccountController@store');
$router->get('/condominiums/{condominium_id}/bank-accounts/{id}/edit', 'App\Controllers\BankAccountController@edit');
$router->post('/condominiums/{condominium_id}/bank-accounts/{id}/update', 'App\Controllers\BankAccountController@update');
$router->post('/condominiums/{condominium_id}/bank-accounts/{id}/delete', 'App\Controllers\BankAccountController@delete');

// Financial Transactions routes
$router->get('/condominiums/{condominium_id}/financial-transactions', 'App\Controllers\FinancialTransactionController@index');
$router->get('/condominiums/{condominium_id}/financial-transactions/create', 'App\Controllers\FinancialTransactionController@create');
$router->post('/condominiums/{condominium_id}/financial-transactions', 'App\Controllers\FinancialTransactionController@store');
$router->get('/condominiums/{condominium_id}/financial-transactions/{id}/edit', 'App\Controllers\FinancialTransactionController@edit');
$router->post('/condominiums/{condominium_id}/financial-transactions/{id}/update', 'App\Controllers\FinancialTransactionController@update');
$router->post('/condominiums/{condominium_id}/financial-transactions/{id}/delete', 'App\Controllers\FinancialTransactionController@delete');
$router->get('/condominiums/{condominium_id}/financial-transactions/balance/{account_id}', 'App\Controllers\FinancialTransactionController@getAccountBalance');

// Vote routes
$router->get('/condominiums/{condominium_id}/assemblies/{assembly_id}/votes/create-topic', 'App\Controllers\VoteController@createTopic');
$router->post('/condominiums/{condominium_id}/assemblies/{assembly_id}/votes/topics', 'App\Controllers\VoteController@storeTopic');
$router->get('/condominiums/{condominium_id}/assemblies/{assembly_id}/votes/{topic_id}/edit', 'App\Controllers\VoteController@editTopic');
$router->post('/condominiums/{condominium_id}/assemblies/{assembly_id}/votes/{topic_id}/update', 'App\Controllers\VoteController@updateTopic');
$router->post('/condominiums/{condominium_id}/assemblies/{assembly_id}/votes/{topic_id}/delete', 'App\Controllers\VoteController@deleteTopic');
$router->post('/condominiums/{condominium_id}/assemblies/{assembly_id}/votes/{topic_id}/start', 'App\Controllers\VoteController@startVoting');
$router->post('/condominiums/{condominium_id}/assemblies/{assembly_id}/votes/{topic_id}/end', 'App\Controllers\VoteController@endVoting');
$router->post('/condominiums/{condominium_id}/assemblies/{assembly_id}/votes/{topic_id}', 'App\Controllers\VoteController@vote');
$router->post('/condominiums/{condominium_id}/assemblies/{assembly_id}/votes/{topic_id}/bulk', 'App\Controllers\VoteController@voteBulk');
$router->get('/condominiums/{condominium_id}/assemblies/{assembly_id}/votes/{topic_id}/results', 'App\Controllers\VoteController@results');

// Standalone Votes routes
$router->get('/condominiums/{id}/votes', 'App\Controllers\StandaloneVoteController@index');
$router->get('/condominiums/{id}/votes/create', 'App\Controllers\StandaloneVoteController@create');
$router->post('/condominiums/{id}/votes', 'App\Controllers\StandaloneVoteController@store');
$router->get('/condominiums/{id}/votes/{voteId}', 'App\Controllers\StandaloneVoteController@show');
$router->get('/condominiums/{id}/votes/{voteId}/edit', 'App\Controllers\StandaloneVoteController@edit');
$router->post('/condominiums/{id}/votes/{voteId}', 'App\Controllers\StandaloneVoteController@update');
$router->post('/condominiums/{id}/votes/{voteId}/start', 'App\Controllers\StandaloneVoteController@start');
$router->post('/condominiums/{id}/votes/{voteId}/close', 'App\Controllers\StandaloneVoteController@close');
$router->post('/condominiums/{id}/votes/{voteId}/delete', 'App\Controllers\StandaloneVoteController@delete');
$router->post('/condominiums/{id}/votes/{voteId}/vote', 'App\Controllers\StandaloneVoteController@vote');

// Vote Options routes
$router->get('/condominiums/{id}/vote-options', 'App\Controllers\VoteOptionController@index');
$router->post('/condominiums/{id}/vote-options', 'App\Controllers\VoteOptionController@store');
$router->post('/condominiums/{id}/vote-options/{optionId}', 'App\Controllers\VoteOptionController@update');
$router->post('/condominiums/{id}/vote-options/{optionId}/delete', 'App\Controllers\VoteOptionController@delete');

// Message routes
$router->get('/condominiums/{condominium_id}/messages', 'App\Controllers\MessageController@index');
$router->get('/condominiums/{condominium_id}/messages/create', 'App\Controllers\MessageController@create');
$router->post('/condominiums/{condominium_id}/messages', 'App\Controllers\MessageController@store');
$router->get('/condominiums/{condominium_id}/messages/{id}', 'App\Controllers\MessageController@show');
$router->post('/condominiums/{condominium_id}/messages/{id}/reply', 'App\Controllers\MessageController@reply');
$router->post('/condominiums/{condominium_id}/messages/upload-image', 'App\Controllers\MessageController@uploadInlineImage');
$router->get('/condominiums/{condominium_id}/messages/{message_id}/attachments/{attachment_id}/download', 'App\Controllers\MessageController@downloadAttachment');

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

// Help routes
$router->get('/help', 'App\Controllers\HelpController@index');
$router->get('/help/{section}', 'App\Controllers\HelpController@show');
$router->get('/help/{section}/modal', 'App\Controllers\HelpController@modal');

// Add your routes here


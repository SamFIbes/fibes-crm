<?php

use App\Http\Controllers\AccountPasswordController;
use App\Http\Controllers\AccountSecurityController;
use App\Http\Controllers\AccountSessionController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\ViewAsController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AutomationController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CallController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\CoverageController;
use App\Http\Controllers\CsatController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DispatchController;
use App\Http\Controllers\ExchangeRateController;
use App\Http\Controllers\GeographyController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\NetworkController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OpportunityController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\PublicQuoteController;
use App\Http\Controllers\PurchasingController;
use App\Http\Controllers\QaController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RetentionController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TechController;
use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/health', function () {
    try {
        DB::select('select 1 as ok');
        $database = 'ok';
    } catch (Throwable) {
        $database = 'down';
    }

    return response()->json([
        'status' => $database === 'ok' ? 'ok' : 'down',
        'database' => $database,
    ], $database === 'ok' ? 200 : 503);
})->name('health');
Route::get('/reports/exports/{export}', [ReportController::class, 'download'])->middleware('throttle:public-token')->name('reports.download');
Route::get('/exports/{token}', [ImportController::class, 'download'])->middleware('throttle:public-token')->name('exports.download');
Route::get('/tv/sales', [PerformanceController::class, 'tv'])->middleware('throttle:tv')->name('tv.sales');

Route::get('/fonts/arabic-400.woff2', function () {
    $path = base_path('node_modules/@fontsource/ibm-plex-sans-arabic/files/ibm-plex-sans-arabic-arabic-400-normal.woff2');
    abort_unless(is_file($path), 404);

    return response()->file($path, ['Content-Type' => 'font/woff2', 'Cache-Control' => 'public, max-age=604800']);
})->name('fonts.arabic');

Route::get('/offer/{quote}', [PublicQuoteController::class, 'show'])->middleware(['signed', 'throttle:30,1'])->name('quotes.public');
Route::post('/offer/{quote}/accept', [PublicQuoteController::class, 'accept'])->middleware(['signed', 'throttle:30,1'])->name('quotes.public.accept');
Route::post('/offer/{quote}/reject', [PublicQuoteController::class, 'reject'])->middleware(['signed', 'throttle:30,1'])->name('quotes.public.reject');
Route::get('/v/{token}', [PublicQuoteController::class, 'verify'])->middleware('throttle:30,1')->name('quotes.verify');
Route::get('/sign/{contract}', [PublicQuoteController::class, 'signForm'])->middleware(['signed', 'throttle:30,1'])->name('contracts.public');
Route::post('/sign/{contract}/otp', [PublicQuoteController::class, 'otp'])->middleware(['signed', 'throttle:30,1'])->name('contracts.public.otp');
Route::post('/sign/{contract}', [PublicQuoteController::class, 'sign'])->middleware(['signed', 'throttle:30,1'])->name('contracts.public.sign');

Route::get('/csat/{token}', [CsatController::class, 'show'])->middleware('throttle:30,1')->name('csat.show');
Route::post('/csat/{token}', [CsatController::class, 'store'])->middleware('throttle:30,1')->name('csat.store');

Route::post('/locale', [LocaleController::class, 'update'])
    ->middleware('throttle:30,1')
    ->name('locale.update');

require __DIR__.'/auth.php';

Route::middleware(['auth', 'account.security'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/dashboard/layout', [DashboardController::class, 'layout'])->name('dashboard.layout');
    Route::get('/imports', [ImportController::class, 'index'])->middleware('permission:imports.manage')->name('imports');
    Route::post('/imports/preview', [ImportController::class, 'preview'])->middleware('permission:imports.manage')->name('imports.preview');
    Route::post('/imports/krypton', [ImportController::class, 'krypton'])->middleware('permission:imports.manage')->name('imports.krypton');
    Route::post('/imports', [ImportController::class, 'apply'])->middleware('permission:imports.manage')->name('imports.apply');
    Route::post('/imports/{batch}/undo', [ImportController::class, 'undo'])->middleware('permission:imports.manage')->name('imports.undo');
    Route::post('/exports/customers', [ImportController::class, 'export'])->middleware(['permission:customers.view,reports.export', 'throttle:exports'])->name('exports.customers');
    Route::get('/automations', [AutomationController::class, 'index'])->middleware('permission:automations.view,automations.manage')->name('automations');
    Route::post('/automations', [AutomationController::class, 'store'])->middleware('permission:automations.manage')->name('automations.store');
    Route::post('/automations/pause', [AutomationController::class, 'pause'])->middleware('permission:automations.manage')->name('automations.pause');
    Route::post('/automations/webhooks', [AutomationController::class, 'webhook'])->middleware('permission:automations.manage')->name('automations.webhooks');
    Route::post('/automations/keys', [AutomationController::class, 'key'])->middleware('permission:automations.manage')->name('automations.keys');
    Route::post('/automations/{automation}/dry', [AutomationController::class, 'dry'])->middleware('permission:automations.manage')->name('automations.dry');
    Route::post('/automations/{automation}', [AutomationController::class, 'update'])->middleware('permission:automations.manage')->name('automations.update');
    Route::get('/reports', [ReportController::class, 'index'])->middleware('permission:reports.view')->name('reports');
    Route::get('/reports/{report}', [ReportController::class, 'show'])->middleware('permission:reports.view')->name('reports.show');
    Route::post('/reports/{report}/export', [ReportController::class, 'export'])->middleware('permission:reports.export')->name('reports.export');
    Route::post('/reports/{report}/schedule', [ReportController::class, 'schedule'])->middleware('permission:reports.view')->name('reports.schedule');
    Route::get('/leads', [LeadController::class, 'index'])->middleware('permission:leads.view')->name('leads');
    Route::get('/leads/matches', [LeadController::class, 'matches'])->middleware('permission:leads.view,leads.create')->name('leads.matches');
    Route::post('/leads', [LeadController::class, 'store'])->middleware('permission:leads.create')->name('leads.store');
    Route::post('/leads/import', [LeadController::class, 'import'])->middleware('permission:leads.create')->name('leads.import');
    Route::patch('/leads/{lead}', [LeadController::class, 'update'])->middleware('permission:leads.edit')->name('leads.update');
    Route::post('/leads/{lead}/claim', [LeadController::class, 'claim'])->middleware('permission:leads.claim')->name('leads.claim');
    Route::post('/leads/{lead}/assign', [LeadController::class, 'assign'])->middleware('permission:leads.assign')->name('leads.assign');
    Route::post('/leads/{lead}/transition', [LeadController::class, 'transition'])->middleware('permission:leads.edit,leads.assign')->name('leads.transition');
    Route::post('/lead-sources', [LeadController::class, 'storeSource'])->middleware('permission:leads.assign')->name('leads.sources');
    Route::post('/campaigns', [LeadController::class, 'storeCampaign'])->middleware('permission:leads.assign')->name('leads.campaigns');
    Route::post('/disqualify-reasons', [LeadController::class, 'storeReason'])->middleware('permission:leads.assign')->name('leads.reasons');
    Route::put('/lead-assignment', [LeadController::class, 'assignment'])->middleware('permission:leads.assign')->name('leads.assignment');
    Route::get('/opportunities', [OpportunityController::class, 'index'])->middleware('permission:opportunities.view,opportunities.view_won')->name('opportunities');
    Route::post('/opportunities/reassign', [OpportunityController::class, 'reassign'])->middleware('permission:opportunities.edit')->name('opportunities.reassign');
    Route::post('/opportunities/{opportunity}/stage', [OpportunityController::class, 'move'])->middleware('permission:opportunities.edit')->name('opportunities.stage');
    Route::get('/surveys', [ModuleController::class, 'surveys'])->middleware('permission:jobs.view,jobs.schedule,jobs.complete,jobs.request')->name('surveys');
    Route::get('/orders', [OrderController::class, 'index'])->middleware('permission:orders.create,orders.view,orders.activate,purchasing.create,purchasing.receive')->name('orders');
    Route::post('/orders', [OrderController::class, 'store'])->middleware('permission:orders.create')->name('orders.store');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->middleware('permission:orders.create,orders.view,orders.activate,jobs.schedule')->name('orders.show');
    Route::post('/orders/{order}/transition', [OrderController::class, 'transition'])->middleware('permission:orders.create,orders.activate,jobs.schedule')->name('orders.transition');
    Route::post('/orders/{order}/reserve', [OrderController::class, 'reserve'])->middleware('permission:inventory.reserve')->name('orders.reserve');
    Route::get('/purchasing/export', [OrderController::class, 'export'])->middleware(['permission:purchasing.create,inventory.view', 'throttle:exports'])->name('purchasing.export');
    Route::post('/purchasing', [PurchasingController::class, 'store'])->middleware('permission:purchasing.create')->name('purchasing.store');
    Route::post('/purchasing/{purchaseOrder}/submit', [PurchasingController::class, 'submit'])->middleware('permission:purchasing.create')->name('purchasing.submit');
    Route::post('/purchasing/{purchaseOrder}/send', [PurchasingController::class, 'send'])->middleware('permission:purchasing.create')->name('purchasing.send');
    Route::post('/purchasing/{purchaseOrder}/receive', [PurchasingController::class, 'receive'])->middleware('permission:purchasing.receive,purchasing.create')->name('purchasing.receive');
    Route::get('/quotes', [QuoteController::class, 'index'])->middleware('permission:quotes.create,quotes.approve,contracts.manage,contracts.view')->name('quotes');
    Route::post('/quotes', [QuoteController::class, 'store'])->middleware('permission:quotes.create')->name('quotes.store');
    Route::put('/quotes/{quote}', [QuoteController::class, 'update'])->middleware('permission:quotes.create')->name('quotes.update');
    Route::post('/quotes/{quote}/submit', [QuoteController::class, 'submit'])->middleware('permission:quotes.create')->name('quotes.submit');
    Route::post('/quotes/{quote}/revise', [QuoteController::class, 'revise'])->middleware('permission:quotes.create')->name('quotes.revise');
    Route::post('/quotes/{quote}/send', [QuoteController::class, 'send'])->middleware('permission:quotes.create')->name('quotes.send');
    Route::get('/quotes/{quote}/print', [QuoteController::class, 'print'])->middleware('permission:quotes.create,quotes.approve,contracts.manage,contracts.view')->name('quotes.print');
    Route::post('/quotes/{quote}/accept', [QuoteController::class, 'accept'])->middleware('permission:quotes.create,contracts.manage')->name('quotes.accept');
    Route::post('/quotes/{quote}/reject', [QuoteController::class, 'reject'])->middleware('permission:quotes.create,contracts.manage')->name('quotes.reject');
    Route::post('/quotes/{quote}/contract', [QuoteController::class, 'contract'])->middleware('permission:contracts.manage')->name('quotes.contract');
    Route::get('/contracts/{contract}/sign', [QuoteController::class, 'signForm'])->middleware('permission:contracts.manage,contracts.view')->name('contracts.sign.form');
    Route::post('/contracts/{contract}/otp', [QuoteController::class, 'otp'])->middleware('permission:contracts.manage')->name('contracts.otp');
    Route::post('/contracts/{contract}/sign', [QuoteController::class, 'sign'])->middleware('permission:contracts.manage')->name('contracts.sign');
    Route::post('/contracts/{contract}/scan', [QuoteController::class, 'scan'])->middleware('permission:contracts.manage')->name('contracts.scan');
    Route::get('/contracts/{contract}/print', [QuoteController::class, 'printContract'])->middleware('permission:contracts.manage,contracts.view')->name('contracts.print');
    Route::get('/settings/integrations', [IntegrationController::class, 'edit'])->middleware('permission:integrations.view,integrations.manage')->name('settings.integrations');
    Route::put('/settings/integrations', [IntegrationController::class, 'update'])->middleware('permission:integrations.manage')->name('settings.integrations.update');
    Route::post('/settings/integrations/{integration}/test', [IntegrationController::class, 'test'])->middleware('permission:integrations.manage')->name('settings.integrations.test');
    Route::get('/settings', [SettingsController::class, 'edit'])->middleware('permission:settings.view,settings.manage')->name('settings');
    Route::put('/settings', [SettingsController::class, 'update'])->middleware('permission:settings.manage')->name('settings.update');
    Route::put('/settings/commercial', [SettingsController::class, 'updateCommercial'])->middleware('permission:settings.manage')->name('settings.commercial');
    Route::post('/settings/holidays', [SettingsController::class, 'storeHoliday'])->middleware('permission:settings.manage')->name('settings.holidays.store');
    Route::patch('/settings/holidays/{holiday}', [SettingsController::class, 'updateHoliday'])->middleware('permission:settings.manage')->name('settings.holidays.update');
    Route::delete('/settings/holidays/{holiday}', [SettingsController::class, 'destroyHoliday'])->middleware('permission:settings.manage')->name('settings.holidays.destroy');
    Route::post('/settings/ticket-categories', [SettingsController::class, 'storeCategory'])->middleware('permission:settings.manage')->name('settings.categories.store');
    Route::put('/settings/escalation', [SettingsController::class, 'updateEscalation'])->middleware('permission:settings.manage')->name('settings.escalation');
    Route::put('/settings/tiers/{tier}', [SettingsController::class, 'updateTier'])->middleware('permission:settings.manage')->name('settings.tiers.update');
    Route::post('/settings/wrap-up-codes', [SettingsController::class, 'storeWrapUp'])->middleware('permission:settings.manage')->name('settings.wrap.store');
    Route::post('/settings/checklists', [SettingsController::class, 'storeChecklist'])->middleware('permission:settings.manage')->name('settings.checklists.store');

    Route::get('/geography', [GeographyController::class, 'index'])->middleware('permission:geography.view,geography.manage')->name('geography');
    Route::post('/geography/governorates', [GeographyController::class, 'storeGovernorate'])->middleware('permission:geography.manage')->name('geography.governorates.store');
    Route::put('/geography/governorates/{governorate}', [GeographyController::class, 'updateGovernorate'])->middleware('permission:geography.manage')->name('geography.governorates.update');
    Route::post('/geography/districts', [GeographyController::class, 'storeDistrict'])->middleware('permission:geography.manage')->name('geography.districts.store');
    Route::put('/geography/districts/{district}', [GeographyController::class, 'updateDistrict'])->middleware('permission:geography.manage')->name('geography.districts.update');
    Route::post('/geography/areas', [GeographyController::class, 'storeArea'])->middleware('permission:geography.manage')->name('geography.areas.store');
    Route::put('/geography/areas/{area}', [GeographyController::class, 'updateArea'])->middleware('permission:geography.manage')->name('geography.areas.update');
    Route::delete('/geography/areas/{area}', [GeographyController::class, 'destroyArea'])->middleware('permission:geography.manage')->name('geography.areas.destroy');

    Route::get('/rates', [ExchangeRateController::class, 'index'])->middleware('permission:rates.view,rates.manage')->name('rates');
    Route::get('/catalog', [CatalogController::class, 'index'])->middleware('permission:catalog.view')->name('catalog');
    Route::get('/catalog/products', [CatalogController::class, 'products'])->middleware('permission:catalog.view')->name('catalog.products');
    Route::post('/catalog/products', [CatalogController::class, 'storeProduct'])->middleware('permission:catalog.edit')->name('catalog.products.store');
    Route::post('/catalog/prices', [CatalogController::class, 'storePrice'])->middleware('permission:catalog.prices.edit')->name('catalog.prices.store');
    Route::post('/catalog/price-requests', [CatalogController::class, 'requestPrice'])->middleware('permission:catalog.prices.request')->name('catalog.prices.request');
    Route::post('/catalog/price-requests/{priceChangeRequest}/apply', [CatalogController::class, 'applyRequest'])->middleware('permission:catalog.prices.edit')->name('catalog.prices.apply');
    Route::post('/catalog/promotions', [CatalogController::class, 'storePromotion'])->middleware('permission:catalog.promotions.edit')->name('catalog.promotions.store');
    Route::post('/catalog/promotions/preview', [CatalogController::class, 'preview'])->middleware('permission:catalog.view')->name('catalog.promotions.preview');
    Route::post('/catalog/limits', [CatalogController::class, 'saveLimit'])->middleware('permission:catalog.edit')->name('catalog.limits');
    Route::post('/catalog/migrate', [CatalogController::class, 'migrate'])->middleware('permission:catalog.edit')->name('catalog.migrate');
    Route::post('/rates', [ExchangeRateController::class, 'store'])->middleware('permission:rates.manage')->name('rates.store');

    Route::get('/files', [AttachmentController::class, 'index'])->middleware('permission:attachments.view,attachments.upload')->name('files');
    Route::post('/files', [AttachmentController::class, 'store'])->middleware('permission:attachments.upload')->name('files.store');
    Route::get('/files/{attachment}/download', [AttachmentController::class, 'download'])->middleware(['signed', 'permission:attachments.view,attachments.upload'])->name('files.download');
    Route::delete('/files/{attachment}', [AttachmentController::class, 'destroy'])->middleware('permission:attachments.delete')->name('files.destroy');

    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::post('/notifications/read', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('/customers', [CustomerController::class, 'index'])->middleware('permission:customers.view')->name('customers');
    Route::post('/customers', [CustomerController::class, 'store'])->middleware('permission:customers.create')->name('customers.store');
    Route::post('/customer-views', [CustomerController::class, 'storeView'])->middleware('permission:customers.view')->name('customers.views.store');
    Route::delete('/customer-views/{view}', [CustomerController::class, 'destroyView'])->middleware('permission:customers.view')->name('customers.views.destroy');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->middleware('permission:customers.view')->name('customers.show');
    Route::patch('/customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:customers.edit')->name('customers.update');
    Route::post('/customers/{customer}/services', [CustomerController::class, 'storeService'])->middleware('permission:customers.edit,services.technical.edit')->name('customers.services.store');
    Route::post('/customers/{customer}/services/{service}/reveal', [CustomerController::class, 'reveal'])->middleware('permission:services.radius_password.reveal')->name('customers.services.reveal');
    Route::post('/customers/{customer}/services/{service}/radius', [CustomerController::class, 'radiusSession'])->middleware('permission:services.radius_fields.view,services.radius_fields.edit')->name('customers.services.radius');
    Route::post('/customers/{customer}/whatsapp/renewal', [CustomerController::class, 'whatsappRenewal'])->middleware('permission:customers.view')->name('customers.whatsapp.renewal');
    Route::post('/customers/{customer}/notes', [CustomerController::class, 'storeNote'])->middleware('permission:customers.edit')->name('customers.notes.store');
    Route::post('/customers/{customer}/files', [CustomerController::class, 'storeFile'])->middleware('permission:attachments.upload')->name('customers.files.store');
    Route::get('/tickets', [TicketController::class, 'index'])->middleware('permission:tickets.view')->name('tickets');
    Route::get('/tickets/lookup', [TicketController::class, 'lookup'])->middleware('permission:tickets.create')->name('tickets.lookup');
    Route::post('/tickets', [TicketController::class, 'store'])->middleware('permission:tickets.create')->name('tickets.store');
    Route::post('/ticket-views', [TicketController::class, 'storeView'])->middleware('permission:tickets.view')->name('tickets.views.store');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->middleware('permission:tickets.view')->name('tickets.show');
    Route::patch('/tickets/{ticket}/status', [TicketController::class, 'updateStatus'])->middleware('permission:tickets.view')->name('tickets.status');
    Route::post('/tickets/{ticket}/escalate', [TicketController::class, 'escalate'])->middleware('permission:tickets.escalate')->name('tickets.escalate');
    Route::post('/tickets/{ticket}/messages', [TicketController::class, 'reply'])->middleware('permission:tickets.view')->name('tickets.messages');
    Route::post('/tickets/{ticket}/whatsapp', [TicketController::class, 'whatsapp'])->middleware('permission:tickets.view')->name('tickets.whatsapp');
    Route::post('/tickets/{ticket}/files', [TicketController::class, 'storeFile'])->middleware('permission:attachments.upload')->name('tickets.files');
    Route::get('/incidents', [IncidentController::class, 'index'])->middleware('permission:incidents.manage')->name('incidents');
    Route::post('/incidents', [IncidentController::class, 'store'])->middleware('permission:incidents.manage')->name('incidents.store');
    Route::get('/incidents/{incident}', [IncidentController::class, 'show'])->middleware('permission:incidents.manage')->name('incidents.show');
    Route::post('/incidents/{incident}/tickets', [IncidentController::class, 'link'])->middleware('permission:incidents.manage')->name('incidents.link');
    Route::delete('/incidents/{incident}/tickets/{ticket}', [IncidentController::class, 'unlink'])->middleware('permission:incidents.manage')->name('incidents.unlink');
    Route::post('/incidents/{incident}/link-matching', [IncidentController::class, 'linkMatching'])->middleware('permission:incidents.manage')->name('incidents.link-matching');
    Route::post('/incidents/{incident}/resolve', [IncidentController::class, 'resolve'])->middleware('permission:incidents.manage')->name('incidents.resolve');
    Route::get('/calls/pop', [CallController::class, 'pop'])->middleware('permission:calls.log,calls.view')->name('calls.pop');
    Route::get('/calls/board', [CallController::class, 'board'])->middleware('permission:calls.view')->name('calls.board');
    Route::get('/calls', [CallController::class, 'index'])->middleware('permission:calls.log,calls.view')->name('calls');
    Route::get('/performance/export', [PerformanceController::class, 'export'])->middleware(['permission:commissions.view', 'throttle:exports'])->name('performance.export');
    Route::get('/performance', [PerformanceController::class, 'index'])->middleware('permission:commissions.view,targets.set')->name('performance');
    Route::post('/performance/targets', [PerformanceController::class, 'target'])->middleware('permission:targets.set')->name('performance.targets');
    Route::post('/performance/adjustments', [PerformanceController::class, 'adjust'])->middleware('permission:commissions.view')->name('performance.adjustments');
    Route::post('/performance/tv-token', [PerformanceController::class, 'token'])->middleware('permission:targets.set,settings.manage')->name('performance.token');
    Route::get('/qa', [QaController::class, 'index'])->middleware('permission:qa.score,qa.view')->name('qa');
    Route::post('/qa/score', [QaController::class, 'score'])->middleware('permission:qa.score')->name('qa.score');
    Route::post('/qa/sample', [QaController::class, 'sample'])->middleware('permission:qa.score')->name('qa.sample');
    Route::post('/qa/forms', [QaController::class, 'revise'])->middleware('permission:qa.score')->name('qa.forms');
    Route::post('/qa/status', [QaController::class, 'status'])->middleware('permission:qa.score,qa.view')->name('qa.status');
    Route::post('/qa/{evaluation}/acknowledge', [QaController::class, 'acknowledge'])->middleware('permission:qa.view,qa.score')->name('qa.acknowledge');
    Route::post('/calls', [CallController::class, 'store'])->middleware('permission:calls.log')->name('calls.store');
    Route::post('/calls/leads', [CallController::class, 'storeLead'])->middleware('permission:leads.create')->name('calls.leads');
    Route::post('/calls/duty', [CallController::class, 'duty'])->middleware('permission:calls.log,calls.view')->name('calls.duty');
    Route::post('/calls/extension', [CallController::class, 'extension'])->middleware('permission:calls.log,calls.view')->name('calls.extension');
    Route::post('/calls/dial', [CallController::class, 'dial'])->middleware('permission:calls.log')->name('calls.dial');
    Route::patch('/calls/customers/{customer}', [CallController::class, 'updateContact'])->middleware('permission:customers.edit')->name('calls.contact');
    Route::get('/dispatch', [DispatchController::class, 'index'])->middleware('permission:jobs.schedule')->name('dispatch');
    Route::post('/dispatch', [DispatchController::class, 'store'])->middleware('permission:jobs.schedule,jobs.request')->name('dispatch.store');
    Route::patch('/dispatch/jobs/{job}', [DispatchController::class, 'update'])->middleware('permission:jobs.schedule')->name('dispatch.update');
    Route::post('/dispatch/technicians/{user}/skills', [DispatchController::class, 'skills'])->middleware('permission:jobs.schedule')->name('dispatch.skills');
    Route::get('/tech', [TechController::class, 'index'])->middleware('permission:jobs.view,jobs.complete')->name('tech');
    Route::post('/tech/confirm', [TechController::class, 'confirm'])->middleware('permission:jobs.view,jobs.complete,payments.collect')->name('tech.confirm');
    Route::post('/tech/jobs/{job}/status', [TechController::class, 'status'])->middleware('permission:jobs.view,jobs.complete,jobs.schedule')->name('tech.status');
    Route::post('/tech/jobs/{job}/checks/{check}', [TechController::class, 'check'])->middleware('permission:jobs.view,jobs.complete')->name('tech.check');
    Route::patch('/tech/jobs/{job}', [TechController::class, 'notes'])->middleware('permission:jobs.view,jobs.complete')->name('tech.notes');
    Route::post('/tech/jobs/{job}/photos', [TechController::class, 'photo'])->middleware('permission:jobs.view,jobs.complete')->name('tech.photo');
    Route::post('/tech/jobs/{job}/signature', [TechController::class, 'signature'])->middleware('permission:jobs.view,jobs.complete')->name('tech.signature');
    Route::post('/tech/jobs/{job}/stock', [TechController::class, 'stock'])->middleware('permission:jobs.view,jobs.complete')->name('tech.stock');
    Route::post('/tech/jobs/{job}/survey', [TechController::class, 'survey'])->middleware('permission:jobs.view,jobs.complete')->name('tech.survey');
    Route::post('/tech/cash-unlock', [TechController::class, 'unlockCash'])->middleware('permission:payments.collect')->name('tech.cash.unlock');
    Route::post('/tech/jobs/{job}/cash', [TechController::class, 'cash'])->middleware('permission:payments.collect')->name('tech.cash');
    Route::get('/coverage', [CoverageController::class, 'index'])->middleware('permission:coverage.view,coverage.check,coverage.edit')->name('coverage');
    Route::post('/coverage/checks', [CoverageController::class, 'check'])->middleware('permission:coverage.check')->name('coverage.check');
    Route::post('/coverage/items', [CoverageController::class, 'store'])->middleware('permission:coverage.edit')->name('coverage.store');
    Route::post('/coverage/items/{item}/publish', [CoverageController::class, 'publish'])->middleware('permission:coverage.edit')->name('coverage.publish');
    Route::post('/coverage/import', [CoverageController::class, 'import'])->middleware('permission:coverage.edit')->name('coverage.import');
    Route::get('/inventory', [InventoryController::class, 'index'])->middleware('permission:inventory.view,inventory.move')->name('inventory');
    Route::post('/inventory/receive', [InventoryController::class, 'receive'])->middleware('permission:inventory.move')->name('inventory.receive');
    Route::post('/inventory/transfer', [InventoryController::class, 'transfer'])->middleware('permission:inventory.move')->name('inventory.transfer');
    Route::post('/inventory/return', [InventoryController::class, 'putBack'])->middleware('permission:inventory.move')->name('inventory.return');
    Route::post('/inventory/write-off', [InventoryController::class, 'writeOff'])->middleware('permission:inventory.adjust')->name('inventory.write-off');
    Route::post('/inventory/reservations', [InventoryController::class, 'reserve'])->middleware('permission:inventory.reserve')->name('inventory.reserve');
    Route::post('/inventory/counts', [InventoryController::class, 'count'])->middleware('permission:inventory.view,inventory.move')->name('inventory.count');
    Route::post('/inventory/issue', [InventoryController::class, 'issue'])->middleware('permission:inventory.move')->name('inventory.issue');
    Route::post('/inventory/erp-request', [InventoryController::class, 'requestFromErp'])->middleware('permission:inventory.move')->name('inventory.erp');
    Route::get('/network', [NetworkController::class, 'index'])->middleware('permission:services.technical.view,services.technical.edit,coverage.view,jobs.schedule')->name('network');
    Route::post('/network/nodes', [NetworkController::class, 'store'])->middleware('permission:services.technical.edit')->name('network.store');
    Route::post('/tickets/{ticket}/csat', [CsatController::class, 'manual'])->middleware('permission:tickets.view')->name('tickets.csat');
    Route::post('/tickets/{ticket}/checks/{check}', [TicketController::class, 'toggleCheck'])->middleware('permission:tickets.view')->name('tickets.check');
    Route::get('/tasks', [TaskController::class, 'index'])->middleware('permission:tasks.view')->name('tasks');
    Route::post('/tasks', [TaskController::class, 'store'])->middleware('permission:tasks.create')->name('tasks.store');
    Route::post('/task-rules', [TaskController::class, 'storeRule'])->middleware('permission:tasks.create')->name('tasks.rules');
    Route::patch('/tasks/{task}', [TaskController::class, 'reschedule'])->middleware('permission:tasks.edit')->name('tasks.reschedule');
    Route::post('/tasks/{task}/done', [TaskController::class, 'complete'])->middleware('permission:tasks.edit')->name('tasks.done');
    Route::post('/calendar/move', [TaskController::class, 'moveEvent'])->middleware('permission:tasks.view')->name('calendar.move');
    Route::get('/approvals', [ApprovalController::class, 'index'])->middleware('permission:approvals.view,approvals.decide,approvals.request')->name('approvals');
    Route::post('/approvals', [ApprovalController::class, 'store'])->middleware('permission:approvals.request')->name('approvals.store');
    Route::post('/approvals/bulk', [ApprovalController::class, 'bulk'])->middleware('permission:approvals.decide')->name('approvals.bulk');
    Route::post('/approvals/delegations', [ApprovalController::class, 'delegate'])->middleware('permission:approvals.decide')->name('approvals.delegate');
    Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve'])->middleware('permission:approvals.decide')->name('approvals.approve');
    Route::post('/approvals/{approval}/reject', [ApprovalController::class, 'reject'])->middleware('permission:approvals.decide')->name('approvals.reject');
    Route::get('/billing', [BillingController::class, 'index'])->middleware('permission:invoices.view,invoices.view_balance,billing.view_amounts')->name('billing');
    Route::post('/billing/run', [BillingController::class, 'run'])->middleware('permission:invoices.issue')->name('billing.run');
    Route::post('/billing/refunds', [BillingController::class, 'refund'])->middleware('permission:payments.reverse')->name('billing.refunds');
    Route::post('/payments', [PaymentController::class, 'store'])->middleware('permission:payments.record,payments.request')->name('payments.store');
    Route::post('/payments/{payment}/confirm', [PaymentController::class, 'confirm'])->middleware('permission:payments.record')->name('payments.confirm');
    Route::post('/payments/{payment}/reverse', [PaymentController::class, 'reverse'])->middleware('permission:payments.reverse')->name('payments.reverse');
    Route::get('/payments/{payment}', [PaymentController::class, 'receipt'])->middleware('permission:payments.record,payments.collect,billing.view_amounts')->name('payments.receipt');
    Route::post('/cash-sessions/{session}/close', [PaymentController::class, 'close'])->middleware('permission:payments.record,payments.collect')->name('cash.close');
    Route::get('/customers/{customer}/statement', [PaymentController::class, 'statement'])->middleware('permission:billing.view_amounts')->name('customers.statement');
    Route::get('/collections', [CollectionController::class, 'index'])->middleware('permission:services.suspend,services.request_suspend')->name('collections');
    Route::post('/collections/run', [CollectionController::class, 'run'])->middleware('permission:services.suspend')->name('collections.run');
    Route::post('/collections/{case}/promise', [CollectionController::class, 'promise'])->middleware('permission:services.suspend')->name('collections.promise');
    Route::post('/collections/{case}/assign', [CollectionController::class, 'assign'])->middleware('permission:services.suspend')->name('collections.assign');
    Route::post('/collections/{case}/suspend', [CollectionController::class, 'suspend'])->middleware('permission:services.suspend')->name('collections.suspend');
    Route::post('/collections/{case}/terminate', [CollectionController::class, 'terminate'])->middleware('permission:services.suspend')->name('collections.terminate');
    Route::post('/collections/{case}/reactivate', [CollectionController::class, 'reactivate'])->middleware('permission:services.suspend')->name('collections.reactivate');
    Route::post('/collections/{case}/write-off', [CollectionController::class, 'writeOff'])->middleware('permission:payments.write_off')->name('collections.write-off');
    Route::post('/customers/{customer}/exemption', [CollectionController::class, 'exempt'])->middleware('permission:services.suspend,services.request_suspend')->name('collections.exempt');
    Route::get('/retention', [RetentionController::class, 'index'])->middleware('permission:retention.offer,retention.request')->name('retention');
    Route::post('/retention/{case}/offer', [RetentionController::class, 'offer'])->middleware('permission:retention.offer,retention.request')->name('retention.offer');
    Route::post('/retention/{case}/decline', [RetentionController::class, 'decline'])->middleware('permission:retention.offer,retention.request')->name('retention.decline');
    Route::get('/invoices/{invoice}', [BillingController::class, 'print'])->middleware('permission:invoices.view,billing.view_amounts')->name('invoices.print');
    Route::post('/invoices/{invoice}/credit', [BillingController::class, 'credit'])->middleware('permission:invoices.issue')->name('invoices.credit');
    Route::post('/invoices/{invoice}/void', [BillingController::class, 'void'])->middleware('permission:invoices.issue')->name('invoices.void');
    Route::post('/invoices/{invoice}/write-off', [BillingController::class, 'writeOff'])->middleware('permission:payments.write_off')->name('invoices.write-off');
    Route::post('/invoices/{invoice}/whish', [BillingController::class, 'whish'])->middleware('permission:payments.record')->name('invoices.whish');
    Route::post('/invoices/{invoice}/whatsapp', [BillingController::class, 'whatsapp'])->middleware('permission:payments.record')->name('invoices.whatsapp');
    Route::get('/audit', [ModuleController::class, 'audit'])->middleware('permission:audit.view')->name('audit');

    Route::middleware('permission:users.view,users.manage')->group(function () {
        Route::get('/admin/users', [UserController::class, 'index'])->name('admin.users.index');
        Route::get('/admin/users/{user}/edit', [UserController::class, 'edit'])->whereNumber('user')->name('admin.users.edit');
        Route::get('/admin/roles', [RoleController::class, 'index'])->name('admin.roles.index');
        Route::get('/admin/roles/{role}/edit', [RoleController::class, 'edit'])->whereNumber('role')->name('admin.roles.edit');
    });

    Route::middleware('permission:users.manage')->group(function () {
        Route::get('/admin/users/create', [UserController::class, 'create'])->name('admin.users.create');
        Route::post('/admin/users', [UserController::class, 'store'])->name('admin.users.store');
        Route::put('/admin/users/{user}', [UserController::class, 'update'])->whereNumber('user')->name('admin.users.update');
        Route::post('/admin/roles', [RoleController::class, 'store'])->name('admin.roles.store');
        Route::put('/admin/roles/{role}', [RoleController::class, 'update'])->whereNumber('role')->name('admin.roles.update');
        Route::post('/admin/roles/{role}/clone', [RoleController::class, 'clone'])->whereNumber('role')->name('admin.roles.clone');
        Route::post('/admin/view-as', [ViewAsController::class, 'store'])->name('admin.view-as.store');
    });

    // Not behind users.manage: while viewing as someone, permissions are the viewed
    // user's, so the "Stop viewing" button must work without them. It only clears
    // the view-as marker from the caller's own session.
    Route::delete('/admin/view-as', [ViewAsController::class, 'destroy'])->name('admin.view-as.stop');

    Route::get('/account/password', [AccountPasswordController::class, 'edit'])->name('password.required');
    Route::put('/account/password/required', [AccountPasswordController::class, 'update'])->name('password.required.update');

    Route::get('/account/sessions', [AccountSessionController::class, 'index'])->name('account.sessions');
    Route::delete('/account/sessions/others', [AccountSessionController::class, 'destroyOthers'])->name('account.sessions.others');
    Route::delete('/account/sessions/{sessionId}', [AccountSessionController::class, 'destroy'])->name('account.sessions.destroy');

    Route::get('/account/security', [AccountSecurityController::class, 'edit'])->name('account.security');
    Route::put('/account/password', [AccountSecurityController::class, 'updatePassword'])->name('account.password');
    Route::post('/account/two-factor', [AccountSecurityController::class, 'enable'])->name('account.two-factor.enable');
    Route::post('/account/two-factor/confirm', [AccountSecurityController::class, 'confirm'])->name('account.two-factor.confirm');
    Route::delete('/account/two-factor', [AccountSecurityController::class, 'disable'])->name('account.two-factor.disable');
});

Route::fallback([HomeController::class, 'missing']);

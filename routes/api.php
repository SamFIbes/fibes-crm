<?php

use App\Http\Controllers\AutomationController;
use App\Http\Controllers\ErpNextWebhookController;
use App\Http\Controllers\LeadWebhookController;
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\TelephonyController;
use App\Http\Controllers\WhatsAppWebhookController;
use App\Http\Controllers\WhishWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/tv/sales', [PerformanceController::class, 'feed'])->name('tv.feed');
Route::get('/v1/automations/ping', [AutomationController::class, 'ping'])->name('automations.ping');

Route::post('/v1/hooks/web-form', [LeadWebhookController::class, 'webForm'])
    ->middleware('throttle:30,1')
    ->name('hooks.web-form');
Route::get('/v1/hooks/meta', [LeadWebhookController::class, 'metaVerify'])->name('hooks.meta.verify');
Route::post('/v1/hooks/meta', [LeadWebhookController::class, 'meta'])->name('hooks.meta');

Route::post('/v1/hooks/erpnext', ErpNextWebhookController::class)
    ->middleware(['throttle:60,1', 'erpnext.signature'])
    ->name('hooks.erpnext');

Route::post('/v1/hooks/whish', WhishWebhookController::class)
    ->middleware(['throttle:60,1', 'whish.signature'])
    ->name('hooks.whish');

Route::get('/v1/hooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('hooks.whatsapp.verify');
Route::post('/v1/hooks/whatsapp', [WhatsAppWebhookController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('hooks.whatsapp');

Route::post('/telephony/incoming', [TelephonyController::class, 'incoming'])
    ->middleware(['throttle:60,1', 'telephony.signature'])
    ->name('telephony.incoming');

Route::post('/telephony/abandoned', [TelephonyController::class, 'abandoned'])
    ->middleware(['throttle:60,1', 'telephony.signature'])
    ->name('telephony.abandoned');

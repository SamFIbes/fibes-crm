<?php

namespace App\Providers;

use App\Events\OpportunityWon;
use App\Events\OrderActivated;
use App\Listeners\OpenOrderFromWin;
use App\Listeners\OpenSubscriptionFromActivation;
use App\Listeners\RecordActivatedPerformance;
use App\Listeners\RecordWonPerformance;
use App\Logging\RedactPii;
use App\Services\Automations\AutomationDesk;
use App\Services\Billing\LedgerBillingGate;
use App\Services\Collections\RadiusDriver;
use App\Services\Files\ClamAvScanner;
use App\Services\Files\FileScanner;
use App\Services\Files\NullFileScanner;
use App\Services\Integrations\GrandstreamClient;
use App\Services\Integrations\HttpGrandstreamClient;
use App\Services\Integrations\HttpKryptonRadiusClient;
use App\Services\Integrations\HttpWhatsAppBusinessClient;
use App\Services\Integrations\HttpWhishClient;
use App\Services\Integrations\KryptonRadiusClient;
use App\Services\Integrations\WhatsAppBusinessClient;
use App\Services\Integrations\WhishClient;
use App\Services\Opportunities\CheckedCoverageGate;
use App\Services\Opportunities\CoverageGate;
use App\Services\Opportunities\ManagerOverrideWonGate;
use App\Services\Opportunities\WonGate;
use App\Services\Orders\BillingGate;
use App\Services\Payments\WhishDriver;
use App\Services\Quotes\ChromiumDocumentDriver;
use App\Services\Quotes\DocumentDriver;
use App\Services\Quotes\HtmlDocumentDriver;
use App\Services\Quotes\LogSms;
use App\Services\Quotes\SmsDriver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FileScanner::class, function (): FileScanner {
            return config('fibes.attachments.driver') === 'clamav'
                ? new ClamAvScanner
                : new NullFileScanner;
        });
        $this->app->bind(CoverageGate::class, CheckedCoverageGate::class);
        $this->app->bind(WonGate::class, ManagerOverrideWonGate::class);
        $this->app->bind(DocumentDriver::class, function (): DocumentDriver {
            return config('fibes.documents.driver') === 'chromium'
                ? new ChromiumDocumentDriver
                : new HtmlDocumentDriver;
        });
        $this->app->bind(SmsDriver::class, LogSms::class);
        $this->app->bind(BillingGate::class, LedgerBillingGate::class);
        $this->app->bind(WhishDriver::class, HttpWhishClient::class);
        $this->app->bind(WhishClient::class, HttpWhishClient::class);
        $this->app->bind(RadiusDriver::class, HttpKryptonRadiusClient::class);
        $this->app->bind(KryptonRadiusClient::class, HttpKryptonRadiusClient::class);
        $this->app->bind(GrandstreamClient::class, HttpGrandstreamClient::class);
        $this->app->bind(WhatsAppBusinessClient::class, HttpWhatsAppBusinessClient::class);
    }

    public function boot(): void
    {
        // migrate:fresh, db:wipe, and rollback stay available in local and test.
        // Staging and production refuse them.
        DB::prohibitDestructiveCommands(! $this->app->environment(['local', 'testing']));

        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers());
        $this->rateLimiters();
        $logger = Log::getLogger();

        if (method_exists($logger, 'pushProcessor')) {
            $logger->pushProcessor(new RedactPii);
        }
        Event::listen(OpportunityWon::class, OpenOrderFromWin::class);
        Event::listen(OpportunityWon::class, RecordWonPerformance::class);
        Event::listen(OpportunityWon::class, function (OpportunityWon $event): void {
            app(AutomationDesk::class)->emit('opportunity.won', [
                'subject_type' => 'opportunity', 'subject_id' => $event->opportunity->id, 'customer_id' => $event->opportunity->customer_id,
            ]);
        });
        Event::listen(OrderActivated::class, OpenSubscriptionFromActivation::class);
        Event::listen(OrderActivated::class, RecordActivatedPerformance::class);
        Event::listen(OrderActivated::class, function (OrderActivated $event): void {
            app(AutomationDesk::class)->emit('order.activated', [
                'subject_type' => 'order', 'subject_id' => $event->order->id, 'customer_id' => $event->order->customer_id,
            ]);
        });
    }

    /**
     * Limits for routes anyone on the internet can call.
     */
    private function rateLimiters(): void
    {
        // A sales TV polls every 2 seconds; several screens may share one office IP.
        RateLimiter::for('tv', fn (Request $request) => $request->attributes->get('tv_token') === true
            ? Limit::perMinute(240)->by('tv:'.$request->ip())
            : Limit::perMinute(20)->by('tv-anon:'.$request->ip()));

        // Signed links and API keys: plenty for real use, too few to guess.
        RateLimiter::for('public-token', fn (Request $request) => Limit::perMinute(30)->by('public-token:'.$request->ip()));

        // Downloads of whole tables by signed-in staff.
        RateLimiter::for('exports', fn (Request $request) => Limit::perMinute(10)->by('exports:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}

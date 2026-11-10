<?php

use App\Modules\Crm\Providers\CrmServiceProvider;
use App\Modules\Quotes\Providers\QuotesServiceProvider;
use App\Modules\Settings\Providers\SettingsServiceProvider;
use App\Modules\Surveys\Providers\SurveysServiceProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Locales
    |--------------------------------------------------------------------------
    |
    | Arabic and English are first-class from the foundation step. The
    | language switch writes the session and, for a signed-in user, users.locale.
    |
    */

    'locales' => ['en', 'ar'],

    /*
    |--------------------------------------------------------------------------
    | First administrator
    |--------------------------------------------------------------------------
    |
    | Seeded by AdminUserSeeder. Credentials come only from the environment.
    | There is no default password in the repository.
    |
    */

    'admin' => [
        'name' => env('ADMIN_NAME', 'FIBES Admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
        'locale' => env('ADMIN_LOCALE', 'en'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Domain modules
    |--------------------------------------------------------------------------
    |
    | Each module owns its routes, models, and screens as those steps land.
    | Auth, the design system, and the app shell stay in the core app.
    |
    */

    'modules' => [
        'crm' => [
            'provider' => CrmServiceProvider::class,
            'responsibility' => 'Leads, accounts, contacts, and opportunities.',
            'nav' => ['leads', 'opportunities'],
        ],
        'surveys' => [
            'provider' => SurveysServiceProvider::class,
            'responsibility' => 'Operations scheduling and technician site surveys.',
            'nav' => ['surveys'],
        ],
        'quotes' => [
            'provider' => QuotesServiceProvider::class,
            'responsibility' => 'Quote builder, currency, approvals, and branded PDFs.',
            'nav' => ['quotes'],
        ],
        'settings' => [
            'provider' => SettingsServiceProvider::class,
            'responsibility' => 'Sources, products, pipelines, SLA policy, and other working rules.',
            'nav' => ['settings'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Lead SLA
    |--------------------------------------------------------------------------
    |
    | A breached first-response SLA alerts the supervisor. Technician
    | escalations belong to a later support phase and are not lead alerts.
    |
    */

    'sla' => [
        'breach_notifies' => 'supervisor',
        // Sam, 31 Oct 2026: support is 24/7, so the clock is calendar time.
        'clock' => 'calendar',
        'support_hours' => '24/7',
        // Response times are assumption A5. Resolve times are Sam's answer:
        // P1 and P2 within 24 hours, P3 and P4 within 48 hours. Editable later.
        'priorities' => [
            'P1' => ['respond_minutes' => 15, 'resolve_minutes' => 24 * 60],
            'P2' => ['respond_minutes' => 30, 'resolve_minutes' => 24 * 60],
            'P3' => ['respond_minutes' => 2 * 60, 'resolve_minutes' => 48 * 60],
            'P4' => ['respond_minutes' => 8 * 60, 'resolve_minutes' => 48 * 60],
        ],
        'lead_first_response_minutes' => 15,
        // Qualify within a day, and accept a handoff within 4 hours.
        // Support is 24/7, so these clocks run on calendar time and skip holidays.
        'lead_qualify_minutes' => 24 * 60,
        'lead_handoff_minutes' => 4 * 60,
    ],

    'coverage' => [
        'covered_m' => 150,
        'near_m' => 500,
        'sector_m' => 8000,
        'tile_url' => env('FIBES_MAP_TILES', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
        'depot' => ['lat' => 33.8938, 'lng' => 35.5018],
        'fiberdesk' => (bool) env('FIBES_FIBERDESK', false),
    ],

    'pipelines' => [
        // HSI is not a FIBES service. Internal setup skips the site survey the same way.
        'skip_survey' => ['hsi', 'internal'],
        'winback_days' => 75,
    ],

    'webhooks' => [
        'web_form_secret' => env('FIBES_WEB_FORM_SECRET', ''),
        'meta_verify_token' => env('FIBES_META_VERIFY_TOKEN', ''),
        'meta_app_secret' => env('FIBES_META_APP_SECRET', ''),
        'turnstile_secret' => env('FIBES_TURNSTILE_SECRET', ''),
        // Website forms may sign "<unix time>.<body>" and send X-Fibes-Timestamp.
        // Set to true once the website does, to refuse old body-only signatures.
        'web_form_require_timestamp' => (bool) env('FIBES_WEB_FORM_REQUIRE_TIMESTAMP', false),
        'web_form_tolerance_seconds' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Escalation ladder
    |--------------------------------------------------------------------------
    |
    | Support is 24/7. A warning or a breach can move the ticket to the next
    | tier. Both switches are edited in Settings. The default only moves a
    | ticket when the clock actually runs out.
    |
    */

    'escalation' => [
        'on_warning' => 'none',
        'on_breach' => 'next_tier',
    ],

    /*
    |--------------------------------------------------------------------------
    | What FIBES sells
    |--------------------------------------------------------------------------
    |
    | Sam, 31 Oct 2026. HSI is not a service type. Reseller is a customer
    | type, not a service. Plans and prices live in the catalog.
    |
    */

    'service_types' => [
        'ftth' => ['label' => 'Fiber', 'variant' => 'FTTH'],
        'fttb' => ['label' => 'Fiber', 'variant' => 'FTTB'],
        'ptmp' => ['label' => 'PTMP'],
        'ptp' => ['label' => 'PTP'],
        'internal' => ['label' => 'Internal Setup'],
        'dsl' => ['label' => 'DSL'],
    ],

    'customer_types' => [
        'retail' => 'Retail',
        'reseller' => 'Reseller',
    ],

    /*
    |--------------------------------------------------------------------------
    | Two-factor policy
    |--------------------------------------------------------------------------
    |
    | Required roles must enroll after the grace period. Super Admin is on
    | by default. Management, finance, NOC, and supervisors can be added
    | here when those roles exist, for example:
    | super_admin,management,finance,noc_engineer,cc_supervisor,ops_manager
    |
    */

    'two_factor' => [
        'grace_days' => (int) env('FIBES_2FA_GRACE_DAYS', 7),
        'required_roles' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('FIBES_2FA_REQUIRED_ROLES', 'super_admin,management,finance,noc_engineer,cc_supervisor,ops_manager')),
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sessions
    |--------------------------------------------------------------------------
    |
    | Idle timeout matches SESSION_LIFETIME. The absolute limit signs the
    | browser out even when it stays active. Sessions are stored in the
    | database so a person can see and revoke them.
    |
    */

    'session' => [
        'idle_minutes' => (int) env('SESSION_LIFETIME', 60),
        'absolute_minutes' => (int) env('SESSION_ABSOLUTE_MINUTES', 720),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | Login is 5 attempts a minute for the same email and IP, then a
    | 15-minute pause after 10 failures. Password reset and the authenticator
    | challenge use the same per-minute cap. API, webhook, and export caps
    | are recorded for the routes that do not exist yet.
    |
    */

    'telephony' => [
        'secret' => env('FIBES_TELEPHONY_SECRET'),
        'url' => env('FIBES_TELEPHONY_URL', ''),
        // Click-to-dial uses the UCM HTTP API (not AMI). See docs/TELEPHONY.md.
        'user' => env('FIBES_TELEPHONY_USER', ''),
        'password' => env('FIBES_TELEPHONY_PASSWORD'),
        'api_path' => env('FIBES_TELEPHONY_API_PATH', '/api'),
        'dial_action' => env('FIBES_TELEPHONY_DIAL_ACTION', 'dialOutbound'),
    ],

    /*
    | Hub ERP is Frappe / ERPNext at erp.thehubholding.com.
    | The live company is Smart Network (LBP, Lebanon). Stock requests
    | are draft Material Requests. The flag stays off until an API key
    | exists — the Hub user does not have API Access yet.
    */

    'erpnext' => [
        'enabled' => (bool) env('FIBES_ERPNEXT_ENABLED', false),
        'url' => env('FIBES_ERPNEXT_URL', 'https://erp.thehubholding.com'),
        'api_key' => env('FIBES_ERPNEXT_API_KEY'),
        'api_secret' => env('FIBES_ERPNEXT_API_SECRET'),
        'webhook_secret' => env('FIBES_ERPNEXT_WEBHOOK_SECRET'),
        'company' => env('FIBES_ERPNEXT_COMPANY', 'Smart Network'),
    ],

    'attachments' => [
        'max_kilobytes' => (int) env('FIBES_ATTACHMENT_MAX_KB', 10240),
        'driver' => env('FIBES_FILE_SCAN_DRIVER', 'null'),
        'clamscan' => env('FIBES_CLAMSCAN'),
        'clamav_host' => env('FIBES_CLAMAV_HOST', '127.0.0.1'),
        'clamav_port' => (int) env('FIBES_CLAMAV_PORT', 3310),
    ],

    'load' => [
        'customers' => (int) env('FIBES_LOAD_CUSTOMERS', 30000),
    ],

    'observability' => [
        'dsn' => env('SENTRY_DSN', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Quotes and contracts
    |--------------------------------------------------------------------------
    |
    | html prints the branded page. chromium asks Chrome to make a PDF when
    | FIBES_CHROMIUM_BIN is set. VAT, the contract length, the early
    | termination fee, and the fallback exchange rate are placeholders.
    |
    */

    /*
    | Deposit is off until Sam turns it on. Install SLA is 5 business days.
    | The first invoice is a manual confirmation until billing exists.
    */

    'orders' => [
        'deposit' => (bool) env('FIBES_ORDER_DEPOSIT', false),
        'install_business_days' => (int) env('FIBES_INSTALL_BUSINESS_DAYS', 5),
    ],

    'backup' => [
        'key' => env('FIBES_BACKUP_KEY', ''),
        'directory' => env('FIBES_BACKUP_DIRECTORY', storage_path('app/backups')),
        'scratch' => env('FIBES_BACKUP_SCRATCH', ''),
        'destination' => env('FIBES_BACKUP_DESTINATION', ''),
    ],

    'radius' => [
        'enabled' => (bool) env('FIBES_RADIUS', false),
        'url' => env('FIBES_RADIUS_URL', ''),
        'api_key' => env('FIBES_RADIUS_API_KEY'),
        // When true, a hand task is opened even after a live suspend or reactivate succeeds.
        'also_task' => (bool) env('FIBES_RADIUS_ALSO_TASK', false),
        // Assumed Smart Networks / Krypton paths. Change these when the vendor guide arrives.
        'paths' => [
            'health' => env('FIBES_RADIUS_PATH_HEALTH', '/api/v1/health'),
            'user' => env('FIBES_RADIUS_PATH_USER', '/api/v1/users/{username}'),
            'suspend' => env('FIBES_RADIUS_PATH_SUSPEND', '/api/v1/users/{username}/suspend'),
            'reactivate' => env('FIBES_RADIUS_PATH_REACTIVATE', '/api/v1/users/{username}/reactivate'),
            'session' => env('FIBES_RADIUS_PATH_SESSION', '/api/v1/users/{username}/session'),
        ],
    ],

    'whish' => [
        'enabled' => (bool) env('FIBES_WHISH', false),
        'url' => env('FIBES_WHISH_URL', ''),
        'api_key' => env('FIBES_WHISH_API_KEY'),
        'webhook_secret' => env('FIBES_WHISH_WEBHOOK_SECRET'),
        'paths' => [
            'health' => env('FIBES_WHISH_PATH_HEALTH', '/api/v1/health'),
            'checkout' => env('FIBES_WHISH_PATH_CHECKOUT', '/api/v1/checkouts'),
        ],
    ],

    'whatsapp' => [
        'enabled' => (bool) env('FIBES_WHATSAPP', false),
        'token' => env('FIBES_WHATSAPP_TOKEN'),
        'phone_number_id' => env('FIBES_WHATSAPP_PHONE_ID', ''),
        'graph_version' => env('FIBES_WHATSAPP_GRAPH_VERSION', 'v21.0'),
        'verify_token' => env('FIBES_WHATSAPP_VERIFY_TOKEN') ?: env('FIBES_META_VERIFY_TOKEN'),
        'app_secret' => env('FIBES_WHATSAPP_APP_SECRET') ?: env('FIBES_META_APP_SECRET'),
        'templates' => [
            'renewal' => env('FIBES_WHATSAPP_TEMPLATE_RENEWAL', 'fibes_renewal_reminder'),
            'ticket' => env('FIBES_WHATSAPP_TEMPLATE_TICKET', 'fibes_ticket_update'),
            'payment' => env('FIBES_WHATSAPP_TEMPLATE_PAYMENT', 'fibes_payment_reminder'),
        ],
    ],

    'documents' => [
        'driver' => env('FIBES_PDF_DRIVER', 'html'),
        'chromium' => env('FIBES_CHROMIUM_BIN', ''),
        'sms' => env('FIBES_SMS_DRIVER', 'log'),
        'vat_percent' => env('FIBES_VAT_PERCENT', '11'),
        'term_months' => 12,
        'early_termination_fee' => '0.00',
        'fallback_rate' => '89500.0000',
        'valid_days' => 14,
    ],

    'rate_limits' => [
        'login_per_minute' => 5,
        'login_lockout_attempts' => 10,
        'login_lockout_minutes' => 15,
        'password_reset_per_minute' => 5,
        'two_factor_per_minute' => 5,
        'api_per_minute' => 120,
        'webhook_per_minute' => 60,
        'export_per_hour' => 10,
    ],

];

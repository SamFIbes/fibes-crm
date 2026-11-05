<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Access\AccessGate;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * @var string
     */
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $access = app(AccessGate::class);
        $actor = $user ? ($access->effective($request) ?? $user) : null;
        $viewAs = null;

        if ($user && $actor && $actor->isNot($user)) {
            $viewAs = ['id' => $actor->id, 'name' => $actor->name];
        }

        return [
            ...parent::share($request),
            'locale' => app()->getLocale(),
            'dir' => app()->getLocale() === 'ar' ? 'rtl' : 'ltr',
            'translations' => trans('ui'),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'locale' => $user->locale,
                    'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                    'role' => $user->role,
                ] : null,
                'nav' => $actor ? $this->navigation($access, $actor) : [],
                'abilities' => $actor ? [
                    'customers_create' => $access->allows($actor, 'customers.create'),
                    'tickets_create' => $access->allows($actor, 'tickets.create'),
                    'tasks_create' => $access->allows($actor, 'tasks.create'),
                    'calls' => $access->allows($actor, 'calls.log') || $access->allows($actor, 'calls.view'),
                ] : [],
                'view_as' => $viewAs,
            ],
            'security' => [
                'must_change_password' => (bool) $user?->must_change_password,
                'two_factor_grace_days' => $user?->twoFactorGraceDaysLeft(),
            ],
            'flash' => [
                'status' => $request->hasSession() ? $request->session()->get('status') : null,
                'recovery_codes' => $request->hasSession() ? $request->session()->get('recovery_codes') : null,
                'lead_matches' => $request->hasSession() ? $request->session()->get('lead_matches') : null,
                'link' => $request->hasSession() ? $request->session()->get('link') : null,
                'tv_token' => $request->hasSession() ? $request->session()->get('tv_token') : null,
            ],
            'app' => [
                'name' => config('app.name'),
            ],
            'notifications' => $user ? [
                'unread' => $user->unreadNotifications()->count(),
                'items' => $user->notifications()->latest()->limit(8)->get()->map(fn ($notice) => [
                    'id' => $notice->id,
                    'title' => is_array($notice->data) ? (string) ($notice->data['title'] ?? '') : '',
                    'body' => is_array($notice->data) ? (string) ($notice->data['body'] ?? '') : '',
                    'read' => $notice->read_at !== null,
                    'url' => is_array($notice->data) ? ($notice->data['url'] ?? null) : null,
                ])->all(),
            ] : ['unread' => 0, 'items' => []],
        ];
    }

    /**
     * @return list<string>
     */
    private function navigation(AccessGate $access, User $user): array
    {
        $nav = ['dashboard'];
        $map = [
            'customers' => ['customers.view'],
            'tickets' => ['tickets.view'],
            'dispatch' => ['jobs.schedule'],
            'calls' => ['calls.log', 'calls.view'],
            'qa' => ['qa.score', 'qa.view'],
            'performance' => ['commissions.view', 'targets.set'],
            'reports' => ['reports.view'],
            'automations' => ['automations.view', 'automations.manage'],
            'imports' => ['imports.manage'],
            'incidents' => ['incidents.manage'],
            'tasks' => ['tasks.view'],
            'approvals' => ['approvals.view', 'approvals.decide', 'approvals.request'],
            'billing' => ['invoices.view', 'invoices.view_balance', 'billing.view_amounts'],
            'collections' => ['services.suspend', 'services.request_suspend'],
            'retention' => ['retention.offer', 'retention.request'],
            'tech' => ['jobs.view', 'jobs.complete'],
            'network' => ['services.technical.view', 'coverage.view', 'coverage.check'],
            'coverage' => ['coverage.view', 'coverage.check', 'coverage.edit'],
            'users' => ['users.view', 'users.manage'],
            'roles' => ['users.view', 'users.manage'],
            'audit' => ['audit.view'],
            'settings' => ['settings.view', 'settings.manage'],
            'integrations' => ['integrations.view', 'integrations.manage'],
            'geography' => ['geography.view', 'geography.manage'],
            'rates' => ['rates.view', 'rates.manage'],
            'catalog' => ['catalog.view'],
            'files' => ['attachments.view', 'attachments.upload'],
            'inventory' => ['inventory.view', 'inventory.move'],
            'leads' => ['leads.view'],
            'opportunities' => ['opportunities.view', 'opportunities.view_won'],
            'surveys' => ['jobs.view', 'jobs.schedule', 'jobs.complete', 'jobs.request'],
            'quotes' => ['quotes.create', 'quotes.approve', 'contracts.manage', 'contracts.view'],
            'orders' => ['orders.create', 'orders.view', 'orders.activate', 'purchasing.create'],
        ];

        foreach ($map as $key => $permissions) {
            foreach ($permissions as $permission) {
                if ($access->allows($user, $permission)) {
                    $nav[] = $key;
                    break;
                }
            }
        }

        return $nav;
    }
}

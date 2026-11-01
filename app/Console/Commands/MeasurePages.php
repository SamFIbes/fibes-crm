<?php

namespace App\Console\Commands;

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DispatchController;
use App\Models\Customer;
use App\Models\User;
use App\Services\Billing\Ledger;
use App\Services\Catalog\CatalogDesk;
use App\Services\Coverage\Geo;
use App\Services\Customers\CustomerRecords;
use App\Services\Jobs\FieldWork;
use App\Services\Tickets\IncidentRecords;
use App\Services\Tickets\TicketRecords;
use App\Support\Access\AccessGate;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class MeasurePages extends Command
{
    protected $signature = 'perf:pages {--samples=20}';

    protected $description = 'Measure server time for the customer list, search, customer page, and dispatch board';

    public function handle(): int
    {
        $admin = User::query()->where('role', 'super_admin')->first();

        if ($admin === null) {
            $this->error('No super admin is available.');

            return 1;
        }

        $loaded = Customer::query()->where('account_no', 'like', 'LD-%');
        $half = intdiv(max(1, (clone $loaded)->count()), 2);
        $customer = (clone $loaded)->orderBy('id')->skip($half)->first();
        $needle = $customer instanceof Customer ? $customer->account_no : 'LD-000001';
        $samples = max(5, (int) $this->option('samples'));
        $pages = [
            'customers' => fn () => app(CustomerController::class)->index($this->request('/customers', $admin), app(CustomerRecords::class), app(AccessGate::class), app(CatalogDesk::class)),
            'search' => fn () => app(CustomerController::class)->index($this->request('/customers?q='.urlencode((string) $needle), $admin), app(CustomerRecords::class), app(AccessGate::class), app(CatalogDesk::class)),
            'customer' => fn () => $customer instanceof Customer
                ? app(CustomerController::class)->show($this->request('/customers/'.$customer->id, $admin), $customer, app(CustomerRecords::class), app(TicketRecords::class), app(IncidentRecords::class), app(AccessGate::class), app(CatalogDesk::class), app(Ledger::class))
                : null,
            'dispatch' => fn () => app(DispatchController::class)->index($this->request('/dispatch?mode=week', $admin), app(FieldWork::class), app(AccessGate::class), app(Geo::class)),
        ];

        foreach ($pages as $name => $run) {
            $run();
            $times = [];

            for ($i = 0; $i < $samples; $i++) {
                $start = hrtime(true);
                $run();
                $times[] = (hrtime(true) - $start) / 1_000_000;
            }

            sort($times);
            $p95 = $times[(int) floor((count($times) - 1) * 0.95)];
            $this->line(sprintf('%s p95 %.1f ms  median %.1f ms  max %.1f ms', $name, $p95, $times[(int) floor(count($times) / 2)], $times[array_key_last($times)]));
        }

        return 0;
    }

    private function request(string $uri, User $admin): Request
    {
        $request = Request::create($uri, 'GET');
        $request->setUserResolver(fn () => $admin);

        return $request;
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\DiscountLimit;
use App\Models\Product;
use App\Models\Quote;
use App\Rules\SafeEmail;
use App\Services\Catalog\PriceResolver;
use App\Services\Quotes\ContractDesk;
use App\Services\Quotes\QuoteDesk;
use App\Services\Settings\CommercialSettings;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class QuoteController extends Controller
{
    public function index(Request $request, AccessGate $access, QuoteDesk $quotes, ContractDesk $contracts, PriceResolver $prices): Response
    {
        $actor = $access->effective($request);
        abort_unless($actor !== null, 403);
        $limit = DiscountLimit::query()->where('role', (string) $actor->role)->first();

        return Inertia::render('Quotes/Index', [
            'quotes' => $quotes->visible($actor)->with('lines')->latest('id')->limit(40)->get()->map(fn (Quote $quote) => $this->row($quote))->values(),
            'contracts' => $contracts->visible($actor)->with('quote')->latest('id')->limit(40)->get()->map(fn (Contract $contract) => [
                'id' => $contract->id,
                'number' => $contract->number,
                'status' => $contract->status,
                'quote_number' => $contract->quote?->number,
                'term_months' => $contract->term_months,
                'signer_name' => $contract->signer_name,
                'sha256' => $contract->sha256,
                'signed' => $contract->status === 'signed',
            ])->values(),
            'products' => Product::query()->where('is_active', true)->whereNull('archived_at')->orderByRaw("case when kind = 'plan' then 0 else 1 end")->orderBy('sku')->get()->map(function (Product $product) use ($prices) {
                $price = $prices->resolve($product->id, now()->toDateString());

                return [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name_en' => $product->name_en,
                    'name_ar' => $product->name_ar,
                    'kind' => $product->kind,
                    'price' => $price['price'] ?? null,
                    'recurring' => $product->kind === 'plan',
                ];
            })->values(),
            'limit' => $limit instanceof DiscountLimit ? [
                'percent' => (string) $limit->line_percent,
                'amount' => (string) $limit->line_amount,
            ] : null,
            'can_create' => $access->allows($actor, 'quotes.create'),
            'can_contract' => $access->allows($actor, 'contracts.manage'),
            'vat' => (string) app(CommercialSettings::class)->get('vat_percent'),
            'term_months' => (int) app(CommercialSettings::class)->get('term_months'),
            'early_fee' => (string) app(CommercialSettings::class)->money('early_termination_fee'),
        ]);
    }

    public function store(Request $request, AccessGate $access, QuoteDesk $quotes): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor !== null, 403);
        $quote = $quotes->create($actor, $this->validated($request), $request);

        return back()->with('status', __($quote->status === 'pending_approval' ? 'ui.quotes.pending_note' : 'ui.quotes.saved'));
    }

    public function update(Request $request, Quote $quote, AccessGate $access, QuoteDesk $quotes): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor !== null, 403);
        $quotes->find($actor, $quote->id);
        $quotes->update($quote, $actor, $this->validated($request), $request);

        return back()->with('status', __('ui.quotes.saved'));
    }

    public function submit(Request $request, Quote $quote, AccessGate $access, QuoteDesk $quotes): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor !== null, 403);
        $quote = $quotes->find($actor, $quote->id);
        $quote = $quotes->submit($quote, $actor, $request);

        return back()->with('status', __($quote->status === 'pending_approval' ? 'ui.quotes.pending_note' : 'ui.quotes.submitted'));
    }

    public function revise(Request $request, Quote $quote, AccessGate $access, QuoteDesk $quotes): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor !== null, 403);
        $quote = $quotes->find($actor, $quote->id);
        $quotes->revise($quote, $actor, $request);

        return back()->with('status', __('ui.quotes.revised'));
    }

    public function send(Request $request, Quote $quote, AccessGate $access, QuoteDesk $quotes): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor !== null, 403);
        $quote = $quotes->find($actor, $quote->id);
        $data = $request->validate(['channel' => ['required', 'in:email,whatsapp,link']]);
        $link = $quotes->send($quote, $actor, $data['channel'], $request);

        return back()->with('status', __('ui.quotes.sent'))->with('link', $link);
    }

    public function print(Request $request, Quote $quote, AccessGate $access, QuoteDesk $quotes): HttpResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor !== null, 403);
        $quote = $quotes->find($actor, $quote->id);

        if (! $quotes->canPrint($quote)) {
            return response(__('ui.quotes.print_locked'), 422);
        }

        $document = $quotes->document($quote, $actor, $request);

        return response($document['body'], 200, [
            'Content-Type' => $document['mime'],
            'Content-Disposition' => 'inline; filename="'.$quote->number.'.'.(str_contains($document['mime'], 'pdf') ? 'pdf' : 'html').'"',
        ]);
    }

    public function accept(Request $request, Quote $quote, AccessGate $access, QuoteDesk $quotes): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor !== null, 403);
        $quote = $quotes->find($actor, $quote->id);
        $quotes->accept($quote, $actor, $request);

        return back()->with('status', __('ui.quotes.accepted'));
    }

    public function reject(Request $request, Quote $quote, AccessGate $access, QuoteDesk $quotes): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor !== null, 403);
        $quote = $quotes->find($actor, $quote->id);
        $quotes->reject($quote, $actor, $request);

        return back()->with('status', __('ui.quotes.rejected'));
    }

    public function contract(Request $request, Quote $quote, AccessGate $access, QuoteDesk $quotes, ContractDesk $contracts): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor !== null, 403);
        $quote = $quotes->find($actor, $quote->id);
        $data = $request->validate([
            'term_months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'auto_renew' => ['nullable', 'boolean'],
        ]);
        $contracts->open($quote, $actor, $data, $request);

        return back()->with('status', __('ui.quotes.contract'));
    }

    public function signForm(Request $request, Contract $contract, AccessGate $access, ContractDesk $contracts): Response
    {
        $actor = $access->effective($request);
        abort_unless($actor !== null, 403);
        $contract = $contracts->find($actor, $contract->id);
        $contract->loadMissing('quote');

        return Inertia::render('Quotes/Sign', [
            'contract' => $this->signPayload($contract),
            'otp_url' => route('contracts.otp', $contract),
            'sign_url' => route('contracts.sign', $contract),
            'scan_url' => route('contracts.scan', $contract),
        ]);
    }

    public function otp(Request $request, Contract $contract, AccessGate $access, ContractDesk $contracts): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor !== null, 403);
        $contract = $contracts->find($actor, $contract->id);
        $contracts->sendCode($contract, $request);

        return back()->with('status', __('ui.quotes.code_sent'));
    }

    public function sign(Request $request, Contract $contract, AccessGate $access, ContractDesk $contracts): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor !== null, 403);
        $contract = $contracts->find($actor, $contract->id);
        $contracts->sign($contract, $request->all(), $request, $actor);

        return back()->with('status', __('ui.quotes.signed'));
    }

    public function scan(Request $request, Contract $contract, AccessGate $access, ContractDesk $contracts): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor !== null, 403);
        $contract = $contracts->find($actor, $contract->id);
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
            'signer_name' => ['required', 'string', 'max:120'],
            'consent' => ['accepted'],
        ]);
        $contracts->sign($contract, [
            'file' => $request->file('file'),
            'signer_name' => $request->string('signer_name')->toString(),
            'consent' => true,
        ], $request, $actor, true);

        return back()->with('status', __('ui.quotes.signed'));
    }

    public function printContract(Request $request, Contract $contract, AccessGate $access, ContractDesk $contracts): HttpResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor !== null, 403);
        $contract = $contracts->find($actor, $contract->id);
        $document = $contracts->document($contract);

        return response($document['body'], 200, ['Content-Type' => $document['mime']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'opportunity_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:160'],
            'customer_email' => ['nullable', new SafeEmail],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'language' => ['nullable', 'in:en,ar'],
            'currency' => ['nullable', 'in:USD,LBP'],
            'valid_until' => ['nullable', 'date'],
            'apply_vat' => ['nullable', 'boolean'],
            'promo_code' => ['nullable', 'string', 'max:40'],
            'submit' => ['nullable', 'boolean'],
            'product_id' => ['nullable', 'integer'],
            'qty' => ['nullable', 'numeric', 'min:0.01'],
            'discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['nullable', 'array'],
            'lines.*.product_id' => ['required_with:lines', 'integer'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0.01'],
            'lines.*.discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:200'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Quote $quote): array
    {
        return [
            'id' => $quote->id,
            'number' => $quote->number,
            'version' => $quote->version,
            'status' => $quote->status,
            'language' => $quote->language,
            'currency' => $quote->currency,
            'total' => (string) $quote->total,
            'rate' => (string) $quote->exchange_rate,
            'valid_until' => $quote->valid_until?->format('d/m/Y'),
            'customer' => $quote->customer_name,
            'approver' => $quote->approver_name,
            'printable' => in_array($quote->status, ['approved', 'sent', 'accepted'], true),
            'lines' => $quote->lines->map(fn ($line) => [
                'description' => $line->description,
                'qty' => (string) $line->qty,
                'unit_price' => (string) $line->unit_price,
                'discount_pct' => (string) $line->discount_pct,
                'line_total' => (string) $line->line_total,
                'recurring' => (bool) $line->is_recurring,
            ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function signPayload(Contract $contract): array
    {
        return [
            'id' => $contract->id,
            'number' => $contract->number,
            'status' => $contract->status,
            'body' => (string) $contract->body_html,
            'term_months' => $contract->term_months,
            'fee' => (string) $contract->early_termination_fee,
            'signed' => $contract->status === 'signed',
            'sha256' => $contract->sha256,
        ];
    }
}

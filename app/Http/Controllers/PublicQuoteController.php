<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Quote;
use App\Services\Quotes\ContractDesk;
use App\Services\Quotes\QuoteDesk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class PublicQuoteController extends Controller
{
    public function show(Request $request, Quote $quote, QuoteDesk $quotes): Response
    {
        $quote->loadMissing('lines');

        return Inertia::render('Quotes/Offer', [
            'quote' => [
                'id' => $quote->id,
                'number' => $quote->number,
                'status' => $quote->status,
                'language' => $quote->language,
                'currency' => $quote->currency,
                'total' => (string) $quote->total,
                'valid_until' => $quote->valid_until?->format('d/m/Y'),
                'customer' => $quote->customer_name,
                'lines' => $quote->lines->map(fn ($line) => [
                    'description' => $line->description,
                    'qty' => (string) $line->qty,
                    'total' => (string) $line->line_total,
                ])->values(),
            ],
            'accept_url' => URL::temporarySignedRoute('quotes.public.accept', now()->addDays(14), ['quote' => $quote->id]),
            'reject_url' => URL::temporarySignedRoute('quotes.public.reject', now()->addDays(14), ['quote' => $quote->id]),
            'can_answer' => $quote->status === 'sent',
        ]);
    }

    public function accept(Request $request, Quote $quote, QuoteDesk $quotes): RedirectResponse
    {
        $quotes->accept($quote, null, $request);

        return back()->with('status', __('ui.quotes.accepted'));
    }

    public function reject(Request $request, Quote $quote, QuoteDesk $quotes): RedirectResponse
    {
        $quotes->reject($quote, null, $request);

        return back()->with('status', __('ui.quotes.rejected'));
    }

    public function verify(string $token): Response
    {
        $quote = Quote::query()->where('public_token', $token)->first();
        abort_unless($quote instanceof Quote, 404);
        $contract = Contract::query()->where('quote_id', $quote->id)->where('status', 'signed')->latest('id')->first();

        return Inertia::render('Quotes/Verify', [
            'quote' => [
                'number' => $quote->number,
                'status' => $quote->status,
                'total' => (string) $quote->total,
                'currency' => $quote->currency,
                'version' => $quote->version,
            ],
            'sha256' => $contract?->sha256,
        ]);
    }

    public function signForm(Request $request, Contract $contract): Response
    {
        abort_unless($request->hasValidSignature(), 403);

        $until = now()->addDays(7);

        return Inertia::render('Quotes/Sign', [
            'contract' => [
                'id' => $contract->id,
                'number' => $contract->number,
                'status' => $contract->status,
                'body' => (string) $contract->body_html,
                'term_months' => $contract->term_months,
                'fee' => (string) $contract->early_termination_fee,
                'signed' => $contract->status === 'signed',
                'sha256' => $contract->sha256,
            ],
            'otp_url' => URL::temporarySignedRoute('contracts.public.otp', $until, ['contract' => $contract->id]),
            'sign_url' => URL::temporarySignedRoute('contracts.public.sign', $until, ['contract' => $contract->id]),
            'scan_url' => null,
        ]);
    }

    public function otp(Request $request, Contract $contract, ContractDesk $contracts): RedirectResponse
    {
        $contracts->sendCode($contract, $request);

        return back()->with('status', __('ui.quotes.code_sent'));
    }

    public function sign(Request $request, Contract $contract, ContractDesk $contracts): RedirectResponse
    {
        $contracts->sign($contract, $request->all(), $request, null);

        return back()->with('status', __('ui.quotes.signed'));
    }
}

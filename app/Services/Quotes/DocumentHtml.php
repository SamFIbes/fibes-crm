<?php

namespace App\Services\Quotes;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Quote;
use App\Services\Opportunities\OpportunityMoney;
use App\Services\Settings\CommercialSettings;
use Illuminate\Http\Request;

class DocumentHtml
{
    public function __construct(
        private OpportunityMoney $money,
        private QrMark $qr,
    ) {}

    public function quote(Quote $quote): string
    {
        $quote->loadMissing('lines');
        $locale = $quote->language === 'ar' ? 'ar' : 'en';
        $dir = $locale === 'ar' ? 'rtl' : 'ltr';
        $title = trans('ui.quotes.document_title', [], $locale);
        $lbp = $quote->currency === 'LBP'
            ? (string) $quote->total
            : $this->money->lbp((string) $quote->total, (string) $quote->exchange_rate);
        $check = url('/v/'.$quote->public_token);
        $rows = '';

        foreach ($quote->lines as $line) {
            $rows .= '<tr><td>'.e($line->description).'</td><td dir="ltr">'.e($this->amount((string) $line->qty, '')).'</td><td dir="ltr">'.e($this->amount((string) $line->unit_price, (string) $quote->currency)).'</td><td dir="ltr">'.e((string) $line->discount_pct).'%</td><td dir="ltr">'.e($this->amount((string) $line->line_total, (string) $quote->currency)).'</td></tr>';
        }

        $approver = $quote->approver_name
            ? '<p data-testid="quote-approver">'.e(trans('ui.quotes.approved_by', ['name' => $quote->approver_name], $locale)).'</p>'
            : '';

        return $this->shell($locale, $dir, $title, <<<HTML
            <h1>{$title}</h1>
            <p class="muted">{$quote->number} · v{$quote->version}</p>
            <p>{$this->t('ui.quotes.document_intro', $locale)}</p>
            <table>
                <thead><tr><th>{$this->t('ui.quotes.product', $locale)}</th><th>{$this->t('ui.quotes.qty', $locale)}</th><th>{$this->t('ui.quotes.unit', $locale)}</th><th>{$this->t('ui.quotes.discount', $locale)}</th><th>{$this->t('ui.quotes.total', $locale)}</th></tr></thead>
                <tbody>{$rows}</tbody>
            </table>
            <p dir="ltr"><strong>{$this->amount((string) $quote->total, (string) $quote->currency)}</strong> · {$this->grouped($lbp)} ل.ل.</p>
            <p dir="ltr">{$this->t('ui.quotes.rate', $locale)} {$quote->exchange_rate}</p>
            <p>{$this->t('ui.quotes.prices_note', $locale)}</p>
            <p>{$this->t('ui.quotes.vat_note', $locale, ['percent' => $this->vatPercent()])}</p>
            {$approver}
            <div class="qr" dir="ltr">{$this->qr->svg($check)}<p>{$check}</p></div>
        HTML);
    }

    public function invoice(Invoice $invoice, string $locale): string
    {
        $invoice->loadMissing(['lines', 'customer']);
        $locale = $locale === 'ar' ? 'ar' : 'en';
        $dir = $locale === 'ar' ? 'rtl' : 'ltr';
        $title = trans('ui.billing.document_title', [], $locale);
        $name = $locale === 'ar'
            ? ($invoice->customer?->name_ar ?: $invoice->customer?->name_en)
            : $invoice->customer?->name_en;
        $rows = '';

        foreach ($invoice->lines as $line) {
            $rows .= '<tr><td>'.e($line->description).'</td><td dir="ltr">'.e($this->amount((string) $line->qty, '')).'</td><td dir="ltr">'.e($this->amount((string) $line->unit_price, 'USD')).'</td><td dir="ltr">'.e($this->amount((string) $line->line_total, 'USD')).'</td></tr>';
        }

        $number = e((string) $invoice->number);
        $customer = e((string) $name);
        $rate = e((string) $invoice->exchange_rate);
        $lbp = e($this->grouped((string) $invoice->lbp_total));

        return $this->shell($locale, $dir, $title, <<<HTML
            <h1>{$title}</h1>
            <p class="muted" dir="ltr">{$number}</p>
            <p>{$customer}</p>
            <table>
                <thead><tr><th>{$this->t('ui.quotes.product', $locale)}</th><th>{$this->t('ui.quotes.qty', $locale)}</th><th>{$this->t('ui.quotes.unit', $locale)}</th><th>{$this->t('ui.billing.total', $locale)}</th></tr></thead>
                <tbody>{$rows}</tbody>
            </table>
            <p dir="ltr">{$this->t('ui.billing.tax_label', $locale)} {$this->amount((string) $invoice->tax, 'USD')}</p>
            <p dir="ltr"><strong>{$this->amount((string) $invoice->total, 'USD')}</strong> · {$lbp} ل.ل.</p>
            <p dir="ltr">{$this->t('ui.quotes.rate', $locale)} {$rate}</p>
            <p>{$this->t('ui.quotes.vat_note', $locale, ['percent' => $this->vatPercent()])}</p>
        HTML);
    }

    public function receipt(Payment $payment, string $locale): string
    {
        $payment->loadMissing(['customer', 'allocations.invoice']);
        $locale = $locale === 'ar' ? 'ar' : 'en';
        $dir = $locale === 'ar' ? 'rtl' : 'ltr';
        $title = trans('ui.payments.receipt_title', [], $locale);
        $name = e($locale === 'ar'
            ? ($payment->customer?->name_ar ?: $payment->customer?->name_en)
            : $payment->customer?->name_en);
        $lbp = $payment->currency === 'LBP'
            ? $this->grouped((string) $payment->tender_amount)
            : $this->grouped($this->money->lbp((string) $payment->amount, (string) $payment->exchange_rate));
        $rows = '';

        foreach ($payment->allocations as $allocation) {
            $rows .= '<tr><td dir="ltr">'.e((string) $allocation->invoice?->number).'</td><td dir="ltr">'.e($this->amount((string) $allocation->amount, 'USD')).'</td></tr>';
        }

        return $this->shell($locale, $dir, $title, <<<HTML
            <h1>{$title}</h1>
            <p class="muted" dir="ltr">{$payment->number}</p>
            <p>{$this->t('ui.payments.method_'.$payment->method, $locale)} · {$this->t('ui.payments.status_'.$payment->status, $locale)}</p>
            <p>{$name}</p>
            <p dir="ltr"><strong>{$this->amount((string) $payment->amount, 'USD')}</strong> · {$lbp} ل.ل.</p>
            <p dir="ltr">{$this->t('ui.quotes.rate', $locale)} {$payment->exchange_rate}</p>
            <table><thead><tr><th>{$this->t('ui.billing.number', $locale)}</th><th>{$this->t('ui.billing.total', $locale)}</th></tr></thead><tbody>{$rows}</tbody></table>
        HTML);
    }

    /**
     * @param  list<LedgerEntry>  $rows
     */
    public function statement(Customer $customer, array $rows, string $locale, ?string $from, ?string $to): string
    {
        $locale = $locale === 'ar' ? 'ar' : 'en';
        $dir = $locale === 'ar' ? 'rtl' : 'ltr';
        $title = trans('ui.payments.statement_title', [], $locale);
        $name = e($locale === 'ar' ? ($customer->name_ar ?: $customer->name_en) : $customer->name_en);
        $body = '';

        foreach ($rows as $row) {
            $body .= '<tr><td>'.e($this->t('ui.payments.entry_'.$row->entry_type, $locale)).'</td><td dir="ltr">'.e($this->amount((string) $row->debit, 'USD')).'</td><td dir="ltr">'.e($this->amount((string) $row->credit, 'USD')).'</td><td dir="ltr">'.e($this->amount((string) $row->balance_after, 'USD')).'</td></tr>';
        }

        $range = e(trim(($from ?: '').' – '.($to ?: '')));

        return $this->shell($locale, $dir, $title, <<<HTML
            <h1>{$title}</h1>
            <p>{$name}</p>
            <p class="muted" dir="ltr">{$range}</p>
            <table>
                <thead><tr><th>{$this->t('ui.payments.entry', $locale)}</th><th>{$this->t('ui.payments.debit', $locale)}</th><th>{$this->t('ui.payments.credit', $locale)}</th><th>{$this->t('ui.billing.balance', $locale)}</th></tr></thead>
                <tbody>{$body}</tbody>
            </table>
            <p>{$this->t('ui.payments.statement_note', $locale)}</p>
        HTML);
    }

    public function contractBody(Contract $contract): string
    {
        if ($contract->status === 'signed' && is_string($contract->snapshot_html) && $contract->snapshot_html !== '') {
            return $contract->snapshot_html;
        }

        $locale = $contract->quote?->language === 'ar' ? 'ar' : 'en';
        $dir = $locale === 'ar' ? 'rtl' : 'ltr';

        return $this->shell($locale, $dir, (string) ($contract->number ?? 'Contract'), (string) $contract->body_html.'<p>'.$this->t('ui.quotes.terms_note', $locale).'</p>');
    }

    public function freeze(Contract $contract, string $signer, Request $request): string
    {
        $locale = $contract->quote?->language === 'ar' ? 'ar' : 'en';
        $dir = $locale === 'ar' ? 'rtl' : 'ltr';
        $when = now()->timezone((string) config('app.timezone'))->format('d/m/Y H:i');
        $audit = '<section class="audit"><h2>'.$this->t('ui.quotes.sign_title', $locale).'</h2><p>'.e($signer).'</p><p dir="ltr">'.e($when).'</p><p dir="ltr">'.e((string) $request->ip()).'</p><p>'.e((string) $request->userAgent()).'</p></section>';

        return $this->shell($locale, $dir, (string) ($contract->number ?? 'Contract'), (string) $contract->body_html.$audit);
    }

    private function shell(string $locale, string $dir, string $title, string $body): string
    {
        $font = e(url('/fonts/arabic-400.woff2'));
        $logo = e(url('/brand/fibes-icon.png'));

        return <<<HTML
<!DOCTYPE html>
<html lang="{$locale}" dir="{$dir}">
<head>
<meta charset="utf-8">
<title>{$title}</title>
<style>
@font-face { font-family: 'IBM Plex Sans Arabic'; src: url('{$font}') format('woff2'); font-weight: 400; }
body { margin: 0; color: #0E0F2D; background: #fff; font-family: 'IBM Plex Sans Arabic', 'Noto Naskh Arabic', sans-serif; }
.sheet { max-width: 800px; margin: 0 auto; padding: 32px 28px 48px; }
header { display: flex; justify-content: space-between; align-items: center; border-bottom: 4px solid #66C48F; padding-bottom: 16px; margin-bottom: 24px; }
img.logo { height: 42px; width: auto; }
h1 { font-size: 28px; margin: 0 0 8px; }
table { width: 100%; border-collapse: collapse; margin: 16px 0; }
th, td { text-align: start; padding: 8px 6px; border-bottom: 1px solid #e6e8ef; }
th { color: #5c6478; font-size: 12px; }
.muted { color: #5c6478; }
.qr svg { width: 120px; height: 120px; }
.qr p { font-size: 12px; word-break: break-all; }
.audit { margin-top: 28px; padding-top: 12px; border-top: 2px solid #0E0F2D; }
</style>
</head>
<body>
<div class="sheet">
<header><span dir="ltr"><img class="logo" alt="FIBES" src="{$logo}"></span><strong style="color:#66C48F">FIBES</strong></header>
{$body}
</div>
</body>
</html>
HTML;
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function t(string $key, string $locale, array $replace = []): string
    {
        return e(trans($key, $replace, $locale));
    }

    private function vatPercent(): string
    {
        return rtrim(rtrim((string) app(CommercialSettings::class)->get('vat_percent'), '0'), '.');
    }

    private function amount(string $value, string $currency): string
    {
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '00');
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole) ?? $whole;

        if ($currency === 'LBP') {
            return ($negative ? '-' : '').$whole.' ل.ل.';
        }

        if ($currency === '') {
            return ($negative ? '-' : '').$whole;
        }

        return ($negative ? '-' : '').'$'.$whole.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function grouped(string $value): string
    {
        $whole = explode('.', ltrim($value, '-'))[0];

        return preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole) ?? $whole;
    }
}

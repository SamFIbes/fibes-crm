<?php

namespace App\Services\Quotes;

use App\Mail\QuoteMail;
use App\Models\Approval;
use App\Models\Attachment;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Opportunity;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteEvent;
use App\Models\QuoteLine;
use App\Models\User;
use App\Services\Approvals\ApprovalDesk;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\PriceResolver;
use App\Services\Catalog\PromotionEngine;
use App\Services\Opportunities\OpportunityMoney;
use App\Services\Settings\CommercialSettings;
use App\Support\Access\AccessGate;
use App\Support\Phone\PhoneNumbers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class QuoteDesk
{
    public function __construct(
        private QuoteMachine $machine,
        private QuoteMath $math,
        private DiscountCap $caps,
        private PriceResolver $prices,
        private PromotionEngine $promos,
        private OpportunityMoney $lbp,
        private DocumentHtml $html,
        private DocumentDriver $driver,
        private AuditLogger $audit,
        private AccessGate $access,
        private PhoneNumbers $phones,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $actor, array $input, Request $request): Quote
    {
        $built = $this->build($actor, $input);
        $quote = Quote::query()->create([
            'status' => 'draft',
            'version' => 1,
            'opportunity_id' => $built['opportunity_id'],
            'customer_id' => $built['customer_id'],
            'customer_name' => $built['customer_name'],
            'customer_email' => $built['customer_email'],
            'customer_phone' => $built['customer_phone'],
            'owner_id' => $actor->id,
            'team_id' => $actor->teams()->value('teams.id'),
            'language' => $built['language'],
            'currency' => $built['currency'],
            'exchange_rate' => $built['rate'],
            'valid_until' => $built['valid_until'],
            'subtotal' => $built['totals']['subtotal'],
            'discount_total' => $built['totals']['discount'],
            'tax_total' => $built['totals']['tax'],
            'total' => $built['totals']['total'],
            'apply_vat' => $built['apply_vat'],
            'promo_code' => $built['promo'],
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $this->storeLines($quote, $built['lines']);
        $this->audit->record('quote.created', $actor, ['quote_id' => $quote->id, 'number' => $quote->fresh()->number], $request);
        $this->audit->record('quote.priced', $actor, [
            'quote_id' => $quote->id,
            'subtotal' => $built['usd']['subtotal'],
            'discount' => $built['usd']['discount'],
            'tax' => $built['usd']['tax'],
            'total' => $built['usd']['total'],
            'currency' => $built['currency'],
            'rate' => $built['rate'],
        ], $request);

        if (! empty($input['submit'])) {
            return $this->submit($quote->fresh() ?? $quote, $actor, $request, $built['assessment']);
        }

        return $quote->fresh() ?? $quote;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Quote $quote, User $actor, array $input, Request $request): Quote
    {
        if ($quote->status !== 'draft') {
            throw ValidationException::withMessages(['status' => __('ui.quotes.illegal')]);
        }

        $built = $this->build($actor, $input);
        $quote->fill([
            'opportunity_id' => $built['opportunity_id'],
            'customer_id' => $built['customer_id'],
            'customer_name' => $built['customer_name'],
            'customer_email' => $built['customer_email'],
            'customer_phone' => $built['customer_phone'],
            'language' => $built['language'],
            'currency' => $built['currency'],
            'exchange_rate' => $built['rate'],
            'valid_until' => $built['valid_until'],
            'subtotal' => $built['totals']['subtotal'],
            'discount_total' => $built['totals']['discount'],
            'tax_total' => $built['totals']['tax'],
            'total' => $built['totals']['total'],
            'apply_vat' => $built['apply_vat'],
            'promo_code' => $built['promo'],
            'updated_by' => $actor->id,
        ])->save();
        $quote->lines()->delete();
        $this->storeLines($quote, $built['lines']);
        $this->audit->record('quote.priced', $actor, [
            'quote_id' => $quote->id,
            'total' => $built['usd']['total'],
            'discount' => $built['usd']['discount'],
            'rate' => $built['rate'],
        ], $request);

        if (! empty($input['submit'])) {
            return $this->submit($quote->fresh() ?? $quote, $actor, $request, $built['assessment']);
        }

        return $quote;
    }

    /**
     * @param  array{needs: bool, type: string|null, percent: string, amount: string}|null  $assessment
     */
    public function submit(Quote $quote, User $actor, Request $request, ?array $assessment = null): Quote
    {
        if ($quote->status !== 'draft') {
            throw ValidationException::withMessages(['status' => __('ui.quotes.illegal')]);
        }

        $assessment ??= $this->assessStored($quote, $actor);

        if ($assessment['needs'] && is_string($assessment['type'])) {
            $approval = app(ApprovalDesk::class)->open(
                $actor,
                $assessment['type'],
                'quote',
                $quote->id,
                ['percent' => $assessment['percent'], 'amount' => $assessment['amount']],
                (string) $quote->number,
                $request,
            );
            $quote->refresh();
            $quote->approval_id = $approval->id;

            if ($approval->status === 'approved') {
                if ($quote->status !== 'approved') {
                    $this->transition($quote, 'approved', $actor, $request);
                }

                $quote->approver_name ??= $actor->name;
                $quote->approved_by ??= $actor->id;
            } elseif ($quote->status === 'draft') {
                $this->transition($quote, 'pending_approval', $actor, $request);
            }

            $quote->save();

            return $quote->fresh() ?? $quote;
        }

        $this->transition($quote, 'approved', $actor, $request);
        $quote->save();

        return $quote->fresh() ?? $quote;
    }

    public function approveFromInbox(Quote $quote, User $actor, Approval $approval): void
    {
        if (! in_array($quote->status, ['draft', 'pending_approval'], true)) {
            return;
        }

        $this->transition($quote, 'approved', $actor, null);
        $quote->approver_name = $actor->name;
        $quote->approved_by = $actor->id;
        $quote->approval_id = $approval->id;
        $quote->save();
    }

    public function reopen(Quote $quote, ?User $actor, ?Request $request): void
    {
        if ($quote->status !== 'pending_approval') {
            return;
        }

        $this->transition($quote, 'draft', $actor, $request);
        $quote->save();
    }

    public function revise(Quote $quote, User $actor, Request $request): Quote
    {
        if (! in_array($quote->status, ['approved', 'sent'], true)) {
            throw ValidationException::withMessages(['status' => __('ui.quotes.illegal')]);
        }

        $copy = $quote->replicate([
            'ulid', 'public_token', 'sent_at', 'accepted_at', 'rejected_at', 'approval_id',
            'pdf_attachment_id', 'approver_name', 'approved_by',
        ]);
        $copy->version = $quote->version + 1;
        $copy->parent_id = $quote->id;
        $copy->status = 'draft';
        $copy->created_by = $actor->id;
        $copy->updated_by = $actor->id;
        $copy->save();

        foreach ($quote->lines as $line) {
            $fresh = $line->replicate();
            $fresh->quote_id = $copy->id;
            $fresh->save();
        }

        $this->audit->record('quote.revised', $actor, [
            'quote_id' => $copy->id,
            'from_id' => $quote->id,
            'version' => $copy->version,
        ], $request);

        return $copy->fresh() ?? $copy;
    }

    public function send(Quote $quote, User $actor, string $channel, Request $request): string
    {
        if ($quote->status !== 'approved') {
            throw ValidationException::withMessages(['status' => __('ui.quotes.illegal')]);
        }

        if ($channel === 'email' && trim((string) $quote->customer_email) === '') {
            throw ValidationException::withMessages(['customer_email' => __('ui.quotes.email')]);
        }

        $share = $this->shareLink($quote);
        $link = $share;

        if ($channel === 'whatsapp') {
            $phone = ltrim((string) $quote->customer_phone, '+');
            $link = 'https://wa.me/'.$phone.'?text='.rawurlencode($share);
        }

        $this->transition($quote, 'sent', $actor, $request);
        $quote->save();

        if ($channel === 'email') {
            Mail::to((string) $quote->customer_email)->send(new QuoteMail(
                trans('ui.quotes.document_title', [], $quote->language),
                trans('ui.quotes.document_intro', [], $quote->language),
                $link,
            ));
        }

        return $link;
    }

    public function accept(Quote $quote, ?User $actor, Request $request): Quote
    {
        $this->expireIfNeeded($quote, $actor, $request);
        $quote->refresh();

        if ($quote->status === 'expired') {
            throw ValidationException::withMessages(['quote' => __('ui.quotes.expired')]);
        }

        $this->transition($quote, 'accepted', $actor, $request);
        $quote->save();

        return $quote;
    }

    public function reject(Quote $quote, ?User $actor, Request $request): Quote
    {
        $this->transition($quote, 'rejected', $actor, $request);
        $quote->save();

        return $quote;
    }

    public function canPrint(Quote $quote): bool
    {
        return in_array($quote->status, ['approved', 'sent', 'accepted'], true);
    }

    /**
     * @return array{body: string, mime: string}
     */
    public function document(Quote $quote, ?User $actor, ?Request $request): array
    {
        $quote->loadMissing('lines');
        $html = $this->html->quote($quote);
        $printed = $this->driver->print($html);

        if ($quote->pdf_attachment_id === null) {
            $file = $this->storeBytes($printed['body'], $printed['mime'], 'quote-'.$quote->number, $quote, $actor);
            $quote->pdf_attachment_id = $file->id;
            $quote->save();
        }

        $this->audit->record('quote.printed', $actor, ['quote_id' => $quote->id], $request);

        return $printed;
    }

    public function shareLink(Quote $quote): string
    {
        $until = $quote->valid_until?->copy()->endOfDay();

        if ($until === null || $until->isPast()) {
            $until = now()->addDays((int) app(CommercialSettings::class)->get('quote_valid_days'));
        }

        return URL::temporarySignedRoute('quotes.public', $until, ['quote' => $quote->id]);
    }

    public function visible(User $user): Builder
    {
        $query = Quote::query()->whereNull('archived_at');

        if ($this->access->allows($user, 'quotes.create')) {
            return $this->access->restrict($query, $user, 'quotes.create', 'owner_id', 'team_id');
        }

        if ($this->access->allows($user, 'quotes.approve')) {
            return $query;
        }

        $permission = $this->access->allows($user, 'contracts.manage') ? 'contracts.manage' : 'contracts.view';

        if ($this->access->allows($user, $permission)) {
            return $this->access->restrict($query, $user, $permission, 'owner_id', 'team_id');
        }

        return $query->whereRaw('0 = 1');
    }

    public function find(User $user, int $id): Quote
    {
        $quote = Quote::query()->find($id);
        abort_unless($quote instanceof Quote, 404);
        abort_unless($this->visible($user)->whereKey($quote->id)->exists(), 403);

        return $quote;
    }

    public function expireDue(): int
    {
        $count = 0;
        Quote::query()
            ->where('status', 'sent')
            ->whereDate('valid_until', '<', now()->toDateString())
            ->orderBy('id')
            ->each(function (Quote $quote) use (&$count): void {
                $this->transition($quote, 'expired', null, null);
                $quote->save();
                $count++;
            });

        return $count;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function build(User $actor, array $input): array
    {
        $language = ($input['language'] ?? 'en') === 'ar' ? 'ar' : 'en';
        $currency = ($input['currency'] ?? 'USD') === 'LBP' ? 'LBP' : 'USD';
        $applyVat = filter_var($input['apply_vat'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $deal = null;
        $customer = null;

        if (! empty($input['opportunity_id'])) {
            $deal = Opportunity::query()->find((int) $input['opportunity_id']);

            if ($deal instanceof Opportunity) {
                $customer = $deal->customer;
            }
        }

        if (! $customer instanceof Customer && ! empty($input['customer_id'])) {
            $customer = Customer::query()->find((int) $input['customer_id']);
        }

        $phone = $input['customer_phone'] ?? $customer?->whatsapp_e164;

        if (is_string($phone) && trim($phone) !== '') {
            try {
                $phone = $this->phones->normalize($phone);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['customer_phone' => __('ui.quotes.phone')]);
            }
        } else {
            $phone = null;
        }

        $rows = $this->rows($input);
        $rate = $this->currentRate();
        $taxRate = $applyVat ? $this->math->money((string) app(CommercialSettings::class)->get('vat_percent')) : '0.00';
        $lines = [];
        $flags = [];

        foreach ($rows as $index => $row) {
            $product = Product::query()->where('is_active', true)->find((int) ($row['product_id'] ?? 0));

            if (! $product instanceof Product) {
                throw ValidationException::withMessages(['product_id' => __('ui.quotes.product')]);
            }

            $catalog = $this->prices->resolve($product->id, now()->toDateString(), $customer?->type, $deal?->area_id);

            if ($catalog === null) {
                throw ValidationException::withMessages(['product_id' => __('ui.quotes.product')]);
            }

            $list = $this->math->money($catalog['price']);
            $posted = $row['unit_price'] ?? null;
            $unit = is_numeric($posted) ? $this->math->money((string) $posted) : $list;
            $custom = bccomp($unit, $list, 2) !== 0;
            $math = $this->math->line((string) ($row['qty'] ?? '1'), $unit, (string) ($row['discount_pct'] ?? '0'), $taxRate);
            $free = in_array($product->kind, ['installation', 'equipment'], true) && bccomp($math['net'], '0', 2) === 0;
            $name = $language === 'ar' ? $product->name_ar : $product->name_en;
            $lines[] = [
                'product_id' => $product->id,
                'description' => is_string($row['description'] ?? null) && $row['description'] !== '' ? $row['description'] : $name,
                'qty' => $this->math->money((string) ($row['qty'] ?? '1')),
                'unit_price' => $unit,
                'discount_pct' => $math['percent'],
                'discount_amount' => $math['discount'],
                'tax_rate' => $taxRate,
                'line_total' => $math['total'],
                'is_recurring' => $product->kind === 'plan',
                'custom_price' => $custom,
                'sort' => $index,
                'gross' => $math['gross'],
                'discount' => $math['discount'],
                'tax' => $math['tax'],
                'total' => $math['total'],
            ];
            $flags[] = ['percent' => $math['percent'], 'amount' => $math['discount'], 'custom' => $custom, 'free' => $free];
        }

        $totals = $this->math->sum($lines);
        $promo = isset($input['promo_code']) && is_string($input['promo_code']) && trim($input['promo_code']) !== ''
            ? strtoupper(trim($input['promo_code']))
            : null;

        if ($promo !== null) {
            $first = $lines[0]['product_id'] ?? null;
            $result = $this->promos->evaluate([$promo], $totals['subtotal'], [
                'product_id' => $first,
                'customer_type' => $customer?->type,
                'customer_id' => $customer?->id,
            ]);

            if ($result['refused'] !== []) {
                throw ValidationException::withMessages(['promo_code' => $result['refused'][0]['message']]);
            }

            $saving = '0.00';
            $needsPromo = false;

            foreach ($result['applied'] as $applied) {
                $saving = bcadd($saving, (string) ($applied['saving'] ?? '0'), 2);
                $needsPromo = $needsPromo || ! empty($applied['needs_approval']);
            }

            if (bccomp($saving, '0', 2) === 1) {
                $totals['discount'] = bcadd($totals['discount'], $saving, 2);
                $net = bcsub($totals['subtotal'], $totals['discount'], 2);

                if (bccomp($net, '0', 2) === -1) {
                    $net = '0.00';
                }

                $totals['tax'] = $applyVat ? bcdiv(bcmul($net, $taxRate, 4), '100', 2) : '0.00';
                $totals['total'] = bcadd($net, $totals['tax'], 2);

                if ($flags !== []) {
                    $flags[0]['amount'] = bcadd($flags[0]['amount'], $saving, 2);
                    $extra = bccomp($totals['subtotal'], '0', 2) === 1
                        ? bcdiv(bcmul($saving, '100', 4), $totals['subtotal'], 2)
                        : '0.00';

                    if (bccomp($extra, $flags[0]['percent'], 2) === 1) {
                        $flags[0]['percent'] = $extra;
                    }
                }
            }
        } else {
            $needsPromo = false;
        }

        $assessment = $this->caps->assess($actor, $flags, $totals['subtotal'], $totals['discount']);

        if ($needsPromo && ! $assessment['needs']) {
            $assessment['needs'] = true;
            $assessment['type'] = 'promo_approval';
        }

        $usd = $totals;

        if ($currency === 'LBP') {
            $totals = [
                'subtotal' => $this->asLbp($totals['subtotal'], $rate),
                'discount' => $this->asLbp($totals['discount'], $rate),
                'tax' => $this->asLbp($totals['tax'], $rate),
                'total' => $this->asLbp($totals['total'], $rate),
            ];

            foreach ($lines as $index => $line) {
                $lines[$index]['unit_price'] = $this->asLbp($line['unit_price'], $rate);
                $lines[$index]['discount_amount'] = $this->asLbp($line['discount_amount'], $rate);
                $lines[$index]['line_total'] = $this->asLbp($line['line_total'], $rate);
            }
        }

        $valid = $input['valid_until'] ?? null;

        return [
            'lines' => $lines,
            'totals' => $totals,
            'usd' => $usd,
            'assessment' => $assessment,
            'rate' => $rate,
            'currency' => $currency,
            'language' => $language,
            'apply_vat' => $applyVat,
            'promo' => $promo,
            'opportunity_id' => $deal?->id,
            'customer_id' => $customer?->id,
            'customer_name' => $input['customer_name'] ?? ($language === 'ar' ? $customer?->name_ar : $customer?->name_en),
            'customer_email' => $input['customer_email'] ?? $customer?->email,
            'customer_phone' => $phone,
            'valid_until' => is_string($valid) && $valid !== '' ? $valid : now()->addDays((int) app(CommercialSettings::class)->get('quote_valid_days'))->toDateString(),
        ];
    }

    /**
     * @return array{needs: bool, type: string|null, percent: string, amount: string}
     */
    private function assessStored(Quote $quote, User $actor): array
    {
        $quote->loadMissing('lines.product');
        $flags = [];
        $discount = '0.00';
        $subtotal = '0.00';

        foreach ($quote->lines as $line) {
            $flags[] = [
                'percent' => (string) $line->discount_pct,
                'amount' => (string) $line->discount_amount,
                'custom' => (bool) $line->custom_price,
                'free' => in_array($line->product?->kind, ['installation', 'equipment'], true) && bccomp((string) $line->discount_pct, '100', 2) === 0,
            ];
            $discount = bcadd($discount, (string) $line->discount_amount, 2);
            $subtotal = bcadd($subtotal, bcmul((string) $line->qty, (string) $line->unit_price, 2), 2);
        }

        if ($quote->currency === 'LBP' && bccomp((string) $quote->exchange_rate, '0', 4) === 1) {
            $subtotal = bcdiv($subtotal, (string) $quote->exchange_rate, 2);
            $discount = bcdiv($discount, (string) $quote->exchange_rate, 2);

            foreach ($flags as $index => $flag) {
                $flags[$index]['amount'] = bcdiv($flag['amount'], (string) $quote->exchange_rate, 2);
            }
        }

        return $this->caps->assess($actor, $flags, $subtotal, $discount);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    private function rows(array $input): array
    {
        if (isset($input['lines']) && is_array($input['lines']) && $input['lines'] !== []) {
            return array_values($input['lines']);
        }

        return [[
            'product_id' => $input['product_id'] ?? null,
            'qty' => $input['qty'] ?? '1',
            'discount_pct' => $input['discount_pct'] ?? '0',
            'unit_price' => $input['unit_price'] ?? null,
            'description' => $input['description'] ?? null,
        ]];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function storeLines(Quote $quote, array $lines): void
    {
        foreach ($lines as $line) {
            QuoteLine::query()->create([
                'quote_id' => $quote->id,
                'product_id' => $line['product_id'],
                'description' => $line['description'],
                'qty' => $line['qty'],
                'unit_price' => $line['unit_price'],
                'discount_pct' => $line['discount_pct'],
                'discount_amount' => $line['discount_amount'],
                'tax_rate' => $line['tax_rate'],
                'line_total' => $line['line_total'],
                'is_recurring' => $line['is_recurring'],
                'custom_price' => $line['custom_price'],
                'sort' => $line['sort'],
            ]);
        }
    }

    private function transition(Quote $quote, string $to, ?User $actor, ?Request $request): void
    {
        $from = (string) $quote->status;
        $this->machine->assert($from, $to);
        $quote->status = $to;

        if ($to === 'sent') {
            $quote->sent_at = now();
        }

        if ($to === 'accepted') {
            $quote->accepted_at = now();
        }

        if ($to === 'rejected') {
            $quote->rejected_at = now();
        }

        QuoteEvent::query()->create([
            'quote_id' => $quote->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor?->id,
        ]);
        $this->audit->record('quote.'.$to, $actor, [
            'quote_id' => $quote->id,
            'from' => $from,
            'to' => $to,
            'total' => (string) $quote->total,
            'currency' => $quote->currency,
        ], $request);
    }

    private function expireIfNeeded(Quote $quote, ?User $actor, ?Request $request): void
    {
        if ($quote->status !== 'sent' || $quote->valid_until === null) {
            return;
        }

        if ($quote->valid_until->toDateString() >= now()->toDateString()) {
            return;
        }

        $this->transition($quote, 'expired', $actor, $request);
        $quote->save();
    }

    private function currentRate(): string
    {
        $rate = ExchangeRate::query()->latest('id')->value('usd_lbp');

        if (is_string($rate) && $rate !== '') {
            return bcadd($rate, '0', 4);
        }

        return app(CommercialSettings::class)->rate();
    }

    private function asLbp(string $usd, string $rate): string
    {
        return bcadd($this->lbp->lbp($usd, $rate), '0', 2);
    }

    private function storeBytes(string $body, string $mime, string $name, Quote $quote, ?User $actor): Attachment
    {
        $extension = str_contains($mime, 'pdf') ? 'pdf' : 'html';
        $path = 'quotes/'.$quote->id.'/'.$name.'.'.$extension;
        Storage::disk('local')->put($path, $body);

        return Attachment::query()->create([
            'disk' => 'local',
            'path' => $path,
            'original_name' => $name.'.'.$extension,
            'mime' => $mime,
            'size' => strlen($body),
            'sha256' => hash('sha256', $body),
            'scan_status' => 'skipped',
            'uploaded_by' => $actor?->id,
            'attachable_type' => $quote->getMorphClass(),
            'attachable_id' => $quote->id,
        ]);
    }
}

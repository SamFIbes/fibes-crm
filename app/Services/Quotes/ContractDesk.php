<?php

namespace App\Services\Quotes;

use App\Models\Attachment;
use App\Models\Clause;
use App\Models\Contract;
use App\Models\DocumentTemplate;
use App\Models\Quote;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\CommercialSettings;
use App\Support\Access\AccessGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ContractDesk
{
    public function __construct(
        private TemplateRenderer $templates,
        private DocumentHtml $html,
        private DocumentDriver $driver,
        private SmsDriver $sms,
        private AuditLogger $audit,
        private AccessGate $access,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function open(Quote $quote, User $actor, array $input, Request $request): Contract
    {
        if ($quote->status !== 'accepted') {
            throw ValidationException::withMessages(['quote' => __('ui.quotes.illegal')]);
        }

        $locale = $quote->language === 'ar' ? 'ar' : 'en';
        $template = DocumentTemplate::query()
            ->where('type', 'contract')
            ->where('locale', $locale)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        if (! $template instanceof DocumentTemplate) {
            throw ValidationException::withMessages(['template' => __('ui.quotes.contract')]);
        }

        $commercial = app(CommercialSettings::class);
        $term = (int) ($input['term_months'] ?? $commercial->get('term_months'));
        $fee = (string) $commercial->money('early_termination_fee');
        $plan = $quote->lines()->where('is_recurring', true)->value('description')
            ?? $quote->lines()->value('description')
            ?? '';
        $vars = [
            'customer.name' => (string) ($quote->customer_name ?: ''),
            'plan.name' => (string) $plan,
            'term_months' => (string) $term,
            'early_termination_fee' => $fee,
            'quote.number' => (string) $quote->number,
            'quote.total' => (string) $quote->total,
            'quote.currency' => (string) $quote->currency,
        ];
        $body = $this->templates->render($template->body, $vars);
        $clauses = Clause::query()->where('locale', $locale)->where('is_active', true)->orderBy('sort')->get();

        foreach ($clauses as $clause) {
            $body .= '<p><strong>'.e($clause->title).'</strong> '.$this->templates->render($clause->body, $vars).'</p>';
        }

        $contract = Contract::query()->create([
            'customer_id' => $quote->customer_id,
            'opportunity_id' => $quote->opportunity_id,
            'quote_id' => $quote->id,
            'template_id' => $template->id,
            'version' => 1,
            'status' => 'draft',
            'term_months' => $term,
            'start_on' => now()->toDateString(),
            'end_on' => now()->addMonths($term)->toDateString(),
            'auto_renew' => filter_var($input['auto_renew'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'early_termination_fee' => $fee,
            'currency' => $quote->currency,
            'body_html' => $body,
            'signer_phone' => $quote->customer_phone,
            'owner_id' => $quote->owner_id ?: $actor->id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $this->audit->record('contract.created', $actor, [
            'contract_id' => $contract->id,
            'quote_id' => $quote->id,
            'template_id' => $template->id,
        ], $request);

        return $contract->fresh() ?? $contract;
    }

    public function sendCode(Contract $contract, Request $request): void
    {
        if ($contract->status === 'signed') {
            throw ValidationException::withMessages(['contract' => __('ui.quotes.illegal')]);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $phone = (string) ($contract->signer_phone ?: '+96100000000');
        $this->sms->send($phone, trans('ui.quotes.code_sent', [], $contract->quote?->language ?? 'en'), $code, $this->cacheKey($contract));
        $this->audit->record('contract.otp', null, ['contract_id' => $contract->id], $request);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function sign(Contract $contract, array $input, Request $request, ?User $actor, bool $scanned = false): Contract
    {
        if ($contract->status === 'signed') {
            throw ValidationException::withMessages(['contract' => __('ui.quotes.illegal')]);
        }

        if (! filter_var($input['consent'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw ValidationException::withMessages(['consent' => __('ui.quotes.consent_needed')]);
        }

        $name = trim((string) ($input['signer_name'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages(['signer_name' => __('ui.quotes.signer')]);
        }

        $contract->loadMissing('quote');
        $signature = null;

        if ($scanned) {
            $file = $input['file'] ?? null;

            if (! $file instanceof UploadedFile) {
                throw ValidationException::withMessages(['file' => __('ui.quotes.scan')]);
            }

            $signature = $this->storeUpload($file, $contract, $actor);
        } else {
            $expected = Cache::get($this->cacheKey($contract));
            $given = (string) ($input['otp'] ?? '');

            if (! is_string($expected) || $given === '' || ! hash_equals($expected, $given)) {
                throw ValidationException::withMessages(['otp' => __('ui.quotes.bad_code')]);
            }

            $signature = $this->storePad((string) ($input['signature'] ?? ''), $contract, $actor);
        }

        $snapshot = $this->html->freeze($contract, $name, $request);
        $digest = hash('sha256', $snapshot);
        $stored = $this->storeBytes($snapshot, 'contract-'.$contract->number, $contract, $actor);
        $contract->fill([
            'status' => 'signed',
            'snapshot_html' => $snapshot,
            'sha256' => $digest,
            'signed_at' => now(),
            'signer_name' => $name,
            'signer_ip' => $request->ip(),
            'signer_user_agent' => $request->userAgent() ? substr((string) $request->userAgent(), 0, 500) : null,
            'signature_attachment_id' => $signature?->id,
            'signed_pdf_attachment_id' => $stored->id,
            'updated_by' => $actor?->id,
        ])->save();
        Cache::forget($this->cacheKey($contract));
        $this->audit->record('contract.signed', $actor, [
            'contract_id' => $contract->id,
            'sha256' => $digest,
        ], $request);

        return $contract;
    }

    /**
     * @return array{body: string, mime: string}
     */
    public function document(Contract $contract): array
    {
        $contract->loadMissing('quote');

        return $this->driver->print($this->html->contractBody($contract));
    }

    public function visible(User $user): Builder
    {
        $query = Contract::query()->whereNull('archived_at');
        $permission = $this->access->allows($user, 'contracts.manage') ? 'contracts.manage' : 'contracts.view';

        if ($this->access->allows($user, $permission)) {
            return $this->access->restrict($query, $user, $permission, 'owner_id', 'owner_id');
        }

        if ($this->access->allows($user, 'quotes.approve')) {
            return $query;
        }

        return $query->whereRaw('0 = 1');
    }

    public function find(User $user, int $id): Contract
    {
        $contract = Contract::query()->find($id);
        abort_unless($contract instanceof Contract, 404);
        abort_unless($this->visible($user)->whereKey($contract->id)->exists(), 403);

        return $contract;
    }

    private function cacheKey(Contract $contract): string
    {
        return 'fibes-sms:'.$contract->id;
    }

    private function storePad(string $data, Contract $contract, ?User $actor): ?Attachment
    {
        if (! str_starts_with($data, 'data:image/png;base64,')) {
            throw ValidationException::withMessages(['signature' => __('ui.quotes.sign')]);
        }

        $raw = base64_decode(substr($data, strlen('data:image/png;base64,')), true);

        if (! is_string($raw) || $raw === '' || strlen($raw) > 400000) {
            throw ValidationException::withMessages(['signature' => __('ui.quotes.sign')]);
        }

        return $this->storeBytes($raw, 'signature-'.$contract->id.'.png', $contract, $actor, 'image/png');
    }

    private function storeUpload(UploadedFile $file, Contract $contract, ?User $actor): Attachment
    {
        $path = $file->store('contracts/'.$contract->id, 'local');
        $absolute = Storage::disk('local')->path((string) $path);

        return Attachment::query()->create([
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType() ?: 'application/octet-stream',
            'size' => $file->getSize() ?: 0,
            'sha256' => hash_file('sha256', $absolute) ?: '',
            'scan_status' => 'skipped',
            'uploaded_by' => $actor?->id,
            'attachable_type' => $contract->getMorphClass(),
            'attachable_id' => $contract->id,
        ]);
    }

    private function storeBytes(string $body, string $name, Contract $contract, ?User $actor, string $mime = 'text/html'): Attachment
    {
        $path = 'contracts/'.$contract->id.'/'.$name;
        Storage::disk('local')->put($path, $body);

        return Attachment::query()->create([
            'disk' => 'local',
            'path' => $path,
            'original_name' => $name,
            'mime' => $mime,
            'size' => strlen($body),
            'sha256' => hash('sha256', $body),
            'scan_status' => 'skipped',
            'uploaded_by' => $actor?->id,
            'attachable_type' => $contract->getMorphClass(),
            'attachable_id' => $contract->id,
        ]);
    }
}

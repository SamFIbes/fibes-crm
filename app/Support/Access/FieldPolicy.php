<?php

namespace App\Support\Access;

use App\Models\User;
use Carbon\CarbonInterface;

class FieldPolicy
{
    public function __construct(private AccessGate $access) {}

    /**
     * @param  array<string, mixed>  $record
     * @param  array{assigned_job?: bool, job_starts_at?: CarbonInterface|null, job_ends_at?: CarbonInterface|null}  $context
     * @return array<string, mixed>
     */
    public function present(User $viewer, array $record, array $context = []): array
    {
        if (! $this->canSeePii($viewer, $context)) {
            foreach (['phone', 'whatsapp', 'email', 'address', 'national_id'] as $field) {
                if (! array_key_exists($field, $record)) {
                    continue;
                }

                $record[$field] = $this->auditorMask($viewer, $field, $record[$field]);
            }
        }

        if (! $this->access->allows($viewer, 'billing.view_amounts') && ! $this->access->allows($viewer, 'commissions.view')) {
            foreach (['balance', 'amount', 'invoice_total', 'commission'] as $field) {
                unset($record[$field]);
            }
        }

        if (! $this->access->allows($viewer, 'billing.view_cost')) {
            unset($record['cost'], $record['margin']);
        }

        if ($this->isReseller($viewer)) {
            unset($record['internal_note']);
        }

        unset($record['radius_password']);

        return $record;
    }

    /**
     * @param  array{assigned_job?: bool, job_starts_at?: CarbonInterface|null, job_ends_at?: CarbonInterface|null}  $context
     */
    public function canSeePii(User $viewer, array $context = []): bool
    {
        if (! $this->access->allows($viewer, 'customers.view_pii')) {
            return false;
        }

        if (! $this->isFieldRole($viewer)) {
            return true;
        }

        if (($context['assigned_job'] ?? false) !== true) {
            return false;
        }

        $start = $context['job_starts_at'] ?? null;
        $end = $context['job_ends_at'] ?? null;

        if (! $start instanceof CarbonInterface || ! $end instanceof CarbonInterface) {
            return false;
        }

        $now = now();

        return $now->greaterThanOrEqualTo($start->copy()->subDay())
            && $now->lessThanOrEqualTo($end->copy()->addDay());
    }

    private function auditorMask(User $viewer, string $field, mixed $value): mixed
    {
        if (! $viewer->hasRole('auditor') || ! is_string($value) || $value === '') {
            return $this->isFieldRole($viewer) ? null : $value;
        }

        if ($field === 'phone' || $field === 'whatsapp') {
            return $this->maskPhone($value);
        }

        if ($field === 'email') {
            return $this->maskEmail($value);
        }

        return '•••';
    }

    public function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) < 6) {
            return '•••';
        }

        return '+'.substr($digits, 0, 3).' '.substr($digits, 3, 1).'• ••• •'.substr($digits, -2);
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);

        if (count($parts) !== 2) {
            return '•••';
        }

        return substr($parts[0], 0, 1).'•••@'.$parts[1];
    }

    private function isFieldRole(User $viewer): bool
    {
        return $viewer->hasRole('field_technician') || $viewer->hasRole('installer');
    }

    private function isReseller(User $viewer): bool
    {
        return $viewer->hasRole('reseller');
    }
}

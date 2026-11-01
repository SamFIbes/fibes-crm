<?php

namespace App\Services\Opportunities;

use App\Models\CoverageCheck;
use App\Models\Opportunity;
use App\Models\SurveyResult;

class CheckedCoverageGate implements CoverageGate
{
    public function proposalAllowed(Opportunity $opportunity): bool
    {
        $check = null;

        if ($opportunity->coverage_check_id) {
            $check = CoverageCheck::query()->find($opportunity->coverage_check_id);
        }

        if (! $check instanceof CoverageCheck) {
            $check = CoverageCheck::query()
                ->where('subject_type', 'opportunity')
                ->where('subject_id', $opportunity->id)
                ->latest('id')
                ->first();
        }

        if (! $check instanceof CoverageCheck && $opportunity->lead_id) {
            $check = CoverageCheck::query()
                ->where('subject_type', 'lead')
                ->where('subject_id', $opportunity->lead_id)
                ->latest('id')
                ->first();
        }

        if ($check instanceof CoverageCheck && $check->result === 'covered') {
            return true;
        }

        return SurveyResult::query()
            ->where('feasible', true)
            ->whereHas('job', function ($query) use ($opportunity) {
                $query->where('type', 'survey')->where(function ($inner) use ($opportunity) {
                    $inner->where('opportunity_id', $opportunity->id)
                        ->orWhere('customer_id', $opportunity->customer_id);
                });
            })
            ->exists();
    }
}

<?php

namespace App\Services\Files;

use App\Models\Attachment;
use App\Models\User;
use App\Services\Customers\CustomerRecords;
use App\Services\Jobs\FieldWork;
use App\Services\Quotes\ContractDesk;
use App\Services\Quotes\QuoteDesk;
use App\Services\Tickets\TicketRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which attachments a person may list, download, or delete.
 *
 * A file that belongs to a record (customer, ticket, job, quote, contract) is only
 * visible to someone who can see that record. Files with no parent stay visible to
 * anyone allowed onto the Files page. Files on any other kind of record are hidden.
 */
class AttachmentScope
{
    public function __construct(
        private CustomerRecords $customers,
        private TicketRecords $tickets,
        private FieldWork $jobs,
        private QuoteDesk $quotes,
        private ContractDesk $contracts,
    ) {}

    /**
     * @return Builder<Attachment>
     */
    public function visible(User $viewer): Builder
    {
        $parents = [
            $this->customers->query($viewer),
            $this->tickets->query($viewer),
            $this->jobs->query($viewer),
            $this->quotes->visible($viewer),
            $this->contracts->visible($viewer),
        ];

        return Attachment::query()->where(function (Builder $outer) use ($parents) {
            $outer->where(fn (Builder $free) => $free->whereNull('attachable_type')->whereNull('attachable_id'));

            foreach ($parents as $parent) {
                $model = $parent->getModel();
                $ids = $parent->reorder()->select($model->getQualifiedKeyName());

                $outer->orWhere(fn (Builder $owned) => $owned
                    ->where('attachable_type', $model->getMorphClass())
                    ->whereIn('attachable_id', $ids));
            }
        });
    }

    public function canSee(User $viewer, Attachment $attachment): bool
    {
        return $this->visible($viewer)->whereKey($attachment->getKey())->exists();
    }
}

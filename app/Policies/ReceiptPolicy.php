<?php

namespace App\Policies;

use App\Enums\ReceiptStatus;
use App\Models\Receipt;
use App\Models\User;

class ReceiptPolicy
{
    public function viewAny(User $u): bool
    {
        return $u->hasPermission('receipts.view') || $u->hasPermission('receipts.view_all');
    }

    public function view(User $u, Receipt $r): bool
    {
        return $u->hasPermission('receipts.view_all')
            || ($u->hasPermission('receipts.view') && $r->created_by === $u->id);
    }

    public function create(User $u): bool
    {
        return $u->hasPermission('receipts.create');
    }

    public function update(User $u, Receipt $r): bool
    {
        if (! $u->hasPermission('receipts.edit') || ! $this->view($u, $r) || ! $r->isEditable()) {
            return false;
        }

        // Encoders may only edit their own drafts/recorded; verified ones need finance rights.
        return $r->status !== ReceiptStatus::Verified || $u->hasPermission('receipts.verify');
    }

    public function verify(User $u, Receipt $r): bool
    {
        return $u->hasPermission('receipts.verify') && $r->status === ReceiptStatus::Recorded;
    }

    public function process(User $u, Receipt $r): bool
    {
        return $u->hasPermission('receipts.verify') && $r->status === ReceiptStatus::Verified;
    }

    public function archive(User $u, Receipt $r): bool
    {
        return $u->hasPermission('receipts.verify') && $r->status === ReceiptStatus::Processed;
    }

    public function cancel(User $u, Receipt $r): bool
    {
        return $u->hasPermission('receipts.cancel')
            && ! in_array($r->status, [ReceiptStatus::Archived, ReceiptStatus::Cancelled], true);
    }
}

<?php

namespace App\Policies;

use App\Enums\ChequeStatus;
use App\Models\Cheque;
use App\Models\User;

class ChequePolicy
{
    public function viewAny(User $u): bool
    {
        return $u->hasPermission('cheques.view') || $u->hasPermission('cheques.view_all');
    }

    public function view(User $u, Cheque $c): bool
    {
        return $u->hasPermission('cheques.view_all')
            || ($u->hasPermission('cheques.view') && $c->created_by === $u->id);
    }

    public function create(User $u): bool
    {
        return $u->hasPermission('cheques.create');
    }

    /** Full edit of a draft. Encoders only touch their own drafts. */
    public function update(User $u, Cheque $c): bool
    {
        if (! $u->hasPermission('cheques.edit') || ! $this->view($u, $c)) {
            return false;
        }
        if ($c->status === ChequeStatus::Draft) {
            return true;
        }

        // After Prepared only people who can prepare may adjust descriptive fields.
        return $u->hasPermission('cheques.prepare')
            && ! in_array($c->status, [ChequeStatus::Cancelled, ChequeStatus::Voided], true);
    }

    public function prepare(User $u, Cheque $c): bool
    {
        return $u->hasPermission('cheques.prepare') && $this->view($u, $c) && $c->status === ChequeStatus::Draft;
    }

    public function release(User $u, Cheque $c): bool
    {
        return $u->hasPermission('cheques.release') && $c->status === ChequeStatus::Printed;
    }

    public function clear(User $u, Cheque $c): bool
    {
        return $u->hasPermission('cheques.clear')
            && in_array($c->status, [ChequeStatus::Released, ChequeStatus::ForClearing], true);
    }

    public function stop(User $u, Cheque $c): bool
    {
        return $u->hasPermission('cheques.cancel') && in_array($c->status, ChequeStatus::stoppable(), true);
    }
}

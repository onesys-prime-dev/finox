<?php

namespace App\Policies;

use App\Models\PrintJob;
use App\Models\User;

class PrintJobPolicy
{
    public function view(User $u, PrintJob $j): bool
    {
        return $u->hasPermission('print.print') && ($j->user_id === $u->id || $u->hasPermission('print.reprint') || $u->isAdmin());
    }

    public function confirm(User $u, PrintJob $j): bool
    {
        return $u->hasPermission('print.print');
    }
}

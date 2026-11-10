<?php

namespace App\Policies;

use App\Models\DocumentFile;
use App\Models\User;

class DocumentFilePolicy
{
    public function viewAny(User $u): bool
    {
        return $u->hasPermission('documents.view') || $u->hasPermission('documents.upload');
    }

    /** Streams only after this check — files have no public URL. */
    public function view(User $u, DocumentFile $d): bool
    {
        if ($u->hasPermission('documents.view') && ! $d->documentable_type) {
            return true;
        }
        if ($d->uploaded_by === $u->id) {
            return true;
        }
        $parent = $d->documentable;
        if ($parent) {
            return $u->can('view', $parent) && ($u->hasPermission('documents.view') || $u->hasPermission('documents.upload'));
        }

        return false;
    }

    public function upload(User $u): bool
    {
        return $u->hasPermission('documents.upload');
    }

    public function replace(User $u, DocumentFile $d): bool
    {
        return $u->hasPermission('documents.upload') && $d->is_current && $this->view($u, $d);
    }
}

<?php

namespace App\Policies;

use App\Models\AdminAuditLog;
use App\Models\User;

/** Audit log：owner 唯讀；⛔ 任何人都不得從後台建立、修改或刪除。 */
class AdminAuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, AdminAuditLog $log): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AdminAuditLog $log): bool
    {
        return false;
    }

    public function delete(User $user, AdminAuditLog $log): bool
    {
        return false;
    }

    public function forceDelete(User $user, AdminAuditLog $log): bool
    {
        return false;
    }
}

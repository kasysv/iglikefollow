<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /** 使用者管理僅限 owner。 */
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, User $model): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, User $model): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user, User $model): bool
    {
        if (! $user->isOwner() || $user->id === $model->id) {
            return false;
        }

        // ⛔ 不可刪除最後一位啟用中的 owner，否則後台會永久鎖死。
        return ! $model->isLastActiveOwner();
    }

    /**
     * Demoting or deactivating the final owner would lock everyone out of the
     * panel, so that specific change is refused even for another owner.
     */
    public function changeRoleOrStatus(User $user, User $model): bool
    {
        return $user->isOwner() && ! $model->isLastActiveOwner();
    }

    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }
}

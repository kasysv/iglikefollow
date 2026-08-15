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
        // ⛔ 不可刪除自己，避免鎖死唯一 owner。
        return $user->isOwner() && $user->id !== $model->id;
    }

    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }
}

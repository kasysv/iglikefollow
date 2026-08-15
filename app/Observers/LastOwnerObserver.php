<?php

namespace App\Observers;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Prevents the final active owner from being demoted, deactivated or deleted.
 *
 * Enforced at the model layer rather than only in policies, so a seeder, a
 * console command or a future admin screen cannot lock everyone out of the
 * panel by accident.
 */
class LastOwnerObserver
{
    public function updating(User $user): void
    {
        $losingOwnership = $user->isDirty('role')
            && $user->getOriginal('role') === User::ROLE_OWNER
            && $user->role !== User::ROLE_OWNER;

        $beingDeactivated = $user->isDirty('is_active')
            && $user->getOriginal('is_active')
            && ! $user->is_active;

        if (! $losingOwnership && ! $beingDeactivated) {
            return;
        }

        if ($this->isFinalActiveOwner($user)) {
            throw ValidationException::withMessages([
                'role' => '這是最後一位啟用中的擁有者，不能降級或停用，否則沒有人能管理後台。',
            ]);
        }
    }

    public function deleting(User $user): void
    {
        if ($this->isFinalActiveOwner($user)) {
            throw ValidationException::withMessages([
                'role' => '這是最後一位啟用中的擁有者，不能刪除。',
            ]);
        }
    }

    /** Checks the stored state, not the pending change. */
    private function isFinalActiveOwner(User $user): bool
    {
        if ($user->getOriginal('role') !== User::ROLE_OWNER || ! $user->getOriginal('is_active')) {
            return false;
        }

        return User::query()
            ->where('role', User::ROLE_OWNER)
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->doesntExist();
    }
}

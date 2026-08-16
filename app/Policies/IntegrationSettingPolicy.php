<?php

namespace App\Policies;

use App\Models\IntegrationSetting;
use App\Models\User;

/**
 * Only an active owner touches credentials.
 *
 * These keys can move money and issue tax documents, so the audience is the
 * smallest one that can still run the business. ⛔ Editors are excluded from
 * viewing as well as editing: "is a production key configured" is itself
 * information worth withholding.
 *
 * isOwner() already requires an active account, so a suspended owner fails
 * every check here without a separate test.
 */
class IntegrationSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, IntegrationSetting $setting): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, IntegrationSetting $setting): bool
    {
        return $user->isOwner();
    }

    /** ⛔ 刪除整組 credential 不在本輪範圍：空白保留原值，誤刪無從復原。 */
    public function delete(User $user, IntegrationSetting $setting): bool
    {
        return false;
    }

    public function restore(User $user, IntegrationSetting $setting): bool
    {
        return false;
    }

    public function forceDelete(User $user, IntegrationSetting $setting): bool
    {
        return false;
    }
}

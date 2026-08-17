<?php

namespace App\Policies;

use App\Models\ProviderService;
use App\Models\User;

/**
 * Only an active Owner may read the provider service catalog.
 *
 * ⛔ Editors cannot see it at all. The catalog names which supplier the site
 * buys from, at what raw rate — commercial information customer support has
 * no need for.
 *
 * ⛔ Nobody may write through the admin. Catalog rows are observations of the
 * provider's account; the only legitimate writer is a complete snapshot apply,
 * and CATALOG-A gives even that no entry point. Hand-editing an observation
 * turns it into fiction.
 */
class ProviderServicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, ProviderService $providerService): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ProviderService $providerService): bool
    {
        return false;
    }

    public function delete(User $user, ProviderService $providerService): bool
    {
        return false;
    }

    public function restore(User $user, ProviderService $providerService): bool
    {
        return false;
    }

    public function forceDelete(User $user, ProviderService $providerService): bool
    {
        return false;
    }
}

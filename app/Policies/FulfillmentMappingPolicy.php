<?php

namespace App\Policies;

use App\Models\FulfillmentMapping;
use App\Models\User;

/**
 * Only an active Owner may configure supplier mappings.
 *
 * ⛔ Editors cannot even see them. A mapping names the provider service a
 * product is ordered from — commercially sensitive, and the field an attacker
 * would most want to change: repointing a mapping redirects every future order
 * for that product to a service of their choosing.
 *
 * ⛔ Nothing may be deleted. A mapping is referenced by every fulfilment row
 * created from it, and removing one would leave those rows unable to explain
 * where they were sent. Disable it instead — `is_enabled` exists for exactly
 * this.
 */
class FulfillmentMappingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, FulfillmentMapping $mapping): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, FulfillmentMapping $mapping): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user, FulfillmentMapping $mapping): bool
    {
        return false;
    }

    public function restore(User $user, FulfillmentMapping $mapping): bool
    {
        return false;
    }

    public function forceDelete(User $user, FulfillmentMapping $mapping): bool
    {
        return false;
    }
}

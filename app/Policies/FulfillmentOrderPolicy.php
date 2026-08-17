<?php

namespace App\Policies;

use App\Models\FulfillmentOrder;
use App\Models\User;

/**
 * Fulfilment records are read-only, for everyone.
 *
 * Support staff need to see where an order stands, so editors can read. Nobody
 * can write.
 *
 * ⛔ There is no retry, no cancel and no "mark as completed", by design rather
 * than by omission. Each of those is a claim about what a supplier did, and a
 * person clicking a button in our admin cannot make it true — a row marked
 * completed by hand would tell the next person the customer was served when
 * nobody knows that. Rows that need judgement stop in `submission_unknown` and
 * wait for someone to check with the provider directly.
 */
class FulfillmentOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner() || $user->isEditor();
    }

    public function view(User $user, FulfillmentOrder $fulfillment): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, FulfillmentOrder $fulfillment): bool
    {
        return false;
    }

    public function delete(User $user, FulfillmentOrder $fulfillment): bool
    {
        return false;
    }

    public function restore(User $user, FulfillmentOrder $fulfillment): bool
    {
        return false;
    }

    public function forceDelete(User $user, FulfillmentOrder $fulfillment): bool
    {
        return false;
    }
}

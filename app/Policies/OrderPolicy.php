<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * Orders are readable by staff and writable by nobody.
 *
 * Both owners and editors need to answer "did this customer's payment go
 * through", so both can view. Nothing may edit or delete: an order is the
 * record of what a customer agreed to, and marking one paid by hand would
 * bypass the payment verification that is the whole point of the lifecycle.
 */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner() || $user->isEditor();
    }

    public function view(User $user, Order $order): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Order $order): bool
    {
        return false;
    }

    public function delete(User $user, Order $order): bool
    {
        return false;
    }

    public function restore(User $user, Order $order): bool
    {
        return false;
    }

    public function forceDelete(User $user, Order $order): bool
    {
        return false;
    }
}

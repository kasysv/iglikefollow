<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

/**
 * Invoices are readable by owners and writable by nobody.
 *
 * ⛔ Narrower than orders: an editor needs to answer "did this payment go
 * through", but an invoice is a tax document, and its numbers are accounting
 * material rather than customer support material.
 *
 * Nothing may write. Editing an issued invoice by hand would put this
 * database out of step with the tax authority's record, and a retry button
 * would be a way to issue a second invoice for the same order — the exact
 * outcome the reconciliation state exists to prevent.
 */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return false;
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return false;
    }

    public function restore(User $user, Invoice $invoice): bool
    {
        return false;
    }

    public function forceDelete(User $user, Invoice $invoice): bool
    {
        return false;
    }
}

<?php

namespace App\Contracts;

/**
 * Where the dispatch adapter gets its API key — and nowhere else.
 *
 * ⛔ An injection seam, not a convenience. The adapter never queries the
 * integration settings table itself, so tests can exercise the full submit
 * path with a fake key while the real encrypted credential row stays
 * untouched — zero queries, zero decrypts. No production implementation of
 * this contract exists in this milestone, and none may be bound outside
 * tests.
 */
interface TheMostPanelDispatchCredentialSource
{
    /**
     * The key to send, or null when it is missing, disabled or unreadable.
     *
     * ⛔ null MUST make the adapter fail closed before any network I/O.
     * Implementations never throw for those three states — an exception here
     * would turn "not configured" into a failing job on a paid order.
     */
    public function apiKey(): ?string;
}

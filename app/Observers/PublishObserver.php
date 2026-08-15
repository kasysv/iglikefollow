<?php

namespace App\Observers;

use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Enforces the publish and URL invariants on every save.
 *
 * This lives in an observer rather than in the Filament forms because the forms
 * only limit what the *UI* offers. Filament's own docs are explicit that
 * disabled() is front-end state; a hand-crafted Livewire payload still reaches
 * the server, and an editor could previously use one to publish content or to
 * move a live page to a different platform. Every rule below therefore runs
 * against the model itself, so it holds for the standalone Resources, the
 * relation managers, and plain Eloquent updates alike.
 */
class PublishObserver
{
    private const PUBLISHED_STATES = ['published', 'archived'];

    public function saving(Model $model): void
    {
        $this->restrictStatusToOwners($model);
        $this->stampFirstPublish($model);
        $this->protectLockedSlug($model);
        $this->protectServiceParent($model);
    }

    /**
     * Only an owner may move content out of draft.
     *
     * Editors may create and edit drafts, so the value is reverted rather than
     * thrown: the rest of their save is legitimate work that should not be lost.
     * A brand new record falls back to 'draft'.
     */
    private function restrictStatusToOwners(Model $model): void
    {
        if (! $model->isDirty('status')) {
            return;
        }

        $user = Auth::user();

        // 沒有登入使用者時代表 seeder、artisan 或測試工廠，⛔ 不套用後台權限。
        if (! $user instanceof User || $user->isOwner()) {
            return;
        }

        $wantsRestrictedState = in_array($model->status, self::PUBLISHED_STATES, true);
        $leavesRestrictedState = in_array($model->getOriginal('status'), self::PUBLISHED_STATES, true);

        if ($wantsRestrictedState || $leavesRestrictedState) {
            $model->status = $model->exists
                ? $model->getOriginal('status')
                : 'draft';
        }
    }

    /**
     * Sections and FAQs carry a status but no first_published_at column: they
     * have no URL of their own, so there is nothing to lock.
     */
    private function stampFirstPublish(Model $model): void
    {
        if ($model->status !== 'published' || ! $this->tracksFirstPublish($model)) {
            return;
        }

        if ($model->first_published_at === null) {
            $model->first_published_at = now();
        }
    }

    private function tracksFirstPublish(Model $model): bool
    {
        return in_array('first_published_at', $model->getFillable(), true)
            || array_key_exists('first_published_at', $model->getCasts());
    }

    /**
     * A slug that has ever been published is permanent.
     *
     * Changing it would break the live URL, so the original value is restored
     * rather than throwing: the rest of the save is still legitimate.
     * An editor may never change a slug, published or not.
     */
    private function protectLockedSlug(Model $model): void
    {
        if (! $model->exists || ! $model->isDirty('slug')) {
            return;
        }

        $user = Auth::user();
        $isEditor = $user instanceof User && ! $user->isOwner();

        if ($isEditor || $model->getOriginal('first_published_at') !== null) {
            $model->slug = $model->getOriginal('slug');
        }
    }

    /**
     * A Service's platform is part of its public URL.
     *
     * /services/{platform.slug}/{service.slug} means re-parenting a live
     * service silently changes the address of an indexed page with no 301
     * behind it. Once published that link is permanent for everyone, owners
     * included; moving it is a job for a future SEO migration workflow.
     * Editors may never move a service at all.
     */
    private function protectServiceParent(Model $model): void
    {
        if (! $model instanceof Service || ! $model->exists || ! $model->isDirty('platform_id')) {
            return;
        }

        $user = Auth::user();
        $isEditor = $user instanceof User && ! $user->isOwner();

        if ($isEditor || $model->getOriginal('first_published_at') !== null) {
            $model->platform_id = $model->getOriginal('platform_id');
        }
    }
}

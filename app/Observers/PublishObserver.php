<?php

namespace App\Observers;

use Illuminate\Database\Eloquent\Model;

/**
 * Stamps first_published_at the first time a record becomes published, and
 * refuses slug edits once that stamp exists.
 *
 * Without this the timestamp was only ever written by the seeder, so anything
 * published through the admin kept a null stamp and its slug stayed editable —
 * meaning a live URL could be changed silently, which is exactly what the SEO
 * migration rule forbids.
 */
class PublishObserver
{
    public function saving(Model $model): void
    {
        $this->stampFirstPublish($model);
        $this->protectLockedSlug($model);
    }

    private function stampFirstPublish(Model $model): void
    {
        if ($model->status !== 'published') {
            return;
        }

        if ($model->first_published_at === null) {
            $model->first_published_at = now();
        }
    }

    /**
     * A slug that has ever been published is permanent.
     *
     * Changing it would break the live URL, so the original value is restored
     * rather than throwing: the rest of the save is still legitimate.
     */
    private function protectLockedSlug(Model $model): void
    {
        if (! $model->exists || ! $model->isDirty('slug')) {
            return;
        }

        $wasPublished = $model->getOriginal('first_published_at') !== null;

        if ($wasPublished) {
            $model->slug = $model->getOriginal('slug');
        }
    }
}

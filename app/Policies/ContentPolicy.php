<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared permission matrix for editable content (spec §5).
 *
 * Owner  : everything, including publish / unpublish and slug edits on drafts.
 * Editor : create and edit drafts only — never publish, never change a slug.
 */
abstract class ContentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner() || $user->isEditor();
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    /** 只有 owner 能發布或下架。 */
    public function publish(User $user, Model $model): bool
    {
        return $user->isOwner();
    }

    /** slug 首次發布後鎖定；未發布的草稿只有 owner 能改。 */
    public function updateSlug(User $user, Model $model): bool
    {
        if (! $user->isOwner()) {
            return false;
        }

        return method_exists($model, 'isSlugLocked')
            ? ! $model->isSlugLocked()
            : true;
    }

    /** 日常操作使用 soft delete；⛔ 不提供 hard delete。 */
    public function delete(User $user, Model $model): bool
    {
        return $user->isOwner();
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }

    public function restore(User $user, Model $model): bool
    {
        return $user->isOwner();
    }
}

<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('staff.view_any')
            || ($user->department_id !== null && $user->hasPermission('staff.view_department'));
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): Response
    {
        return User::query()->visibleTo($user)->whereKey($model->id)->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('staff.manage');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        return $user->hasPermission('staff.manage');
    }

    public function manageAny(User $user): bool
    {
        return $user->hasPermission('staff.manage');
    }

    public function manage(User $user, User $model): bool
    {
        return $user->hasPermission('staff.manage');
    }

    public function issuePasswordReset(User $user, User $model): bool
    {
        return $user->hasPermission('staff.manage') && $user->hasPermission('staff.reset_password');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }
}

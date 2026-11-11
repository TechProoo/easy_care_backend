<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PatientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('patients.view');
    }

    public function view(User $user, Patient $patient): Response
    {
        return $user->hasPermission('patients.view') && $patient->access_status === 'active'
            ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('patients.view') && $user->hasPermission('patients.create');
    }

    public function update(User $user, Patient $patient): Response
    {
        if (! $user->hasPermission('patients.update') || ! $user->hasPermission('patients.view')) {
            return Response::deny();
        }

        return $this->view($user, $patient);
    }
}

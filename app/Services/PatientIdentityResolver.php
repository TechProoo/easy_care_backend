<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\PatientMerge;
use App\Models\User;

class PatientIdentityResolver
{
    public function resolve(User $actor, int $id): Patient
    {
        abort_unless($actor->hasPermission('patients.view'), 403);
        $seen = [];
        for ($depth = 0; $depth < 64; $depth++) {
            abort_if(isset($seen[$id]), 404);
            $seen[$id] = true;
            $patient = Patient::query()->findOrFail($id);
            abort_if($patient->access_status === 'restricted', 404);
            $merge = PatientMerge::query()->where('source_patient_id', $id)->first();
            if ($patient->access_status === 'active') {
                abort_if($merge !== null, 404);

                return $patient;
            }
            abort_unless($patient->access_status === 'merged' && $merge !== null, 404);
            $id = $merge->target_patient_id;
        }
        abort(404);
    }
}

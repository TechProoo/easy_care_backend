<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ResetStaffPasswordRequest;
use App\Services\StaffAdministration;
use Illuminate\Http\Response;

class StaffRecoveryController extends Controller
{
    public function __invoke(ResetStaffPasswordRequest $request, StaffAdministration $staff): Response
    {
        $staff->resetPassword($request->validated());
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}

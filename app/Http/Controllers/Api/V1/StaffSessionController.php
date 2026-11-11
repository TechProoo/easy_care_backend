<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\StaffResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class StaffSessionController extends Controller
{
    public function csrf(Request $request): JsonResponse
    {
        return response()->json(['data' => ['csrf_token' => $request->session()->token()]]);
    }

    public function store(LoginRequest $request): JsonResponse
    {
        abort_if(Auth::guard('web')->check(), 409);
        $user = $request->authenticate();
        $user->forceFill(['last_login_at' => now()])->save();

        return $this->sessionResponse($request, $user);
    }

    public function show(Request $request): JsonResponse
    {
        return $this->sessionResponse($request, $request->user('web'));
    }

    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    private function sessionResponse(Request $request, User $user): JsonResponse
    {
        $user->load(['department', 'roles.permissions']);

        return response()->json(['data' => [
            'user' => new StaffResource($user),
            'csrf_token' => $request->session()->token(),
            'expires_at' => now()->addMinutes(config('session.lifetime'))->toISOString(),
        ]]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;

class AccessControlController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): JsonResponse
    {
        $known = array_keys(config('authorization.permissions'));

        return response()->json(['data' => [
            'permissions' => Permission::query()->whereIn('code', $known)->orderBy('code')->get(['code', 'name']),
            'roles' => Role::query()->with('permissions')->orderBy('code')->get()->map(
                fn (Role $role): array => [
                    'code' => $role->code,
                    'name' => $role->name,
                    'permissions' => $role->permissions->whereIn('code', $known)->pluck('code')->sort()->values(),
                ],
            ),
        ]]);
    }
}

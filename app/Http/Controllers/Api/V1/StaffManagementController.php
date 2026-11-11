<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IssueStaffResetRequest;
use App\Http\Requests\Api\V1\ListStaffRequest;
use App\Http\Requests\Api\V1\StoreStaffRequest;
use App\Http\Requests\Api\V1\UpdateStaffRequest;
use App\Http\Resources\StaffManagementResource;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\StaffAdministration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class StaffManagementController extends Controller
{
    public function index(ListStaffRequest $request): AnonymousResourceCollection
    {
        $query = User::query()->with(['department', 'roles']);
        $search = $request->validated('q');
        if ($search !== null && $search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->whereRaw("users.name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("users.username LIKE ? ESCAPE '!'", [$pattern]);
            });
        }
        $sort = $request->validated('sort', 'name');

        return StaffManagementResource::collection($query
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('users.id')->paginate($request->integer('per_page', 25))
            ->appends($request->safe()->only(['q', 'sort', 'per_page'])));
    }

    public function options(Request $request): JsonResponse
    {
        $held = $request->user('web')->permissionCodes();

        return response()->json(['data' => [
            'departments' => Department::query()->orderBy('name')->get(['id', 'code', 'name']),
            'roles' => Role::query()->with('permissions')->orderBy('name')->get()->map(fn (Role $role): array => [
                'code' => $role->code,
                'name' => $role->name,
                'assignable' => $role->permissions->pluck('code')
                    ->intersect(array_keys(config('authorization.permissions')))->diff($held)->isEmpty(),
            ]),
        ]]);
    }

    public function show(User $user): StaffManagementResource
    {
        return new StaffManagementResource($user->load(['department', 'roles']));
    }

    public function store(StoreStaffRequest $request, StaffAdministration $staff): StaffManagementResource
    {
        try {
            $user = $staff->create($request->user('web'), $request->validated());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['username' => ['A staff identifier is already in use. Reload and check the username, email and staff code.']]);
        }

        return new StaffManagementResource($user->load(['department', 'roles']));
    }

    public function update(UpdateStaffRequest $request, User $user, StaffAdministration $staff): StaffManagementResource
    {
        try {
            $user = $staff->update($request->user('web'), $user, $request->validated());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['staff_code' => ['A staff identifier is already in use. Reload and check the email and staff code.']]);
        }

        return new StaffManagementResource($user->load(['department', 'roles']));
    }

    public function issueReset(IssueStaffResetRequest $request, User $user, StaffAdministration $staff): JsonResponse
    {
        $reset = $staff->issueReset($request->user('web'), $user, $request->validated());

        return response()->json(['data' => [
            'user' => new StaffManagementResource($reset['user']->load(['department', 'roles'])),
            'reset_code' => $reset['token'],
            'expires_at' => $reset['expires_at'],
        ]], 201);
    }
}

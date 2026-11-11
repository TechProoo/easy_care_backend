<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListStaffRequest;
use App\Http\Resources\StaffDirectoryResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StaffDirectoryController extends Controller
{
    public function index(ListStaffRequest $request): AnonymousResourceCollection
    {
        $query = User::query()->visibleTo($request->user('web'))->with('department');
        $search = $request->validated('q');

        if ($search !== null && $search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->whereRaw("users.name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("users.username LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        $sort = $request->validated('sort', 'name');

        return StaffDirectoryResource::collection(
            $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
                ->orderBy('users.id')
                ->paginate($request->integer('per_page', config('api.pagination.per_page')))
                ->appends($request->safe()->only(['q', 'sort', 'per_page'])),
        );
    }

    public function show(User $user): StaffDirectoryResource
    {
        return new StaffDirectoryResource($user->load('department'));
    }
}

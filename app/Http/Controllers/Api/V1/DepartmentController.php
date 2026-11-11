<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListDepartmentsRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DepartmentController extends Controller
{
    public function index(ListDepartmentsRequest $request): AnonymousResourceCollection
    {
        $query = Department::query();
        $search = $request->validated('q');

        if ($search !== null && $search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->whereRaw("code LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("name LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        $sort = $request->validated('sort', 'code');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        return DepartmentResource::collection(
            $query->orderBy(ltrim($sort, '-'), $direction)
                ->orderBy('id')
                ->paginate($request->integer('per_page', config('api.pagination.per_page')))
                ->appends($request->safe()->only(['q', 'sort', 'per_page'])),
        );
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListAuditEventsRequest;
use App\Http\Resources\AuditEventResource;
use App\Services\AuditTrail;
use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AuditHistoryController extends Controller
{
    public function __invoke(ListAuditEventsRequest $request, AuditTrail $audit): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $throughId = isset($filters['through_id']) ? (int) $filters['through_id'] : (int) DB::table('audit_events')->max('id');
        $query = DB::table('audit_events')->where('id', '<=', $throughId);
        foreach (['action', 'outcome', 'channel', 'actor_id', 'subject_type', 'subject_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['from'])) {
            $query->where('occurred_at', '>=', CarbonImmutable::parse($filters['from'], 'UTC')->startOfDay());
        }
        if (isset($filters['to'])) {
            $query->where('occurred_at', '<', CarbonImmutable::parse($filters['to'], 'UTC')->addDay()->startOfDay());
        }

        $page = $query->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate($request->integer('per_page', config('api.pagination.per_page')))
            ->appends(array_replace($request->safe()->except('page'), ['through_id' => $throughId]));
        $audit->record('audit.viewed', $request->user('web')->id, 'audit');

        return AuditEventResource::collection($page)->additional(['through_id' => $throughId]);
    }
}

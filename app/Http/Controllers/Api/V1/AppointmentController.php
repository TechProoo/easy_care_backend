<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AppointmentActionRequest;
use App\Http\Requests\Api\V1\ListAppointmentsRequest;
use App\Http\Requests\Api\V1\StoreAppointmentRequest;
use App\Http\Requests\Api\V1\UpdateAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\AppointmentRevisionResource;
use App\Models\Appointment;
use App\Models\User;
use App\Services\AppointmentBookings;
use App\Services\AuditTrail;
use App\Services\IdempotentCommands;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AppointmentController extends Controller
{
    public function index(ListAppointmentsRequest $request, AuditTrail $audit): AnonymousResourceCollection
    {
        $query = Appointment::query()->visibleTo($request->user('web'))->with('followUpLink');
        foreach (preg_split('/\s+/u', $request->validated('q', '') ?? '', flags: PREG_SPLIT_NO_EMPTY) as $part) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $part).'%';
            $query->where(fn (Builder $query): Builder => $query->whereRaw("first_name LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("last_name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("phone LIKE ? ESCAPE '!'", [$pattern]));
        }
        foreach (['status', 'doctor_id', 'patient_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->validated($field));
            }
        }
        if ($request->filled('from_date')) {
            $query->where('starts_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $request->validated('from_date'), config('appointments.timezone'))->utc()->format('Y-m-d H:i:s.u'));
        }
        if ($request->filled('to_date')) {
            $query->where('starts_at', '<', CarbonImmutable::createFromFormat('!Y-m-d', $request->validated('to_date'), config('appointments.timezone'))->addDay()->utc()->format('Y-m-d H:i:s.u'));
        }
        $sort = $request->validated('sort', 'starts_at');
        $page = $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('id')
            ->paginate($request->integer('per_page', config('api.pagination.per_page')))->appends($request->validated());
        $audit->record('appointment.listed', $request->user('web')->id, 'appointment');

        return AppointmentResource::collection($page)->additional(['timezone' => config('appointments.timezone')]);
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => ['timezone' => config('appointments.timezone'),
            'booking_channels' => config('appointments.booking_channels'), 'visit_types' => config('appointments.visit_types'),
            'statuses' => array_keys(Appointment::TRANSITIONS), 'requires_end_time' => true, 'clinician_overlap_allowed' => false]]);
    }

    public function clinicians(ListAppointmentsRequest $request): JsonResponse
    {
        $query = User::query()->where('is_active', true)->whereHas('roles', fn (Builder $query): Builder => $query->where('code', 'doctor'));
        foreach (preg_split('/\s+/u', $request->validated('q', '') ?? '', flags: PREG_SPLIT_NO_EMPTY) as $part) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $part).'%';
            $query->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]);
        }
        $page = $query->orderBy('name')->orderBy('id')->paginate($request->integer('per_page', config('api.pagination.per_page')), ['id', 'name']);

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function show(Request $request, int $appointment, AuditTrail $audit): AppointmentResource
    {
        $record = Appointment::query()->visibleTo($request->user('web'))->with('followUpLink')->findOrFail($appointment);
        $audit->record('appointment.viewed', $request->user('web')->id, 'appointment', $record->id);

        return (new AppointmentResource($record))->additional(['meta' => ['timezone' => config('appointments.timezone')]]);
    }

    public function history(ListAppointmentsRequest $request, int $appointment, AuditTrail $audit): AnonymousResourceCollection
    {
        $record = Appointment::query()->visibleTo($request->user('web'))->findOrFail($appointment);
        $audit->record('appointment.history_viewed', $request->user('web')->id, 'appointment', $record->id);

        return AppointmentRevisionResource::collection($record->revisions()->paginate($request->integer('per_page', config('api.pagination.per_page'))));
    }

    public function store(Request $request, AppointmentBookings $bookings, IdempotentCommands $commands): JsonResponse
    {
        return $commands->run($request, function () use ($request, $bookings): JsonResponse {
            $booking = $bookings->create($request->user('web'), app(StoreAppointmentRequest::class)->validated());

            return (new AppointmentResource($booking))->response()->setStatusCode(201);
        });
    }

    public function update(Request $request, int $appointment, AppointmentBookings $bookings, IdempotentCommands $commands): JsonResponse
    {
        return $commands->run($request, function () use ($request, $appointment, $bookings): JsonResponse {
            $booking = $bookings->update($request->user('web'), $appointment, app(UpdateAppointmentRequest::class)->validated());

            return (new AppointmentResource($booking))->response();
        });
    }

    public function action(Request $request, int $appointment, AppointmentBookings $bookings, IdempotentCommands $commands): JsonResponse
    {
        return $commands->run($request, function () use ($request, $appointment, $bookings): JsonResponse {
            $action = str_replace('api.v1.appointments.', '', $request->route()->getName());
            $booking = $bookings->update($request->user('web'), $appointment, app(AppointmentActionRequest::class)->validated(), $action);

            return (new AppointmentResource($booking))->response();
        });
    }
}

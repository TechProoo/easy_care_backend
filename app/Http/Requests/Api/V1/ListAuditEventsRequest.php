<?php

namespace App\Http\Requests\Api\V1;

use App\Services\AuditTrail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAuditEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['sometimes', 'string', Rule::in(AuditTrail::ACTIONS)],
            'outcome' => ['sometimes', 'string', Rule::in(['succeeded', 'denied', 'failed'])],
            'channel' => ['sometimes', 'string', Rule::in(['http', 'console'])],
            'actor_id' => ['sometimes', 'integer', 'min:1'],
            'subject_type' => ['string', Rule::in(['staff', 'role', 'audit', 'file']), 'required_with:subject_id'],
            'subject_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', Rule::when($this->filled('from'), 'after_or_equal:from')],
            'through_id' => ['sometimes', 'integer', 'min:0'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('api.pagination.max_per_page')],
        ];
    }
}

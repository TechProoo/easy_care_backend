<?php

namespace App\Http\Requests\Api\V1;

class UpdateStaffRequest extends StoreStaffRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'username' => ['prohibited'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
    }
}

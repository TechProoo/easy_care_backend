<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\ProtectedFileRules;
use Illuminate\Foundation\Http\FormRequest;

class UploadFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ProtectedFileRules::make(),
            'uploaded_by' => ['prohibited'],
            'storage_path' => ['prohibited'],
            'mime_type' => ['prohibited'],
            'byte_size' => ['prohibited'],
            'sha256' => ['prohibited'],
            'scan_status' => ['prohibited'],
            'patient_id' => ['prohibited'],
            'encounter_id' => ['prohibited'],
        ];
    }
}

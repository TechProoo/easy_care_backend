<?php

namespace App\Http\Resources;

use App\Services\ClinicalRecords;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClinicalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $version = $this->kind === 'entry' ? $this->entry?->templateVersion : null;

        return ClinicalRecords::snapshot($this->resource) + [
            'author_name' => $this->author?->name, 'finalizer_name' => $this->finalizedBy?->name,
            'superseded' => $this->resource->getAttribute('superseded') ?? $this->amendment()->finalized()->exists(),
            'body_mass_index' => $this->kind === 'vitals' ? $this->vitals?->bodyMassIndex() : null,
            'template_version' => $version === null ? null : ['id' => $version->id, 'template_id' => $version->clinical_template_id,
                'code' => $version->template->code, 'version' => $version->version, 'title' => $version->title, 'fields' => (object) $version->fields],
        ];
    }
}

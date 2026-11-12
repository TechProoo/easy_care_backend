<?php

namespace Database\Factories;

use App\Models\ClinicalTemplateVersion;
use App\Models\ClinicalTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClinicalTemplateVersion> */
class ClinicalTemplateVersionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'clinical_template_id' => ClinicalTemplate::factory(), 'version' => 1,
            'title' => 'Synthetic note version', 'fields' => ['summary' => ['type' => 'text', 'label' => 'Summary']],
            'published_by' => User::factory(), 'published_at' => now('UTC'),
        ];
    }
}

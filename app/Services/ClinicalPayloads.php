<?php

namespace App\Services;

use App\Models\ClinicalRecord;
use App\Models\ClinicalTemplateVersion;
use App\Models\Encounter;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClinicalPayloads
{
    public const FIELDS = [
        'vitals' => ['observed_at', 'temperature_c', 'systolic_mmhg', 'diastolic_mmhg', 'weight_kg', 'height_m',
            'blood_glucose', 'blood_glucose_unit', 'pulse_bpm', 'respiratory_rate', 'spo2_percent', 'fetal_heart_rate', 'urinalysis', 'comment'],
        'entry' => ['section', 'clinical_template_version_id', 'fields', 'content', 'related_record_id'],
        'diagnosis' => ['description', 'code_system', 'code_system_version', 'code'],
        'procedure' => ['name', 'code', 'remarks', 'findings', 'performed_at', 'quoted_amount', 'currency'],
    ];

    public const SECTIONS = ['presenting_complaints', 'physical_examination', 'assessment', 'plan', 'note', 'procedure_findings'];

    public const EXAM_FIELDS = ['general', 'cns', 'chest', 'cvs', 'abdomen', 'dre', 've', 'mss', 'ent', 'comment'];

    public const DECIMALS = ['temperature_c' => [4, 2], 'weight_kg' => [5, 3], 'height_m' => [3, 3],
        'blood_glucose' => [7, 3], 'pulse_bpm' => [5, 2], 'respiratory_rate' => [5, 2],
        'spo2_percent' => [3, 2], 'fetal_heart_rate' => [5, 2], 'quoted_amount' => [12, 2]];

    /** @return array<string, mixed> */
    public static function stored(ClinicalRecord $record): array
    {
        $relation = match ($record->kind) { 'vitals' => 'vitals', 'entry' => 'entry', 'diagnosis' => 'diagnosis', 'procedure' => 'procedure' };
        $payload = $record->{$relation};
        if ($payload === null) {
            return [];
        }
        $result = [];
        foreach (self::FIELDS[$record->kind] as $field) {
            $value = $payload->{$field};
            $result[$field] = $value instanceof CarbonInterface ? $value->toISOString() : $value;
        }
        if ($record->kind === 'procedure') {
            $result['performed_by'] = $payload->performed_by;
        }
        if ($record->kind === 'entry') {
            $result['fields'] = (object) ($result['fields'] ?? []);
        }

        return $result;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function validate(string $kind, array $input, Encounter $visit, User $actor, bool $sign, ?ClinicalRecord $original = null): array
    {
        foreach (array_diff(array_keys($input), self::FIELDS[$kind]) as $field) {
            $this->invalid((string) $field, 'This clinical field cannot be supplied.');
        }
        $previous = $original === null ? [] : self::stored($original);
        $data = array_replace(array_fill_keys(self::FIELDS[$kind], null), $previous, $input);
        unset($data['performed_by']);
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = trim($value) === '' ? null : trim($value);
            }
        }
        $rules = match ($kind) {
            'vitals' => $this->vitalRules(),
            'entry' => ['section' => ['required', Rule::in(self::SECTIONS)],
                'clinical_template_version_id' => ['nullable', 'integer', 'min:1', 'max:999999999999999999'],
                'fields' => ['nullable', 'array', 'max:50'], 'content' => ['nullable', 'string', 'max:20000'],
                'related_record_id' => ['nullable', 'integer', 'min:1', 'max:999999999999999999']],
            'diagnosis' => ['description' => ['required', 'string', 'max:10000'], 'code_system' => ['nullable', 'string', 'max:32'],
                'code_system_version' => ['nullable', 'string', 'max:32'], 'code' => ['nullable', 'string', 'max:64']],
            'procedure' => ['name' => ['required', 'string', 'max:255'], 'code' => ['nullable', 'string', 'max:64'],
                'remarks' => ['nullable', 'string', 'max:20000'], 'findings' => ['nullable', 'string', 'max:20000'],
                'performed_at' => ['nullable', 'string'], 'quoted_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999.99'],
                'currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/D']],
        };
        if (($data['fields'] ?? null) instanceof \stdClass) {
            $data['fields'] = (array) $data['fields'];
        }
        $prefixed = [];
        foreach ($rules as $field => $constraints) {
            $prefixed['payload.'.$field] = $constraints;
        }
        Validator::make(['payload' => $data], $prefixed)->validate();
        foreach (self::DECIMALS as $field => [$digits, $scale]) {
            if (! array_key_exists($field, $data) || $data[$field] === null) {
                continue;
            }
            $value = (string) $data[$field];
            if (preg_match('/^-?[0-9]{1,'.$digits.'}(?:\.[0-9]{1,'.$scale.'})?$/D', $value) !== 1) {
                $this->invalid($field, 'Use a decimal value within the supported precision.');
            }
            $data[$field] = number_format((float) $value, $scale, '.', '');
        }
        if ($kind === 'vitals') {
            $this->pair($data, ['systolic_mmhg', 'diastolic_mmhg']);
            $this->pair($data, ['blood_glucose', 'blood_glucose_unit']);
            $data['observed_at'] = $this->instant($data['observed_at'], 'observed_at');
            foreach (['systolic_mmhg', 'diastolic_mmhg'] as $field) {
                $data[$field] = $data[$field] === null ? null : (int) $data[$field];
            }
            if ($sign && collect($data)->except(['observed_at', 'comment', 'blood_glucose_unit'])->filter(fn (mixed $value): bool => $value !== null)->isEmpty()) {
                $this->invalid('observed_at', 'Record at least one measurement before signing vitals.');
            }
        } elseif ($kind === 'diagnosis') {
            $this->pair($data, ['code', 'code_system', 'code_system_version']);
        } elseif ($kind === 'entry') {
            $data = $this->entry($data, $previous, $original, $visit, $actor, $sign);
        } else {
            $this->pair($data, ['quoted_amount', 'currency']);
            $data['performed_at'] = $data['performed_at'] === null ? null : $this->instant($data['performed_at'], 'performed_at');
            $data['performed_by'] = $data['performed_at'] === null ? null
                : (($previous['performed_at'] ?? null) === $data['performed_at'] ? $previous['performed_by'] : $actor->id);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function vitalRules(): array
    {
        $rules = ['observed_at' => ['required', 'string'], 'systolic_mmhg' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'diastolic_mmhg' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'blood_glucose_unit' => ['nullable', Rule::in(['mg/dL', 'mmol/L'])],
            'urinalysis' => ['nullable', 'string', 'max:5000'], 'comment' => ['nullable', 'string', 'max:5000']];
        foreach (self::DECIMALS as $field => [$digits, $scale]) {
            if ($field === 'quoted_amount') {
                continue;
            }
            $rules[$field] = ['nullable', 'numeric', 'decimal:0,'.$scale];
            if (in_array($field, ['weight_kg', 'height_m'], true)) {
                $rules[$field][] = 'gt:0';
            } elseif ($field !== 'temperature_c') {
                $rules[$field][] = 'min:0';
            }
            if ($field === 'spo2_percent') {
                $rules[$field][] = 'max:100';
            }
        }

        return $rules;
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $previous @return array<string, mixed> */
    private function entry(array $data, array $previous, ?ClinicalRecord $original, Encounter $visit, User $actor, bool $sign): array
    {
        foreach (['section', 'clinical_template_version_id', 'related_record_id'] as $field) {
            if ($original !== null && (string) $data[$field] !== (string) $previous[$field]) {
                $this->invalid($field, 'The note section, template version and related record remain fixed.');
            }
        }
        $data['fields'] = (array) ($data['fields'] ?? []);
        if ($data['clinical_template_version_id'] !== null) {
            $version = ClinicalTemplateVersion::query()->with('template')->find($data['clinical_template_version_id']);
            if ($version === null || ($original === null && ! $version->template->is_active)) {
                $this->invalid('clinical_template_version_id', 'Choose an available published template version.');
            }
            $definition = $this->definition($version);
        } else {
            $definition = $data['section'] === 'physical_examination'
                ? array_fill_keys(self::EXAM_FIELDS, ['type' => 'text', 'max_length' => 5000]) : [];
        }
        foreach (array_diff(array_keys($data['fields']), array_keys($definition)) as $field) {
            $this->invalid('fields.'.(string) $field, 'This field is not defined by the saved clinical template.');
        }
        foreach ($definition as $key => $field) {
            $exists = array_key_exists($key, $data['fields']);
            $value = $data['fields'][$key] ?? null;
            if (is_string($value)) {
                $value = trim($value) === '' ? null : trim($value);
                $data['fields'][$key] = $value;
            }
            $required = $sign && ($field['required'] ?? false);
            $rules = [$required ? 'required' : 'nullable'];
            $rules = [...$rules, ...match ($field['type']) {
                'text' => ['string', 'max:'.($field['max_length'] ?? 5000)],
                'number' => ['numeric'], 'integer' => ['integer'], 'boolean' => ['boolean'],
                'date' => ['date_format:Y-m-d'], 'select' => ['string', Rule::in($field['options'])],
            }];
            if (in_array($field['type'], ['number', 'integer'], true)) {
                foreach (['min', 'max'] as $bound) {
                    if (isset($field[$bound])) {
                        $rules[] = $bound.':'.$field[$bound];
                    }
                }
            }
            Validator::make(['payload' => ['fields' => $data['fields']]], ['payload.fields.'.$key => $rules])->validate();
            if ($exists && $value !== null && $field['type'] === 'boolean' && ! is_bool($value)) {
                $this->invalid('fields.'.$key, 'Supply a JSON boolean.');
            }
        }
        if ($sign && $data['content'] === null && collect($data['fields'])->filter(fn (mixed $value): bool => $value !== null && $value !== '')->isEmpty()) {
            $this->invalid('content', 'Enter clinical content before signing this note.');
        }
        if ($data['section'] === 'procedure_findings' && $data['related_record_id'] === null) {
            $this->invalid('related_record_id', 'Choose the saved procedure this finding describes.');
        }
        if ($original === null && $data['related_record_id'] !== null) {
            $related = ClinicalRecord::query()->visibleTo($actor)->where('encounter_id', $visit->id)
                ->where('kind', 'procedure')->where('status', 'signed')->whereDoesntHave('amendment', fn (Builder $query): Builder => $query->finalized())
                ->lockForUpdate()->find($data['related_record_id']);
            if ($related === null) {
                $this->invalid('related_record_id', 'Choose a current signed procedure within this visit.');
            }
        }
        $data['clinical_template_version_id'] = $data['clinical_template_version_id'] === null ? null : (int) $data['clinical_template_version_id'];
        $data['related_record_id'] = $data['related_record_id'] === null ? null : (int) $data['related_record_id'];
        $data['fields'] = (object) $data['fields'];

        return $data;
    }

    /** @return array<string, array<string, mixed>> */
    public function definition(ClinicalTemplateVersion $version): array
    {
        $fields = $version->fields;
        if (! is_array($fields) || ($fields !== [] && array_is_list($fields)) || count($fields) > 50) {
            $this->invalid('clinical_template_version_id', 'This template definition is not supported.');
        }
        foreach ($fields as $key => $field) {
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) !== 1 || ! is_array($field)
                || array_diff(array_keys($field), ['type', 'label', 'required', 'max_length', 'options', 'min', 'max', 'unit']) !== []
                || ! in_array($field['type'] ?? null, ['text', 'number', 'integer', 'boolean', 'date', 'select'], true)
                || (isset($field['label']) && (! is_string($field['label']) || mb_strlen($field['label']) > 160))
                || (isset($field['unit']) && (! is_string($field['unit']) || mb_strlen($field['unit']) > 32))
                || (isset($field['required']) && ! is_bool($field['required']))
                || (isset($field['max_length']) && (! is_int($field['max_length']) || $field['max_length'] < 1 || $field['max_length'] > 20000))) {
                $this->invalid('clinical_template_version_id', 'This template definition is not supported.');
            }
            foreach (['min', 'max'] as $bound) {
                if (isset($field[$bound]) && (! is_numeric($field[$bound]) || ! is_finite((float) $field[$bound]))) {
                    $this->invalid('clinical_template_version_id', 'This template has an invalid numeric bound.');
                }
            }
            if (isset($field['min'], $field['max']) && $field['min'] > $field['max']) {
                $this->invalid('clinical_template_version_id', 'This template has inconsistent bounds.');
            }
            if ($field['type'] === 'select' && (! isset($field['options']) || ! is_array($field['options'])
                || ! array_is_list($field['options']) || count($field['options']) < 1 || count($field['options']) > 100
                || ! collect($field['options'])->every(fn (mixed $option): bool => is_string($option) && mb_strlen($option) <= 255))) {
                $this->invalid('clinical_template_version_id', 'This template has invalid choices.');
            }
        }

        return $fields;
    }

    private function instant(string $value, string $field): string
    {
        $date = AppointmentTime::parse($value);
        if ($date === null || $date->greaterThan(now('UTC'))) {
            $this->invalid($field, 'Supply an existing observation time in ISO 8601 format with an explicit UTC offset.');
        }

        return $date->toISOString();
    }

    /** @param array<string, mixed> $data @param list<string> $fields */
    private function pair(array $data, array $fields): void
    {
        $count = count(array_filter($fields, fn (string $field): bool => $data[$field] !== null));
        if ($count !== 0 && $count !== count($fields)) {
            $this->invalid($fields[0], 'Supply all related values together, including their units or coding version.');
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages(['payload.'.$field => [$message]]);
    }
}

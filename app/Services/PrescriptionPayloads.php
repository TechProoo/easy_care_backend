<?php

namespace App\Services;

use App\Models\ClinicalRecord;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PrescriptionPayloads
{
    public const ITEM_FIELDS = ['medication_name', 'form', 'strength', 'dose', 'route', 'frequency',
        'duration_value', 'duration_unit', 'quantity', 'quantity_unit', 'instructions'];

    /** @return array<string, mixed> */
    public static function stored(ClinicalRecord $record): array
    {
        $prescription = $record->prescription;
        if ($prescription === null) {
            return [];
        }

        return ['order_status' => $prescription->order_status, 'instructions' => $prescription->instructions,
            'items' => $prescription->items->map(fn (PrescriptionItem $item): array => $item->only(self::ITEM_FIELDS))->all()];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function validate(array $input, bool $sign, ?ClinicalRecord $original = null): array
    {
        $this->known($input, ['instructions', 'items'], 'payload');
        $previous = $original === null ? [] : self::stored($original);
        $data = array_replace(['instructions' => null, 'items' => []], $previous, $input);
        $data['instructions'] = $this->text($data['instructions']);
        Validator::make(['payload' => $data], ['payload.instructions' => ['nullable', 'string', 'max:20000'],
            'payload.items' => ['present', 'array', 'list', $sign ? 'min:1' : 'min:0', 'max:100']])->validate();
        if (is_string($data['instructions']) && strlen($data['instructions']) > 65535) {
            $this->invalid('payload.instructions', 'These instructions exceed the supported text storage size.');
        }
        foreach ($data['items'] as $index => &$item) {
            if (! is_array($item)) {
                $this->invalid('payload.items.'.$index, 'Supply an object for each medication line.');
            }
            $this->known($item, self::ITEM_FIELDS, 'payload.items.'.$index);
            $item = array_replace(array_fill_keys(self::ITEM_FIELDS, null), $item);
            $item = array_map($this->text(...), $item);
            $prefix = 'payload.items.'.$index.'.';
            $rules = [];
            foreach (['medication_name' => 255, 'form' => 80, 'strength' => 120, 'dose' => 255, 'route' => 80,
                'frequency' => 255, 'duration_unit' => 32, 'quantity_unit' => 64, 'instructions' => 10000] as $field => $max) {
                $required = $field === 'medication_name' || ($sign && in_array($field, ['dose', 'route', 'frequency'], true));
                $rules[$prefix.$field] = [$required ? 'required' : 'nullable', 'string', 'max:'.$max];
            }
            foreach (['duration_value' => '9999999.999', 'quantity' => '999999999.999'] as $field => $max) {
                $rules[$prefix.$field] = ['nullable', 'numeric', 'gt:0', 'max:'.$max, 'decimal:0,3'];
            }
            Validator::make(['payload' => ['items' => [$index => $item]]], $rules)->validate();
            foreach (['duration_value' => ['duration_unit', 7], 'quantity' => ['quantity_unit', 9]] as $field => [$unit, $digits]) {
                if (($item[$field] === null) !== ($item[$unit] === null)) {
                    $this->invalid($prefix.$field, 'Supply the value and its unit together.');
                }
                if ($item[$field] !== null) {
                    if (preg_match('/^[0-9]{1,'.$digits.'}(?:\.[0-9]{1,3})?$/D', (string) $item[$field]) !== 1) {
                        $this->invalid($prefix.$field, 'Use a positive decimal within the supported precision.');
                    }
                    $item[$field] = number_format((float) $item[$field], 3, '.', '');
                }
            }
        }
        unset($item);
        $data['order_status'] = $previous['order_status'] ?? 'active';

        return $data;
    }

    /** @param array<string, mixed> $payload */
    public function write(ClinicalRecord $record, array $payload): void
    {
        $prescription = Prescription::query()->find($record->id) ?? new Prescription;
        $prescription->forceFill(['clinical_record_id' => $record->id, 'order_status' => $payload['order_status'],
            'instructions' => $payload['instructions']])->save();
        $prescription->items()->delete();
        foreach ($payload['items'] as $index => $item) {
            $prescription->items()->forceCreate(['line_number' => $index + 1] + $item);
        }
        $record->unsetRelation('prescription');
    }

    private function text(mixed $value): mixed
    {
        return is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
    }

    /** @param array<string, mixed> $data @param list<string> $fields */
    private function known(array $data, array $fields, string $prefix): void
    {
        foreach (array_diff(array_keys($data), $fields) as $field) {
            $this->invalid($prefix.'.'.$field, 'This prescription field cannot be supplied.');
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}

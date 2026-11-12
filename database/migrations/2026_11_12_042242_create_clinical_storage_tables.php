<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PAYLOADS = ['vitals' => 'vital_signs', 'entry' => 'clinical_entries',
        'diagnosis' => 'diagnoses', 'procedure' => 'clinical_procedures', 'prescription' => 'prescriptions'];

    public function up(): void
    {
        Schema::create('clinical_templates', function (Blueprint $table): void {
            $table->id();
            $this->token($table, 'code', 64)->unique();
            $table->string('name', 160);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps(6);
        });
        Schema::create('clinical_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinical_template_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('title', 160);
            $table->json('fields');
            $table->foreignId('published_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('published_at', 6);
            $table->unique(['clinical_template_id', 'version'], 'clinical_template_version_unique');
        });
        Schema::create('clinical_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('encounter_id');
            $table->foreign(['encounter_id', 'patient_id'])->references(['id', 'patient_id'])->on('encounters')->restrictOnDelete();
            $this->token($table, 'kind', 16);
            $this->token($table, 'status', 16)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('authored_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('recorded_at', 6);
            $table->foreignId('finalized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('finalized_at', 6)->nullable();
            $table->unsignedBigInteger('amends_id')->nullable()->unique();
            $table->text('amendment_reason')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps(6);
            $table->unique(['id', 'encounter_id', 'patient_id', 'kind'], 'clinical_record_identity');
            $table->foreign(['amends_id', 'encounter_id', 'patient_id', 'kind'], 'clinical_amendment_identity')
                ->references(['id', 'encounter_id', 'patient_id', 'kind'])->on('clinical_records')->restrictOnDelete();
            $table->index(['patient_id', 'recorded_at', 'id'], 'clinical_patient_history');
            $table->index(['encounter_id', 'kind', 'status'], 'clinical_encounter_kind_status');
            $table->index(['authored_by', 'status'], 'clinical_author_drafts');
        });
        Schema::create('vital_signs', function (Blueprint $table): void {
            $this->payloadKey($table);
            $table->dateTime('observed_at', 6);
            $table->decimal('temperature_c', 6, 2)->nullable();
            $table->unsignedSmallInteger('systolic_mmhg')->nullable();
            $table->unsignedSmallInteger('diastolic_mmhg')->nullable();
            $table->decimal('weight_kg', 8, 3)->nullable();
            $table->decimal('height_m', 6, 3)->nullable();
            $table->decimal('blood_glucose', 10, 3)->nullable();
            $this->token($table, 'blood_glucose_unit', 16)->nullable();
            $table->decimal('pulse_bpm', 7, 2)->nullable();
            $table->decimal('respiratory_rate', 7, 2)->nullable();
            $table->decimal('spo2_percent', 5, 2)->nullable();
            $table->decimal('fetal_heart_rate', 7, 2)->nullable();
            $table->text('urinalysis')->nullable();
            $table->text('comment')->nullable();
        });
        Schema::create('clinical_entries', function (Blueprint $table): void {
            $this->payloadKey($table);
            $table->string('section', 64);
            $table->foreignId('clinical_template_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('fields');
            $table->text('content')->nullable();
            $table->foreignId('related_record_id')->nullable()->index()->constrained('clinical_records')->restrictOnDelete();
        });
        Schema::create('diagnoses', function (Blueprint $table): void {
            $this->payloadKey($table);
            $table->text('description');
            $table->string('code_system', 32)->nullable();
            $table->string('code_system_version', 32)->nullable();
            $table->string('code', 64)->nullable();
        });
        Schema::create('clinical_procedures', function (Blueprint $table): void {
            $this->payloadKey($table);
            $table->string('name', 255);
            $table->string('code', 64)->nullable();
            $table->text('remarks')->nullable();
            $table->text('findings')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('performed_at', 6)->nullable();
            $table->decimal('quoted_amount', 14, 2)->nullable();
            $this->token($table, 'currency', 3)->nullable();
        });
        Schema::create('prescriptions', function (Blueprint $table): void {
            $this->payloadKey($table);
            $this->token($table, 'order_status', 16)->default('active');
            $table->text('instructions')->nullable();
        });
        Schema::create('prescription_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prescription_id');
            $table->foreign('prescription_id')->references('clinical_record_id')->on('prescriptions')->restrictOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->string('medication_name', 255);
            $table->string('form', 80)->nullable();
            $table->string('strength', 120)->nullable();
            $table->string('dose', 255)->nullable();
            $table->string('route', 80)->nullable();
            $table->string('frequency', 255)->nullable();
            $table->decimal('duration_value', 10, 3)->nullable();
            $table->string('duration_unit', 32)->nullable();
            $table->decimal('quantity', 12, 3)->nullable();
            $table->string('quantity_unit', 64)->nullable();
            $table->text('instructions')->nullable();
            $table->unique(['prescription_id', 'line_number']);
        });
        $this->templateGuards();
        $this->recordGuards();
        foreach (self::PAYLOADS as $kind => $table) {
            $this->payloadGuards($table, $kind);
        }
        $this->itemGuards();
        $this->valueGuards();
    }

    public function down(): void
    {
        foreach (['prescription_items', 'prescriptions', 'clinical_procedures', 'diagnoses', 'clinical_entries',
            'vital_signs', 'clinical_records', 'clinical_template_versions', 'clinical_templates'] as $table) {
            if ($table === 'clinical_records') {
                Schema::withoutForeignKeyConstraints(fn () => Schema::dropIfExists($table));
            } else {
                Schema::dropIfExists($table);
            }
        }
    }

    private function templateGuards(): void
    {
        $this->guard('clinical_templates', "length(trim(NEW.code)) BETWEEN 1 AND 64
            AND length(trim(NEW.name)) BETWEEN 1 AND 160 AND NEW.is_active IN (0, 1)");
        $this->trigger('clinical_templates', 'update', 'clinical_templates_identity',
            $this->anyChanged(['id', 'code', 'created_by', 'created_at']), 'Template identity is permanent.');
        $this->trigger('clinical_templates', 'delete', 'clinical_templates_no_delete', '1 = 1', 'Templates are retained.');
        $this->guard('clinical_template_versions', 'NEW.version > 0 AND length(trim(NEW.title)) BETWEEN 1 AND 160
            AND '.$this->jsonObject('fields').' AND NEW.published_at IS NOT NULL');
        foreach (['update', 'delete'] as $operation) {
            $this->trigger('clinical_template_versions', $operation, 'clinical_template_versions_no_'.$operation,
                '1 = 1', 'Published clinical templates are immutable.');
        }
        $this->dateGuards('clinical_template_versions', ['published_at']);
    }

    private function recordGuards(): void
    {
        $this->lockParent('clinical_records', 'encounters', 'NEW.encounter_id', ['insert', 'update']);
        $this->guard('clinical_records', "NEW.kind IN ('vitals', 'entry', 'diagnosis', 'procedure', 'prescription')
            AND NEW.version > 0 AND NEW.recorded_at IS NOT NULL
            AND EXISTS (SELECT 1 FROM encounters e WHERE e.id = NEW.encounter_id AND e.patient_id = NEW.patient_id
                AND NEW.recorded_at >= e.opened_at AND e.status <> 'cancelled')
            AND ((NEW.amends_id IS NULL AND NEW.amendment_reason IS NULL)
                OR (length(trim(NEW.amendment_reason)) BETWEEN 5 AND 2000))
            AND ((NEW.status = 'draft' AND NEW.finalized_by IS NULL AND NEW.finalized_at IS NULL AND NEW.void_reason IS NULL)
                OR (NEW.status = 'signed' AND NEW.finalized_by IS NOT NULL AND NEW.finalized_at >= NEW.recorded_at AND NEW.void_reason IS NULL)
                OR (NEW.status = 'voided' AND NEW.finalized_by IS NOT NULL AND NEW.finalized_at >= NEW.recorded_at
                    AND length(trim(NEW.void_reason)) BETWEEN 5 AND 2000))");
        DB::unprepared('DROP TRIGGER IF EXISTS clinical_records_validate_insert');
        $this->trigger('clinical_records', 'insert', 'clinical_records_validate_insert',
            "(NEW.status = 'draft' AND NEW.version = 1 AND NEW.finalized_by IS NULL AND NEW.finalized_at IS NULL
                AND NEW.void_reason IS NULL AND NEW.kind IN ('vitals', 'entry', 'diagnosis', 'procedure', 'prescription')
                AND EXISTS (SELECT 1 FROM patients p WHERE p.id = NEW.patient_id AND p.access_status = 'active')
                AND EXISTS (SELECT 1 FROM encounters e WHERE e.id = NEW.encounter_id AND e.patient_id = NEW.patient_id
                    AND NEW.recorded_at >= e.opened_at AND (e.status IN ('open', 'in_progress') OR (e.status = 'closed' AND NEW.amends_id IS NOT NULL)))
                AND ((NEW.amends_id IS NULL AND NEW.amendment_reason IS NULL)
                    OR (length(trim(NEW.amendment_reason)) BETWEEN 5 AND 2000 AND EXISTS
                        (SELECT 1 FROM clinical_records r WHERE r.id = NEW.amends_id AND r.status IN ('signed', 'voided')
                            AND r.encounter_id = NEW.encounter_id AND r.patient_id = NEW.patient_id AND r.kind = NEW.kind
                            AND NEW.recorded_at >= r.finalized_at)))) IS NOT TRUE", 'Invalid clinical draft or amendment.');
        $this->trigger('clinical_records', 'update', 'clinical_records_preserve_history',
            'OLD.status <> \'draft\' OR NEW.version <> OLD.version + 1 OR '.$this->anyChanged([
                'id', 'patient_id', 'encounter_id', 'kind', 'authored_by', 'recorded_at', 'amends_id', 'amendment_reason', 'created_at',
            ]), 'Clinical identity, authorship, version and finalized history are protected.');
        $checks = [];
        foreach (self::PAYLOADS as $kind => $table) {
            $extra = $kind === 'prescription' ? ' AND EXISTS (SELECT 1 FROM prescription_items i WHERE i.prescription_id = NEW.id'.$this->locking().')' : '';
            $checks[] = "(NEW.kind = '{$kind}' AND EXISTS (SELECT 1 FROM {$table} p WHERE p.clinical_record_id = NEW.id".$this->locking()."){$extra})";
        }
        $this->trigger('clinical_records', 'update', 'clinical_records_require_payload',
            "NEW.status = 'signed' AND NOT (".implode(' OR ', $checks).')', 'Signing requires the matching clinical content.');
        $this->trigger('clinical_records', 'delete', 'clinical_records_no_delete', '1 = 1', 'Clinical history cannot be deleted.');
        $this->dateGuards('clinical_records', ['recorded_at', 'finalized_at']);
    }

    private function payloadGuards(string $table, string $kind): void
    {
        $this->lockParent($table, 'clinical_records', 'NEW.clinical_record_id', ['insert', 'update']);
        foreach (['insert', 'update'] as $operation) {
            $this->trigger($table, $operation, $table.'_draft_'.$operation,
                "NOT EXISTS (SELECT 1 FROM clinical_records r WHERE r.id = NEW.clinical_record_id AND r.kind = '{$kind}' AND r.status = 'draft'".$this->locking().')'
                    .($operation === 'update' ? ' OR NEW.clinical_record_id <> OLD.clinical_record_id' : ''),
                'Only matching draft clinical content can be written.');
        }
        $this->trigger($table, 'delete', $table.'_no_delete', '1 = 1', 'Clinical content is retained.');
    }

    private function itemGuards(): void
    {
        $this->lockParent('prescription_items', 'clinical_records', 'NEW.prescription_id', ['insert', 'update']);
        foreach (['insert', 'update'] as $operation) {
            $this->trigger('prescription_items', $operation, 'prescription_items_draft_'.$operation,
                "NOT EXISTS (SELECT 1 FROM clinical_records r WHERE r.id = NEW.prescription_id AND r.kind = 'prescription' AND r.status = 'draft'".$this->locking().')'
                    .($operation === 'update' ? ' OR NEW.id <> OLD.id OR NEW.prescription_id <> OLD.prescription_id' : ''),
                'Only draft prescription instructions can be written.');
        }
        $this->lockParent('prescription_items', 'clinical_records', 'OLD.prescription_id', ['delete']);
        $this->trigger('prescription_items', 'delete', 'prescription_items_draft_delete',
            "NOT EXISTS (SELECT 1 FROM clinical_records r WHERE r.id = OLD.prescription_id AND r.status = 'draft'".$this->locking().')',
            'Finalized prescription instructions are retained.');
        $this->guard('prescription_items', "NEW.line_number BETWEEN 1 AND 65535 AND length(trim(NEW.medication_name)) BETWEEN 1 AND 255
            AND ((NEW.duration_value IS NULL AND NEW.duration_unit IS NULL)
                OR (NEW.duration_value > 0 AND length(trim(NEW.duration_unit)) BETWEEN 1 AND 32))
            AND ((NEW.quantity IS NULL AND NEW.quantity_unit IS NULL)
                OR (NEW.quantity > 0 AND length(trim(NEW.quantity_unit)) BETWEEN 1 AND 64))");
    }

    private function valueGuards(): void
    {
        $this->guard('vital_signs', "NEW.observed_at IS NOT NULL
            AND ((NEW.systolic_mmhg IS NULL AND NEW.diastolic_mmhg IS NULL) OR (NEW.systolic_mmhg BETWEEN 1 AND 65535 AND NEW.diastolic_mmhg BETWEEN 1 AND 65535))
            AND (NEW.weight_kg IS NULL OR NEW.weight_kg > 0) AND (NEW.height_m IS NULL OR NEW.height_m > 0)
            AND ((NEW.blood_glucose IS NULL AND NEW.blood_glucose_unit IS NULL)
                OR (NEW.blood_glucose >= 0 AND NEW.blood_glucose_unit IN ('mg/dL', 'mmol/L')))
            AND (NEW.pulse_bpm IS NULL OR NEW.pulse_bpm >= 0) AND (NEW.respiratory_rate IS NULL OR NEW.respiratory_rate >= 0)
            AND (NEW.spo2_percent IS NULL OR NEW.spo2_percent BETWEEN 0 AND 100)
            AND (NEW.fetal_heart_rate IS NULL OR NEW.fetal_heart_rate >= 0)");
        $this->guard('clinical_entries', 'length(trim(NEW.section)) BETWEEN 1 AND 64 AND '.$this->jsonObject('fields')."
            AND (NEW.related_record_id IS NULL OR EXISTS (SELECT 1 FROM clinical_records a JOIN clinical_records b
                ON a.encounter_id = b.encounter_id AND a.patient_id = b.patient_id
                WHERE a.id = NEW.clinical_record_id AND b.id = NEW.related_record_id AND a.id <> b.id))");
        $this->guard('diagnoses', "length(trim(NEW.description)) > 0
            AND ((NEW.code IS NULL AND NEW.code_system IS NULL AND NEW.code_system_version IS NULL)
                OR (length(trim(NEW.code)) BETWEEN 1 AND 64 AND length(trim(NEW.code_system)) BETWEEN 1 AND 32
                    AND length(trim(NEW.code_system_version)) BETWEEN 1 AND 32))");
        $currency = DB::getDriverName() === 'mysql' ? "NEW.currency REGEXP '^[A-Z]{3}$'" : "NEW.currency GLOB '[A-Z][A-Z][A-Z]'";
        $this->guard('clinical_procedures', "length(trim(NEW.name)) BETWEEN 1 AND 255
            AND ((NEW.performed_by IS NULL AND NEW.performed_at IS NULL) OR (NEW.performed_by IS NOT NULL AND NEW.performed_at IS NOT NULL))
            AND ((NEW.quoted_amount IS NULL AND NEW.currency IS NULL) OR (NEW.quoted_amount >= 0 AND {$currency}))");
        $this->guard('prescriptions', "NEW.order_status IN ('active', 'stopped', 'completed')");
        $this->dateGuards('vital_signs', ['observed_at']);
        $this->dateGuards('clinical_procedures', ['performed_at']);
    }

    private function payloadKey(Blueprint $table): void
    {
        $table->unsignedBigInteger('clinical_record_id')->primary();
        $table->foreign('clinical_record_id')->references('id')->on('clinical_records')->restrictOnDelete();
    }

    private function token(Blueprint $table, string $name, int $length): \Illuminate\Database\Schema\ColumnDefinition
    {
        $column = $table->string($name, $length);
        if (DB::getDriverName() === 'mysql') {
            $column->collation('utf8mb4_0900_as_cs');
        }

        return $column;
    }

    /** @param list<string> $columns */
    private function anyChanged(array $columns): string
    {
        return implode(' OR ', array_map(fn (string $column): string => DB::getDriverName() === 'mysql'
            ? "NOT (CAST(NEW.{$column} AS BINARY) <=> CAST(OLD.{$column} AS BINARY))"
            : "NEW.{$column} IS NOT OLD.{$column}", $columns));
    }

    private function jsonObject(string $column): string
    {
        return DB::getDriverName() === 'mysql' ? "JSON_TYPE(NEW.{$column}) = 'OBJECT'" : "json_type(NEW.{$column}) = 'object'";
    }

    private function locking(): string
    {
        return DB::getDriverName() === 'mysql' ? ' FOR UPDATE' : '';
    }

    /** @param list<string> $operations */
    private function lockParent(string $table, string $parent, string $key, array $operations): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        foreach ($operations as $operation) {
            DB::unprepared("CREATE TRIGGER {$table}_lock_{$operation} BEFORE {$operation} ON {$table} FOR EACH ROW
                BEGIN DECLARE locked_id BIGINT UNSIGNED;
                SELECT id INTO locked_id FROM {$parent} WHERE id = {$key} FOR UPDATE; END");
        }
    }

    private function guard(string $table, string $condition): void
    {
        foreach (['insert', 'update'] as $operation) {
            $this->trigger($table, $operation, $table.'_validate_'.$operation, "({$condition}) IS NOT TRUE", 'Invalid clinical storage value.');
        }
    }

    /** @param list<string> $columns */
    private function dateGuards(string $table, array $columns): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }
        foreach ($columns as $column) {
            foreach (['insert', 'update'] as $operation) {
                $this->trigger($table, $operation, $table.'_'.$column.'_'.$operation,
                    "NEW.{$column} IS NOT NULL AND julianday(NEW.{$column}) IS NULL", 'Invalid clinical timestamp.');
            }
        }
    }

    private function trigger(string $table, string $operation, string $name, string $condition, string $message): void
    {
        if (DB::getDriverName() === 'mysql') {
            $condition = str_replace('length(', 'CHAR_LENGTH(', $condition);
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW
                BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} WHEN {$condition}
                BEGIN SELECT RAISE(ABORT, '{$message}'); END");
        }
    }
};

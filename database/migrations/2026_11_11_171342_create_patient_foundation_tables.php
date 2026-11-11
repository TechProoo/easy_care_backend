<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 191);
            $table->timestamps(6);
        });

        Schema::create('patients', function (Blueprint $table): void {
            $table->id();
            $table->string('mrn', 64)->unique();
            $table->string('first_name', 64);
            $table->string('last_name', 64);
            $table->string('middle_name', 64)->nullable();
            $table->string('sex', 64)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->boolean('dob_estimated')->default(false);
            $table->string('email', 191)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('marital_status', 64)->nullable();
            $table->string('religion', 64)->nullable();
            $table->string('occupation', 191)->nullable();
            $table->string('state_of_origin', 191)->nullable();
            $table->text('address')->nullable();
            $table->string('life_status', 64)->default('unknown');
            $table->dateTime('deceased_at', 6)->nullable();
            $table->string('access_status', 64)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('category_id')->constrained('patient_categories')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('photo_file_id')->nullable()->constrained('files')->restrictOnDelete();
            $table->timestamps(6);
            $table->index(['last_name', 'first_name', 'id']);
            $table->index('phone');
            $table->index(['category_id', 'id']);
            $table->index(['access_status', 'id']);
        });

        Schema::create('patient_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->string('identifier', 64)->unique();
            $table->string('kind', 64);
            $table->dateTime('retired_at', 6)->nullable();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->timestamps(6);
            $table->index(['patient_id', 'retired_at']);
        });

        Schema::create('patient_contacts', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 191);
            $table->string('relationship', 64);
            $table->string('phone', 32);
            $table->unsignedInteger('priority')->default(1);
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->timestamps(6);
            $table->unique(['patient_id', 'priority']);
        });

        Schema::create('patient_relationships', function (Blueprint $table): void {
            $table->id();
            $table->string('relationship', 64);
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('related_patient_id')->constrained('patients')->restrictOnDelete();
            $table->timestamps(6);
            $table->unique(['patient_id', 'related_patient_id', 'relationship'], 'patient_relationships_unique_link');
        });

        $length = DB::getDriverName() === 'mysql' ? 'char_length' : 'length';
        $access = $this->caseSensitive('NEW.access_status');
        $life = $this->caseSensitive('NEW.life_status');
        $kind = $this->caseSensitive('NEW.kind');
        $oldKind = $this->caseSensitive('OLD.kind');
        $this->guard('patient_categories', $this->canonical('code', false).' AND length(trim(NEW.name)) > 0');
        $this->guard('patients', $this->canonical('mrn')."
            AND {$length}(trim(NEW.first_name)) BETWEEN 1 AND 64
            AND {$length}(trim(NEW.last_name)) BETWEEN 1 AND 64
            AND NEW.version > 0
            AND {$access} IN ('active', 'restricted', 'merged')
            AND {$life} IN ('unknown', 'alive', 'deceased')
            AND NEW.dob_estimated IN (0, 1)
            AND (NEW.dob_estimated = 0 OR NEW.date_of_birth IS NOT NULL)
            AND (NEW.deceased_at IS NULL OR NEW.life_status = 'deceased')");
        $this->guard('patient_identifiers', $this->canonical('identifier')."
            AND {$kind} IN ('mrn', 'legacy_mrn')
            AND ((NEW.kind = 'mrn' AND NEW.retired_at IS NULL AND EXISTS (
                SELECT 1 FROM patients WHERE id = NEW.patient_id AND mrn = NEW.identifier
            )) OR (NEW.retired_at IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM patients WHERE id = NEW.patient_id AND mrn = NEW.identifier
            )))");
        $this->guard('patient_contacts', "NEW.priority > 0 AND length(trim(NEW.name)) > 0
            AND length(trim(NEW.relationship)) > 0 AND length(trim(NEW.phone)) > 0");
        $this->guard('patient_relationships', 'NEW.patient_id <> NEW.related_patient_id AND length(trim(NEW.relationship)) > 0');

        $this->trigger('patient_identifiers', 'before', 'update', 'patient_identifiers_preserve_identity',
            "NEW.identifier <> OLD.identifier OR NEW.patient_id <> OLD.patient_id OR {$kind} <> {$oldKind}
                OR (OLD.retired_at IS NOT NULL AND (NEW.retired_at IS NULL OR NEW.retired_at <> OLD.retired_at))",
            'Patient numbers and their ownership are permanent.');
        $this->trigger('patient_identifiers', 'before', 'delete', 'patient_identifiers_no_delete', '1 = 1',
            'Patient numbers cannot be deleted or reused.');

        $now = DB::getDriverName() === 'mysql' ? 'UTC_TIMESTAMP(6)' : "STRFTIME('%Y-%m-%d %H:%M:%f', 'now')";
        $this->afterPatientWrite('insert', 'patients_reserve_mrn', "
            INSERT INTO patient_identifiers (identifier, kind, patient_id, created_at, updated_at)
            VALUES (NEW.mrn, 'mrn', NEW.id, {$now}, {$now});
        ");
        $this->afterPatientWrite('update', 'patients_retain_mrn', "
            UPDATE patient_identifiers SET retired_at = {$now}, updated_at = {$now}
                WHERE patient_id = NEW.id AND identifier = OLD.mrn;
            INSERT INTO patient_identifiers (identifier, kind, patient_id, created_at, updated_at)
                VALUES (NEW.mrn, 'mrn', NEW.id, {$now}, {$now});
        ", 'NEW.mrn <> OLD.mrn');
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_relationships');
        Schema::dropIfExists('patient_contacts');
        Schema::dropIfExists('patient_identifiers');
        Schema::dropIfExists('patients');
        Schema::dropIfExists('patient_categories');
    }

    private function canonical(string $column, bool $upper = true): string
    {
        $value = 'NEW.'.$column;
        $characters = $upper ? 'A-Z0-9/._-' : 'a-z0-9._-';
        $first = $upper ? 'A-Z0-9' : 'a-z0-9';
        if (DB::getDriverName() === 'mysql') {
            return "char_length({$value}) BETWEEN 1 AND 64
                AND NOT REGEXP_LIKE({$value}, '[^{$characters}]', 'c')
                AND REGEXP_LIKE(LEFT({$value}, 1), '[{$first}]', 'c')";
        }

        return "length({$value}) BETWEEN 1 AND 64 AND {$value} NOT GLOB '*[^{$characters}]*' AND {$value} GLOB '[{$first}]*'";
    }

    private function caseSensitive(string $value): string
    {
        return DB::getDriverName() === 'mysql' ? "CAST({$value} AS BINARY)" : $value;
    }

    private function guard(string $table, string $condition): void
    {
        foreach (['insert', 'update'] as $operation) {
            $this->trigger($table, 'before', $operation, $table.'_validate_'.$operation, "NOT ({$condition})", 'Invalid patient record.');
        }
    }

    private function trigger(string $table, string $timing, string $operation, string $name, string $when, string $message): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER {$name} {$timing} {$operation} ON {$table} FOR EACH ROW
                BEGIN IF {$when} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} {$timing} {$operation} ON {$table} WHEN {$when}
                BEGIN SELECT RAISE(ABORT, '{$message}'); END");
        }
    }

    private function afterPatientWrite(string $operation, string $name, string $body, ?string $when = null): void
    {
        if (DB::getDriverName() === 'mysql') {
            $body = $when === null ? $body : "IF {$when} THEN {$body} END IF;";
            DB::unprepared("CREATE TRIGGER {$name} AFTER {$operation} ON patients FOR EACH ROW BEGIN {$body} END");
        } else {
            $clause = $when === null ? '' : "WHEN {$when}";
            DB::unprepared("CREATE TRIGGER {$name} AFTER {$operation} ON patients {$clause} BEGIN {$body} END");
        }
    }
};

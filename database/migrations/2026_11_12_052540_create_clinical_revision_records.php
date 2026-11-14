<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinical_record_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('operation', 16);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->json('snapshot');
            $table->dateTime('recorded_at', 6);
            $table->unique(['clinical_record_id', 'version']);
        });
        $mysql = DB::getDriverName() === 'mysql';
        $length = $mysql ? 'CHAR_LENGTH' : 'length';
        $fields = [];
        foreach (['id', 'patient_id', 'encounter_id', 'version', 'kind', 'status'] as $field) {
            $value = $mysql ? "JSON_UNQUOTE(JSON_EXTRACT(NEW.snapshot, '$.{$field}'))" : "json_extract(NEW.snapshot, '$.{$field}')";
            $fields[] = "{$value} = r.{$field}";
        }
        $this->trigger('insert', 'clinical_revisions_validate',
            "(NEW.recorded_at IS NOT NULL AND EXISTS (SELECT 1 FROM clinical_records r WHERE r.id = NEW.clinical_record_id
                AND r.version = NEW.version AND r.authored_by = NEW.actor_id AND NEW.recorded_at >= r.recorded_at
                AND ".implode(' AND ', $fields)."
                AND ((NEW.operation = 'created' AND NEW.version = 1 AND r.status = 'draft' AND NEW.reason IS NULL)
                    OR (NEW.operation = 'updated' AND NEW.version > 1 AND r.status = 'draft' AND NEW.reason IS NULL)
                    OR (NEW.operation = 'signed' AND NEW.version > 1 AND r.status = 'signed' AND NEW.reason IS NULL)
                    OR (NEW.operation = 'amended' AND NEW.version > 1 AND r.status = 'signed' AND r.amends_id IS NOT NULL
                        AND {$length}(trim(NEW.reason)) BETWEEN 5 AND 2000)
                    OR (NEW.operation = 'voided' AND NEW.version > 1 AND r.status = 'voided'
                        AND {$length}(trim(NEW.reason)) BETWEEN 5 AND 2000)))) IS NOT TRUE", 'Invalid private clinical revision.');
        foreach (['update', 'delete'] as $operation) {
            $this->trigger($operation, 'clinical_revisions_no_'.$operation, '1 = 1', 'Clinical revisions are append-only.');
        }
        if (! $mysql) {
            $this->trigger('insert', 'clinical_revisions_date', 'julianday(NEW.recorded_at) IS NULL', 'Invalid clinical revision timestamp.');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_revisions');
    }

    private function trigger(string $operation, string $name, string $condition, string $message): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON clinical_revisions FOR EACH ROW
                BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON clinical_revisions WHEN {$condition}
                BEGIN SELECT RAISE(ABORT, '{$message}'); END");
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->bookingHistory(true);
        Schema::create('encounter_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('encounter_id')->constrained('encounters')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('operation', 16);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->json('snapshot');
            $table->dateTime('recorded_at', 6);
            $table->unique(['encounter_id', 'version']);
        });
        $jsonId = $this->jsonNumber('id');
        $jsonVersion = $this->jsonNumber('version');
        $length = DB::getDriverName() === 'mysql' ? 'CHAR_LENGTH' : 'length';
        $this->trigger('encounter_revisions', 'insert', 'encounter_revisions_validate',
            "(NEW.version > 0 AND NEW.operation IN ('opened', 'assigned', 'reassigned', 'accepted', 'completed', 'cancelled')
                AND ((NEW.operation = 'opened' AND NEW.version = 1 AND NEW.reason IS NULL)
                    OR (NEW.operation <> 'opened' AND NEW.version > 1 AND {$length}(trim(NEW.reason)) BETWEEN 5 AND 2000))
                AND {$jsonId} = NEW.encounter_id AND {$jsonVersion} = NEW.version
                AND EXISTS (SELECT 1 FROM encounters e WHERE e.id = NEW.encounter_id AND e.version = NEW.version)) IS NOT TRUE",
            'Invalid encounter history snapshot.');
        foreach (['update', 'delete'] as $operation) {
            $this->trigger('encounter_revisions', $operation, 'encounter_revisions_no_'.$operation,
                '1 = 1', 'Encounter history is append-only.');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('encounter_revisions');
        $this->bookingHistory(false);
    }

    private function bookingHistory(bool $arrival): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS appointment_revisions_validate');
        $operations = "'created', 'updated', 'rescheduled', 'confirmed', 'cancelled', 'no_show'".($arrival ? ", 'attended'" : '');
        $jsonId = $this->jsonNumber('id');
        $jsonVersion = $this->jsonNumber('version');
        $length = DB::getDriverName() === 'mysql' ? 'CHAR_LENGTH' : 'length';
        $this->trigger('appointment_revisions', 'insert', 'appointment_revisions_validate',
            "(NEW.version > 0 AND NEW.operation IN ({$operations})
                AND ((NEW.operation = 'created' AND NEW.version = 1 AND NEW.reason IS NULL)
                    OR (NEW.operation <> 'created' AND NEW.version > 1 AND {$length}(trim(NEW.reason)) BETWEEN 5 AND 2000))
                AND {$jsonId} = NEW.appointment_id AND {$jsonVersion} = NEW.version
                AND EXISTS (SELECT 1 FROM appointments a WHERE a.id = NEW.appointment_id AND a.version = NEW.version)) IS NOT TRUE",
            'Invalid appointment history snapshot.');
    }

    private function jsonNumber(string $field): string
    {
        return DB::getDriverName() === 'mysql'
            ? "CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.snapshot, '$.{$field}')) AS UNSIGNED)"
            : "json_extract(NEW.snapshot, '$.{$field}')";
    }

    private function trigger(string $table, string $operation, string $name, string $condition, string $message): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW
                BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} WHEN {$condition}
                BEGIN SELECT RAISE(ABORT, '{$message}'); END");
        }
    }
};

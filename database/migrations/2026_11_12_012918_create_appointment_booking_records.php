<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->appointmentValidation(true);
        Schema::create('appointment_follow_ups', function (Blueprint $table): void {
            $table->foreignId('appointment_id')->primary()->constrained('appointments')->restrictOnDelete();
            $table->foreignId('previous_appointment_id')->constrained('appointments')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at', 6);
        });
        Schema::create('appointment_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('appointment_id')->constrained('appointments')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('operation', 16);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->json('snapshot');
            $table->dateTime('recorded_at', 6);
            $table->unique(['appointment_id', 'version']);
        });
        $this->trigger('appointment_follow_ups', 'insert', 'appointment_follow_ups_validate',
            "NEW.appointment_id = NEW.previous_appointment_id OR NOT EXISTS
                (SELECT 1 FROM appointments child JOIN appointments parent ON parent.id = NEW.previous_appointment_id
                WHERE child.id = NEW.appointment_id AND child.version = 1 AND child.status = 'booked'
                    AND child.booked_by = NEW.created_by AND parent.status = 'attended'
                    AND parent.patient_id IS NOT NULL AND parent.patient_id = child.patient_id AND child.starts_at > parent.starts_at)",
            'A follow-up requires a new booking after an attended booking for the same patient.');
        $this->trigger('appointments', 'update', 'appointments_preserve_follow_up_order',
            "EXISTS (SELECT 1 FROM appointment_follow_ups f JOIN appointments parent ON parent.id = f.previous_appointment_id
                WHERE f.appointment_id = OLD.id AND NEW.starts_at <= parent.starts_at)",
            'A follow-up cannot precede its original attended booking.');
        $jsonId = $this->jsonNumber('id');
        $jsonVersion = $this->jsonNumber('version');
        $reasonLength = DB::getDriverName() === 'mysql' ? 'CHAR_LENGTH' : 'length';
        $this->trigger('appointment_revisions', 'insert', 'appointment_revisions_validate',
            "(NEW.version > 0 AND NEW.operation IN ('created', 'updated', 'rescheduled', 'confirmed', 'cancelled', 'no_show')
                AND ((NEW.operation = 'created' AND NEW.version = 1 AND NEW.reason IS NULL)
                    OR (NEW.operation <> 'created' AND NEW.version > 1 AND {$reasonLength}(trim(NEW.reason)) BETWEEN 5 AND 2000))
                AND {$jsonId} = NEW.appointment_id AND {$jsonVersion} = NEW.version
                AND EXISTS (SELECT 1 FROM appointments a WHERE a.id = NEW.appointment_id AND a.version = NEW.version)) IS NOT TRUE",
            'Invalid appointment history snapshot.');
        foreach (['appointment_follow_ups', 'appointment_revisions'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                $this->trigger($table, $operation, $table.'_no_'.$operation, '1 = 1', 'Appointment history is append-only.');
            }
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS appointments_preserve_follow_up_order');
        Schema::dropIfExists('appointment_revisions');
        Schema::dropIfExists('appointment_follow_ups');
        $this->appointmentValidation(false);
    }

    private function appointmentValidation(bool $characters): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        $length = $characters ? 'CHAR_LENGTH' : 'length';
        $condition = "{$length}(trim(NEW.first_name)) BETWEEN 1 AND 64
            AND {$length}(trim(NEW.last_name)) BETWEEN 1 AND 64 AND length(trim(NEW.phone)) BETWEEN 1 AND 32
            AND {$length}(trim(NEW.booking_channel)) BETWEEN 1 AND 64 AND {$length}(trim(NEW.visit_type)) BETWEEN 1 AND 64
            AND NEW.version > 0 AND NEW.status IN ('booked', 'confirmed', 'attended', 'cancelled', 'no_show')
            AND (NEW.ends_at IS NULL OR NEW.ends_at > NEW.starts_at)
            AND (NEW.status <> 'attended' OR NEW.patient_id IS NOT NULL)";
        foreach (['insert', 'update'] as $operation) {
            $name = 'appointments_validate_'.$operation;
            DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
            $this->trigger('appointments', $operation, $name, "({$condition}) IS NOT TRUE", 'Invalid visit record.');
        }
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

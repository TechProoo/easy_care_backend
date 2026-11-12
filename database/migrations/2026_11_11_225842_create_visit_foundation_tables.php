<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name', 64);
            $table->string('last_name', 64);
            $table->string('phone', 32);
            $table->dateTime('starts_at', 6);
            $table->dateTime('ends_at', 6)->nullable();
            $table->string('booking_channel', 64);
            $table->string('visit_type', 64);
            $table->string('specialist_name', 191)->nullable();
            $table->string('specialist_email', 191)->nullable();
            $this->statusColumn($table, 'booked');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('patient_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('booked_by')->constrained('users')->restrictOnDelete();
            $table->timestamps(6);
            $table->unique(['id', 'patient_id']);
            $table->index(['starts_at', 'status']);
            $table->index(['doctor_id', 'starts_at']);
            $table->index(['patient_id', 'starts_at']);
        });
        Schema::create('encounters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('appointment_id')->nullable()->unique();
            $table->foreign(['appointment_id', 'patient_id'])->references(['id', 'patient_id'])
                ->on('appointments')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('category_id')->constrained('patient_categories')->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->string('visit_type', 64);
            $careSetting = $table->string('care_setting', 16);
            if (DB::getDriverName() === 'mysql') {
                $careSetting->collation('utf8mb4_0900_as_cs');
            }
            $this->statusColumn($table, 'open');
            $table->dateTime('opened_at', 6);
            $table->dateTime('closed_at', 6)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps(6);
            $table->unique(['id', 'patient_id']);
            $table->index(['patient_id', 'opened_at']);
            $table->index(['status', 'opened_at']);
        });
        Schema::create('doctor_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('encounter_id')->constrained()->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('assigned_at', 6);
            $table->dateTime('accepted_at', 6)->nullable();
            $table->dateTime('ended_at', 6)->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('end_reason')->nullable();
            $table->string('consultation_type', 64)->nullable();
            $this->statusColumn($table, 'assigned');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('current_encounter_id')->nullable()
                ->virtualAs("CASE WHEN ended_at IS NULL THEN encounter_id ELSE NULL END")->unique();
            $table->timestamps(6);
            $table->index(['doctor_id', 'status', 'assigned_at']);
            $table->index(['encounter_id', 'assigned_at', 'id']);
        });

        $this->guard('appointments', "length(trim(NEW.first_name)) BETWEEN 1 AND 64
            AND length(trim(NEW.last_name)) BETWEEN 1 AND 64 AND length(trim(NEW.phone)) BETWEEN 1 AND 32
            AND length(trim(NEW.booking_channel)) BETWEEN 1 AND 64 AND length(trim(NEW.visit_type)) BETWEEN 1 AND 64
            AND NEW.version > 0 AND NEW.status IN ('booked', 'confirmed', 'attended', 'cancelled', 'no_show')
            AND (NEW.ends_at IS NULL OR NEW.ends_at > NEW.starts_at)
            AND (NEW.status <> 'attended' OR NEW.patient_id IS NOT NULL)");
        $this->trigger('appointments', 'insert', 'appointments_start_booked',
            "NEW.status <> 'booked' OR NEW.version <> 1", 'Appointments must start booked at version one.');
        $this->trigger('appointments', 'update', 'appointments_preserve_lifecycle',
            "NEW.id <> OLD.id OR NEW.booked_by <> OLD.booked_by OR ".$this->changed('created_at')."
            OR NEW.version <> OLD.version + 1 OR OLD.status NOT IN ('booked', 'confirmed')
            OR (OLD.patient_id IS NOT NULL AND ".$this->changed('patient_id').")
            OR NOT (NEW.status = OLD.status
                OR (OLD.status = 'booked' AND NEW.status IN ('confirmed', 'attended', 'cancelled', 'no_show'))
                OR (OLD.status = 'confirmed' AND NEW.status IN ('attended', 'cancelled', 'no_show')))",
            'Appointment identity, version or transition is invalid.');

        $this->guard('encounters', "length(trim(NEW.visit_type)) BETWEEN 1 AND 64
            AND NEW.care_setting IN ('OPD', 'IPD', 'EMERGENCY') AND NEW.version > 0
            AND ((NEW.status IN ('open', 'in_progress') AND NEW.closed_at IS NULL)
                OR (NEW.status IN ('closed', 'cancelled') AND NEW.closed_at IS NOT NULL AND NEW.closed_at >= NEW.opened_at))");
        $this->trigger('encounters', 'insert', 'encounters_start_open',
            "NEW.status <> 'open' OR NEW.version <> 1
            OR NOT EXISTS (SELECT 1 FROM patients p WHERE p.id = NEW.patient_id AND p.category_id = NEW.category_id)
            OR (NEW.appointment_id IS NOT NULL AND NOT EXISTS
                (SELECT 1 FROM appointments a WHERE a.id = NEW.appointment_id AND a.patient_id = NEW.patient_id AND a.status = 'attended'))",
            'Encounters must start open with matching patient, category and attended booking.');
        $this->trigger('encounters', 'update', 'encounters_preserve_lifecycle',
            "NEW.id <> OLD.id OR NEW.patient_id <> OLD.patient_id OR ".$this->changed('appointment_id')."
            OR NEW.category_id <> OLD.category_id OR NEW.opened_by <> OLD.opened_by OR ".$this->changed('opened_at')."
            OR ".$this->changed('created_at')." OR NEW.version <> OLD.version + 1
            OR OLD.status NOT IN ('open', 'in_progress')
            OR NOT (NEW.status = OLD.status OR (OLD.status = 'open' AND NEW.status IN ('in_progress', 'cancelled'))
                OR (OLD.status = 'in_progress' AND NEW.status = 'closed'))
            OR (OLD.status = 'in_progress' AND (".$this->changed('visit_type')." OR ".$this->changed('care_setting')."))
            OR (NEW.status IN ('closed', 'cancelled') AND EXISTS
                (SELECT 1 FROM doctor_assignments d WHERE d.encounter_id = OLD.id AND d.ended_at IS NULL".$this->locking()."))
            OR (NEW.status = 'cancelled' AND EXISTS
                (SELECT 1 FROM doctor_assignments d WHERE d.encounter_id = OLD.id AND d.accepted_at IS NOT NULL".$this->locking()."))
            OR (NEW.closed_at IS NOT NULL AND EXISTS
                (SELECT 1 FROM doctor_assignments d WHERE d.encounter_id = OLD.id AND d.ended_at > NEW.closed_at".$this->locking()."))",
            'Encounter identity, assignment state, version or transition is invalid.');

        foreach (['insert', 'update'] as $operation) {
            if (DB::getDriverName() === 'mysql') {
                DB::unprepared("CREATE TRIGGER assignments_lock_encounter_{$operation} BEFORE {$operation} ON doctor_assignments FOR EACH ROW
                    BEGIN DECLARE locked_id BIGINT UNSIGNED;
                    SELECT id INTO locked_id FROM encounters WHERE id = NEW.encounter_id FOR UPDATE; END");
            }
        }
        $this->guard('doctor_assignments', "NEW.version > 0
            AND (NEW.consultation_type IS NULL OR length(trim(NEW.consultation_type)) BETWEEN 1 AND 64)
            AND EXISTS (SELECT 1 FROM encounters e WHERE e.id = NEW.encounter_id AND NEW.assigned_at >= e.opened_at
                AND e.status IN ('open', 'in_progress') AND (NEW.status <> 'accepted' OR e.status = 'in_progress'))
            AND ((NEW.status = 'assigned' AND NEW.accepted_at IS NULL AND NEW.ended_at IS NULL AND NEW.ended_by IS NULL AND NEW.end_reason IS NULL)
                OR (NEW.status = 'accepted' AND NEW.accepted_at >= NEW.assigned_at AND NEW.ended_at IS NULL AND NEW.ended_by IS NULL AND NEW.end_reason IS NULL)
                OR (NEW.status IN ('completed', 'cancelled', 'reassigned') AND NEW.ended_at >= NEW.assigned_at AND NEW.ended_by IS NOT NULL
                    AND (NEW.accepted_at IS NULL OR (NEW.accepted_at >= NEW.assigned_at AND NEW.ended_at >= NEW.accepted_at))
                    AND (NEW.status <> 'completed' OR NEW.accepted_at IS NOT NULL)
                    AND (NEW.status <> 'cancelled' OR NEW.accepted_at IS NULL)
                    AND (NEW.status = 'completed' OR length(trim(NEW.end_reason)) > 0)))");
        $this->trigger('doctor_assignments', 'insert', 'assignments_start_assigned',
            "NEW.status <> 'assigned' OR NEW.version <> 1
            OR EXISTS (SELECT 1 FROM doctor_assignments d WHERE d.encounter_id = NEW.encounter_id AND d.ended_at > NEW.assigned_at)",
            'Assignments must start assigned at version one after earlier assignments ended.');
        $this->trigger('doctor_assignments', 'update', 'assignments_preserve_history',
            "NEW.id <> OLD.id OR NEW.encounter_id <> OLD.encounter_id OR NEW.doctor_id <> OLD.doctor_id
            OR NEW.assigned_by <> OLD.assigned_by OR ".$this->changed('assigned_at')." OR ".$this->changed('consultation_type')."
            OR ".$this->changed('created_at')." OR NEW.version <> OLD.version + 1
            OR OLD.status NOT IN ('assigned', 'accepted') OR NEW.status = OLD.status
            OR (OLD.status = 'assigned' AND NEW.status <> 'accepted' AND NEW.accepted_at IS NOT NULL)
            OR (OLD.accepted_at IS NOT NULL AND ".$this->changed('accepted_at').")
            OR NOT ((OLD.status = 'assigned' AND NEW.status IN ('accepted', 'cancelled', 'reassigned'))
                OR (OLD.status = 'accepted' AND NEW.status IN ('completed', 'reassigned')))",
            'Assignment history, version or transition is invalid.');
        foreach (['appointments', 'encounters', 'doctor_assignments'] as $table) {
            $this->trigger($table, 'delete', $table.'_no_delete', '1 = 1', 'Visit records cannot be deleted.');
        }
        if (DB::getDriverName() === 'sqlite') {
            foreach (['appointments' => ['starts_at', 'ends_at'], 'encounters' => ['opened_at', 'closed_at'],
                'doctor_assignments' => ['assigned_at', 'accepted_at', 'ended_at']] as $table => $columns) {
                foreach ($columns as $column) {
                    $this->guardDates($table, $column);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['doctor_assignments', 'encounters', 'appointments'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function statusColumn(Blueprint $table, string $default): void
    {
        $column = $table->string('status', 16)->default($default);
        if (DB::getDriverName() === 'mysql') {
            $column->collation('utf8mb4_0900_as_cs');
        }
    }

    private function changed(string $column): string
    {
        return DB::getDriverName() === 'mysql'
            ? "NOT (CAST(NEW.{$column} AS BINARY) <=> CAST(OLD.{$column} AS BINARY))"
            : "NEW.{$column} IS NOT OLD.{$column}";
    }

    private function locking(): string
    {
        return DB::getDriverName() === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function guard(string $table, string $condition): void
    {
        foreach (['insert', 'update'] as $operation) {
            $this->trigger($table, $operation, $table.'_validate_'.$operation, "({$condition}) IS NOT TRUE", 'Invalid visit record.');
        }
    }

    private function guardDates(string $table, string $column): void
    {
        foreach (['insert', 'update'] as $operation) {
            $this->trigger($table, $operation, $table.'_'.$column.'_'.$operation,
                "NEW.{$column} IS NOT NULL AND julianday(NEW.{$column}) IS NULL", 'Invalid visit timestamp.');
        }
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

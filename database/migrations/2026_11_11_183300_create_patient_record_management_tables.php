<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotency_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('outcome_id')->nullable();
        });
        Schema::create('patient_flags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->string('type', 64);
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('resolution_reason')->nullable();
            $table->dateTime('resolved_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['patient_id', 'resolved_at', 'id']);
        });
        Schema::create('patient_category_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('previous_category_id')->constrained('patient_categories')->restrictOnDelete();
            $table->foreignId('new_category_id')->constrained('patient_categories')->restrictOnDelete();
            $table->string('previous_mrn', 64);
            $table->string('new_mrn', 64);
            $table->text('reason');
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('changed_at', 6);
            $table->timestamps(6);
            $table->index(['patient_id', 'id']);
        });
        Schema::create('patient_access_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->string('action', 16);
            $table->text('reason');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('occurred_at', 6);
            $table->timestamps(6);
            $table->index(['patient_id', 'id']);
        });
        Schema::create('patient_merge_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('target_patient_id')->constrained('patients')->restrictOnDelete();
            $table->unsignedInteger('source_version');
            $table->unsignedInteger('target_version');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('review_reason')->nullable();
            $table->dateTime('reviewed_at', 6)->nullable();
            $table->unsignedBigInteger('pending_source_id')->nullable()
                ->virtualAs("CASE WHEN status = 'pending' THEN source_patient_id ELSE NULL END")->unique();
            $table->timestamps(6);
            $table->index(['target_patient_id', 'status']);
        });
        Schema::create('patient_merges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->unique()->constrained('patient_merge_requests')->restrictOnDelete();
            $table->foreignId('source_patient_id')->unique()->constrained('patients')->restrictOnDelete();
            $table->foreignId('target_patient_id')->constrained('patients')->restrictOnDelete();
            $table->unsignedInteger('source_version');
            $table->unsignedInteger('target_version');
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->dateTime('approved_at', 6);
            $table->timestamps(6);
            $table->index('target_patient_id');
        });
        $flagTypeChanged = $this->changed('type');
        $reasonChanged = $this->changed('reason');
        $this->guard('patient_flags', "length(trim(NEW.type)) BETWEEN 1 AND 64 AND length(trim(NEW.reason)) > 0
            AND ((NEW.resolved_at IS NULL AND NEW.resolved_by IS NULL AND NEW.resolution_reason IS NULL)
                OR (NEW.resolved_at IS NOT NULL AND NEW.resolved_by IS NOT NULL AND length(trim(NEW.resolution_reason)) > 0))");
        $this->trigger('patient_flags', 'update', 'patient_flags_preserve_history',
            "NEW.patient_id <> OLD.patient_id OR {$flagTypeChanged} OR {$reasonChanged} OR NEW.created_by <> OLD.created_by
                OR OLD.resolved_at IS NOT NULL OR NEW.resolved_at IS NULL", 'Flag history cannot be rewritten.');
        $this->trigger('patient_flags', 'delete', 'patient_flags_no_delete', '1 = 1', 'Flag history cannot be deleted.');
        $this->guard('patient_access_events', "NEW.action IN ('restrict', 'restore') AND length(trim(NEW.reason)) > 0");
        $this->guard('patient_category_history', 'length(trim(NEW.reason)) > 0 AND length(NEW.previous_mrn) > 0 AND length(NEW.new_mrn) > 0');
        $this->guard('patient_merge_requests', "NEW.source_patient_id <> NEW.target_patient_id AND NEW.source_version > 0 AND NEW.target_version > 0
            AND length(trim(NEW.reason)) > 0 AND NEW.version > 0
            AND ((NEW.status = 'pending' AND NEW.version = 1 AND NEW.reviewed_at IS NULL AND NEW.reviewed_by IS NULL AND NEW.review_reason IS NULL)
                OR (NEW.status IN ('approved', 'rejected', 'cancelled') AND NEW.version = 2 AND NEW.reviewed_at IS NOT NULL
                    AND NEW.reviewed_by IS NOT NULL AND length(trim(NEW.review_reason)) > 0
                    AND ((NEW.status = 'cancelled' AND NEW.reviewed_by = NEW.requested_by)
                        OR (NEW.status IN ('approved', 'rejected') AND NEW.reviewed_by <> NEW.requested_by))))");
        $this->trigger('patient_merge_requests', 'insert', 'patient_merge_requests_start_pending', "NEW.status <> 'pending'", 'Merge requests must start pending.');
        $this->trigger('patient_merge_requests', 'update', 'patient_merge_requests_preserve_history',
            "OLD.status <> 'pending' OR NEW.status = 'pending' OR NEW.source_patient_id <> OLD.source_patient_id
                OR NEW.target_patient_id <> OLD.target_patient_id OR NEW.source_version <> OLD.source_version
                OR NEW.target_version <> OLD.target_version OR NEW.requested_by <> OLD.requested_by OR {$reasonChanged}",
            'Merge requests can only be reviewed once.');
        $this->trigger('patient_merge_requests', 'delete', 'patient_merge_requests_no_delete', '1 = 1', 'Merge request history cannot be deleted.');
        $this->guard('patient_merges', "NEW.source_patient_id <> NEW.target_patient_id AND NEW.source_version > 0 AND NEW.target_version > 0
            AND EXISTS (SELECT 1 FROM patient_merge_requests r WHERE r.id = NEW.request_id AND r.status = 'approved'
                AND r.source_patient_id = NEW.source_patient_id AND r.target_patient_id = NEW.target_patient_id
                AND r.reviewed_by = NEW.approved_by AND r.requested_by <> NEW.approved_by
                AND NEW.source_version = r.source_version + 1 AND NEW.target_version = r.target_version + 1)
            AND NOT EXISTS (SELECT 1 FROM patient_merges m WHERE m.source_patient_id = NEW.target_patient_id)");
        foreach (['patient_category_history', 'patient_access_events', 'patient_merges'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                $this->trigger($table, $operation, $table.'_no_'.$operation, '1 = 1', 'Patient management history is append-only.');
            }
        }
    }

    public function down(): void
    {
        foreach (['patient_merges', 'patient_merge_requests', 'patient_access_events', 'patient_category_history', 'patient_flags'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('idempotency_requests', function (Blueprint $table): void {
            $table->dropColumn('outcome_id');
        });
    }

    private function changed(string $column): string
    {
        return DB::getDriverName() === 'mysql'
            ? "CAST(NEW.{$column} AS BINARY) <> CAST(OLD.{$column} AS BINARY)"
            : "NEW.{$column} <> OLD.{$column}";
    }

    private function guard(string $table, string $condition): void
    {
        foreach (['insert', 'update'] as $operation) {
            $this->trigger($table, $operation, $table.'_validate_'.$operation, "({$condition}) IS NOT TRUE", 'Invalid patient management record.');
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

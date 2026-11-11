<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $settings = DB::selectOne('SELECT @@log_bin AS binary_logging, @@log_bin_trust_function_creators AS trusted_creators');
            if ($settings->binary_logging && ! $settings->trusted_creators) {
                throw new RuntimeException('Before installing audit triggers with binary logging, ask the database administrator to enable log_bin_trust_function_creators for the migration. See the audit setup notes.');
            }
        }

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('action', 64);
            $table->string('outcome', 16);
            $table->string('channel', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->uuid('request_id');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('safe_summary');
            $table->dateTime('occurred_at', 6);
            $table->index(['occurred_at', 'id']);
            $table->index(['subject_type', 'subject_id', 'occurred_at']);
            $table->index(['actor_id', 'occurred_at']);
            $table->index(['action', 'outcome', 'occurred_at']);
        });

        foreach (['update', 'delete'] as $operation) {
            $name = 'audit_events_no_'.$operation;
            if (DB::getDriverName() === 'mysql') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit events are append-only'");
            } elseif (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON audit_events BEGIN SELECT RAISE(ABORT, 'Audit events are append-only'); END");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};

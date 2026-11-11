<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('route', 191);
            $table->char('request_key', 64);
            $table->char('request_hash', 64);
            $table->string('status', 32);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('resource_type', 32)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->unsignedInteger('resource_version')->nullable();
            $table->dateTime('expires_at', 6);
            $table->timestamps(6);
            $table->unique(['user_id', 'route', 'request_key']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_requests');
    }
};

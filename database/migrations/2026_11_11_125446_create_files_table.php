<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table): void {
            $table->id();
            $table->string('storage_path', 160)->unique();
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('byte_size');
            $table->char('sha256', 64);
            $table->string('scan_status', 32)->default('not_scanned');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps(6);
            $table->index(['uploaded_by', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};

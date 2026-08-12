<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_suite_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('uploaded_by')->nullable();
            $table->longText('playwright_code')->nullable();
            $table->enum('status', ['draft', 'active', 'disabled'])->default('draft');
            $table->timestamp('last_run_at')->nullable();
            $table->enum('last_run_status', ['passed', 'failed', 'error', 'timeout', 'cancelled'])->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tests');
    }
};

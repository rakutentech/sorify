<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_suite_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_suite_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('cron_expression');
            $table->string('timezone')->default('UTC');
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_suite_schedules');
    }
};

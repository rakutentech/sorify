<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_schedule_test', function (Blueprint $table) {
            $table->foreignId('test_suite_schedule_id')->constrained('test_suite_schedules')->cascadeOnDelete();
            $table->foreignId('test_id')->constrained()->cascadeOnDelete();

            $table->primary(['test_suite_schedule_id', 'test_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_schedule_test');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_suite_variables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_suite_id')->constrained()->cascadeOnDelete();
            $table->string('key', 255);
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['test_suite_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_suite_variables');
    }
};

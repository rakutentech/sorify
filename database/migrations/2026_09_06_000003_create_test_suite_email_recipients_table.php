<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_suite_email_recipients', function (Blueprint $table) {
            $table->foreignId('test_suite_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['test_suite_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_suite_email_recipients');
    }
};

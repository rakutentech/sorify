<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_suite_proxy_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_suite_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 255);
            $table->string('proxy', 500);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_suite_proxy_rules');
    }
};

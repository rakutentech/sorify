<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_suite_cookies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_suite_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->text('value')->nullable();
            $table->string('domain', 255)->nullable();
            $table->string('path', 255)->nullable();
            $table->string('url', 500)->nullable();
            $table->bigInteger('expires')->nullable();
            $table->boolean('http_only')->default(false);
            $table->boolean('secure')->default(false);
            $table->string('same_site', 10)->nullable();
            $table->timestamps();

            $table->index('test_suite_id');
            // Same cookie name may exist for different domains/paths.
            $table->unique(['test_suite_id', 'name', 'domain', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_suite_cookies');
    }
};

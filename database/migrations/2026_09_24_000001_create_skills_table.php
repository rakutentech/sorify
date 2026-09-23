<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A user-owned skill: a markdown document attached to My AI Agent
        // chats as extra instructions. Private by default; a public skill
        // appears in the shared browse page and can be copied by others.
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->longText('content');
            $table->boolean('is_public')->default(false);
            $table->unsignedInteger('copies_count')->default(0);
            // The skill this one was copied from (a copy is fully owned and
            // editable by the copier; later edits don't propagate).
            $table->foreignId('copied_from_id')->nullable()->constrained('skills')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'name']);
            $table->index(['is_public', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skills');
    }
};

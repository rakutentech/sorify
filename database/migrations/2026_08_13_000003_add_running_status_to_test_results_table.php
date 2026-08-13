<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->rebuildSqliteTable(['passed', 'failed', 'error', 'timeout', 'skipped', 'cancelled', 'running']);
            return;
        }

        DB::statement("ALTER TABLE test_results MODIFY status ENUM('passed', 'failed', 'error', 'timeout', 'skipped', 'cancelled', 'running') NOT NULL");
    }

    public function down(): void
    {
        DB::table('test_results')->where('status', 'running')->update(['status' => 'error']);

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->rebuildSqliteTable(['passed', 'failed', 'error', 'timeout', 'skipped', 'cancelled']);
            return;
        }

        DB::statement("ALTER TABLE test_results MODIFY status ENUM('passed', 'failed', 'error', 'timeout', 'skipped', 'cancelled') NOT NULL");
    }

    // SQLite has no ALTER COLUMN support for CHECK constraints (which is how it
    // enforces enum()); the table must be rebuilt with the new allowed value list.
    // legacy_alter_table prevents SQLite from rewriting other tables' (e.g.
    // screenshots) foreign keys to point at the temporary "_old" name on rename.
    private function rebuildSqliteTable(array $statuses): void
    {
        DB::statement('PRAGMA legacy_alter_table = ON');
        Schema::disableForeignKeyConstraints();

        Schema::rename('test_results', 'test_results_old');

        Schema::create('test_results', function (Blueprint $table) use ($statuses) {
            $table->id();
            $table->foreignId('test_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('test_id')->constrained()->cascadeOnDelete();
            $table->enum('status', $statuses);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->longText('stdout')->nullable();
            $table->longText('stderr')->nullable();
            $table->text('error_message')->nullable();
            $table->text('error_stack')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        DB::statement('INSERT INTO test_results SELECT * FROM test_results_old');

        Schema::drop('test_results_old');

        Schema::enableForeignKeyConstraints();
        DB::statement('PRAGMA legacy_alter_table = OFF');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_suites', function (Blueprint $table) {
            $table->string('duplication_status', 20)->nullable()->after('status');
            $table->foreignId('duplicated_from_suite_id')
                ->nullable()
                ->after('duplication_status')
                ->constrained('test_suites')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('test_suites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duplicated_from_suite_id');
            $table->dropColumn('duplication_status');
        });
    }
};

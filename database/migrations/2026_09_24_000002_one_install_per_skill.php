<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A user can install a given shared skill only once: unique index on
     * (user_id, copied_from_id). Existing duplicates (if any) are reduced
     * to the newest copy per pair before the index is added.
     */
    public function up(): void
    {
        $duplicateIds = DB::table('skills as s1')
            ->join('skills as s2', function ($join) {
                $join->on('s1.user_id', 's2.user_id')
                    ->on('s1.copied_from_id', 's2.copied_from_id')
                    ->on('s1.id', '<', 's2.id');
            })
            ->whereNotNull('s1.copied_from_id')
            ->pluck('s1.id');

        if ($duplicateIds->isNotEmpty()) {
            DB::table('skills')->whereIn('id', $duplicateIds)->delete();
        }

        Schema::table('skills', function (Blueprint $table) {
            $table->unique(['user_id', 'copied_from_id'], 'skills_user_copied_from_unique');
        });
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->dropUnique('skills_user_copied_from_unique');
        });
    }
};

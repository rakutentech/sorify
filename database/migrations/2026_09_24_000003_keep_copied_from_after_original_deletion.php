<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep copied_from_id when the original skill is deleted.
     *
     * An installed copy is fully detached, so deleting the original must
     * not touch it. Previously the FK nulled copied_from_id on delete,
     * which silently turned the installed copy into what looks like an
     * original — the profile could no longer tell "installed, but the
     * original is gone". Without the constraint the id simply dangles and
     * the missing original is detectable (and displayable) in code.
     */
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->dropForeign(['copied_from_id']);
        });
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->foreign('copied_from_id')->references('id')->on('skills')->nullOnDelete();
        });
    }
};

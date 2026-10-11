<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            // Optional custom headers (name => value) sent with every chat
            // request to the profile's endpoint. Some gateways route or
            // filter by client identity (e.g. a User-Agent they recognize);
            // the default Guzzle UA is not always on the allow list, so the
            // per-profile escape hatch is user-configured headers. Nothing
            // is preset — the profile owner decides what to send.
            $table->json('extra_headers')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->dropColumn('extra_headers');
        });
    }
};

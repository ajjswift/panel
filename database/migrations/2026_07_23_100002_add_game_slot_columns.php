<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Maximum number of game slots for the server. A value of 1 means the
            // server behaves exactly like a standard Pterodactyl server.
            $table->unsignedInteger('game_slot_limit')->default(1)->after('backup_limit');
        });

        Schema::table('eggs', function (Blueprint $table) {
            // Administrator allowlist flag: only eggs explicitly marked as safe
            // may be used for game-slot creation and switching.
            $table->boolean('game_switch_enabled')->default(false)->after('script_is_privileged');
        });

        Schema::table('backups', function (Blueprint $table) {
            // Which slot was active when the backup was taken. NULL for backups
            // created before this feature existed ("legacy" backups).
            $table->unsignedInteger('game_slot_id')->nullable()->after('server_id');

            $table->foreign('game_slot_id')->references('id')->on('game_slots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropForeign(['game_slot_id']);
            $table->dropColumn('game_slot_id');
        });

        Schema::table('eggs', function (Blueprint $table) {
            $table->dropColumn('game_switch_enabled');
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('game_slot_limit');
        });
    }
};

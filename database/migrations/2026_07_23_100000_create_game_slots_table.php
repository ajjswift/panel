<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('game_slots', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('nest_id');
            $table->unsignedInteger('egg_id');
            $table->string('name');
            $table->string('docker_image');
            $table->text('startup');
            // Saved egg environment for the slot while it is inactive. Values are
            // encrypted at rest since egg variables may contain sensitive data.
            $table->text('environment')->nullable();
            // Directory name of the slot's store inside the server volume. Never
            // exposed through client APIs.
            $table->string('storage_reference');
            $table->string('installation_status')->default('not_installed');
            $table->string('state')->default('normal');
            $table->boolean('is_active')->default(false);
            // Maintained alongside is_active: holds the server_id when the slot is
            // active and NULL otherwise, giving a portable database-level guarantee
            // that at most one slot per server is ever active.
            $table->unsignedInteger('active_marker')->nullable();
            $table->unsignedBigInteger('disk_usage_bytes')->default(0);
            $table->timestamp('disk_scanned_at')->nullable();
            $table->timestamp('last_activated_at')->nullable();
            $table->timestamp('last_switch_completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('active_marker');
            $table->index(['server_id', 'is_active']);
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('nest_id')->references('id')->on('nests');
            $table->foreign('egg_id')->references('id')->on('eggs');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_slots');
    }
};

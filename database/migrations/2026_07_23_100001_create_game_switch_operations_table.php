<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('game_switch_operations', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('source_slot_id')->nullable();
            $table->unsignedInteger('destination_slot_id');
            $table->unsignedInteger('requested_by')->nullable();
            $table->string('state')->default('pending');
            $table->string('current_stage')->default('pending');
            // Last durably completed checkpoint; recovery resumes from here.
            $table->string('checkpoint')->default('created');
            // Holds the server_id while the operation is live and NULL once it is
            // terminal. The unique index makes concurrent switches impossible at
            // the database level.
            $table->unsignedInteger('lock_marker')->nullable();
            $table->string('previous_power_state')->nullable();
            $table->boolean('restore_power')->default(true);
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->json('internal_error_context')->nullable();
            $table->string('rollback_state')->nullable();
            $table->timestamps();

            $table->unique('lock_marker');
            $table->index(['server_id', 'state']);
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('source_slot_id')->references('id')->on('game_slots')->nullOnDelete();
            $table->foreign('destination_slot_id')->references('id')->on('game_slots')->cascadeOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_switch_operations');
    }
};

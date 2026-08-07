<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('eggs', function (Blueprint $table) {
            $table->unsignedSmallInteger('initial_allocation_count')->default(1)->after('force_outgoing_ip');
        });

        Schema::table('egg_variables', function (Blueprint $table) {
            // One-based index into the allocations assigned when the server is created.
            $table->unsignedSmallInteger('allocation_index')->nullable()->after('default_value');
        });
    }

    public function down(): void
    {
        Schema::table('egg_variables', function (Blueprint $table) {
            $table->dropColumn('allocation_index');
        });

        Schema::table('eggs', function (Blueprint $table) {
            $table->dropColumn('initial_allocation_count');
        });
    }
};

<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('resellers', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            // The account that owns this reseller. Unique: one reseller per user.
            $table->unsignedInteger('user_id')->unique();
            $table->string('name');
            $table->boolean('enabled')->default(true);

            // Resource pool. Across every dimension: -1 = unlimited, 0 = none.
            $table->integer('memory')->default(0);
            $table->integer('disk')->default(0);
            $table->integer('cpu')->default(0);
            $table->integer('server_limit')->default(0);
            $table->integer('user_limit')->default(0);
            $table->integer('database_limit')->default(0);
            $table->integer('allocation_limit')->default(0);
            $table->integer('backup_limit')->default(0);

            // Branding. Colors are hex strings; the 50-900 ramps are derived
            // from them server-side by ColorRampGenerator.
            $table->string('app_name')->nullable();
            $table->string('brand_color', 7)->nullable();
            $table->string('accent_color', 7)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->timestamp('theme_updated_at')->nullable();

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('reseller_nodes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('reseller_id');
            $table->unsignedInteger('node_id');

            $table->unique(['reseller_id', 'node_id']);
            $table->foreign('reseller_id')->references('id')->on('resellers')->cascadeOnDelete();
            $table->foreign('node_id')->references('id')->on('nodes')->cascadeOnDelete();
        });

        Schema::create('reseller_domains', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('reseller_id');
            $table->string('hostname')->unique();
            $table->string('verification_token', 64);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_check_error')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->foreign('reseller_id')->references('id')->on('resellers')->cascadeOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            // Set on a reseller's *tenants*, never on the reseller's own account.
            $table->unsignedInteger('reseller_id')->nullable()->after('root_admin');

            $table->foreign('reseller_id')->references('id')->on('resellers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
            $table->dropColumn('reseller_id');
        });

        Schema::dropIfExists('reseller_domains');
        Schema::dropIfExists('reseller_nodes');
        Schema::dropIfExists('resellers');
    }
};

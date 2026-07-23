<?php

use Ramsey\Uuid\Uuid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('dns_service_profiles', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('protocol')->default('tcp');
            $table->unsignedInteger('default_port')->nullable();
            $table->boolean('supports_direct_dns')->default(true);
            $table->boolean('supports_srv')->default(false);
            $table->string('srv_service')->nullable();
            $table->string('srv_protocol')->nullable();
            $table->unsignedSmallInteger('srv_priority')->default(0);
            $table->unsignedSmallInteger('srv_weight')->default(5);
            $table->boolean('portless_on_default_port')->default(true);
            $table->boolean('cloudflare_proxy_eligible')->default(false);
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        DB::table('dns_service_profiles')->insert([
            [
                'uuid' => Uuid::uuid4()->toString(),
                'slug' => 'minecraft-java',
                'name' => 'Minecraft Java',
                'protocol' => 'tcp',
                'default_port' => 25565,
                'supports_direct_dns' => true,
                'supports_srv' => true,
                'srv_service' => '_minecraft',
                'srv_protocol' => '_tcp',
                'srv_priority' => 0,
                'srv_weight' => 5,
                'portless_on_default_port' => true,
                'cloudflare_proxy_eligible' => false,
                'description' => 'Minecraft Java clients support SRV discovery for non-default ports.',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'uuid' => Uuid::uuid4()->toString(),
                'slug' => 'generic-tcp',
                'name' => 'Generic TCP service',
                'protocol' => 'tcp',
                'default_port' => null,
                'supports_direct_dns' => true,
                'supports_srv' => false,
                'srv_service' => null,
                'srv_protocol' => null,
                'srv_priority' => 0,
                'srv_weight' => 5,
                'portless_on_default_port' => false,
                'cloudflare_proxy_eligible' => false,
                'description' => 'DNS resolves the hostname, but clients must include non-default ports.',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'uuid' => Uuid::uuid4()->toString(),
                'slug' => 'generic-udp',
                'name' => 'Generic UDP service',
                'protocol' => 'udp',
                'default_port' => null,
                'supports_direct_dns' => true,
                'supports_srv' => false,
                'srv_service' => null,
                'srv_protocol' => null,
                'srv_priority' => 0,
                'srv_weight' => 5,
                'portless_on_default_port' => false,
                'cloudflare_proxy_eligible' => false,
                'description' => 'DNS resolves the hostname, but clients must include non-default ports.',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'uuid' => Uuid::uuid4()->toString(),
                'slug' => 'unsupported',
                'name' => 'Unsupported service',
                'protocol' => 'tcp',
                'default_port' => null,
                'supports_direct_dns' => false,
                'supports_srv' => false,
                'srv_service' => null,
                'srv_protocol' => null,
                'srv_priority' => 0,
                'srv_weight' => 0,
                'portless_on_default_port' => false,
                'cloudflare_proxy_eligible' => false,
                'description' => 'This service is not eligible for managed public DNS.',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Schema::create('managed_domains', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->string('name');
            $table->string('domain')->unique();
            $table->string('provider')->default('cloudflare');
            $table->string('zone_id');
            $table->text('api_token');
            $table->boolean('enabled')->default(false);
            $table->string('label_pattern')->default('^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$');
            $table->unsignedInteger('per_server_limit')->nullable();
            $table->unsignedInteger('per_user_limit')->nullable();
            $table->unsignedInteger('domain_limit')->nullable();
            $table->boolean('supports_srv')->default(true);
            $table->boolean('supports_direct_dns')->default(true);
            $table->unsignedInteger('ttl')->default(300);
            $table->string('target_ipv4')->nullable();
            $table->string('target_ipv6')->nullable();
            $table->string('cname_target')->nullable();
            $table->json('allowed_node_ids')->nullable();
            $table->json('allowed_egg_ids')->nullable();
            $table->json('allowed_service_profile_ids')->nullable();
            $table->json('reserved_labels')->nullable();
            $table->text('description')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamp('last_provider_check_at')->nullable();
            $table->string('last_provider_status')->nullable();
            $table->string('last_provider_error_code')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'domain']);
        });

        Schema::table('nodes', function (Blueprint $table) {
            $table->string('dns_target_ipv4')->nullable()->after('fqdn');
            $table->string('dns_target_ipv6')->nullable()->after('dns_target_ipv4');
            $table->string('dns_target_hostname')->nullable()->after('dns_target_ipv6');
        });

        Schema::table('eggs', function (Blueprint $table) {
            $table->string('subdomain_compatibility')->default('incompatible')->after('game_switch_enabled');
            $table->string('subdomain_default_policy')->default('disabled')->after('subdomain_compatibility');
            $table->unsignedInteger('dns_service_profile_id')->nullable()->after('subdomain_default_policy');
            $table->boolean('subdomain_server_override_allowed')->default(true)->after('dns_service_profile_id');
            $table->text('subdomain_notes')->nullable()->after('subdomain_server_override_allowed');

            $table->foreign('dns_service_profile_id')->references('id')->on('dns_service_profiles')->nullOnDelete();
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->string('subdomain_policy')->default('inherit')->after('game_slot_limit');
            $table->unsignedInteger('subdomain_limit')->default(0)->after('subdomain_policy');
            $table->unsignedInteger('dns_service_profile_id')->nullable()->after('subdomain_limit');
            $table->json('subdomain_domain_restrictions')->nullable()->after('dns_service_profile_id');
            $table->string('subdomain_policy_source')->nullable()->after('subdomain_domain_restrictions');
            $table->text('subdomain_admin_notes')->nullable()->after('subdomain_policy_source');

            $table->foreign('dns_service_profile_id')->references('id')->on('dns_service_profiles')->nullOnDelete();
        });

        Schema::create('managed_subdomains', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('allocation_id')->nullable();
            $table->unsignedInteger('managed_domain_id');
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('dns_service_profile_id')->nullable();
            $table->string('label');
            $table->string('fqdn')->unique();
            $table->string('routing_mode')->default('direct_dns');
            $table->string('detected_service');
            $table->string('service_detection_source');
            $table->string('status')->default('pending');
            $table->unsignedInteger('desired_state_version')->default(1);
            $table->string('public_target_type');
            $table->string('public_target');
            $table->unsignedInteger('target_port');
            $table->string('connection_address');
            $table->json('desired_record_plan');
            $table->timestamp('last_synchronized_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('sanitized_error_message')->nullable();
            $table->timestamp('provider_drift_detected_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'status']);
            $table->index(['allocation_id', 'status']);
            $table->index(['managed_domain_id', 'status']);
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('allocation_id')->references('id')->on('allocations')->nullOnDelete();
            $table->foreign('managed_domain_id')->references('id')->on('managed_domains');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('dns_service_profile_id')->references('id')->on('dns_service_profiles')->nullOnDelete();
        });

        Schema::create('managed_dns_records', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('managed_subdomain_id');
            $table->string('provider');
            $table->string('zone_id');
            $table->string('provider_record_id')->nullable();
            $table->string('type', 10);
            $table->string('name');
            $table->text('content')->nullable();
            $table->unsignedInteger('ttl')->default(300);
            $table->boolean('proxied')->default(false);
            $table->unsignedSmallInteger('srv_priority')->nullable();
            $table->unsignedSmallInteger('srv_weight')->nullable();
            $table->unsignedInteger('srv_port')->nullable();
            $table->string('srv_target')->nullable();
            $table->string('sync_status')->default('pending');
            $table->timestamp('last_synchronized_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_record_id']);
            $table->index(['managed_subdomain_id', 'sync_status']);
            $table->foreign('managed_subdomain_id')->references('id')->on('managed_subdomains')->cascadeOnDelete();
        });

        Schema::create('managed_dns_sync_attempts', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('managed_subdomain_id');
            $table->unsignedInteger('desired_state_version');
            $table->string('action');
            $table->string('status')->default('queued');
            $table->unsignedInteger('attempt')->default(0);
            $table->json('completed_steps')->nullable();
            $table->string('error_code')->nullable();
            $table->text('sanitized_error_message')->nullable();
            $table->string('rollback_status')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['managed_subdomain_id', 'created_at']);
            $table->foreign('managed_subdomain_id')->references('id')->on('managed_subdomains')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_dns_sync_attempts');
        Schema::dropIfExists('managed_dns_records');
        Schema::dropIfExists('managed_subdomains');

        Schema::table('servers', function (Blueprint $table) {
            $table->dropForeign(['dns_service_profile_id']);
            $table->dropColumn([
                'subdomain_policy',
                'subdomain_limit',
                'dns_service_profile_id',
                'subdomain_domain_restrictions',
                'subdomain_policy_source',
                'subdomain_admin_notes',
            ]);
        });

        Schema::table('eggs', function (Blueprint $table) {
            $table->dropForeign(['dns_service_profile_id']);
            $table->dropColumn([
                'subdomain_compatibility',
                'subdomain_default_policy',
                'dns_service_profile_id',
                'subdomain_server_override_allowed',
                'subdomain_notes',
            ]);
        });

        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['dns_target_ipv4', 'dns_target_ipv6', 'dns_target_hostname']);
        });

        Schema::dropIfExists('managed_domains');
        Schema::dropIfExists('dns_service_profiles');
    }
};

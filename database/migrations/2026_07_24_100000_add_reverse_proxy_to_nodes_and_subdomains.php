<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->boolean('reverse_proxy_enabled')->default(false)->after('dns_target_hostname');
            // The managed domain under which this node's agent hostname lives
            // ({node-slug}.{base-domain}).
            $table->unsignedInteger('reverse_proxy_base_domain_id')->nullable()->after('reverse_proxy_enabled');
            $table->unsignedInteger('reverse_proxy_control_port')->default(8443)->after('reverse_proxy_base_domain_id');
            // Secret the panel presents to the agent's control API (encrypted).
            $table->text('reverse_proxy_api_key')->nullable()->after('reverse_proxy_control_port');
            // Public id + encrypted secret the agent presents on status callbacks
            // (mirrors the daemon_token_id / daemon_token pattern).
            $table->string('reverse_proxy_token_id')->nullable()->unique()->after('reverse_proxy_api_key');
            $table->text('reverse_proxy_token')->nullable()->after('reverse_proxy_token_id');
            // Last-reported agent health.
            $table->string('reverse_proxy_status')->nullable()->after('reverse_proxy_token');
            $table->string('reverse_proxy_agent_version')->nullable()->after('reverse_proxy_status');
            $table->timestamp('reverse_proxy_last_seen_at')->nullable()->after('reverse_proxy_agent_version');

            $table->foreign('reverse_proxy_base_domain_id')->references('id')->on('managed_domains')->nullOnDelete();
        });

        Schema::table('managed_subdomains', function (Blueprint $table) {
            // Per-route status reported by the node agent for reverse-proxied
            // addresses. Direct-DNS addresses leave these null.
            $table->string('proxy_dns_status')->nullable()->after('status');
            $table->string('proxy_cert_status')->nullable()->after('proxy_dns_status');
            $table->string('proxy_status')->nullable()->after('proxy_cert_status');
            $table->timestamp('proxy_cert_expires_at')->nullable()->after('proxy_status');
            $table->timestamp('proxy_reported_at')->nullable()->after('proxy_cert_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('managed_subdomains', function (Blueprint $table) {
            $table->dropColumn([
                'proxy_dns_status',
                'proxy_cert_status',
                'proxy_status',
                'proxy_cert_expires_at',
                'proxy_reported_at',
            ]);
        });

        Schema::table('nodes', function (Blueprint $table) {
            $table->dropForeign(['reverse_proxy_base_domain_id']);
            $table->dropColumn([
                'reverse_proxy_enabled',
                'reverse_proxy_base_domain_id',
                'reverse_proxy_control_port',
                'reverse_proxy_api_key',
                'reverse_proxy_token_id',
                'reverse_proxy_token',
                'reverse_proxy_status',
                'reverse_proxy_agent_version',
                'reverse_proxy_last_seen_at',
            ]);
        });
    }
};

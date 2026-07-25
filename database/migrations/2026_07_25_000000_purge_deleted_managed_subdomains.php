<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        // These rows were left behind by the old deletion flow. Removing the
        // parent also cascades to its managed DNS records and sync attempts.
        DB::table('managed_subdomains')->whereNotNull('deleted_at')->delete();
    }

    public function down(): void
    {
        // Deleted ownership records cannot be reconstructed.
    }
};

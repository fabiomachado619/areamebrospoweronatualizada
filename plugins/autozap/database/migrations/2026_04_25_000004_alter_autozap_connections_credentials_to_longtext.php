<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('autozap_connections')) {
            return;
        }

        // Avoid Doctrine DBAL dependency; use raw SQL.
        try {
            DB::statement('ALTER TABLE `autozap_connections` MODIFY `credentials` LONGTEXT NULL');
        } catch (\Throwable) {
            // Ignore if already LONGTEXT or engine doesn't support MODIFY in this way.
        }
    }

    public function down(): void
    {
        // Do not revert to JSON; longtext is safe for encrypted payloads.
    }
};


<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('enrollment_webhook_logs')) {
            return;
        }

        Schema::table('enrollment_webhook_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('enrollment_webhook_logs', 'external_product_id')) {
                $table->string('external_product_id', 191)->nullable()->after('course_id');
            }
        });

        Schema::table('enrollment_webhook_logs', function (Blueprint $table) {
            if (! $this->indexExists('enrollment_webhook_logs', 'enrollment_webhook_dedup')) {
                $table->index(
                    ['tenant_id', 'platform', 'transaction_id', 'event', 'course_id'],
                    'enrollment_webhook_dedup'
                );
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('enrollment_webhook_logs')) {
            return;
        }

        Schema::table('enrollment_webhook_logs', function (Blueprint $table) {
            if ($this->indexExists('enrollment_webhook_logs', 'enrollment_webhook_dedup')) {
                $table->dropIndex('enrollment_webhook_dedup');
            }
            if (Schema::hasColumn('enrollment_webhook_logs', 'external_product_id')) {
                $table->dropColumn('external_product_id');
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = $connection->select("PRAGMA index_list('{$table}')");

            return collect($indexes)->contains(fn ($index) => ($index->name ?? '') === $indexName);
        }

        $database = $connection->getDatabaseName();
        $result = $connection->select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $table, $indexName]
        );

        return $result !== [];
    }
};

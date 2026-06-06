<?php

use App\Models\EnrollmentWebhookCredential;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollment_webhook_credentials', function (Blueprint $table) {
            if (! Schema::hasColumn('enrollment_webhook_credentials', 'webhook_key')) {
                $table->string('webhook_key', 64)->nullable()->unique()->after('name');
            }
        });

        EnrollmentWebhookCredential::query()
            ->whereNull('webhook_key')
            ->orderBy('id')
            ->each(function (EnrollmentWebhookCredential $webhook) {
                $webhook->forceFill([
                    'webhook_key' => EnrollmentWebhookCredential::generateUniqueWebhookKey(),
                ])->save();
            });
    }

    public function down(): void
    {
        Schema::table('enrollment_webhook_credentials', function (Blueprint $table) {
            if (Schema::hasColumn('enrollment_webhook_credentials', 'webhook_key')) {
                $table->dropUnique(['webhook_key']);
                $table->dropColumn('webhook_key');
            }
        });
    }
};

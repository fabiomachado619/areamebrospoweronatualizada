<?php

namespace App\Console\Commands;

use App\Models\EnrollmentWebhookCredential;
use Illuminate\Console\Command;

class IssueEnrollmentWebhookTokenCommand extends Command
{
    protected $signature = 'enrollment-webhook:issue-token
                            {tenant_id : ID do tenant}
                            {--name=n8n : Nome identificador do token}
                            {--signing-secret= : Segredo HMAC opcional (X-Signature)}';

    protected $description = 'Gera token Bearer para POST /api/webhooks/enrollment (exibe uma única vez)';

    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant_id');
        $name = (string) $this->option('name');
        $signingSecret = $this->option('signing-secret');

        ['model' => $credential, 'plain_token' => $plain] = EnrollmentWebhookCredential::issueForTenant(
            $tenantId,
            $name,
            is_string($signingSecret) && $signingSecret !== '' ? $signingSecret : null
        );

        $this->info('Token de matrícula gerado com sucesso.');
        $this->line('Tenant ID: '.$tenantId);
        $this->line('Credential ID: '.$credential->id);
        $this->newLine();
        $this->warn('Guarde o token abaixo — ele não será exibido novamente:');
        $this->line($plain);
        $this->newLine();
        $this->line('Endpoint: POST '.url('/api/webhooks/enrollment'));
        $this->line('Header: Authorization: Bearer {token}');

        if ($credential->getSigningSecretPlain()) {
            $this->line('HMAC habilitado via X-Signature (sha256=...)');
        }

        return self::SUCCESS;
    }
}

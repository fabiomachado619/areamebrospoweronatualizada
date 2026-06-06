<?php

namespace App\Console\Commands;

use App\Models\EnrollmentExternalProductMapping;
use App\Models\Product;
use Illuminate\Console\Command;

class MapEnrollmentExternalProductCommand extends Command
{
    protected $signature = 'enrollment-webhook:map-product
                            {tenant_id : ID do tenant}
                            {platform : Plataforma externa (ex: kiwify)}
                            {external_product_id : ID do produto na plataforma}
                            {product_id : UUID do curso Getfy (area_membros)}';

    protected $description = 'Mapeia produto externo → curso Getfy para webhook de matrícula';

    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant_id');
        $platform = strtolower((string) $this->argument('platform'));
        $externalId = (string) $this->argument('external_product_id');
        $productId = (string) $this->argument('product_id');

        $product = Product::query()->find($productId);
        if (! $product || (int) $product->tenant_id !== $tenantId) {
            $this->error('Curso não encontrado ou não pertence ao tenant.');

            return self::FAILURE;
        }
        if ($product->type !== Product::TYPE_AREA_MEMBROS || $product->isMemberHub()) {
            $this->error('product_id deve ser um curso area_membros (não HUB).');

            return self::FAILURE;
        }

        EnrollmentExternalProductMapping::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'platform' => $platform,
                'external_product_id' => $externalId,
            ],
            ['product_id' => $productId]
        );

        $this->info("Mapeamento salvo: {$platform}/{$externalId} → {$product->name} ({$productId})");

        return self::SUCCESS;
    }
}

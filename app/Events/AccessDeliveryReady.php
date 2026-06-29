<?php

namespace App\Events;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AccessDeliveryReady
{
    use Dispatchable, SerializesModels;

    /**
     * Disparado após envio bem-sucedido do e-mail de acesso para um aluno específico.
     *
     * @param  array{type?:string, link?:string, email?:string, password?:string, product_type?:string}  $access
     * @param  array{source?:string, transaction_id?:string|null, platform?:string|null, sent_at?:string}  $context
     */
    public function __construct(
        public ?Order $order = null,
        public array $access = [],
        public ?User $user = null,
        public ?Product $product = null,
        public array $context = [],
    ) {}
}


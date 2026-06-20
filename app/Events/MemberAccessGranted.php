<?php

namespace App\Events;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MemberAccessGranted
{
    use Dispatchable, SerializesModels;

    /**
     * Disparado quando um aluno recebe acesso (matrícula webhook, cadastro manual, liberação de curso).
     *
     * @param  array{type?:string, link?:string, email?:string, password?:string, product_type?:string}  $access
     */
    public function __construct(
        public User $user,
        public Product $product,
        public array $access = [],
    ) {}
}

<?php

namespace App\Exceptions;

use App\Models\Order;
use Illuminate\Http\Request;
use RuntimeException;

class ExistingPixCheckoutRedirect extends RuntimeException
{
    public function __construct(
        public readonly Order $order,
        public readonly Request $request,
    ) {
        parent::__construct('Existing pending PIX checkout.');
    }
}

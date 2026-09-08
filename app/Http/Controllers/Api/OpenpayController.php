<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

class OpenpayController extends Controller
{
    public function config()
    {
        return response()->json([
            'merchant_id' => config('openpay.merchant_id'),
            'public_key' => config('openpay.public_key'),
            'sandbox' => config('openpay.sandbox'),
        ]);
    }
}
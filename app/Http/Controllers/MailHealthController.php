<?php

namespace App\Http\Controllers;

use App\Services\TransactionalMailHealthService;
use Illuminate\Http\JsonResponse;

class MailHealthController extends Controller
{
    public function show(TransactionalMailHealthService $service): JsonResponse
    {
        return response()->json($service->diagnostics());
    }
}

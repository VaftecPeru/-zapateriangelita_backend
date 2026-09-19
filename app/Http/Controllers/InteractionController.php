<?php

namespace App\Http\Controllers;

use App\Models\StoreInteraction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InteractionController extends Controller
{
    private const ALLOWED_TYPES = [
        'product_view',
        'whatsapp_click',
        'newsletter_subscribe',
        'add_to_cart',
        'checkout_start',
    ];

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:' . implode(',', self::ALLOWED_TYPES)],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'metadata' => ['nullable', 'array'],
        ]);

        StoreInteraction::create([
            'user_id' => $request->user()?->id,
            'product_id' => $validated['product_id'] ?? null,
            'type' => $validated['type'],
            'metadata' => $validated['metadata'] ?? null,
        ]);

        return response()->json(['success' => true], 201);
    }
}

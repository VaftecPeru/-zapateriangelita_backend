<?php

namespace App\Http\Controllers;

use App\Models\NewsletterSubscriber;
use App\Models\StoreInteraction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NewsletterController extends Controller
{
    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
        ]);

        $email = strtolower(trim($validated['email']));
        $subscriber = NewsletterSubscriber::firstOrNew(['email' => $email]);
        $alreadyActive = $subscriber->exists && $subscriber->status === 'active';

        $subscriber->status = 'active';
        $subscriber->subscribed_at = $subscriber->subscribed_at ?: now();
        $subscriber->unsubscribed_at = null;
        $subscriber->save();

        StoreInteraction::create([
            'user_id' => $request->user()?->id,
            'type' => 'newsletter_subscribe',
            'metadata' => ['email_domain' => substr(strrchr($email, '@') ?: '', 1)],
        ]);

        return response()->json([
            'success' => true,
            'already_subscribed' => $alreadyActive,
            'message' => $alreadyActive
                ? 'Este correo ya estaba suscrito.'
                : 'Suscripción registrada correctamente.',
        ], $alreadyActive ? 200 : 201);
    }
}

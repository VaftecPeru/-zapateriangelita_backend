<?php

namespace App\Http\Controllers;

use App\Models\NewsletterSubscriber;
use App\Models\StoreInteraction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class NewsletterController extends Controller
{
    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
        ]);

        $email = strtolower(trim($validated['email']));
        $alreadyActive = DB::transaction(function () use ($email) {
            $subscriber = NewsletterSubscriber::firstOrCreate(['email' => $email], [
                'status' => 'active',
                'subscribed_at' => now(),
                'welcome_due_at' => now(),
            ]);

            if ($subscriber->wasRecentlyCreated) {
                return false;
            }

            $subscriber = NewsletterSubscriber::whereKey($subscriber->id)->lockForUpdate()->firstOrFail();
            if ($subscriber->status === 'active') {
                return true;
            }

            $subscriber->forceFill([
                'status' => 'active',
                'subscribed_at' => now(),
                'unsubscribed_at' => null,
                'welcome_due_at' => now(),
            ])->save();

            return false;
        });

        if (!$alreadyActive) {
            try {
                StoreInteraction::create([
                    'user_id' => $request->user()?->id,
                    'type' => 'newsletter_subscribe',
                    'metadata' => ['email_domain' => substr(strrchr($email, '@') ?: '', 1)],
                ]);
            } catch (\Throwable $e) {
                // La analítica nunca debe impedir que una suscripción válida se guarde.
                Log::warning('NEWSLETTER_ANALYTICS_FAILED', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'already_subscribed' => $alreadyActive,
            'message' => $alreadyActive
                ? 'Este correo ya estaba suscrito.'
                : 'Suscripción registrada correctamente. Recibirás un correo de bienvenida.',
        ], $alreadyActive ? 200 : 201);
    }

    public function unsubscribe(Request $request, NewsletterSubscriber $subscriber): View
    {
        $unsubscribed = $request->isMethod('POST');
        if ($unsubscribed) {
            NewsletterSubscriber::whereKey($subscriber->id)->where('status', 'active')->update([
                'status' => 'unsubscribed',
                'unsubscribed_at' => now(),
                'welcome_due_at' => null,
            ]);
        }

        return view('newsletter.unsubscribe', compact('unsubscribed'));
    }
}

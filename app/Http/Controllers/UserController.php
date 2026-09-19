<?php

namespace App\Http\Controllers;

use App\Models\PasswordResetAudit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::orderBy('created_at', 'desc')->get();
        return response()->json($users);
    }

    public function show(string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        return response()->json($user);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        if ($user->role === 'admin' && auth()->id() !== $user->id) {
            return response()->json([
                'message' => 'No se permite restablecer la contraseña de otro administrador desde esta acción.',
            ], 403);
        }

        $validated = $request->validate([
            'send_email' => ['sometimes', 'boolean'],
        ]);

        $temporaryPassword = $this->generateTemporaryPassword();

        $user->forceFill([
            'password' => Hash::make($temporaryPassword),
            'must_change_password' => true,
            'temporary_password_set_at' => now(),
        ])->save();

        $user->tokens()->delete();

        PasswordResetAudit::create([
            'user_id' => $user->id,
            'admin_id' => auth()->id(),
            'action' => 'admin_temporary_password',
            'ip_address' => $request->ip(),
        ]);

        $emailSent = false;
        if (($validated['send_email'] ?? false) && $user->email) {
            try {
                Mail::raw(
                    "Hola {$user->name},\n\nSe restableció tu contraseña de Zapatería Angelita.\nContraseña temporal: {$temporaryPassword}\n\nAl iniciar sesión deberás crear una nueva contraseña personal.",
                    function ($message) use ($user) {
                        $message->to($user->email)
                            ->subject('Contraseña temporal - Zapatería Angelita');
                    }
                );
                $emailSent = true;
            } catch (\Throwable $e) {
                Log::warning('No se pudo enviar la contraseña temporal por correo.', [
                    'user_id' => $user->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Contraseña temporal generada correctamente.',
            'temporary_password' => $temporaryPassword,
            'email_sent' => $emailSent,
            'user' => $user->fresh(),
        ]);
    }

    public function changeTemporaryPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => [
                'required',
                'string',
                'min:8',
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
                'confirmed',
            ],
        ], [
            'password.regex' => 'La contraseña debe incluir mayúsculas, minúsculas y números.',
        ]);

        $user = $request->user();
        if (!$user->must_change_password) {
            return response()->json([
                'message' => 'La cuenta no tiene un cambio de contraseña pendiente.',
            ], 422);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
            'temporary_password_set_at' => null,
        ])->save();

        // La contraseña temporal deja de ser válida y revocamos las demás sesiones.
        $currentToken = $user->currentAccessToken();
        $user->tokens()->when($currentToken, fn ($query) => $query->where('id', '!=', $currentToken->id))->delete();

        PasswordResetAudit::create([
            'user_id' => $user->id,
            'admin_id' => null,
            'action' => 'user_changed_temporary_password',
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Contraseña actualizada correctamente.',
            'user' => $user->fresh(),
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        if (auth()->id() === $user->id) {
            return response()->json(['message' => 'No puedes eliminar tu propia cuenta.'], 403);
        }

        $user->delete();
        return response()->json(null, 204);
    }

    private function generateTemporaryPassword(): string
    {
        return Str::upper(Str::random(2))
            . Str::lower(Str::random(4))
            . random_int(1000, 9999)
            . '!';
    }
}

<?php

namespace App\Http\Controllers;

use App\Mail\PasswordChangedMail;
use App\Mail\TemporaryPasswordMail;
use App\Models\PasswordResetAudit;
use App\Models\User;
use App\Models\UserRoleAudit;
use App\Services\UserVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function index(Request $request, UserVisibilityService $visibility): JsonResponse
    {
        $actor = $request->user();
        return response()->json($visibility->listFor($actor));
    }

    public function show(Request $request, string $id, UserVisibilityService $visibility): JsonResponse
    {
        $actor = $request->user();
        $user = $visibility->findFor($actor, (int) $id);

        if (!$user) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        return response()->json($user);
    }

    public function resetPassword(Request $request, string $id): JsonResponse
    {
        $actor = $request->user();
        $user = User::find((int) $id);

        if (!$user) {
            return response()->json([
                'message' => 'El usuario ya no existe. Actualiza la lista e intenta nuevamente.',
            ], 404);
        }

        if ($user->role === 'superadmin' && $actor?->id !== $user->id) {
            return response()->json([
                'message' => 'No se permite restablecer la contraseña de otro superadministrador.',
            ], 403);
        }

        if ($user->role === 'admin' && $actor?->role !== 'superadmin' && $actor?->id !== $user->id) {
            return response()->json([
                'message' => 'Solo un superadministrador puede restablecer la contraseña de otro administrador.',
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
            'admin_id' => $request->user()?->id,
            'action' => 'admin_temporary_password',
            'ip_address' => $request->ip(),
        ]);

        $emailSent = false;
        if ($user->email) {
            try {
                Mail::to($user->email)->send(new TemporaryPasswordMail($user, $temporaryPassword));
                $emailSent = true;
            } catch (\Throwable $e) {
                Log::warning('TEMPORARY_PASSWORD_EMAIL_FAILED', [
                    'user_id' => $user->id,
                    'exception' => get_class($e),
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

        $this->sendPasswordChangedNotificationAfterResponse($user, 'temporary_password_changed');

        return response()->json([
            'success' => true,
            'message' => 'Contraseña actualizada correctamente.',
            'user' => $user->fresh(),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => [
                'required',
                'string',
                'min:8',
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
                'different:current_password',
                'confirmed',
            ],
        ], [
            'password.regex' => 'La contraseña debe incluir mayúsculas, minúsculas y números.',
            'password.different' => 'La nueva contraseña debe ser diferente de la contraseña actual.',
        ]);

        $user = $request->user();

        if ($user->must_change_password) {
            return response()->json([
                'message' => 'Primero debes reemplazar la contraseña temporal asignada a tu cuenta.',
                'error_code' => 'TEMPORARY_PASSWORD_CHANGE_REQUIRED',
            ], 422);
        }

        if (!Hash::check($validated['current_password'], (string) $user->password)) {
            return response()->json([
                'message' => 'La contraseña actual no es correcta.',
                'errors' => [
                    'current_password' => ['La contraseña actual no es correcta.'],
                ],
            ], 422);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'temporary_password_set_at' => null,
        ])->save();

        // Mantener la sesión actual y revocar cualquier otra sesión activa.
        $currentToken = $user->currentAccessToken();
        $user->tokens()
            ->when($currentToken, fn ($query) => $query->where('id', '!=', $currentToken->id))
            ->delete();

        PasswordResetAudit::create([
            'user_id' => $user->id,
            'admin_id' => null,
            'action' => 'user_changed_password',
            'ip_address' => $request->ip(),
        ]);

        $this->sendPasswordChangedNotificationAfterResponse($user, 'password_changed');

        return response()->json([
            'success' => true,
            'message' => 'Contraseña actualizada correctamente.',
            'user' => $user->fresh(),
        ]);
    }

    /**
     * Send the notification after the HTTP response so SMTP latency does not block password changes.
     */
    private function sendPasswordChangedNotificationAfterResponse(User $user, string $event): void
    {
        $userId = (int) $user->getKey();
        $email = (string) $user->email;

        app()->terminating(function () use ($user, $userId, $email, $event): void {
            if ($email === '') {
                return;
            }

            try {
                Mail::to($email)->send(new PasswordChangedMail($user, $event));
            } catch (\Throwable $e) {
                Log::warning('PASSWORD_CHANGED_EMAIL_FAILED', [
                    'user_id' => $userId,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
            }
        });
    }

    public function updateRole(Request $request, string $id): JsonResponse
    {
        $actor = $request->user();
        $user = User::find((int) $id);

        if (!$user) {
            return response()->json([
                'message' => 'El usuario ya no existe. Actualiza la lista e intenta nuevamente.',
            ], 404);
        }

        if (!$actor || $actor->role !== 'superadmin') {
            return response()->json([
                'message' => 'Solo el superadministrador puede gestionar roles de usuario.',
            ], 403);
        }

        if ($actor->id === $user->id) {
            return response()->json([
                'message' => 'No puedes modificar tu propio rol desde esta opción.',
            ], 422);
        }

        if ($user->role === 'superadmin') {
            return response()->json([
                'message' => 'La cuenta superadministradora no puede modificarse desde esta opción.',
            ], 422);
        }

        $validated = $request->validate([
            'role' => ['required', 'string', 'in:user,admin'],
        ]);

        $oldRole = $user->role ?: 'user';
        $newRole = $validated['role'];

        if ($oldRole === $newRole) {
            return response()->json([
                'success' => true,
                'message' => 'El usuario ya tiene el rol seleccionado.',
                'user' => $user,
            ]);
        }

        $user->forceFill(['role' => $newRole])->save();

        UserRoleAudit::create([
            'user_id' => $user->id,
            'actor_user_id' => $actor->id,
            'old_role' => $oldRole,
            'new_role' => $newRole,
            'ip_address' => $request->ip(),
        ]);

        // Revocar sesiones para que el cambio de privilegios sea efectivo de inmediato.
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => $newRole === 'admin'
                ? 'Rol de administrador asignado correctamente.'
                : 'Rol de usuario asignado correctamente.',
            'user' => $user->fresh(),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $actor = $request->user();
        $user = User::find((int) $id);

        // La eliminación es idempotente: una fila obsoleta del frontend no debe
        // exponer una excepción de Route Model Binding ni bloquear la intranet.
        if (!$user) {
            return response()->json([
                'success' => true,
                'message' => 'El usuario ya no existía. La lista puede sincronizarse.',
            ]);
        }

        if ($actor?->id === $user->id) {
            return response()->json(['message' => 'No puedes eliminar tu propia cuenta.'], 403);
        }

        if ($user->role === 'superadmin') {
            return response()->json([
                'message' => 'No se permite eliminar una cuenta superadministradora.',
            ], 403);
        }

        if ($user->role === 'admin' && $actor?->role !== 'superadmin') {
            return response()->json([
                'message' => 'Solo un superadministrador puede eliminar una cuenta administradora.',
            ], 403);
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

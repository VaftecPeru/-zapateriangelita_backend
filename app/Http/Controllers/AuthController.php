<?php

namespace App\Http\Controllers;

use App\Mail\PasswordChangedMail;
use App\Mail\WelcomeUserMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $normalizedEmail = strtolower(trim((string) $request->input('email', '')));
        $request->merge(['email' => $normalizedEmail]);

        if ($normalizedEmail !== '' && User::whereRaw('LOWER(TRIM(email)) = ?', [$normalizedEmail])->exists()) {
            return response()->json([
                'message' => 'Este correo electrónico ya está registrado. Inicia sesión o recupera tu contraseña.',
                'error_code' => 'EMAIL_ALREADY_REGISTERED',
                'errors' => [
                    'email' => ['Este correo electrónico ya está registrado.'],
                ],
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255', 'regex:/^[\pL]+(?:[\s\'-][\pL]+)*$/u'],
            'email' => 'required|string|email:rfc|max:255|unique:users,email|regex:/^[^@]+@[^@]+\.[^@]{2,}$/',
            'password' => 'required|string|min:6|regex:/[A-Z]/|regex:/[0-9]/|confirmed',
            'gender' => 'required|string',
            'birthdate' => 'required|date|before_or_equal:' . now()->subYears(18)->format('Y-m-d'),
            'phone' => ['required', 'regex:/^\\+?[0-9]{7,20}$/'],
        ], [
            'birthdate.before_or_equal' => 'Debes ser mayor de edad para registrarte.',
            'password.regex' => 'La contraseña debe tener mayúsculas y números.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $user = User::create([
                'name' => $request->name,
                'email' => $normalizedEmail,
                'password' => Hash::make($request->password),
                'gender' => $request->gender,
                'birthdate' => $request->birthdate,
                'phone' => $request->phone,
                'must_change_password' => false,
                'temporary_password_set_at' => null,
                'role' => 'user',
            ]);
        } catch (QueryException $e) {
            $sqlState = (string) ($e->errorInfo[0] ?? '');
            $driverCode = (int) ($e->errorInfo[1] ?? 0);

            if ($sqlState === '23000' || $driverCode === 1062) {
                return response()->json([
                    'message' => 'Este correo electrónico ya está registrado. Inicia sesión o recupera tu contraseña.',
                    'error_code' => 'EMAIL_ALREADY_REGISTERED',
                    'errors' => [
                        'email' => ['Este correo electrónico ya está registrado.'],
                    ],
                ], 409);
            }

            throw $e;
        }

        try {
            Mail::to($user->email)->send(new WelcomeUserMail($user));
        } catch (\Throwable $e) {
            Log::error('WELCOME_EMAIL_FAILED', [
                'user_id' => $user->id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()
            ->json([
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => $user,
            ])
            ->cookie(
                'sanctum_token',
                $token,
                60 * 24 * 7,
                '/',
                null,
                env('APP_ENV') === 'production',
                true,
                false,
                'lax'
            );
    }

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $normalizedEmail = strtolower(trim((string) $request->email));
        $user = User::whereRaw('LOWER(TRIM(email)) = ?', [$normalizedEmail])->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Credenciales incorrectas'
            ], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()
            ->json([
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => $user,
            ])
            ->cookie(
                'sanctum_token',
                $token,
                60 * 24 * 7,
                '/',
                null,
                env('APP_ENV') === 'production',
                true,
                false,
                'lax'
            );
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada correctamente'
        ])->withoutCookie('sanctum_token');
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email', ''))),
        ]);

        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Respuesta uniforme para no revelar si un correo está registrado.
        $existingUser = User::whereRaw('LOWER(TRIM(email)) = ?', [$request->email])->first();
        $emailForBroker = $existingUser?->email ?? $request->email;

        \Illuminate\Support\Facades\Password::broker()->sendResetLink([
            'email' => $emailForBroker,
        ]);

        return response()->json([
            'message' => 'Si el correo está registrado, recibirás instrucciones para restablecer tu contraseña.'
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email', ''))),
        ]);

        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6|confirmed',
            'token' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $existingUser = User::whereRaw('LOWER(TRIM(email)) = ?', [$request->email])->first();
        $credentials = $request->only('email', 'password', 'password_confirmation', 'token');
        if ($existingUser) {
            $credentials['email'] = $existingUser->email;
        }

        $status = \Illuminate\Support\Facades\Password::broker()->reset(
            $credentials,
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'must_change_password' => false,
                    'temporary_password_set_at' => null,
                ])->setRememberToken(\Illuminate\Support\Str::random(60));
                $user->save();
                $user->tokens()->delete();

                try {
                    Mail::to($user->email)->send(new PasswordChangedMail($user, 'password_reset'));
                } catch (\Throwable $e) {
                    Log::error('PASSWORD_CHANGED_EMAIL_FAILED', [
                        'user_id' => $user->id,
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        );

        return $status === \Illuminate\Support\Facades\Password::PASSWORD_RESET
            ? response()->json(['message' => __($status)])
            : response()->json(['message' => __($status)], 400);
    }
}
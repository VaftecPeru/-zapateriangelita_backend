<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use App\Mail\WelcomeUserMail;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email:rfc,dns|max:255|unique:users|regex:/^[^@]+@[^@]+\.[^@]{2,}$/',
            'password' => 'required|string|min:6|regex:/[A-Z]/|regex:/[0-9]/|confirmed',
            'gender' => 'required|string',
            'birthdate' => 'required|date|before_or_equal:' . now()->subYears(18)->format('Y-m-d'),
            'department' => 'required|string',
            'province' => 'required|string',
            'district' => 'required|string',
        ], [
            'birthdate.before_or_equal' => 'Debes ser mayor de edad para registrarte.',
            'password.regex' => 'La contraseña debe tener mayúsculas y números.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'gender' => $request->gender,
            'birthdate' => $request->birthdate,
            'department' => $request->department,
            'province' => $request->province,
            'district' => $request->district,
            'role' => 'user',
        ]);

        try {
            Mail::to($user->email)->send(new WelcomeUserMail($user, $request->password));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error sending welcome email to ' . $user->email . ': ' . $e->getMessage());
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

        $user = User::where('email', $request->email)->first();

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
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $status = \Illuminate\Support\Facades\Password::broker()->sendResetLink(
            $request->only('email')
        );

        return $status === \Illuminate\Support\Facades\Password::RESET_LINK_SENT
            ? response()->json(['message' => __($status)])
            : response()->json(['message' => __($status)], 400);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6|confirmed',
            'token' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $status = \Illuminate\Support\Facades\Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->setRememberToken(\Illuminate\Support\Str::random(60));
                $user->save();
            }
        );

        return $status === \Illuminate\Support\Facades\Password::PASSWORD_RESET
            ? response()->json(['message' => __($status)])
            : response()->json(['message' => __($status)], 400);
    }
}

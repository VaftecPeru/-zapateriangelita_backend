<?php

namespace App\Http\Controllers;

use App\Mail\WelcomeUserMail;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
    private const GOOGLE_CERTS_URL = 'https://www.googleapis.com/oauth2/v1/certs';
    private const CLOCK_SKEW_SECONDS = 60;

    public function config(): JsonResponse
    {
        $clientId = trim((string) config('services.google.client_id'));

        return response()->json([
            'enabled' => $clientId !== '',
            'client_id' => $clientId !== '' ? $clientId : null,
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'credential' => ['required', 'string', 'max:5000'],
        ]);

        if (!$this->isConfigured()) {
            return response()->json([
                'message' => 'El acceso con Google todavía no está configurado.',
                'error_code' => 'GOOGLE_NOT_CONFIGURED',
            ], 503);
        }

        try {
            $claims = $this->verifyCredential($validated['credential']);
        } catch (\Throwable $e) {
            Log::warning('GOOGLE_AUTH_INVALID_CREDENTIAL', [
                'reason' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No fue posible validar tu cuenta de Google. Intenta nuevamente.',
                'error_code' => 'GOOGLE_CREDENTIAL_INVALID',
            ], 401);
        }

        $googleSub = (string) $claims['sub'];
        $email = strtolower(trim((string) $claims['email']));
        $accountCreated = false;

        $user = User::where('google_sub', $googleSub)->first();

        if (!$user) {
            $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

            if (!$user) {
                $user = new User();
                $user->name = $this->googleDisplayName($claims, $email);
                $user->email = $email;
                $user->password = Str::random(64);
                $user->role = 'user';
                $user->google_sub = $googleSub;
                $user->google_email = $email;
                $user->google_linked_at = now();
                $user->email_verified_at = now();

                try {
                    $user->save();
                    $accountCreated = true;
                } catch (QueryException $e) {
                    // Protege contra dos intentos simultáneos de alta con el mismo correo/sub.
                    Log::warning('GOOGLE_QUICK_REGISTER_RACE', [
                        'email' => $email,
                        'sql_state' => $e->errorInfo[0] ?? null,
                    ]);

                    $user = User::where('google_sub', $googleSub)->first()
                        ?: User::whereRaw('LOWER(email) = ?', [$email])->first();

                    if (!$user) {
                        throw $e;
                    }
                }
            }

            if (!$accountCreated) {
                if ($user->google_sub && !hash_equals((string) $user->google_sub, $googleSub)) {
                    return response()->json([
                        'message' => 'Esta cuenta ya está vinculada con otra identidad de Google.',
                        'error_code' => 'GOOGLE_ACCOUNT_CONFLICT',
                    ], 409);
                }

                if (in_array(($user->role ?? 'user'), ['admin', 'superadmin'], true)) {
                    return response()->json([
                        'message' => 'Por seguridad, una cuenta administrativa debe vincular Google desde una sesión ya autenticada.',
                        'error_code' => 'GOOGLE_ADMIN_LINK_REQUIRED',
                    ], 409);
                }

                // Para cuentas existentes solo se auto-vincula cuando Google es autoridad del correo.
                // Así se evita apropiarse de una cuenta local preexistente mediante un correo externo.
                if (!$user->google_sub && !$this->isGoogleAuthoritativeForEmail($claims)) {
                    return response()->json([
                        'message' => 'Primero inicia sesión con tu contraseña y vincula Google desde tu cuenta.',
                        'error_code' => 'GOOGLE_EXISTING_LOGIN_REQUIRED',
                    ], 409);
                }

                $user->google_sub = $googleSub;
                $user->google_email = $email;
                $user->google_linked_at = $user->google_linked_at ?: now();
                $user->email_verified_at = $user->email_verified_at ?: now();
                $user->save();
            }
        } else {
            $user->google_email = $email;
            $user->google_linked_at = $user->google_linked_at ?: now();
            $user->email_verified_at = $user->email_verified_at ?: now();
            $user->save();
        }

        // La sesión se devuelve inmediatamente. El frontend dispara el correo
        // de bienvenida en una petición separada para que SMTP no bloquee el acceso.
        return $this->issueSession($user, $accountCreated);
    }

    public function sendWelcome(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (!$user || !$user->google_sub || !$user->google_linked_at) {
            return response()->json([
                'message' => 'La cuenta no está vinculada con Google.',
                'error_code' => 'GOOGLE_ACCOUNT_NOT_LINKED',
            ], 422);
        }

        try {
            Mail::to($user->email)->send(new WelcomeUserMail($user, 'google'));

            return response()->json([
                'success' => true,
                'message' => 'Correo de bienvenida enviado.',
            ]);
        } catch (\Throwable $e) {
            Log::error('GOOGLE_WELCOME_EMAIL_FAILED', [
                'user_id' => $user->id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'La cuenta fue creada correctamente, pero el correo de bienvenida no pudo enviarse en este momento.',
            ], 503);
        }
    }

    public function link(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'credential' => ['required', 'string', 'max:5000'],
        ]);

        if (!$this->isConfigured()) {
            return response()->json([
                'message' => 'El acceso con Google todavía no está configurado.',
                'error_code' => 'GOOGLE_NOT_CONFIGURED',
            ], 503);
        }

        /** @var User $user */
        $user = $request->user();

        try {
            $claims = $this->verifyCredential($validated['credential']);
        } catch (\Throwable $e) {
            Log::warning('GOOGLE_LINK_INVALID_CREDENTIAL', [
                'user_id' => $user?->id,
                'reason' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No fue posible validar tu cuenta de Google. Intenta nuevamente.',
                'error_code' => 'GOOGLE_CREDENTIAL_INVALID',
            ], 401);
        }

        $googleSub = (string) $claims['sub'];
        $googleEmail = strtolower(trim((string) $claims['email']));
        $accountEmail = strtolower(trim((string) $user->email));

        if (!hash_equals($accountEmail, $googleEmail)) {
            return response()->json([
                'message' => 'La cuenta de Google debe usar el mismo correo de tu cuenta de Zapatería Angelita.',
                'error_code' => 'GOOGLE_EMAIL_MISMATCH',
            ], 422);
        }

        $alreadyAssigned = User::where('google_sub', $googleSub)
            ->where('id', '!=', $user->id)
            ->exists();

        if ($alreadyAssigned) {
            return response()->json([
                'message' => 'Esta cuenta de Google ya está vinculada con otro usuario.',
                'error_code' => 'GOOGLE_ACCOUNT_ALREADY_LINKED',
            ], 409);
        }

        if ($user->google_sub && !hash_equals((string) $user->google_sub, $googleSub)) {
            return response()->json([
                'message' => 'Tu cuenta ya está vinculada con otra identidad de Google.',
                'error_code' => 'GOOGLE_ACCOUNT_CONFLICT',
            ], 409);
        }

        $user->google_sub = $googleSub;
        $user->google_email = $googleEmail;
        $user->google_linked_at = now();
        $user->email_verified_at = $user->email_verified_at ?: now();
        $user->save();

        return response()->json([
            'message' => 'Cuenta de Google vinculada correctamente.',
            'google_linked' => true,
            'user' => $user->fresh(),
        ]);
    }

    private function issueSession(User $user, bool $accountCreated = false): JsonResponse
    {
        $token = $user->createToken('google_login')->plainTextToken;

        return response()
            ->json([
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => $user->fresh(),
                'google_linked' => true,
                'account_created' => $accountCreated,
                'registration_method' => $accountCreated ? 'google' : null,
                'welcome_email_required' => $accountCreated,
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

    private function googleDisplayName(array $claims, string $email): string
    {
        $name = trim((string) ($claims['name'] ?? ''));
        if ($name !== '') {
            return Str::limit($name, 255, '');
        }

        $localPart = Str::before($email, '@');
        $localPart = preg_replace('/[._-]+/', ' ', $localPart) ?: 'Cliente';

        return Str::limit(Str::title(trim($localPart)), 255, '');
    }

    private function isConfigured(): bool
    {
        return trim((string) config('services.google.client_id')) !== '';
    }

    private function verifyCredential(string $credential): array
    {
        $parts = explode('.', $credential);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Malformed Google ID token.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = json_decode($this->base64UrlDecode($encodedHeader), true);
        $payload = json_decode($this->base64UrlDecode($encodedPayload), true);
        $signature = $this->base64UrlDecode($encodedSignature, true);

        if (!is_array($header) || !is_array($payload) || $signature === '') {
            throw new \RuntimeException('Invalid Google ID token structure.');
        }

        if (($header['alg'] ?? null) !== 'RS256' || empty($header['kid'])) {
            throw new \RuntimeException('Unsupported Google ID token algorithm.');
        }

        $kid = (string) $header['kid'];
        $certificates = $this->googleCertificates();
        $certificate = $certificates[$kid] ?? null;

        if (!$certificate) {
            $certificates = $this->googleCertificates(true);
            $certificate = $certificates[$kid] ?? null;
        }

        if (!is_string($certificate) || $certificate === '') {
            throw new \RuntimeException('Google signing certificate not found.');
        }

        $verified = openssl_verify(
            $encodedHeader . '.' . $encodedPayload,
            $signature,
            $certificate,
            OPENSSL_ALGO_SHA256
        );

        if ($verified !== 1) {
            throw new \RuntimeException('Invalid Google ID token signature.');
        }

        $clientId = trim((string) config('services.google.client_id'));
        $audience = $payload['aud'] ?? null;
        $audienceValid = is_array($audience)
            ? in_array($clientId, $audience, true)
            : is_string($audience) && hash_equals($clientId, $audience);

        if (!$audienceValid) {
            throw new \RuntimeException('Google token audience mismatch.');
        }

        $issuer = (string) ($payload['iss'] ?? '');
        if (!in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)) {
            throw new \RuntimeException('Google token issuer mismatch.');
        }

        $now = time();
        $expiresAt = (int) ($payload['exp'] ?? 0);
        $issuedAt = (int) ($payload['iat'] ?? 0);
        $notBefore = isset($payload['nbf']) ? (int) $payload['nbf'] : null;

        if ($expiresAt <= ($now - self::CLOCK_SKEW_SECONDS)) {
            throw new \RuntimeException('Google token expired.');
        }

        if ($issuedAt > ($now + self::CLOCK_SKEW_SECONDS)) {
            throw new \RuntimeException('Google token issued in the future.');
        }

        if ($notBefore !== null && $notBefore > ($now + self::CLOCK_SKEW_SECONDS)) {
            throw new \RuntimeException('Google token is not active yet.');
        }

        $sub = trim((string) ($payload['sub'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $emailVerified = filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($sub === '' || strlen($sub) > 255) {
            throw new \RuntimeException('Google subject is missing.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !$emailVerified) {
            throw new \RuntimeException('Google email is not verified.');
        }

        return $payload;
    }

    private function googleCertificates(bool $forceRefresh = false): array
    {
        $cacheKey = 'google_identity_x509_certs_v1';

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addMinutes(50), function () {
            $response = Http::connectTimeout(3)
                ->timeout(5)
                ->retry(1, 150)
                ->acceptJson()
                ->get(self::GOOGLE_CERTS_URL);

            if (!$response->successful()) {
                throw new \RuntimeException('Unable to download Google signing certificates.');
            }

            $certificates = $response->json();
            if (!is_array($certificates) || $certificates === []) {
                throw new \RuntimeException('Google signing certificates are empty.');
            }

            return $certificates;
        });
    }

    private function isGoogleAuthoritativeForEmail(array $claims): bool
    {
        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        $emailVerified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!$emailVerified) {
            return false;
        }

        return str_ends_with($email, '@gmail.com') || !empty($claims['hd']);
    }

    private function base64UrlDecode(string $value, bool $binary = false): string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \RuntimeException('Invalid base64url payload.');
        }

        return $decoded;
    }
}

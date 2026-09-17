<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SettingController extends Controller
{
    private const PUBLIC_KEYS = [
        'whatsapp_number',
        'company_name',
        'logo_url',
        'contact_email',
        'reservation_policies',
        'privacy_policy',
        'hero_banners',
    ];

    public function index(): JsonResponse
    {
        $settings = Setting::whereIn('key', self::PUBLIC_KEYS)
            ->get()
            ->pluck('value', 'key')
            ->all();

        return response()->json([
            'success' => true,
            'data' => (object) $settings,
        ]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        if (auth('sanctum')->user()?->role !== 'admin') {
            return response()->json(['message' => 'No tienes permisos para cambiar la configuración'], 403);
        }

        if (!in_array($key, self::PUBLIC_KEYS, true)) {
            return response()->json(['message' => 'La configuración solicitada no está permitida.'], 422);
        }

        $request->validate([
            'value' => ['required'],
        ]);

        $value = (string) $request->input('value');

        if ($key === 'hero_banners') {
            $validationResponse = $this->validateHeroBanners($value);
            if ($validationResponse !== null) {
                return $validationResponse;
            }
        }

        $setting = Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );

        return response()->json([
            'success' => true,
            'message' => 'Configuración actualizada',
            'data' => $setting,
        ]);
    }

    public function uploadBannerImage(Request $request): JsonResponse
    {
        if (auth('sanctum')->user()?->role !== 'admin') {
            return response()->json(['message' => 'No tienes permisos para cargar imágenes.'], 403);
        }

        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:6144'],
        ]);

        $path = $this->storeBannerImage($validated['image']);

        return response()->json([
            'success' => true,
            'message' => 'Imagen del banner cargada correctamente.',
            'data' => [
                'path' => $path,
            ],
        ], 201);
    }

    private function validateHeroBanners(string $value): ?JsonResponse
    {
        $banners = json_decode($value, true);

        if (!is_array($banners) || json_last_error() !== JSON_ERROR_NONE) {
            return response()->json(['message' => 'El contenido de banners debe ser un JSON válido.'], 422);
        }

        if (count($banners) < 1 || count($banners) > 12) {
            return response()->json(['message' => 'Debes configurar entre 1 y 12 banners.'], 422);
        }

        $hasActiveBanner = false;

        foreach ($banners as $index => $banner) {
            if (!is_array($banner)) {
                return response()->json(['message' => 'Cada banner debe contener una configuración válida.'], 422);
            }

            $title = trim((string) ($banner['title'] ?? ''));
            $eyebrow = trim((string) ($banner['eyebrow'] ?? ''));
            $description = trim((string) ($banner['description'] ?? ''));
            $textColor = (string) ($banner['textColor'] ?? '#ffffff');
            $image = trim((string) ($banner['image'] ?? ''));
            $active = ($banner['active'] ?? true) !== false;

            if ($title === '' || mb_strlen($title) > 95) {
                return response()->json(['message' => 'El título del banner ' . ($index + 1) . ' es obligatorio y debe tener máximo 95 caracteres.'], 422);
            }

            if (mb_strlen($eyebrow) > 45 || mb_strlen($description) > 150) {
                return response()->json(['message' => 'El texto del banner ' . ($index + 1) . ' supera la longitud permitida.'], 422);
            }

            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $textColor)) {
                return response()->json(['message' => 'El color de texto del banner ' . ($index + 1) . ' no es válido.'], 422);
            }

            $isSafeLocalPath = str_starts_with($image, '/') && !str_contains($image, '..');
            if ($image !== '' && !$isSafeLocalPath && !filter_var($image, FILTER_VALIDATE_URL)) {
                return response()->json(['message' => 'La imagen del banner ' . ($index + 1) . ' debe ser una URL o una ruta local válida.'], 422);
            }

            if ($active) {
                $hasActiveBanner = true;
            }
        }

        if (!$hasActiveBanner) {
            return response()->json(['message' => 'Debe existir al menos un banner activo.'], 422);
        }

        return null;
    }

    private function storeBannerImage($file): string
    {
        $uploadDirectory = public_path('uploads/hero-banners');
        if (!is_dir($uploadDirectory)) {
            mkdir($uploadDirectory, 0755, true);
        }

        $mime = (string) $file->getMimeType();
        $sourcePath = $file->getPathname();
        $image = null;

        if (in_array($mime, ['image/jpeg', 'image/jpg'], true) && function_exists('imagecreatefromjpeg')) {
            $image = @imagecreatefromjpeg($sourcePath);
        } elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
            $image = @imagecreatefrompng($sourcePath);
        } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $image = @imagecreatefromwebp($sourcePath);
        }

        $baseName = 'hero_' . Str::uuid()->toString();

        if (!$image || !function_exists('imagewebp')) {
            $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            $filename = $baseName . '.' . $extension;
            $file->move($uploadDirectory, $filename);
            return '/uploads/hero-banners/' . $filename;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $maxWidth = 1920;

        if ($width > $maxWidth) {
            $newWidth = $maxWidth;
            $newHeight = (int) round(($height / $width) * $newWidth);
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        $filename = $baseName . '.webp';
        imagewebp($image, $uploadDirectory . '/' . $filename, 82);
        imagedestroy($image);

        return '/uploads/hero-banners/' . $filename;
    }
}

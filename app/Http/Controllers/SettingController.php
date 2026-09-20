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
        'homepage_banners',
    ];

    private const EDITABLE_KEYS = [
        'whatsapp_number',
        'company_name',
        'logo_url',
        'contact_email',
        'reservation_policies',
        'privacy_policy',
        'homepage_banners',
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
        $role = auth('sanctum')->user()?->role;
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            return response()->json([
                'message' => 'No tienes permisos para cambiar la configuración',
            ], 403);
        }

        if ($key === 'homepage_banner_image') {
            return $this->uploadBannerImage($request);
        }

        if ($key === 'site_logo_image') {
            return $this->uploadLogoImage($request);
        }

        if (!in_array($key, self::EDITABLE_KEYS, true)) {
            return response()->json([
                'message' => 'La configuración solicitada no está permitida.',
            ], 422);
        }

        $rules = ['value' => ['required']];

        if ($key === 'whatsapp_number') {
            $rules['value'] = ['required', 'string', 'regex:/^\\+?[0-9]{8,20}$/'];
        } elseif ($key === 'contact_email') {
            $rules['value'] = ['required', 'email:rfc', 'max:255'];
        } elseif ($key === 'logo_url') {
            $rules['value'] = ['required', 'string', 'max:2048'];
        }

        $validated = $request->validate($rules);

        if ($key === 'homepage_banners') {
            $this->validateHomepageBanners((string) $validated['value']);
        }

        $setting = Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $validated['value']],
        );

        return response()->json([
            'success' => true,
            'message' => 'Configuración actualizada',
            'data' => $setting,
        ]);
    }

    private function validateHomepageBanners(string $value): void
    {
        $decoded = json_decode($value, true);

        if (!is_array($decoded)) {
            abort(422, 'El formato de banners no es válido.');
        }

        if (count($decoded) > 12) {
            abort(422, 'Solo se permiten hasta 12 banners en la portada.');
        }

        foreach ($decoded as $index => $banner) {
            if (!is_array($banner)) {
                abort(422, 'Banner inválido en la posición ' . ($index + 1) . '.');
            }

            $title = trim((string) ($banner['title'] ?? ''));
            $image = trim((string) ($banner['image'] ?? ''));
            $textColor = trim((string) ($banner['textColor'] ?? '#ffffff'));

            if ($title === '' || mb_strlen($title) > 120) {
                abort(422, 'Cada banner debe tener un título de hasta 120 caracteres.');
            }

            if ($image === '' || mb_strlen($image) > 2048) {
                abort(422, 'Cada banner debe tener una imagen válida.');
            }

            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $textColor)) {
                abort(422, 'El color de texto del banner debe usar formato hexadecimal.');
            }
        }
    }

    private function uploadLogoImage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $file = $validated['image'];
        $uploadPath = public_path('uploads/settings');

        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }

        $mime = $file->getMimeType();
        $sourcePath = $file->getPathname();
        $filename = 'logo_' . Str::lower(Str::random(14)) . '.webp';
        $finalPath = $uploadPath . DIRECTORY_SEPARATOR . $filename;
        $image = null;

        if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
            $image = @imagecreatefromjpeg($sourcePath);
        } elseif ($mime === 'image/png') {
            $image = @imagecreatefrompng($sourcePath);
        } elseif ($mime === 'image/webp') {
            $image = @imagecreatefromwebp($sourcePath);
        }

        if ($image && function_exists('imagewebp')) {
            $width = imagesx($image);
            $height = imagesy($image);
            $maxWidth = 900;

            if ($width > $maxWidth) {
                $newWidth = $maxWidth;
                $newHeight = (int) round(($height / $width) * $newWidth);
                $resized = imagecreatetruecolor($newWidth, $newHeight);
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                imagecopyresampled(
                    $resized,
                    $image,
                    0,
                    0,
                    0,
                    0,
                    $newWidth,
                    $newHeight,
                    $width,
                    $height,
                );
                imagedestroy($image);
                $image = $resized;
            }

            imagewebp($image, $finalPath, 88);
            imagedestroy($image);
        } else {
            if ($image) {
                imagedestroy($image);
            }

            $extension = strtolower($file->getClientOriginalExtension() ?: 'png');
            $filename = 'logo_' . Str::lower(Str::random(14)) . '.' . $extension;
            $file->move($uploadPath, $filename);
        }

        $path = '/uploads/settings/' . $filename;
        Setting::updateOrCreate(['key' => 'logo_url'], ['value' => $path]);

        return response()->json([
            'success' => true,
            'message' => 'Logo actualizado correctamente.',
            'data' => [
                'path' => $path,
            ],
        ]);
    }

    private function uploadBannerImage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $file = $validated['image'];
        $uploadPath = public_path('uploads/banners');

        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }

        $mime = $file->getMimeType();
        $sourcePath = $file->getPathname();
        $filename = 'banner_' . Str::lower(Str::random(14)) . '.webp';
        $finalPath = $uploadPath . DIRECTORY_SEPARATOR . $filename;
        $image = null;

        if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
            $image = @imagecreatefromjpeg($sourcePath);
        } elseif ($mime === 'image/png') {
            $image = @imagecreatefrompng($sourcePath);
        } elseif ($mime === 'image/webp') {
            $image = @imagecreatefromwebp($sourcePath);
        }

        if ($image && function_exists('imagewebp')) {
            $width = imagesx($image);
            $height = imagesy($image);
            $maxWidth = 1920;

            if ($width > $maxWidth) {
                $newWidth = $maxWidth;
                $newHeight = (int) round(($height / $width) * $newWidth);
                $resized = imagecreatetruecolor($newWidth, $newHeight);
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                imagecopyresampled(
                    $resized,
                    $image,
                    0,
                    0,
                    0,
                    0,
                    $newWidth,
                    $newHeight,
                    $width,
                    $height,
                );
                imagedestroy($image);
                $image = $resized;
            }

            imagewebp($image, $finalPath, 84);
            imagedestroy($image);
        } else {
            if ($image) {
                imagedestroy($image);
            }

            $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            $filename = 'banner_' . Str::lower(Str::random(14)) . '.' . $extension;
            $file->move($uploadPath, $filename);
        }

        return response()->json([
            'success' => true,
            'message' => 'Imagen del banner cargada correctamente.',
            'data' => [
                'path' => '/uploads/banners/' . $filename,
            ],
        ]);
    }
}

<?php

namespace App\Services;

use App\Models\User;

class AccessTokenService
{
    public function issue(User $user, string $name, int $maxActiveTokens = 10): string
    {
        $newToken = $user->createToken($name);
        $keep = max(1, min(50, $maxActiveTokens));

        $idsToDelete = $user->tokens()
            ->orderByDesc('id')
            ->pluck('id')
            ->slice($keep)
            ->values()
            ->all();

        if ($idsToDelete) {
            $user->tokens()->whereIn('id', $idsToDelete)->delete();
        }

        return $newToken->plainTextToken;
    }
}

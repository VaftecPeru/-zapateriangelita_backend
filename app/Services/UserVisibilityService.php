<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;

class UserVisibilityService
{
    public function listFor(User $actor): Collection
    {
        if ($actor->role === 'superadmin') {
            return User::orderBy('created_at', 'desc')->get();
        }

        // Un administrador operativo solo puede listar su propia cuenta y
        // usuarios finales. Otros administradores y superadministradores no
        // forman parte de su ámbito visible.
        return User::query()
            ->where(function ($query) use ($actor) {
                $query
                    ->where('id', $actor->id)
                    ->orWhere('role', 'user');
            })
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (User $user) => $this->sanitizeFor($actor, $user));
    }

    public function findFor(User $actor, int $userId): ?User
    {
        if ($actor->role === 'superadmin') {
            return User::find($userId);
        }

        $user = User::query()
            ->where('id', $userId)
            ->where(function ($query) use ($actor) {
                $query
                    ->where('id', $actor->id)
                    ->orWhere('role', 'user');
            })
            ->first();

        return $user ? $this->sanitizeFor($actor, $user) : null;
    }

    public function sanitizeFor(User $actor, User $user): User
    {
        if ($actor->role === 'superadmin' || $actor->id === $user->id) {
            return $user;
        }

        // El administrador no recibe el rol de terceros; la regla se aplica
        // en backend para que no pueda recuperarlo inspeccionando la API.
        return $user->makeHidden(['role']);
    }
}

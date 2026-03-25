<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     */
    public function register(): void
    {
        //
    }

    /**
     
     */
    public function boot(): void
    {
        \Laravel\Sanctum\Sanctum::getAccessTokenFromRequestUsing(function ($request) {
            return $request->cookie('sanctum_token') ?: $request->bearerToken();
        });
    }
}

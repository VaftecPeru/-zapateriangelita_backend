<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;


Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


/*
|--------------------------------------------------------------------------
| Reintentos de correo transaccional
|--------------------------------------------------------------------------
| En cPanel configure una sola tarea cron que ejecute "php artisan schedule:run"
| cada minuto. Laravel dispara estos dos procesadores sin solaparlos.
*/
Schedule::command('checkout:process-notifications --limit=25')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('welcome:process-notifications --limit=25')
    ->everyMinute()
    ->withoutOverlapping();

<?php

use App\Http\Controllers\NewsletterController;
use Illuminate\Support\Facades\Route;


Route::get('/', function () {
    return view('welcome');
});

Route::match(['GET', 'POST'], '/newsletter/unsubscribe/{subscriber}', [NewsletterController::class, 'unsubscribe'])
    ->middleware('signed')->name('newsletter.unsubscribe');

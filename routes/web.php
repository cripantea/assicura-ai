<?php

use App\Http\Controllers\AiController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect(route('filament.main.auth.login'));
});

Route::post('/ai/generate-email', [AiController::class, 'generate'])
    ->name('ai.generate');

Route::post('/ai/send-email', [AiController::class, 'send'])
    ->name('ai.send');


Route::get('/_xdebug', function () {
    if (function_exists('xdebug_break')) xdebug_break();
    return 'ok';
});


Route::get('/_stop', function () {
    if (function_exists('xdebug_break')) { xdebug_break(); }
    return 'should break';
});

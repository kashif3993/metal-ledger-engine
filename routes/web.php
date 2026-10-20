<?php

use App\Http\Controllers\DemoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('demo.index');
});

Route::get('/demo', [DemoController::class, 'index'])->name('demo.index');
Route::post('/demo/{scenario}', [DemoController::class, 'run'])->name('demo.run');

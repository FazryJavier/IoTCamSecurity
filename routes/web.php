<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceEventController;
use App\Http\Controllers\LoginController;
use Illuminate\Support\Facades\Route;

Route::get('/', [LoginController::class, 'index'])->name('login')->middleware('guest');
Route::post('/', [LoginController::class, 'authenticate'])->middleware(['guest', 'throttle:5,1']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

Route::middleware('auth')->group(function () {
    Route::get('/home', [DashboardController::class, 'index'])->name('home');

    // Security event log (JSON, same response envelope as the API)
    Route::get('/events', [DeviceEventController::class, 'index'])->name('events.index');
    Route::post('/events', [DeviceEventController::class, 'store'])->name('events.store')->middleware('throttle:60,1');
    Route::get('/events/{event}/snapshot', [DeviceEventController::class, 'snapshot'])->name('events.snapshot');
});

<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordController;
use Illuminate\Support\Facades\Route;

// No public registration, e-mail verification or self-service reset:
// FINOX is internal and administrators create users and reset passwords.
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::get('change-password', [PasswordController::class, 'showChange'])->name('password.change');
    Route::post('change-password', [PasswordController::class, 'change'])->name('password.change.update');
    Route::put('password', [PasswordController::class, 'update'])->name('password.update');
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

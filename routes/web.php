<?php

use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ScreenshotController;
use App\Http\Controllers\TestController;
use App\Http\Controllers\TestRunController;
use App\Http\Controllers\TestSuiteController;
use App\Http\Controllers\TestSuiteMemberController;
use App\Http\Controllers\TestSuiteScheduleController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Public: login and password reset only
Route::prefix('sorify')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.store');

    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->name('register.store');

    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');
});

// Public: CI webhooks, guarded by a per-suite token instead of session auth
Route::prefix('sorify/webhooks/{token}')->middleware('sorify.webhook.auth')->name('webhooks.')->group(function () {
    Route::post('/trigger', [WebhookController::class, 'trigger'])->name('trigger');
    Route::get('/runs/{run}/status', [WebhookController::class, 'status'])->name('status');
});

// Authenticated: all app routes
Route::prefix('sorify')->middleware('auth')->group(function () {

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::prefix('suites')->name('suites.')->group(function () {
        Route::get('/', [TestSuiteController::class, 'index'])->name('index');
        Route::post('/', [TestSuiteController::class, 'store'])->name('store');
        Route::get('/{suite}', [TestSuiteController::class, 'show'])->name('show');
        Route::put('/{suite}', [TestSuiteController::class, 'update'])->name('update');
        Route::delete('/{suite}', [TestSuiteController::class, 'destroy'])->name('destroy');

        Route::scopeBindings()->prefix('/{suite}/tests')->name('tests.')->group(function () {
            Route::post('/', [TestController::class, 'store'])->name('store');
            Route::delete('/bulk', [TestController::class, 'bulkDestroy'])->name('bulk-destroy');
            Route::get('/{test}', [TestController::class, 'show'])->name('show');
            Route::put('/{test}', [TestController::class, 'update'])->name('update');
            Route::delete('/{test}', [TestController::class, 'destroy'])->name('destroy');
            Route::put('/{test}/code', [TestController::class, 'updateCode'])->name('update-code');
            Route::post('/{test}/code-versions/{codeVersion}/restore', [TestController::class, 'restoreCodeVersion'])->name('code-versions.restore');
            Route::patch('/{test}/toggle-status', [TestController::class, 'toggleStatus'])->name('toggle-status');
        });

        Route::post('/{suite}/runs', [TestRunController::class, 'store'])->name('runs.store');
        Route::post('/{suite}/webhook/regenerate', [TestSuiteController::class, 'regenerateWebhook'])->name('webhook.regenerate');
        Route::put('/{suite}/schedule', [TestSuiteScheduleController::class, 'update'])->name('schedule.update');
        Route::delete('/{suite}/schedule', [TestSuiteScheduleController::class, 'destroy'])->name('schedule.destroy');

        Route::prefix('/{suite}/users')->name('users.')->group(function () {
            Route::post('/', [TestSuiteMemberController::class, 'store'])->name('store');
            Route::put('/{user}', [TestSuiteMemberController::class, 'update'])->name('update');
            Route::delete('/{user}', [TestSuiteMemberController::class, 'destroy'])->name('destroy');
        });
    });

    Route::prefix('runs')->name('runs.')->group(function () {
        Route::get('/{run}', [TestRunController::class, 'show'])->name('show');
        Route::get('/{run}/status', [TestRunController::class, 'status'])->name('status');
        Route::post('/{run}/cancel', [TestRunController::class, 'cancel'])->name('cancel');
        Route::delete('/{run}', [TestRunController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('results')->name('results.')->group(function () {
        Route::get('/{result}/screenshots', [ScreenshotController::class, 'index'])->name('screenshots');
    });

    Route::get('/screenshots/{screenshot}', [ScreenshotController::class, 'show'])->name('screenshots.show');

    Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
    Route::put('/profile', [ProfileController::class, 'updateName'])->name('profile.update-name');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.update-password');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});

// Admin only
Route::prefix('sorify/admin')->middleware(['auth', 'admin'])->name('admin.')->group(function () {
    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
    Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
    Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');
    Route::post('/users/{user}/reset-password', [AdminUserController::class, 'resetPassword'])->name('users.reset-password');
});

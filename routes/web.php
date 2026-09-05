<?php

use App\Http\Controllers\Admin\GithubAppController as AdminGithubAppController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardNoteController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ScreenshotController;
use App\Http\Controllers\SuiteBookmarkController;
use App\Http\Controllers\TestController;
use App\Http\Controllers\TestRunController;
use App\Http\Controllers\TestSuiteController;
use App\Http\Controllers\TestSuiteIntegrationController;
use App\Http\Controllers\TestSuiteMemberController;
use App\Http\Controllers\TestSuiteScheduleController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Public: MCP discovery. The app is only reverse-proxied under /sorify, so the
// well-known file also needs to be reachable at /sorify/.well-known/mcp.json.
Route::get('sorify/.well-known/mcp.json', fn () => response()->file(public_path('.well-known/mcp.json')));

// Public: built Vite assets. In production the reverse proxy aliases /sorify
// straight to public/, so /sorify/build/* resolves without touching Laravel.
// `php artisan serve` has no such alias, so serve the built assets directly.
// Content-Type is forced by extension: finfo content-sniffing misidentifies
// minified JS/CSS (e.g. as text/x-java), which browsers then refuse to load.
Route::get('sorify/build/{path}', function (string $path) {
    $mimeTypes = [
        'js' => 'text/javascript',
        'mjs' => 'text/javascript',
        'css' => 'text/css',
        'json' => 'application/json',
        'map' => 'application/json',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
    ];

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    return response()->file(public_path('build/'.$path), [
        'Content-Type' => $mimeTypes[$extension] ?? 'application/octet-stream',
    ]);
})->where('path', '.*');

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

    Route::get('/auth/github/redirect', [AuthController::class, 'redirectToGithub'])->name('github.redirect');
    Route::get('/auth/github/callback', [AuthController::class, 'handleGithubCallback'])->name('github.callback');
});

// Public: CI webhooks, guarded by a per-suite token instead of session auth
Route::prefix('sorify/webhooks/{token}')->middleware('sorify.webhook.auth')->name('webhooks.')->group(function () {
    Route::post('/trigger', [WebhookController::class, 'trigger'])->name('trigger');
    Route::get('/runs/{run}/status', [WebhookController::class, 'status'])->name('status');
});

// Authenticated: all app routes
Route::prefix('sorify')->middleware('auth')->group(function () {

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::put('/dashboard-note', [DashboardNoteController::class, 'update'])->middleware('admin')->name('dashboard.note.update');

    Route::prefix('suites')->name('suites.')->group(function () {
        Route::get('/', [TestSuiteController::class, 'index'])->name('index');
        Route::post('/', [TestSuiteController::class, 'store'])->name('store');
        Route::get('/{suite}/review', [TestSuiteController::class, 'review'])->name('review');
        Route::get('/{suite}', [TestSuiteController::class, 'show'])->name('show');
        Route::put('/{suite}', [TestSuiteController::class, 'update'])->name('update');
        Route::delete('/{suite}', [TestSuiteController::class, 'destroy'])->name('destroy');
        Route::post('/{suite}/duplicate', [TestSuiteController::class, 'duplicate'])->name('duplicate');

        Route::scopeBindings()->prefix('/{suite}/tests')->name('tests.')->group(function () {
            Route::post('/', [TestController::class, 'store'])->name('store');
            Route::delete('/bulk', [TestController::class, 'bulkDestroy'])->name('bulk-destroy');
            Route::patch('/bulk/status', [TestController::class, 'bulkUpdateStatus'])->name('bulk-status');
            Route::post('/bulk/duplicate', [TestController::class, 'bulkDuplicate'])->name('bulk-duplicate');
            Route::get('/{test}', [TestController::class, 'show'])->name('show');
            Route::put('/{test}', [TestController::class, 'update'])->name('update');
            Route::delete('/{test}', [TestController::class, 'destroy'])->name('destroy');
            Route::put('/{test}/code', [TestController::class, 'updateCode'])->name('update-code');
            Route::post('/{test}/code-versions/{codeVersion}/restore', [TestController::class, 'restoreCodeVersion'])->name('code-versions.restore');
            Route::patch('/{test}/toggle-status', [TestController::class, 'toggleStatus'])->name('toggle-status');
            Route::post('/{test}/duplicate', [TestController::class, 'duplicate'])->name('duplicate');
        });

        Route::post('/{suite}/runs', [TestRunController::class, 'store'])->name('runs.store');
        Route::post('/{suite}/webhook/regenerate', [TestSuiteController::class, 'regenerateWebhook'])->name('webhook.regenerate');
        Route::delete('/{suite}/webhook/{token}', [TestSuiteController::class, 'destroyWebhook'])->name('webhook.destroy');
        Route::put('/{suite}/schedule', [TestSuiteScheduleController::class, 'update'])->name('schedule.update');
        Route::delete('/{suite}/schedule', [TestSuiteScheduleController::class, 'destroy'])->name('schedule.destroy');

        Route::scopeBindings()->prefix('/{suite}/integrations')->name('integrations.')->group(function () {
            Route::post('/', [TestSuiteIntegrationController::class, 'store'])->name('store');
            Route::put('/{integration}', [TestSuiteIntegrationController::class, 'update'])->name('update');
            Route::delete('/{integration}', [TestSuiteIntegrationController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('/{suite}/users')->name('users.')->group(function () {
            Route::post('/', [TestSuiteMemberController::class, 'store'])->name('store');
            Route::put('/{user}', [TestSuiteMemberController::class, 'update'])->name('update');
            Route::delete('/{user}', [TestSuiteMemberController::class, 'destroy'])->name('destroy');
        });

        Route::post('/{suite}/bookmark', [SuiteBookmarkController::class, 'store'])->name('bookmark.store');
        Route::delete('/{suite}/bookmark', [SuiteBookmarkController::class, 'destroy'])->name('bookmark.destroy');
    });

    Route::get('/bookmarks', [SuiteBookmarkController::class, 'index'])->name('bookmarks.index');

    // GitHub-style activity feed: runs, suite/test changes, new users, …
    Route::prefix('feed')->name('feed.')->group(function () {
        Route::get('/', [FeedController::class, 'index'])->name('index');
        Route::get('/poll', [FeedController::class, 'poll'])->name('poll');
    });

    // Legacy /sorify/runs listing now lives in the feed.
    Route::redirect('/runs', '/sorify/feed', 301);

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
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.update-avatar');
    Route::delete('/profile/avatar', [ProfileController::class, 'removeAvatar'])->name('profile.remove-avatar');
    Route::patch('/profile/locale', [ProfileController::class, 'updateLocale'])->name('profile.update-locale');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});

// Admin only
Route::prefix('sorify/admin')->middleware(['auth', 'admin'])->name('admin.')->group(function () {
    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
    Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
    Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');
    Route::post('/users/{user}/reset-password', [AdminUserController::class, 'resetPassword'])->name('users.reset-password');

    Route::get('/github-apps', [AdminGithubAppController::class, 'index'])->name('github-apps.index');
    Route::post('/github-apps', [AdminGithubAppController::class, 'store'])->name('github-apps.store');
    Route::put('/github-apps/{githubApp}', [AdminGithubAppController::class, 'update'])->name('github-apps.update');
    Route::delete('/github-apps/{githubApp}', [AdminGithubAppController::class, 'destroy'])->name('github-apps.destroy');
    Route::post('/github-apps/test-connection', [AdminGithubAppController::class, 'testConnection'])->name('github-apps.test-connection');
});

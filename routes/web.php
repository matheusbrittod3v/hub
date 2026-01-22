<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\SitemapController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// ============================================================================
// SITEMAP ROUTES (SEO) - Must be before dynamic routes
// ============================================================================
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap.index');
Route::get('/sitemap-static.xml', [SitemapController::class, 'staticPages'])->name('sitemap.static');
Route::get('/sitemap-albums-{page}.xml', [SitemapController::class, 'albums'])
    ->where('page', '[0-9]+')
    ->name('sitemap.albums');
Route::get('/sitemap-profiles-{page}.xml', [SitemapController::class, 'profiles'])
    ->where('page', '[0-9]+')
    ->name('sitemap.profiles');

// robots.txt dinâmico
Route::get('/robots.txt', function () {
    $content = "User-agent: *\n";
    $content .= "Allow: /\n";
    $content .= "Disallow: /dashboard\n";
    $content .= "Disallow: /login\n";
    $content .= "Disallow: /register\n";
    $content .= "Disallow: /user/\n";
    $content .= "Disallow: /api/\n";
    $content .= "\n";
    $content .= "Sitemap: " . url('/sitemap.xml') . "\n";
    
    return response($content, 200)
        ->header('Content-Type', 'text/plain');
})->name('robots.txt');

// ============================================================================
// PUBLIC ROUTES
// ============================================================================

// Home page - reads from read database (replica)
Route::get('/', [HomeController::class, 'index'])->name('home');

// Album with optional SEO slug
Route::get('/album/{id}/{slug?}', [\App\Http\Controllers\AlbumController::class, 'show'])
    ->where('id', '[a-zA-Z0-9]+')
    ->where('slug', '[a-z0-9\-]*')
    ->name('album.show');

// Profile with optional username slug
Route::get('/profile/{id}/{username?}', [\App\Http\Controllers\ProfileController::class, 'show'])
    ->where('id', '[0-9]+')
    ->where('username', '[a-z0-9\-]*')
    ->name('profile.show');

// ============================================================================
// VIDEO STREAMING ROUTES
// ============================================================================
Route::prefix('video')->group(function () {
    // Stream video with Range Request support
    Route::get('/stream/{path}', [\App\Http\Controllers\VideoStreamController::class, 'stream'])
        ->where('path', '.*')
        ->name('video.stream');
    
    // Get signed URL for direct CDN access (recommended for performance)
    Route::get('/signed-url', [\App\Http\Controllers\VideoStreamController::class, 'getSignedUrl'])
        ->name('video.signed-url');
    
    // Get video metadata
    Route::get('/metadata/{path}', [\App\Http\Controllers\VideoStreamController::class, 'metadata'])
        ->where('path', '.*')
        ->name('video.metadata');
    
    // CORS preflight
    Route::options('/stream/{path}', [\App\Http\Controllers\VideoStreamController::class, 'options'])
        ->where('path', '.*');
});

// Language switcher with rate limiting
Route::get('/lang/{locale}', function ($locale) {
    $allowedLocales = ['en', 'pt_BR', 'es', 'fr', 'zh'];
    if (in_array($locale, $allowedLocales, true)) {
        session()->put('locale', $locale);
    }
    return redirect()->back();
})->middleware('throttle.ip:30,1')->name('lang.switch');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard');
    
    // Album Management (requires 48h account age + rate limiting)
    Route::middleware(['account.old_enough', 'throttle.ip:10,1'])->group(function () {
        Route::get('/upload', [\App\Http\Controllers\AlbumController::class, 'create'])->name('album.create');
        Route::post('/album', [\App\Http\Controllers\AlbumController::class, 'store'])->name('album.store');
    });
    
    // Album delete (owner only, rate limited)
    Route::middleware(['throttle.ip:20,1'])->group(function () {
        Route::delete('/album/{id}', [\App\Http\Controllers\AlbumController::class, 'destroy'])
            ->where('id', '[a-zA-Z0-9]+')
            ->name('album.destroy');
        Route::delete('/media/{id}', [\App\Http\Controllers\AlbumController::class, 'destroyMedia'])
            ->where('id', '[0-9]+')
            ->name('media.destroy');
    });
    
    // Profile Image Updates (rate limited)
    Route::middleware(['throttle.ip:5,1'])->group(function () {
        Route::post('/profile/avatar', [\App\Http\Controllers\ProfileController::class, 'updateAvatar'])->name('profile.avatar');
        Route::post('/profile/cover', [\App\Http\Controllers\ProfileController::class, 'updateCover'])->name('profile.cover');
    });
    
});

// ============================================================================
// INTERACTION ROUTES (Likes, Views, Reports, Stats)
// All inside web middleware group to have CSRF protection
// ============================================================================
Route::middleware(['throttle.ip:100,1'])->prefix('interactions')->group(function () {
    // View registration
    Route::post('/view', [\App\Http\Controllers\InteractionController::class, 'registerView'])
        ->name('interactions.view.register');
    
    // Like toggle (works for logged-in and anonymous users)
    Route::post('/like', [\App\Http\Controllers\InteractionController::class, 'toggleLike'])
        ->name('interactions.like.toggle');
    Route::get('/like/check', [\App\Http\Controllers\InteractionController::class, 'checkLike'])
        ->name('interactions.like.check');
    
    // Stats
    Route::get('/stats', [\App\Http\Controllers\InteractionController::class, 'getStats'])
        ->name('interactions.stats');
    
    // Report reasons (public)
    Route::get('/report/reasons', [\App\Http\Controllers\InteractionController::class, 'getReportReasons'])
        ->name('interactions.report.reasons');
    
    // Report submission (rate limited)
    Route::post('/report', [\App\Http\Controllers\InteractionController::class, 'submitReport'])
        ->name('interactions.report.submit');
});

// ============================================================================
// CHAT ROUTES
// ============================================================================
Route::prefix('chat')->group(function () {
    Route::get('/messages', [\App\Http\Controllers\ChatController::class, 'index'])->name('chat.index');
    Route::post('/messages', [\App\Http\Controllers\ChatController::class, 'store'])->name('chat.store');
    Route::post('/messages/{chatMessage}/report', [\App\Http\Controllers\ChatController::class, 'report'])->name('chat.report');
});


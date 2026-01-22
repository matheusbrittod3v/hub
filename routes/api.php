<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Protected API routes
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});

// Rate limited public API
Route::middleware(['throttle:60,1'])->group(function () {
    Route::post('/thumbnail/generate/{album}', [\App\Http\Controllers\Api\ThumbnailController::class, 'generate'])
        ->where('album', '[a-zA-Z0-9]+');
    
    // Lazy load related albums (for better initial page load)
    Route::get('/album/{albumId}/related', [\App\Http\Controllers\AlbumController::class, 'getRelatedApi'])
        ->where('albumId', '[a-zA-Z0-9]+');
});

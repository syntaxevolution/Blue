<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Web\MapController;
use App\Http\Controllers\Web\WorldController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// Debug view — placeholder until Phase 1 ships the real map.
Route::get('/world', [WorldController::class, 'info'])->name('world.info');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/map', [MapController::class, 'show'])->name('map.show');
    Route::post('/map/move', [MapController::class, 'move'])->name('map.move');
    Route::post('/map/drill', [MapController::class, 'drill'])->name('map.drill');
    Route::post('/map/purchase', [MapController::class, 'purchase'])->name('map.purchase');
});

require __DIR__.'/auth.php';

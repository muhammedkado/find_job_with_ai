<?php

use App\Http\Controllers\CVController;
use App\Http\Controllers\JobSearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The wizard is a single Blade page driven by Alpine.js; these endpoints
| are called from it via axios (session-based CSRF, not a public API).
|
*/

Route::get('/', function () {
    return view('home');
});

// Uptime probe for the portfolio's status board (mkado.dev/status.json) and
// external monitors: a real DB round-trip, nothing about versions/environment.
Route::get('/health', function () {
    try {
        \Illuminate\Support\Facades\DB::select('select 1');
        return response()->json(['status' => 'ok'])->header('Cache-Control', 'no-store');
    } catch (\Throwable $e) {
        return response()->json(['status' => 'down'], 503)->header('Cache-Control', 'no-store');
    }
});

Route::middleware('throttle:ai')->group(function () {
    Route::post('/wizard/upload-cv', [CVController::class, 'analyze'])->name('wizard.upload-cv');
    Route::post('/wizard/enhance', [CVController::class, 'enhance'])->name('wizard.enhance');
    Route::post('/wizard/jobs', [JobSearchController::class, 'jobs'])->name('wizard.jobs');
    Route::get('/wizard/jobsearch', [JobSearchController::class, 'searchJobs'])->name('wizard.jobsearch');
});

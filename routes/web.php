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

Route::middleware('throttle:ai')->group(function () {
    Route::post('/wizard/upload-cv', [CVController::class, 'analyze'])->name('wizard.upload-cv');
    Route::post('/wizard/enhance', [CVController::class, 'enhance'])->name('wizard.enhance');
    Route::post('/wizard/jobs', [JobSearchController::class, 'jobs'])->name('wizard.jobs');
    Route::get('/wizard/jobsearch', [JobSearchController::class, 'searchJobs'])->name('wizard.jobsearch');
});

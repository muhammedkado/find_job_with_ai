<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CVController;
use App\Http\Controllers\JobSearchController;


/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/
/*
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});*/


Route::post('/upload-cv', [CVController::class, 'analyze']);

Route::get('/jobsearch', [JobSearchController::class, 'searchJobs']);
Route::post('/jobs', [JobSearchController::class, 'jobs']);
Route::post('/enhance', [CVController::class, 'enhance']);



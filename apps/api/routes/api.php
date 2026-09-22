<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ImageController;
use App\Http\Controllers\Api\SearchController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AskController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me',      [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('images', ImageController::class)
        ->only(['index', 'store', 'show', 'destroy']);

    Route::post('images/{image}/detect', [ImageController::class, 'detect']);

    Route::get('search', SearchController::class);

    Route::post('ask', AskController::class);
});

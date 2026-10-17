<?php

use App\Http\Controllers\Api\LendingController;
use App\Http\Controllers\Api\MemberController;
use Illuminate\Support\Facades\Route;

Route::get('/members', [MemberController::class, 'index']);
Route::post('/members', [MemberController::class, 'store']);
Route::get('/members/{member}', [MemberController::class, 'show']);
Route::patch('/members/{member}', [MemberController::class, 'update']);

Route::get('/lendings', [LendingController::class, 'index']);
Route::post('/lendings', [LendingController::class, 'store']);
Route::get('/lendings/{lending}', [LendingController::class, 'show']);

Route::post(
    '/lendings/{lending}/retry',
    [LendingController::class, 'retry']
);

Route::post(
    '/lendings/{lending}/return',
    [LendingController::class, 'returnBook']
);

Route::post(
    '/lendings/{lending}/report-lost',
    [LendingController::class, 'reportLost']
);

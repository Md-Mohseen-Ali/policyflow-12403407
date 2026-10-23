<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PolicyRequestController;
use App\Http\Controllers\Api\MockInsurerController;

Route::post(
    '/policy-requests',
    [PolicyRequestController::class, 'store']
);

Route::get(
    '/policy-requests',
    [PolicyRequestController::class, 'index']
);

Route::get(
    '/policy-requests/{id}',
    [PolicyRequestController::class, 'show']
);

Route::post(
    '/policy-requests/{id}/submit',
    [PolicyRequestController::class, 'submit']
);

Route::post(
    '/policy-requests/{id}/reconcile',
    [PolicyRequestController::class, 'reconcile']
);

Route::post(
    '/mock-insurer/callback',
    [MockInsurerController::class, 'callback']
);

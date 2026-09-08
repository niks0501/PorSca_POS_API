<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'service' => 'porsca-api',
        'version' => 'v1',
        'health' => '/api/v1/health',
    ]);
});

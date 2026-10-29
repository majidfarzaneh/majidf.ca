<?php

use Illuminate\Support\Facades\Route;

// The React app is served separately; the backend only exposes /api, /sanctum and /up.
Route::get('/', fn () => response()->json(['name' => config('app.name'), 'status' => 'ok']));

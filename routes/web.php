<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('profile_pics/{filename}', function ($filename) {
    $path = storage_path('app/public/profile_pics/'.$filename);
    if (!File::exists($path)) {
        abort(404);
    }
    return response()->file($path);
});

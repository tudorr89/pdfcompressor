<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function (): void {
    abort(401);
});

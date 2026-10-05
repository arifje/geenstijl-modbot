<?php

use Illuminate\Support\Facades\Route;

// The admin panel lives at /admin; /up is the health check.
Route::redirect('/', '/admin');

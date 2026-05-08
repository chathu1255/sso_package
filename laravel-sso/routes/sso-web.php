<?php

use Illuminate\Support\Facades\Route;
use Usjnet\Sso\Http\Controllers\SpaSsoController;

Route::get('/sso/spa/redirect', [SpaSsoController::class, 'redirect'])->name('usjnet.sso.spa.redirect');
Route::get('/sso/spa/callback', [SpaSsoController::class, 'callback'])->name('usjnet.sso.spa.callback');

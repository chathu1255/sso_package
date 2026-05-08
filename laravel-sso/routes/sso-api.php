<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Usjnet\Sso\Http\Controllers\Api\SsoAuthApiController;

Route::prefix('auth')->group(function (): void {
    Route::get('redirect', [SsoAuthApiController::class, 'redirectToSso']);
    Route::post('login', [SsoAuthApiController::class, 'login']);
    Route::get('callback', [SsoAuthApiController::class, 'handleSsoCallback']);
    Route::post('bootstrap', [SsoAuthApiController::class, 'bootstrap']);
    Route::post('exchange-code', [SsoAuthApiController::class, 'exchangeCode']);
    Route::post('refresh', [SsoAuthApiController::class, 'refresh']);
    Route::post('user_logout', [SsoAuthApiController::class, 'userLogout']);
    Route::middleware('sso.token')->get('me', [SsoAuthApiController::class, 'me']);
    Route::middleware('sso.token')->get('whoami', function (Request $request) {
        return response()->json([
            'auth_user' => Auth::user(),
            'request_user' => $request->user(),
            'sso_user' => $request->attributes->get('sso_user'),
        ]);
    });
});

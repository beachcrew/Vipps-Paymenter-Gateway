<?php

use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\Vipps\Http\Controllers\VippsController;

Route::middleware(['web'])->group(function () {
    Route::get('/extensions/vipps/return', [VippsController::class, 'handleReturn'])->name('extension.vipps.return');
});

<?php

use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\Epay\Epay;

Route::get('/extensions/epay/notify', [Epay::class, 'notify'])->name('extensions.gateways.epay.notify');

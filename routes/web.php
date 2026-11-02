<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('site.landing');
})->name('site.landing');

Route::get('/privacy', function () {
    return view('site.privacy');
})->name('site.privacy');

Route::get('/terms', function () {
    return view('site.terms');
})->name('site.terms');

<?php

use App\Http\Controllers\Site\SiteController;
use Illuminate\Support\Facades\Route;

// The React app (sign-in, staff admin, patient portal). Client-side routing handles everything under these paths.
Route::view('/login', 'spa');
Route::view('/register', 'spa');
Route::view('/appointment', 'spa');
Route::view('/set-password', 'spa');
Route::view('/admin/{any?}', 'spa')->where('any', '.*');
Route::view('/portal/{any?}', 'spa')->where('any', '.*');

// Public, server-rendered website.
Route::controller(SiteController::class)->group(function () {
    Route::get('/', 'home');
    Route::get('/about', 'about');
    Route::get('/services', 'services');
    Route::get('/facilities', 'facilities');
    Route::get('/departments', 'departments');
    Route::get('/departments/{slug}', 'department');
    Route::get('/doctors', 'doctors');
    Route::get('/doctors/{id}', 'doctor')->whereNumber('id');
    Route::get('/book', 'book');
    Route::get('/contact', 'contact');
    Route::post('/contact', 'sendContact')->middleware('throttle:contact');
    Route::get('/sitemap.xml', 'sitemap');
    Route::get('/robots.txt', 'robots');
});

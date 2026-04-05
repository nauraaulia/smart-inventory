<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Fallback safety: beberapa browser/tab lama kadang menembak endpoint Livewire update dengan GET.
// Endpoint aslinya POST-only, jadi kita arahkan balik agar user tidak mendapat error page.
Route::get('livewire-{token}/update', function () {
    $fallback = url('/');
    $previous = url()->previous();

    if (is_string($previous) && !str_ends_with($previous, '/update')) {
        return redirect()->to($previous);
    }

    return redirect()->to($fallback);
})->where('token', '[A-Za-z0-9]+');

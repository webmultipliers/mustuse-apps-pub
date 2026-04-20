<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Every URL the embedded PHP runtime serves goes through NativeEdge so the
| Livewire component can resolve the path against the signed manifest. No
| separate route table — screens and deeplinks are manifest-driven.
|
*/

Route::get('/{any?}', \App\Livewire\NativeEdge::class)
    ->where('any', '.*')
    ->name('native-edge');
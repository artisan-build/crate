<?php

declare(strict_types=1);

use ArtisanBuild\CrateServer\Http\Controllers\RegistryFileController;
use Illuminate\Support\Facades\Route;

Route::get('/packages.json', [RegistryFileController::class, 'packages']);
Route::get('/p2/{path}', [RegistryFileController::class, 'provider'])->where('path', '.*');
Route::get('/dist/{path}', [RegistryFileController::class, 'dist'])->where('path', '.*');

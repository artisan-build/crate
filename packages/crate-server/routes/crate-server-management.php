<?php

declare(strict_types=1);

use ArtisanBuild\CrateServer\Http\Controllers\BuildController;
use ArtisanBuild\CrateServer\Http\Controllers\RepositoryController;
use Illuminate\Support\Facades\Route;

Route::prefix('crate')->name('crate.')->group(function (): void {
    Route::get('/repositories', [RepositoryController::class, 'index'])->name('repositories.index');
    Route::post('/repositories', [RepositoryController::class, 'store'])->name('repositories.store');
    Route::put('/repositories/{name}/source-credential', [RepositoryController::class, 'replaceSourceCredential'])->where('name', '.*')->name('repositories.source.replace');
    Route::delete('/repositories/{name}/source-credential', [RepositoryController::class, 'clearSourceCredential'])->where('name', '.*')->name('repositories.source.clear');
    Route::delete('/repositories/{name}', [RepositoryController::class, 'destroy'])->where('name', '.*')->name('repositories.destroy');
    Route::get('/builds', [BuildController::class, 'index'])->name('builds.index');
});

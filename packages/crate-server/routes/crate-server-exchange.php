<?php

declare(strict_types=1);

use ArtisanBuild\CrateServer\Actions\IssueExchangeCode;
use ArtisanBuild\CrateServer\Http\Controllers\ExchangeController;
use Illuminate\Support\Facades\Route;

$code = preg_quote(IssueExchangeCode::PREFIX, '/').'[A-Za-z0-9]{40}';

Route::name('crate.exchange.')->group(function () use ($code): void {
    Route::get('/connect/{code}', [ExchangeController::class, 'script'])->where('code', $code)->name('script');
    Route::post('/exchange/{code}', [ExchangeController::class, 'composerAuth'])->where('code', $code)->name('composer-auth');
    Route::post('/exchange/{code}/basic', [ExchangeController::class, 'basic'])->where('code', $code)->name('basic');
});

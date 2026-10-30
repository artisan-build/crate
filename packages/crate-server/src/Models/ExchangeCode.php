<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Models;

use ArtisanBuild\CrateServer\ExchangeCodeAudiences;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A single-use, short-lived code that becomes a Composer credential when redeemed. Only the code's
 * hash is stored, and the credential does not exist until redemption, so the code is the only
 * thing that is ever shown.
 */
final class ExchangeCode extends Model
{
    protected $connection = 'crate';

    protected $guarded = [];

    /**
     * Codes that can still be redeemed.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('redeemed_at')->where('expires_at', '>', now());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'audience' => ExchangeCodeAudiences::class,
            'expires_at' => 'immutable_datetime',
            'redeemed_at' => 'immutable_datetime',
        ];
    }
}

<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Models;

use ArtisanBuild\CrateContracts\BuildStatus;
use ArtisanBuild\CrateServer\Database\Factories\BuildFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Build extends Model
{
    /** @use HasFactory<BuildFactory> */
    use HasFactory;

    protected $connection = 'crate';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BuildStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ServedRepo, Build>
     */
    public function servedRepo(): BelongsTo
    {
        return $this->belongsTo(ServedRepo::class);
    }

    protected static function newFactory(): Factory
    {
        return BuildFactory::new();
    }
}

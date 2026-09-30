<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Models;

use ArtisanBuild\CrateContracts\RepoStatus;
use ArtisanBuild\CrateContracts\RepoType;
use ArtisanBuild\CrateServer\Database\Factories\ServedRepoFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class ServedRepo extends Model
{
    /** @use HasFactory<ServedRepoFactory> */
    use HasFactory;

    protected $connection = 'crate';

    protected $guarded = [];

    /**
     * @var list<string>
     */
    protected $hidden = ['source_credential'];

    protected static function booted(): void
    {
        self::saving(function (self $repo): void {
            if (! $repo->isDirty('source_credential')) {
                return;
            }

            $repo->has_source_credential = filled($repo->source_credential);

            if (! $repo->has_source_credential) {
                $repo->source_credential = null;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RepoStatus::class,
            'type' => RepoType::class,
            'source_credential' => 'encrypted',
            'has_source_credential' => 'boolean',
            'last_built_at' => 'datetime',
        ];
    }

    protected static function newFactory(): Factory
    {
        return ServedRepoFactory::new();
    }
}

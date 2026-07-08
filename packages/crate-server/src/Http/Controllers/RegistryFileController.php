<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class RegistryFileController
{
    public function packages(): StreamedResponse
    {
        return $this->serve('packages.json');
    }

    public function provider(string $path): StreamedResponse
    {
        return $this->serve('p2/'.ltrim($path, '/'));
    }

    public function dist(string $path): StreamedResponse
    {
        return $this->serve('dist/'.ltrim($path, '/'));
    }

    private function serve(string $path): StreamedResponse
    {
        $disk = Storage::disk((string) config('crate-server.archive_disk'));
        $prefix = trim((string) config('crate-server.output_dir'), '/');
        $storagePath = $prefix === '' ? $path : $prefix.'/'.$path;

        abort_unless($disk->exists($storagePath), 404);

        return $disk->response($storagePath);
    }
}

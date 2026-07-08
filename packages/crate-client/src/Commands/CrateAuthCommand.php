<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateClient\Commands;

use ArtisanBuild\CrateClient\Crate;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

final class CrateAuthCommand extends Command
{
    protected $signature = 'crate:auth {--print : Print the COMPOSER_AUTH JSON to stdout instead of writing auth.json} {--path=auth.json}';

    protected $description = 'Generate Composer auth for a Crate registry.';

    public function handle(): int
    {
        try {
            if ($this->option('print')) {
                $this->getOutput()->writeln(Crate::composerAuthJson(), OutputInterface::OUTPUT_RAW);

                return self::SUCCESS;
            }

            $path = (string) $this->option('path');
            $auth = $this->readAuthJson($path);
            $auth['http-basic'] = array_merge(
                is_array($auth['http-basic'] ?? null) ? $auth['http-basic'] : [],
                Crate::composerAuthFragment(),
            );

            file_put_contents($path, json_encode($auth, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

            $this->info("Wrote Composer auth to {$path}.");

            return self::SUCCESS;
        } catch (RuntimeException|JsonException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function readAuthJson(string $path): array
    {
        if (! file_exists($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if ($contents === false || trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("The Composer auth file at {$path} must contain a JSON object.");
        }

        return $decoded;
    }
}

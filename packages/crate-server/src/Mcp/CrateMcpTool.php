<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Mcp;

use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;

abstract class CrateMcpTool extends Tool
{
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $tool = parent::toArray();
        $tool['inputSchema']['additionalProperties'] = false;

        if (isset($tool['outputSchema'])) {
            $tool['outputSchema']['additionalProperties'] = false;
        }

        return $tool;
    }

    /**
     * @param  list<string>  $allowed
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function validated(Request $request, array $allowed, array $rules): array
    {
        foreach (array_keys($request->all()) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw ValidationException::withMessages([
                    is_string($key) ? $key : 'arguments' => 'Unknown argument.',
                ]);
            }
        }

        return $request->validate($rules);
    }
}

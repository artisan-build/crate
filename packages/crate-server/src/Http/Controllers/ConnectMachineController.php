<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Http\Controllers;

use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\CrateServer\Actions\IssueExchangeCode;
use ArtisanBuild\CrateServer\IssuedExchangeCode;
use ArtisanBuild\CrateServer\RegistryAddress;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The signed-in person's "Connect this machine" page, which hands out a one-line command for their terminal. */
final class ConnectMachineController
{
    /** Explain what connecting does, before any code exists. */
    public function show(RegistryAddress $registry): View
    {
        return view('crate::connect', [
            'command' => null,
            'host' => $registry->authHost(),
            'minutes' => IssueExchangeCode::TTL_MINUTES,
        ]);
    }

    /** Issue a fresh code for this person and show the command that redeems it. */
    public function store(Request $request, IssueExchangeCode $issue, RegistryAddress $registry): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->status === 'active', 403);

        $issued = $issue->forPerson($user);

        return response()->view('crate::connect', [
            'command' => $this->command($issued),
            'host' => $registry->authHost(),
            'minutes' => IssueExchangeCode::TTL_MINUTES,
        ], 201, ['Cache-Control' => 'no-store, private']);
    }

    /** The pipe-to-sh one-liner for an issued code. */
    private function command(IssuedExchangeCode $issued): string
    {
        return 'curl -fsSL '.route('crate.exchange.script', $issued->code).' | sh';
    }
}

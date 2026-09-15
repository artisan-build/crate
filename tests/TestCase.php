<?php

namespace Tests;

use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function actingAsVersioned(User $user, ?string $guard = null): static
    {
        $user->refresh();

        return $this->actingAs($user, $guard)->withSession([
            StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version,
        ]);
    }
}

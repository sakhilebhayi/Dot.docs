<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/** EA-1 / S-16: a copy of .env.example must never start an unsafe production. */
class EnvExampleIsSafeTest extends TestCase
{
    public function test_the_env_example_ships_safe_defaults(): void
    {
        $env = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString("APP_DEBUG=false\n", $env);
        $this->assertStringContainsString("SESSION_SECURE_COOKIE=true\n", $env);
        $this->assertStringNotContainsString('infodot-secret', $env);
        $this->assertStringNotContainsString('LOG_LEVEL=debug', $env);
    }
}

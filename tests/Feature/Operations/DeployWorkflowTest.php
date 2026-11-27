<?php

namespace Tests\Feature\Operations;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * R2-2: what keeps a deploy safe, pinned so a later edit cannot quietly undo it. (actionlint in CI checks that the
 * files are valid; this checks they still say what we decided.)
 */
class DeployWorkflowTest extends TestCase
{
    /** @return array<string, mixed> */
    private function workflow(string $name): array
    {
        return Yaml::parseFile(base_path(".github/workflows/{$name}.yml"));
    }

    /** @return array<int, array<string, mixed>> */
    private function deploySteps(): array
    {
        return $this->workflow('deploy')['jobs']['deploy']['steps'];
    }

    /** @return array<string, mixed> */
    private function step(string $name): array
    {
        foreach ($this->deploySteps() as $step) {
            if (($step['name'] ?? null) === $name) {
                return $step;
            }
        }
        $this->fail("deploy.yml has no step named '{$name}'");
    }

    public function test_a_deploy_starts_only_after_ci_passed_on_main(): void
    {
        $deploy = $this->workflow('deploy');
        // Symfony YAML reads the bare key `on` as the boolean true.
        $on = $deploy['on'] ?? $deploy[true];

        $this->assertArrayNotHasKey('push', $on, 'a push must not deploy before CI has passed');
        $this->assertSame(['CI'], $on['workflow_run']['workflows']);
        $this->assertSame(['completed'], $on['workflow_run']['types']);
        $this->assertSame(['main'], $on['workflow_run']['branches']);

        $job = $deploy['jobs']['deploy'];
        $this->assertStringContainsString("github.event.workflow_run.conclusion == 'success'", $job['if']);
        $this->assertStringContainsString('github.event.workflow_run.head_sha', $job['env']['DEPLOY_SHA']);
        $this->assertSame('${{ env.DEPLOY_SHA }}', $this->deploySteps()[1]['with']['ref'], 'the assets are built from the commit that is deployed');
    }

    public function test_the_deciding_health_check_runs_on_the_host_and_a_failure_rolls_back(): void
    {
        $host = $this->step('Smoke-check the live site from the host');
        $this->assertSame('host_smoke', $host['id']);
        $this->assertStringContainsString('bash scripts/deploy/smoke-check.sh', $host['run']);
        $this->assertFileExists(base_path('scripts/deploy/smoke-check.sh'));

        $rollback = $this->step('Roll back when the live site is unhealthy');
        $this->assertStringContainsString("steps.host_smoke.outputs.result == 'unhealthy'", $rollback['if'], 'an unreachable site is not rolled back on no evidence');
        $this->assertStringContainsString('git reset --hard "$PREV"', $rollback['run']);
        $this->assertStringContainsString('storage/app/build.prev', $rollback['run'], 'the previous release gets its own assets back');
        $this->assertStringContainsString('cache:purge-litespeed', $rollback['run'], 'no cached page of the failed release survives');
        $this->assertStringContainsString('rm -f storage/framework/down', $rollback['run'], 'never left in maintenance mode');

        $outside = $this->step('Check the live site from outside (advisory)');
        $this->assertTrue($outside['continue-on-error']);

        $code = $this->step('Deploy code and run migrations');
        $this->assertStringContainsString('storage/app/deploy-previous-release', $code['run']);
        $this->assertStringContainsString('cp -a public/build storage/app/build.prev', $code['run'], 'kept outside public/, never served');
    }

    public function test_workflow_lint_is_part_of_the_quality_gate(): void
    {
        $ci = $this->workflow('ci');

        $this->assertArrayHasKey('workflow-lint', $ci['jobs']);
        $this->assertContains('workflow-lint', $ci['jobs']['quality-gate']['needs']);
        $this->assertStringContainsString('results[workflow-lint]', $ci['jobs']['quality-gate']['steps'][0]['run']);
    }

    /** A 200 from the host's bot protection is not a leaked file: only the file itself counts (2026-11-27). */
    public function test_the_app_folder_probe_counts_only_the_real_file_as_exposed(): void
    {
        $probe = $this->step('Probe the application folder from outside')['run'];

        $this->assertStringContainsString('signature "$P" "$BODY"', $probe);
        $this->assertStringContainsString("grep -qE '^(APP_KEY|DB_CONNECTION|APP_ENV)='", $probe);
        $this->assertStringContainsString('for BASE in "$KEY/" ""', $probe, 'the site root is probed too');
    }
}

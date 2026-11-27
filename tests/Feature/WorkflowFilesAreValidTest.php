<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * GitHub refuses a workflow file in which one step defines a key twice
 * ("'run' is already defined") and then deploys nothing - while YAML
 * parsers that keep the last value accept it. EA-6's first merge hit exactly
 * that: a step's `- name:` line was lost and its `run:` landed under the step
 * above. This reads every workflow the way GitHub does on that point.
 */
class WorkflowFilesAreValidTest extends TestCase
{
    public function test_no_step_in_any_workflow_defines_a_key_twice(): void
    {
        foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
            $step = null;
            $keys = [];

            foreach ($lines as $n => $line) {
                if (preg_match('/^(\s*)- (name|uses|run|id):/', $line, $m)) {
                    $step = strlen($m[1]);
                    $keys = [$m[2] => true];

                    continue;
                }

                if ($step !== null && preg_match('/^(\s*)([a-z][a-z0-9_-]*):/', $line, $m)) {
                    $indent = strlen($m[1]);
                    if ($indent < $step + 2) {
                        $step = null;
                        $keys = [];

                        continue;
                    }
                    if ($indent === $step + 2) {
                        $this->assertArrayNotHasKey($m[2], $keys, basename($file).':'.($n + 1).": '{$m[2]}' is already defined in this step");
                        $keys[$m[2]] = true;
                    }
                }
            }
        }
    }

    public function test_the_deploy_still_has_its_smoke_check_step(): void
    {
        $this->assertStringContainsString('- name: Smoke-check the live site', (string) file_get_contents(base_path('.github/workflows/deploy.yml')));
    }
}

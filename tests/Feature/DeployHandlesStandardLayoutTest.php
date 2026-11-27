<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * doc.infodot.co.za is moving from the flattened layout (document root = the app folder's parent) to the standard one
 * (document root = <app>/public). The deploy must handle both, because the move is a hosting-panel change the owner
 * makes between two deploys. A workflow cannot be run here; what can be guarded is that nobody removes either branch.
 */
class DeployHandlesStandardLayoutTest extends TestCase
{
    private function deploy(): string
    {
        return file_get_contents(base_path('.github/workflows/deploy.yml'));
    }

    /** The code step, the asset step and (R2-2) the rollback after a failed health check. */
    public function test_the_deploy_asks_the_host_where_the_document_root_is_in_every_place_that_depends_on_it(): void
    {
        $this->assertSame(3, substr_count($this->deploy(), 'single_domain_data domain=doc.infodot.co.za'));
    }

    public function test_the_tracked_root_htaccess_is_moved_aside_only_when_the_document_root_is_public(): void
    {
        $deploy = $this->deploy();

        $this->assertStringContainsString('mv -f .htaccess .htaccess.docroot-is-public-disabled', $deploy);
        $this->assertStringContainsString('flattened layout): app-folder .htaccess stays', $deploy);
    }

    public function test_the_flattened_layout_still_syncs_the_docroot_copy_of_build(): void
    {
        $deploy = $this->deploy();

        $this->assertStringContainsString('"$DEST:$REAL_DOCROOT/build/"', $deploy);
        $this->assertStringContainsString('"$DEST:$DEPLOY_PATH/public/build/"', $deploy);
    }

    /** EA-6: a rollback's `git reset --hard` restores the deny-all .htaccess too; it must be moved aside again. */
    public function test_a_rollback_moves_the_htaccess_aside_again(): void
    {
        $deploy = $this->deploy();
        $rollback = substr($deploy, strpos($deploy, 'rollback() {'), 600);

        $this->assertStringContainsString('git reset --hard "$PREV"', $rollback);
        $this->assertStringContainsString('apply_layout', $rollback);
        $this->assertLessThan(strpos($rollback, 'artisan up'), strpos($rollback, 'apply_layout'));
    }

    /** EA-6: the backup is taken, and an older commit refused, before maintenance mode starts. */
    public function test_the_backup_and_the_ancestry_check_come_before_maintenance_mode(): void
    {
        $deploy = $this->deploy();
        $down = strpos($deploy, 'artisan down --retry=30');

        $this->assertLessThan($down, strpos($deploy, 'git merge-base --is-ancestor "$PREV" "$DEPLOY_SHA"'));
        $this->assertLessThan($down, strpos($deploy, 'predeploy-'));
        $this->assertStringContainsString('git reset --hard "$DEPLOY_SHA"', $deploy);
        $this->assertStringNotContainsString('git reset --hard origin/main', $deploy);
        $this->assertStringContainsString('if: failure()', $deploy);
    }
}

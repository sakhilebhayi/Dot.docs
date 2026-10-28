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

    public function test_the_deploy_asks_the_host_where_the_document_root_is_in_both_places_that_depend_on_it(): void
    {
        $this->assertSame(2, substr_count($this->deploy(), 'single_domain_data domain=doc.infodot.co.za'));
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
}

<?php

namespace Tests\Helpers;

use CodeIgniter\Test\CIUnitTestCase;

class AssetHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper('asset');
    }

    public function testAssetUrl_AppendsTheModificationTimeOfExistingFiles(): void
    {
        $url = assetUrl('css/app.css');

        $this->assertStringStartsWith(base_url('assets/css/app.css') . '?v=', $url);
        $this->assertSame((string) filemtime(FCPATH . 'assets/css/app.css'), substr($url, strpos($url, '?v=') + 3));
    }

    public function testAssetUrl_OmitsTheVersionForMissingFiles(): void
    {
        $this->assertSame(base_url('assets/css/missing.css'), assetUrl('css/missing.css'));
    }
}

<?php

namespace Tests\Helpers;

use CodeIgniter\Test\CIUnitTestCase;

class UiHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper(['form', 'ui']);
    }

    public function testToggleButton_RendersTheCheckboxAsButtonCheck(): void
    {
        $html = toggleButton(['id' => 'discount_toggle_1', 'name' => 'discount_toggle', 'value' => 1], '$', '%');

        $this->assertStringContainsString('class="btn-check"', $html);
        $this->assertStringContainsString('name="discount_toggle"', $html);
        $this->assertStringContainsString('for="discount_toggle_1"', $html);
    }

    public function testToggleButton_MarksTheCheckboxCheckedWhenRequested(): void
    {
        $html = toggleButton(['id' => 'discount_toggle_1', 'name' => 'discount_toggle', 'value' => 1, 'checked' => true], '$', '%');

        $this->assertStringContainsString('checked', $html);
    }

    public function testToggleButton_LeavesTheCheckboxUncheckedByDefault(): void
    {
        $html = toggleButton(['id' => 'discount_toggle_1', 'name' => 'discount_toggle', 'value' => 1], '$', '%');

        $this->assertStringNotContainsString('checked', $html);
    }

    public function testToggleButton_EscapesTheLabels(): void
    {
        $html = toggleButton(['id' => 'discount_toggle_1', 'name' => 'discount_toggle', 'value' => 1], '<b>$</b>', '"%"');

        $this->assertStringContainsString('&lt;b&gt;$&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    public function testToggleButton_CarriesCustomAttributesOnTheCheckbox(): void
    {
        $html = toggleButton(['id' => 'discount_toggle_1', 'name' => 'discount_toggle', 'value' => 1, 'data-line' => 3], '$', '%');

        $this->assertStringContainsString('data-line="3"', $html);
    }
}

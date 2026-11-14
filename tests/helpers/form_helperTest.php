<?php

use App\Libraries\SafeHtml;
use CodeIgniter\Test\CIUnitTestCase;

class form_helperTest extends CIUnitTestCase
{
    private const HOSTILE = '"\'></script><img src=x onerror=zq9(1)>';

    protected function setUp(): void
    {
        parent::setUp();

        helper('form');
    }

    public function testFormDropdown_EscapesTheLabelOfAnOption(): void
    {
        $html = form_dropdown('location', ['1' => self::HOSTILE], '1', ['class' => 'form-select']);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('</script>', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=zq9(1)&gt;', $html);
        $this->assertStringContainsString('<option value="1" selected="selected">&quot;&#039;&gt;&lt;/script&gt;', $html);
    }

    public function testFormDropdown_EscapesTheLabelsInsideAnOptionGroupAndTheGroupLabel(): void
    {
        $html = form_dropdown('location', [self::HOSTILE => ['1' => '<b zq9>']], '');

        $this->assertStringNotContainsString('<b zq9>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('<optgroup label="&quot;&#039;&gt;&lt;/script&gt;&lt;img src=x onerror=zq9(1)&gt;">', $html);
        $this->assertStringContainsString('>&lt;b zq9&gt;</option>', $html);
    }

    public function testFormDropdown_KeepsALabelThatWasAlreadyEscapedOnce(): void
    {
        $html = form_dropdown('location', ['1' => esc('<b>Tom & Jerry</b>')]);

        $this->assertStringContainsString('>&lt;b&gt;Tom &amp; Jerry&lt;/b&gt;</option>', $html);
        $this->assertStringNotContainsString('&amp;lt;', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    public function testFormDropdown_KeepsTheEntitiesThatLegacyScreensStoreOnWrite(): void
    {
        $html = form_dropdown('employee', ['1' => 'John &lt;b&gt; O&#039;Brien']);

        $this->assertStringContainsString('>John &lt;b&gt; O&#039;Brien</option>', $html);
        $this->assertStringNotContainsString('&amp;', $html);
    }

    public function testFormDropdown_EscapesABareAmpersandAndTheQuotes(): void
    {
        $html = form_dropdown('location', ['1' => 'Tom & "Jerry" \'s']);

        $this->assertStringContainsString('>Tom &amp; &quot;Jerry&quot; &#039;s</option>', $html);
    }

    public function testFormDropdown_PrintsTheMarkupOfALabelWrappedInSafeHtml(): void
    {
        $html = form_dropdown('location', ['1' => new SafeHtml('A<br>B')]);

        $this->assertStringContainsString('>A<br>B</option>', $html);
    }

    public function testFormDropdown_EscapesTheLabelOfANumberAndOfNull(): void
    {
        $html = form_dropdown('location', ['1' => 7, '2' => null, '3' => 1.5]);

        $this->assertStringContainsString('<option value="1">7</option>', $html);
        $this->assertStringContainsString('<option value="2"></option>', $html);
        $this->assertStringContainsString('<option value="3">1.5</option>', $html);
    }

    public function testFormDropdown_EscapesTheValueOfAnOption(): void
    {
        $html = form_dropdown('location', ['"><x zq9>' => 'label']);

        $this->assertStringNotContainsString('<x zq9>', $html);
        $this->assertStringContainsString('value="&quot;&gt;&lt;x zq9&gt;"', $html);
    }

    public function testFormDropdown_MarksTheSelectedOptionsAndTakesTheDataOfAnArray(): void
    {
        $html = form_dropdown(['name' => 'tags', 'id' => 'tags', 'options' => ['a' => 'Alpha', 'b' => 'Beta', 'c' => 'Gamma'], 'selected' => ['a', 'c']]);

        $this->assertStringContainsString('<select name="tags" id="tags" multiple="multiple">', $html);
        $this->assertStringContainsString('<option value="a" selected="selected">Alpha</option>', $html);
        $this->assertStringContainsString('<option value="b">Beta</option>', $html);
        $this->assertStringContainsString('<option value="c" selected="selected">Gamma</option>', $html);
    }

    public function testFormDropdown_CarriesTheExtraAttributesOfAStringAndOfAnArray(): void
    {
        $fromString = form_dropdown('mode', ['1' => 'One'], '1', 'id="mode" class="form-select"');
        $fromArray  = form_dropdown('mode', ['1' => 'One'], '1', ['id' => 'mode', 'class' => 'form-select']);

        $this->assertStringContainsString('<select name="mode" id="mode" class="form-select">', $fromString);
        $this->assertStringContainsString('<select name="mode" id="mode" class="form-select">', $fromArray);
    }

    public function testFormLabel_EscapesTheText(): void
    {
        $html = form_label('Quantity ' . self::HOSTILE, 'quantity', ['class' => 'form-label']);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('<label for="quantity" class="form-label">Quantity &quot;&#039;&gt;&lt;/script&gt;&lt;img src=x onerror=zq9(1)&gt;</label>', $html);
    }

    public function testFormLabel_EscapesTheIdAndTheAttributes(): void
    {
        $html = form_label('Name', '"><x zq9>', ['title' => '"><y zq9>', 'class' => 'a b']);

        $this->assertStringNotContainsString('<x zq9>', $html);
        $this->assertStringNotContainsString('<y zq9>', $html);
        $this->assertStringContainsString('for="&quot;&gt;&lt;x zq9&gt;"', $html);
        $this->assertStringContainsString('title="&quot;&gt;&lt;y zq9&gt;"', $html);
        $this->assertStringContainsString('class="a b"', $html);
    }

    public function testFormLabel_PrintsTheMarkupOfATextWrappedInSafeHtml(): void
    {
        $html = form_label(new SafeHtml('Payment Reference Code<br>Length Limits'), 'length');

        $this->assertSame('<label for="length">Payment Reference Code<br>Length Limits</label>', $html);
    }

    public function testFormLabel_KeepsAnEntityAlreadyInTheText(): void
    {
        $html = form_label(esc('Terms & <Conditions>'));

        $this->assertSame('<label>Terms &amp; &lt;Conditions&gt;</label>', $html);
    }

    public function testFormLabel_RendersALabelWithoutAnIdOrAttributes(): void
    {
        $this->assertSame('<label>Name</label>', form_label('Name'));
    }

    public function testEscapeFormText_LeavesAnArabicTextAsItIs(): void
    {
        $this->assertSame('الفرع الرئيسي', escapeFormText('الفرع الرئيسي'));
    }
}

<?php

namespace Tests\Libraries\Documents;

use App\Libraries\Documents\CustomFieldType;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class CustomFieldTypeTest extends CIUnitTestCase
{
    public function testCases_ListTheNineSupportedTypes(): void
    {
        $this->assertSame(['text', 'textarea', 'number', 'date', 'select', 'checkbox', 'url', 'email', 'phone'], array_map(static fn (CustomFieldType $type): string => $type->value, CustomFieldType::cases()));
    }

    public function testHasOptions_IsTrueForTheListOnly(): void
    {
        $this->assertSame([CustomFieldType::Select], array_values(array_filter(CustomFieldType::cases(), static fn (CustomFieldType $type): bool => $type->hasOptions())));
    }

    /**
     * @return array<string, array{0: CustomFieldType, 1: string, 2: ?string}>
     */
    public static function valueProvider(): array
    {
        return [
            'text'                        => [CustomFieldType::Text, '  Blue  ', 'Blue'],
            'text with markup'            => [CustomFieldType::Text, '<b>x</b>', '<b>x</b>'],
            'text on two lines'           => [CustomFieldType::Text, "a\nb", null],
            'text with a control char'    => [CustomFieldType::Text, "a\x07b", null],
            'text of 255 letters'         => [CustomFieldType::Text, str_repeat('ش', 255), str_repeat('ش', 255)],
            'text of 256 letters'         => [CustomFieldType::Text, str_repeat('a', 256), null],
            'textarea on two lines'       => [CustomFieldType::Textarea, "a\r\nb", "a\nb"],
            'textarea of 2000 letters'    => [CustomFieldType::Textarea, str_repeat('a', 2000), str_repeat('a', 2000)],
            'textarea of 2001 letters'    => [CustomFieldType::Textarea, str_repeat('a', 2001), null],
            'whole number'                => [CustomFieldType::Number, '42', '42'],
            'negative decimal'            => [CustomFieldType::Number, '-3.25', '-3.25'],
            'six decimals'                => [CustomFieldType::Number, '0.123456', '0.123456'],
            'seven decimals'              => [CustomFieldType::Number, '0.1234567', null],
            'a thousands comma'           => [CustomFieldType::Number, '1,000', null],
            'an exponent'                 => [CustomFieldType::Number, '1e3', null],
            'fifteen digits'              => [CustomFieldType::Number, '123456789012345', '123456789012345'],
            'sixteen digits'              => [CustomFieldType::Number, '1234567890123456', null],
            'a date'                      => [CustomFieldType::Date, '2026-02-28', '2026-02-28'],
            'a day that does not exist'   => [CustomFieldType::Date, '2026-02-30', null],
            'a date in another format'    => [CustomFieldType::Date, '28/02/2026', null],
            'a leap day'                  => [CustomFieldType::Date, '2028-02-29', '2028-02-29'],
            'a ticked box'                => [CustomFieldType::Checkbox, '1', '1'],
            'a box ticked as on'          => [CustomFieldType::Checkbox, 'on', '1'],
            'a box with another value'    => [CustomFieldType::Checkbox, 'maybe', null],
            'a web address'               => [CustomFieldType::Url, 'https://example.com/a?b=1', 'https://example.com/a?b=1'],
            'a script address'            => [CustomFieldType::Url, 'javascript:alert(1)', null],
            'an address without a scheme' => [CustomFieldType::Url, 'example.com', null],
            'a file address'              => [CustomFieldType::Url, 'file:///etc/passwd', null],
            'an e-mail'                   => [CustomFieldType::Email, 'a.b@example.com', 'a.b@example.com'],
            'not an e-mail'               => [CustomFieldType::Email, 'a.b@', null],
            'a phone'                     => [CustomFieldType::Phone, '+966 50 123 4567', '+966 50 123 4567'],
            'a phone with brackets'       => [CustomFieldType::Phone, '(011) 456-7890', '(011) 456-7890'],
            'a phone with letters'        => [CustomFieldType::Phone, '050-CALL-ME', null],
            'a phone that is too short'   => [CustomFieldType::Phone, '12', null],
        ];
    }

    #[DataProvider('valueProvider')]
    public function testNormalize_AcceptsAValueThatFitsItsTypeAndRejectsOneThatDoesNot(CustomFieldType $type, string $value, ?string $expected): void
    {
        $this->assertSame($expected, $type->normalize($value));
    }

    public function testNormalize_AcceptsOnlyAnOptionOfTheList(): void
    {
        $this->assertSame('Red', CustomFieldType::Select->normalize(' Red ', ['Red', 'Blue']));
        $this->assertNull(CustomFieldType::Select->normalize('Green', ['Red', 'Blue']));
        $this->assertNull(CustomFieldType::Select->normalize('red', ['Red', 'Blue']));
        $this->assertNull(CustomFieldType::Select->normalize('Red', []));
    }

    public function testLanguageKey_NamesTheTypeInTheLanguageFile(): void
    {
        foreach (CustomFieldType::cases() as $type) {
            $this->assertNotSame($type->languageKey(), lang($type->languageKey()), $type->value);
        }
    }
}

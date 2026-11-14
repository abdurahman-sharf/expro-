<?php

namespace Tests\Libraries\Security;

use App\Libraries\Security\PasswordPolicy;
use CodeIgniter\Test\CIUnitTestCase;

class PasswordPolicyTest extends CIUnitTestCase
{
    public function testViolation_RequiresAPassword(): void
    {
        $this->assertSame('Employees.password_required', PasswordPolicy::violation('', 'cashier1'));
    }

    public function testViolation_RejectsAPasswordOfSevenBytes(): void
    {
        $this->assertSame('Employees.password_minlength', PasswordPolicy::violation('1234567', 'cashier1'));
        $this->assertSame('Employees.password_minlength', PasswordPolicy::violation('a', 'cashier1'));
    }

    public function testViolation_AcceptsAPasswordOfEightBytes(): void
    {
        $this->assertNull(PasswordPolicy::violation('12345678', 'cashier1'));
    }

    public function testViolation_RejectsAPasswordThatIsTheUsernameInAnyCase(): void
    {
        $this->assertSame('Employees.password_same_as_username', PasswordPolicy::violation('accountant1', 'accountant1'));
        $this->assertSame('Employees.password_same_as_username', PasswordPolicy::violation('ACCOUNTANT1', 'accountant1'));
    }

    public function testViolation_ComparesWithEveryUsernameGiven(): void
    {
        $this->assertSame('Employees.password_same_as_username', PasswordPolicy::violation('accountant1', 'someone-else', 'accountant1'));
    }

    public function testViolation_IgnoresAnEmptyUsername(): void
    {
        $this->assertNull(PasswordPolicy::violation('12345678', ''));
    }

    public function testViolation_RejectsThePasswordTheInstallerGives(): void
    {
        $this->assertSame('Employees.password_is_default', PasswordPolicy::violation(PasswordPolicy::INSTALLER_DEFAULT, 'cashier1'));
    }

    public function testViolation_AcceptsEightSpacesAsTheLengthIsCountedInBytes(): void
    {
        $this->assertNull(PasswordPolicy::violation('        ', 'cashier1'));
    }
}

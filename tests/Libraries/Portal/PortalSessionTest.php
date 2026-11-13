<?php

namespace Tests\Libraries\Portal;

use App\Libraries\Portal\PortalSession;
use CodeIgniter\Events\Events;
use CodeIgniter\Session\Handlers\ArrayHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockSession;
use Config\Session as SessionConfig;

/**
 * @internal
 */
final class PortalSessionTest extends CIUnitTestCase
{
    private const NOW = 1_800_000_000;

    private MockSession $store;
    private int $regenerations = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION            = [];
        $this->regenerations = 0;
        $counter             = &$this->regenerations;
        $config              = new SessionConfig();

        $this->store = new class (new ArrayHandler($config, '0.0.0.0'), $config, $counter) extends MockSession {
            public function __construct($driver, $config, private int &$counter)
            {
                parent::__construct($driver, $config);
            }

            public function regenerate(bool $destroy = false): void
            {
                $this->counter++;
            }
        };
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        Events::removeAllListeners(PortalSession::REGENERATED);

        parent::tearDown();
    }

    private function session(bool $active = true): PortalSession
    {
        return new PortalSession($this->store, static fn (): bool => $active);
    }

    private function account(int $customer = 5, int $account = 7, int $version = 3): object
    {
        return (object) ['customer_id' => (string) $customer, 'account_id' => (string) $account, 'credential_version' => (string) $version];
    }

    public function testBegin_RenewsTheIdentifierOnceAndKeepsOnlyTheKeysOfTheCustomer(): void
    {
        $_SESSION = ['planted' => 'by an attacker', 'person_id' => 9, 'cart' => [1, 2]];

        $this->session()->begin($this->account(), self::NOW);

        $this->assertSame(1, $this->regenerations);
        $this->assertSame([PortalSession::KEY_CUSTOMER, PortalSession::KEY_ACCOUNT, PortalSession::KEY_VERSION, PortalSession::KEY_STARTED, PortalSession::KEY_ACTIVE], array_keys($_SESSION));
        $this->assertSame([5, 7, 3, self::NOW, self::NOW], array_values($_SESSION));
    }

    public function testBegin_ReadsTheNumbersAsWholeNumbers(): void
    {
        $this->session()->begin($this->account(), self::NOW);

        $session = $this->session();
        $this->assertSame([5, 7, 3], [$session->customerId(), $session->accountId(), $session->credentialVersion()]);
    }

    public function testBegin_DoesNotAskTheEngineToRenewWhenNoSessionIsOpenButStillSaysItRenewed(): void
    {
        $seen = 0;
        Events::on(PortalSession::REGENERATED, static function () use (&$seen): void {
            $seen++;
        });

        $this->session(false)->begin($this->account(), self::NOW);

        $this->assertSame(0, $this->regenerations);
        $this->assertSame(1, $seen);
    }

    public function testEnd_ClearsEveryKeyAndRenewsTheIdentifier(): void
    {
        $this->session()->begin($this->account(), self::NOW);
        $this->regenerations = 0;
        $_SESSION['extra']   = 'x';

        $this->session()->end();

        $this->assertSame([], array_diff_key($_SESSION, ['__ci_vars' => 1]));
        $this->assertSame(1, $this->regenerations);
        $this->assertNull($this->session()->customerId());
    }

    public function testRenew_ChangesTheIdentifierAndTheVersionAndKeepsTheCustomer(): void
    {
        $this->session()->begin($this->account(), self::NOW);
        $this->regenerations = 0;

        $this->session()->renew(4);

        $this->assertSame(1, $this->regenerations);
        $this->assertSame(4, $this->session()->credentialVersion());
        $this->assertSame(5, $this->session()->customerId());
    }

    public function testCustomerId_IsNullForAnEmptySessionAndForValuesThatAreNotWholeNumbersAbove0(): void
    {
        $this->assertNull($this->session()->customerId());

        foreach (['5', 5.0, 0, -3, true, null, [5]] as $value) {
            $_SESSION[PortalSession::KEY_CUSTOMER] = $value;
            $this->assertNull($this->session()->customerId(), var_export($value, true));
        }
    }

    public function testAccountId_IsNullForAnEmptySessionAndForValuesThatAreNotWholeNumbersAbove0(): void
    {
        $this->assertNull($this->session()->accountId());

        foreach (['7', 0, -1, false] as $value) {
            $_SESSION[PortalSession::KEY_ACCOUNT] = $value;
            $this->assertNull($this->session()->accountId(), var_export($value, true));
        }
    }

    public function testHoldsPortal_IsTrueForTheCustomerOrTheAccountAndFalseForAnEmployeeSession(): void
    {
        $this->assertFalse($this->session()->holdsPortal());

        $_SESSION = ['person_id' => 2];
        $this->assertFalse($this->session()->holdsPortal());

        $_SESSION = [PortalSession::KEY_CUSTOMER => 5];
        $this->assertTrue($this->session()->holdsPortal());

        $_SESSION = [PortalSession::KEY_ACCOUNT => 7];
        $this->assertTrue($this->session()->holdsPortal());
    }

    public function testHoldsEmployee_IsTrueOnlyWhenTheKeyOfTheEmployeeIsThere(): void
    {
        $this->assertFalse($this->session()->holdsEmployee());

        $_SESSION = [PortalSession::KEY_CUSTOMER => 5];
        $this->assertFalse($this->session()->holdsEmployee());

        $_SESSION['person_id'] = 2;
        $this->assertTrue($this->session()->holdsEmployee());
    }

    public function testExpired_IsFalseWhileTheSessionIsActiveAndNotOld(): void
    {
        $this->session()->begin($this->account(), self::NOW);

        $this->assertFalse($this->session()->expired(self::NOW + PortalSession::IDLE_SECONDS));
    }

    public function testExpired_IsTrueOneSecondAfterHalfAnHourOfIdleness(): void
    {
        $this->session()->begin($this->account(), self::NOW);

        $this->assertTrue($this->session()->expired(self::NOW + PortalSession::IDLE_SECONDS + 1));
    }

    public function testExpired_IsTrueOneSecondAfterEightHoursEvenForASessionThatIsUsed(): void
    {
        $this->session()->begin($this->account(), self::NOW);

        for ($at = self::NOW; $at < self::NOW + PortalSession::MAX_SECONDS; $at += 1200) {
            $this->session()->touch($at);
        }

        $this->session()->touch(self::NOW + PortalSession::MAX_SECONDS);

        $this->assertFalse($this->session()->expired(self::NOW + PortalSession::MAX_SECONDS));
        $this->assertTrue($this->session()->expired(self::NOW + PortalSession::MAX_SECONDS + 1));
    }

    public function testExpired_IsTrueForASessionThatNeverBegan(): void
    {
        $this->assertTrue($this->session()->expired(self::NOW));
    }

    public function testTouch_MovesTheActivityTimeForwardWithoutChangingTheStart(): void
    {
        $this->session()->begin($this->account(), self::NOW);

        $this->session()->touch(self::NOW + 600);

        $this->assertSame(self::NOW, $_SESSION[PortalSession::KEY_STARTED]);
        $this->assertSame(self::NOW + 600, $_SESSION[PortalSession::KEY_ACTIVE]);
    }
}

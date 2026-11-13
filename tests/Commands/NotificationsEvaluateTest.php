<?php

namespace Tests\Commands;

use App\Libraries\Integrations\IntegrationOptions;
use App\Libraries\Integrations\NotificationRules;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\IntegrationFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The command cron runs: what it makes, what it says, that a second run makes nothing new, that it does
 * nothing while the rules are off, that it refuses a day that is not one, and that it says it sent nothing.
 */
class NotificationsEvaluateTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;
    use IntegrationFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->wipeIntegration();
        $this->startSelling();
        $this->switchIntegrationOn(IntegrationOptions::NOTIFICATIONS);
    }

    protected function tearDown(): void
    {
        $this->wipeIntegration();
        $this->stopSelling();

        parent::tearDown();
    }

    /**
     * @param array<string, string|null> $params
     *
     * @return array{int, string}
     */
    private function evaluate(array $params = []): array
    {
        $this->resetStreamFilterBuffer();
        $exit = service('commands')->run('notifications:evaluate', $params);

        return [$exit, $this->getStreamFilterBuffer()];
    }

    public function testRun_RaisesTheNotificationsOfTheActiveRulesAndSaysThatNothingWasSent(): void
    {
        (new NotificationRules())->create('invoice_overdue', 'Late', '1', null);
        $this->invoiceDated('100.00', $this->daysAgo(10));

        [$exit, $output] = $this->evaluate();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('1 new, 0 closed, 1 open', $output);
        $this->assertStringContainsString('Nothing was sent anywhere.', $output);
        $this->assertStringContainsString('rule ', $output);
        $this->assertSame(1, Database::connect()->table('notifications')->countAllResults());
    }

    public function testRun_MakesNothingNewWhenItIsRunAgain(): void
    {
        (new NotificationRules())->create('invoice_overdue', 'Late', '1', null);
        $this->invoiceDated('100.00', $this->daysAgo(10));

        $this->evaluate();
        [$exit, $output] = $this->evaluate();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('0 new, 0 closed, 1 open', $output);
        $this->assertSame(1, Database::connect()->table('notifications')->countAllResults());
    }

    public function testRun_DoesNothingAndSaysSoWhileTheRulesAreOff(): void
    {
        $this->setConfigValue(IntegrationOptions::NOTIFICATIONS, '0');
        (new NotificationRules())->create('invoice_overdue', 'Late', '1', null);
        $this->invoiceDated('100.00', $this->daysAgo(10));

        [$exit, $output] = $this->evaluate();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('switched off', $output);
        $this->assertSame(0, Database::connect()->table('notifications')->countAllResults());
    }

    public function testRun_NamesARuleThatIsSwitchedOffWhileItClosesWhatItRaised(): void
    {
        $rules = new NotificationRules();
        $id    = $rules->create('invoice_overdue', 'Late', '1', null);

        $this->invoiceDated('100.00', $this->daysAgo(10));
        $this->evaluate();
        $rules->setActive($id, false, null);

        [, $output] = $this->evaluate();

        $this->assertStringContainsString('[switched off]', $output);
        $this->assertStringContainsString('0 new, 1 closed, 0 open', $output);
    }

    public function testRun_CountsFromTheDayGivenAndRefusesADayThatIsNotAllowed(): void
    {
        (new NotificationRules())->create('invoice_overdue', 'Late', '1', null);
        $this->invoiceDated('100.00', $this->daysAgo(1), 1);

        [$exit, $output] = $this->evaluate(['date' => date('Y-m-d', strtotime('+2 days'))]);

        $this->assertSame(7, $exit, 'A day after today is refused.');
        $this->assertStringContainsString('not after today', $output);

        [$exit, $output] = $this->evaluate(['date' => 'yesterday']);

        $this->assertSame(7, $exit);
        $this->assertSame(0, Database::connect()->table('notifications')->countAllResults());

        [$exit, $output] = $this->evaluate(['date' => date('Y-m-d')]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('NOTIFICATIONS as of ' . date('Y-m-d'), $output);
    }
}

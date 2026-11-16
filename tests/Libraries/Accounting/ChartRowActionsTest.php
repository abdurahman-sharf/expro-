<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\ChartRowActions;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ChartRowActionsTest extends TestCase
{
    /**
     * @param array<string, int> $flags
     */
    private function account(array $flags = []): object
    {
        return (object) ($flags + [
            'active'              => 1,
            'is_system'           => 0,
            'is_group'            => 0,
            'has_children'        => 0,
            'has_active_children' => 0,
            'used'                => 0,
            'routed'              => 0,
            'stamped'             => 0,
        ]);
    }

    public function testFor_OffersEveryChangeOfAFreeAccountToWhoManagesTheChart(): void
    {
        $this->assertSame(
            [ChartRowActions::EDIT, ChartRowActions::DEACTIVATE, ChartRowActions::DELETE, ChartRowActions::LEDGER],
            ChartRowActions::for($this->account(), true, true),
        );
    }

    public function testFor_OffersNothingToWhoMayNeitherManageNorReadTheStatement(): void
    {
        $this->assertSame([], ChartRowActions::for($this->account(), false, false));
    }

    public function testFor_OffersOnlyTheStatementToWhoMayNotManageTheChart(): void
    {
        $this->assertSame([ChartRowActions::LEDGER], ChartRowActions::for($this->account(), false, true));
    }

    public function testFor_OffersAddSubAccountOnlyOnAnActiveGroup(): void
    {
        $this->assertContains(ChartRowActions::ADD_CHILD, ChartRowActions::for($this->account(['is_group' => 1]), true, false));
        $this->assertNotContains(ChartRowActions::ADD_CHILD, ChartRowActions::for($this->account(), true, false));
        $this->assertNotContains(ChartRowActions::ADD_CHILD, ChartRowActions::for($this->account(['is_group' => 1, 'active' => 0]), true, false));
        $this->assertNotContains(ChartRowActions::ADD_CHILD, ChartRowActions::for($this->account(['is_group' => 1]), false, true));
    }

    public function testFor_OffersNeitherDeactivateNorDeleteForASystemAccount(): void
    {
        $actions = ChartRowActions::for($this->account(['is_system' => 1]), true, true);

        $this->assertSame([ChartRowActions::EDIT, ChartRowActions::LEDGER], $actions);
    }

    public function testFor_OffersNeitherDeactivateNorDeleteForAnAccountARoutePointsAt(): void
    {
        $this->assertSame([ChartRowActions::EDIT], ChartRowActions::for($this->account(['routed' => 1]), true, false));
    }

    public function testFor_OffersNeitherDeactivateNorDeleteForAnAccountOnTheTaxRowsOfSales(): void
    {
        $this->assertSame([ChartRowActions::EDIT], ChartRowActions::for($this->account(['stamped' => 1]), true, false));
    }

    public function testFor_OffersDeactivateButNotDeleteForAnAccountWithJournalLines(): void
    {
        $this->assertSame([ChartRowActions::EDIT, ChartRowActions::DEACTIVATE], ChartRowActions::for($this->account(['used' => 1]), true, false));
    }

    public function testFor_OffersNoDeactivateWhileAChildIsActive(): void
    {
        $actions = ChartRowActions::for($this->account(['is_group' => 1, 'has_children' => 1, 'has_active_children' => 1]), true, false);

        $this->assertSame([ChartRowActions::ADD_CHILD, ChartRowActions::EDIT], $actions);
    }

    public function testFor_OffersDeleteForAGroupWhoseChildrenAreAllGone(): void
    {
        $this->assertContains(ChartRowActions::DELETE, ChartRowActions::for($this->account(['is_group' => 1]), true, false));
        $this->assertNotContains(ChartRowActions::DELETE, ChartRowActions::for($this->account(['is_group' => 1, 'has_children' => 1]), true, false));
    }

    public function testFor_OffersActivateOnlyToAnInactiveAccountWhoseParentIsActive(): void
    {
        $inactive = $this->account(['active' => 0]);

        $this->assertSame([ChartRowActions::EDIT, ChartRowActions::ACTIVATE, ChartRowActions::DELETE], ChartRowActions::for($inactive, true, false));
        $this->assertSame([ChartRowActions::EDIT, ChartRowActions::DELETE], ChartRowActions::for($inactive, true, false, false));
        $this->assertNotContains(ChartRowActions::DEACTIVATE, ChartRowActions::for($inactive, true, false));
    }

    public function testFor_ReadsTheFlagsOfAnAccountThatCarriesNone(): void
    {
        $bare = (object) ['active' => 1, 'is_system' => 0, 'is_group' => 0];

        $this->assertSame([ChartRowActions::EDIT, ChartRowActions::DEACTIVATE, ChartRowActions::DELETE], ChartRowActions::for($bare, true, false));
    }
}

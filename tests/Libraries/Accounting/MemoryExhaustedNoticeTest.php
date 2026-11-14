<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\MemoryExhaustedNotice;
use CodeIgniter\Test\CIUnitTestCase;
use LogicException;

/**
 * The page a screen of the books gives when a request runs out of memory: only for that failure, with the
 * words of the language in use and the status of a request that cannot be answered as asked.
 *
 * @internal
 */
final class MemoryExhaustedNoticeTest extends CIUnitTestCase
{
    public function testAnswer_SaysWhyAndWhatToDoWhenTheRequestRanOutOfMemory(): void
    {
        $sent     = [];
        $answered = MemoryExhaustedNotice::answer(
            ['type' => E_ERROR, 'message' => 'Allowed memory size of 268435456 bytes exhausted (tried to allocate 2097152 bytes)'],
            static function (int $status, string $page) use (&$sent): void {
                $sent = [$status, $page];
            },
        );

        $this->assertTrue($answered);
        $this->assertSame(422, $sent[0]);
        $this->assertStringContainsString('id="report_error"', $sent[1]);
        $this->assertStringContainsString(esc(lang('Accounting.error_report_memory_exhausted')), $sent[1]);
    }

    public function testAnswer_LeavesEveryOtherFailureAndEveryRequestThatEndedWellAlone(): void
    {
        $sent = static function (): void {
            throw new LogicException('Nothing should have been sent.');
        };

        $this->assertFalse(MemoryExhaustedNotice::answer(null, $sent));
        $this->assertFalse(MemoryExhaustedNotice::answer(['type' => E_ERROR, 'message' => 'Call to undefined function nothing()'], $sent));
        $this->assertFalse(MemoryExhaustedNotice::answer(['type' => E_WARNING, 'message' => 'Allowed memory size of 1 bytes exhausted'], $sent));
    }
}

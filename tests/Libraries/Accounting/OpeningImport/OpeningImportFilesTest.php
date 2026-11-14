<?php

namespace Tests\Libraries\Accounting\OpeningImport;

use App\Libraries\Accounting\OpeningImport\OpeningImportFiles;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Keeping the file of a preview until it is approved, under the fingerprint of its own text.
 *
 * @internal
 */
final class OpeningImportFilesTest extends CIUnitTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/opening_import_files_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function testStore_KeepsTheTextUnderTheHashOfItAndReadGivesItBack(): void
    {
        $files = new OpeningImportFiles($this->directory);
        $token = $files->store("party,amount\n1,5\n");

        $this->assertSame(hash('sha256', "party,amount\n1,5\n"), $token);
        $this->assertSame("party,amount\n1,5\n", $files->read($token));
        $this->assertSame($token, $files->store("party,amount\n1,5\n"));
    }

    public function testRead_AnswersNothingForAnUnknownTokenAndForAnythingThatIsNotAHash(): void
    {
        $files = new OpeningImportFiles($this->directory);

        $this->assertNull($files->read(str_repeat('a', 64)));
        $this->assertNull($files->read('../../.env'));
        $this->assertNull($files->read(str_repeat('A', 64)));
        $this->assertNull($files->read(''));
    }

    public function testStore_KeepsTheFileApartFromTheOtherFilesOfTheUploadsAndNotReadableByOthers(): void
    {
        $files = new OpeningImportFiles($this->directory);
        $token = $files->store('x');

        $this->assertSame('0640', substr(sprintf('%o', fileperms($this->directory . '/' . $token . '.csv')), -4));
    }

    public function testStore_RemovesTheFilesOfMoreThanAWeekAgoAndKeepsTheOthers(): void
    {
        $files = new OpeningImportFiles($this->directory);
        $old   = $files->store('old');
        $new   = $files->store('new');

        touch($this->directory . '/' . $old . '.csv', time() - 8 * 86400);

        $files->store('another');

        $this->assertNull($files->read($old));
        $this->assertSame('new', $files->read($new));
    }

    public function testForget_RemovesTheFile(): void
    {
        $files = new OpeningImportFiles($this->directory);
        $token = $files->store('x');

        $files->forget($token);
        $files->forget('not a token');

        $this->assertNull($files->read($token));
    }
}

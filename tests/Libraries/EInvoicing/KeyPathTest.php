<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\EInvoicing\EInvoiceError;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\KeyPath;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EInvoiceFixtureTrait;

class KeyPathTest extends CIUnitTestCase
{
    use EInvoiceFixtureTrait;

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectories();

        parent::tearDown();
    }

    private function refusal(string $path): EInvoiceException
    {
        try {
            KeyPath::check($path);
        } catch (EInvoiceException $failure) {
            return $failure;
        }

        $this->fail('The path ' . $path . ' was accepted.');
    }

    public function testCheck_AcceptsAFileOutsideTheApplication(): void
    {
        $directory = $this->temporaryDirectory();

        file_put_contents($directory . '/key.pem', 'x');

        $this->assertSame(realpath($directory . '/key.pem'), KeyPath::check($directory . '/key.pem'));
    }

    public function testCheck_AcceptsAFileThatDoesNotExistYetInADirectoryOutsideTheApplication(): void
    {
        $directory = $this->temporaryDirectory();

        $this->assertSame(realpath($directory) . '/later/key.pem', KeyPath::check($directory . '/later/key.pem'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function insidePaths(): array
    {
        return [
            'the project root'    => [ROOTPATH . 'secret.pem'],
            'the code'            => [APPPATH . 'Config/secret.pem'],
            'the web root'        => [FCPATH . 'secret.pem'],
            'the writable folder' => [WRITEPATH . 'secret.pem'],
            'the root itself'     => [rtrim(ROOTPATH, '/')],
            'a folder that does not exist yet in the repository' => [ROOTPATH . 'keys/not/there/yet.pem'],
        ];
    }

    #[DataProvider('insidePaths')]
    public function testCheck_RejectsAPathInsideTheApplicationDirectory(string $path): void
    {
        $this->assertSame(EInvoiceError::KeyPathUnsafe, $this->refusal($path)->error);
    }

    public function testCheck_RejectsAPathThatClimbsBackIntoTheApplicationWithAParentSegment(): void
    {
        $directory = $this->temporaryDirectory();

        $this->assertSame(EInvoiceError::KeyPathUnsafe, $this->refusal($directory . '/../../../../../../..' . ROOTPATH . 'secret.pem')->error);
    }

    public function testCheck_RejectsALinkThatLeadsIntoTheApplication(): void
    {
        $directory = $this->temporaryDirectory();
        $link      = $directory . '/link.pem';

        symlink(ROOTPATH . 'composer.json', $link);

        try {
            $this->assertSame(EInvoiceError::KeyPathUnsafe, $this->refusal($link)->error);
        } finally {
            unlink($link);
        }
    }

    public function testCheck_RejectsADirectoryLinkThatLeadsIntoTheApplicationEvenForAFileThatDoesNotExist(): void
    {
        $directory = $this->temporaryDirectory();
        $link      = $directory . '/inside';

        symlink(WRITEPATH, $link);

        try {
            $this->assertSame(EInvoiceError::KeyPathUnsafe, $this->refusal($link . '/new_key.pem')->error);
        } finally {
            unlink($link);
        }
    }

    public function testCheck_RejectsARelativePathAnEmptyPathAndABytePath(): void
    {
        $this->assertSame(EInvoiceError::KeyPathUnsafe, $this->refusal('keys/key.pem')->error);
        $this->assertSame(EInvoiceError::KeyPathUnsafe, $this->refusal('')->error);
        $this->assertSame(EInvoiceError::KeyPathUnsafe, $this->refusal("/tmp/key\0.pem")->error);
    }

    public function testCheck_RejectsAMissingFolderWithAParentSegmentInTheRemainder(): void
    {
        $directory = $this->temporaryDirectory();

        $this->assertSame(EInvoiceError::KeyPathUnsafe, $this->refusal($directory . '/missing/../key.pem')->error);
    }

    public function testCheck_JudgesAgainstTheDirectoriesItIsGivenWhenItIsGivenSome(): void
    {
        $directory = $this->temporaryDirectory();

        file_put_contents($directory . '/key.pem', 'x');

        $this->expectException(EInvoiceException::class);

        KeyPath::check($directory . '/key.pem', [$directory]);
    }

    public function testCheck_AcceptsADirectoryWhoseNameOnlyBeginsLikeAProtectedOne(): void
    {
        $protected = $this->temporaryDirectory();
        $sibling   = $protected . '_keys';

        mkdir($sibling, 0700);
        file_put_contents($sibling . '/key.pem', 'x');

        try {
            $this->assertSame(realpath($sibling . '/key.pem'), KeyPath::check($sibling . '/key.pem', [$protected]));
        } finally {
            unlink($sibling . '/key.pem');
            rmdir($sibling);
        }
    }
}

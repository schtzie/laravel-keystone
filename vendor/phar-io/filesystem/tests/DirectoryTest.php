<?php
namespace PharIo\FileSystem;

use PHPUnit\Framework\TestCase;

/**
 * @covers \PharIo\FileSystem\Directory
 * @uses   \PharIo\FileSystem\DirectoryException
 */
class DirectoryTest extends TestCase {

    private $testDir;

    protected function setUp(): void {
        $this->testDir = __DIR__ . '/fixtures/directory';
        if (file_exists(sys_get_temp_dir() . '/test')) {
            rmdir(sys_get_temp_dir() . '/test');
        }
    }

    public function testCanBeConvertedToString() {
        $this->assertEquals(realpath($this->testDir), (new Directory($this->testDir))->asString());
    }

    public function testDirectoryIsCreatedWhenMissing() {
        $path = sys_get_temp_dir() . '/test';
        $directory = new Directory($path);
        $this->assertFalse($directory->exists());

        $directory->ensureExists(0770);
        $this->assertTrue($directory->exists());
        $this->assertFileExists($path);
        $this->assertEquals('0770', substr(sprintf('%o', fileperms($path)), -4));
        rmdir($path);
    }

    public function testTryingToInstantiateOnFileThrowsException() {
        $this->expectException(DirectoryException::class);
        $this->expectExceptionCode(DirectoryException::InvalidType);
        (new Directory($this->testDir . '/file'));
    }

    /**
     * @uses \PharIo\FileSystem\Filename
     */
    public function testRequestingFileFromDirectoryReturnsFilenameInstance() {
        $this->assertInstanceOf(
            Filename::class,
            (new Directory($this->testDir))->file('file')
        );
    }

    public function testRequestingChildFromDirectoryReturnsNewDirectoryInstance() {
        $child = (new Directory($this->testDir))->child('child');
        $this->assertInstanceOf(
            Directory::class,
            $child
        );
        $this->assertEquals('child', basename($child->asString()));
    }

    public function testThrowsExceptionInvalidMode() {
        $this->expectException(DirectoryException::class);
        $this->expectExceptionCode(DirectoryException::InvalidMode);
        (new Directory('/'))->ensureExists(9999);
        restore_error_handler();
    }

    public function testThrowsExceptionIfGivenPathCannotBeCreated() {
        $this->expectException(DirectoryException::class);
        $this->expectExceptionCode(DirectoryException::CreateFailed);
        (new Directory('/arbitrary/non/exisiting/path'))->ensureExists(0777);
    }

    /**
     * @dataProvider relativePathTestDataProvider
     *
     * @param $directory
     * @param $otherDirectory
     * @param $expected
     */
    public function testReturnsExpectedRelativePath($directory, $otherDirectory, $expected) {
        $directory = new Directory($directory);
        $otherDirectory = new Directory($otherDirectory);

        $this->assertEquals($expected, $directory->getRelativePathTo($otherDirectory));
    }

    public static function relativePathTestDataProvider() {
        return [
            [__DIR__ . '/fixtures/directory', __DIR__ . '/fixtures', './directory/'],
            [__DIR__ . '/fixtures', __DIR__ . '/fixtures/directory', '../']
        ];
    }
}

<?php
namespace PharIo\FileSystem;

use PHPUnit\Framework\TestCase;

/**
 * @covers \PharIo\FileSystem\Filename
 */
class FilenameTest extends TestCase {

    public function testCanBeConvertedToString() {
        $this->assertEquals(
            'abc',
            (new Filename('abc'))->asString()
        );
    }

    public function testFileExistsReturnsFalseOnMissingFile() {
        $name = new Filename('/does/not/exist');
        $this->assertFalse($name->exists());
    }

    public function testFileExistsReturnsTrueOnExistingFile() {
        $name = new Filename(__FILE__);
        $this->assertTrue($name->exists());
    }

    public function testReadThrowsExceptionIfFileDoesNotExist() {
        $name = new Filename('/does/not/exist');
        $this->expectException(\RuntimeException::class);
        $name->read();
    }

    public function testReadReturnsExpectedFile() {
        $name = new Filename(__DIR__ . '/fixtures/file.txt');
        $expectedFile = new File($name, 'foo');
        $this->assertEquals($expectedFile, $name->read());
    }

    public function testReturnsExpectedFilenameWithoutExtension() {
        $filename = new Filename(__DIR__ . '/foo/bar.txt');
        $expected = new Filename(__DIR__ . '/foo/bar');

        $this->assertEquals($expected, $filename->withoutExtension());
    }

    public function testIsWritable() {
        $filename = new Filename(__DIR__ . '/fixtures/writable/bar.txt');
        $linkFilename = new Filename(__DIR__ . '/fixtures/writable/link');

        $this->assertFalse($filename->exists());
        $this->assertTrue($filename->isWritable());

        try {
            touch($filename->asString());
            $this->assertTrue($filename->exists());
            $this->assertTrue($filename->isWritable());

            // Make file non writable
            chmod($filename->asString(), 0000);
            $this->assertFalse($filename->isWritable());

            // Create link to non writable file
            link($filename->asString(), $linkFilename->asString());
            $this->assertFalse($linkFilename->isWritable());

            // Make file writable
            chmod($filename->asString(), 0644);
            $this->assertTrue($linkFilename->isWritable());
        } finally {
            unlink($filename->asString());
            unlink($linkFilename->asString());
        }
    }

    public function testIsLink() {
        $filename = new Filename(__DIR__ . '/fixtures/writable/bar.txt');
        $linkFilename = new Filename(__DIR__ . '/fixtures/writable/link');

        try {
            touch($filename->asString());
            $this->assertTrue($filename->exists());

            symlink($filename->asString(), $linkFilename->asString());
            $this->assertTrue($linkFilename->isLink());

        } finally {
            unlink($filename->asString());
            unlink($linkFilename->asString());
        }
    }

}

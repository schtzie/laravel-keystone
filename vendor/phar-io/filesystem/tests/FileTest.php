<?php
namespace PharIo\FileSystem;

use PHPUnit\Framework\TestCase;

/**
 * @covers \PharIo\FileSystem\File
 */
class FileTest extends TestCase {


    public function testFilename() {
        $filename = new Filename('foo.phar');
        $file = new File($filename, 'bar');
        $this->assertSame($filename, $file->getFilename());
    }

    public function testContent() {
        $file = new File(new Filename('foo.phar'), 'bar');
        $this->assertSame('bar', $file->getContent());
    }

    /**
     * @uses \PharIo\FileSystem\Filename
     */
    public function testSaveAs() {
        $target = sys_get_temp_dir() . '/testfile';
        $file = new File(new Filename('foo.phar'), 'bar');
        $file->saveAs(new Filename($target));

        $this->assertFileExists($target);
        $this->assertSame('bar', file_get_contents($target));
        unlink($target);
    }
}




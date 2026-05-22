<?php declare(strict_types=1);
namespace PharIo\FileSystem;

class File {

    /** @var Filename */
    private $filename;

    /** @var string */
    private $content;

    public function __construct(Filename $filename, string $content) {
        $this->filename = $filename;
        $this->content = $content;
    }

    public function getFilename(): Filename {
        return $this->filename;
    }

    public function saveAs(Filename $filename) {
        file_put_contents($filename->asString(), $this->getContent());
    }

    public function getContent(): string {
        return $this->content;
    }
}

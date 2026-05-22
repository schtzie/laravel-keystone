<?php declare(strict_types = 1);
namespace PharIo\FileSystem;

class Filename {

    /** @var string */
    private $name;

    public function __construct(string $name) {
        $this->name = $name;
    }

    public function asString(): string {
        return $this->name;
    }

    public function exists(): bool {
        return file_exists($this->name);
    }

    public function isLink(): bool {
        return is_link($this->name);
    }

    public function isWritable(): bool {
        if (is_writable($this->asString())) {
            return true;
        }

        if (is_link($this->asString())) {
            // file_exists follows symlinks and returns false if the linked file does not exist
            // so we need to check first if the file is a link
            return false;
        }

        if (file_exists($this->asString())) {
            return false;
        }

        return $this->getDirectory()->isWritable();
    }

    public function isExecutable(): bool {
        return is_executable($this->name);
    }

    public function read(): File {
        if (!$this->exists()) {
            throw new \RuntimeException('Cannot read - File does not (yet?) exist');
        }
        return new File($this, file_get_contents($this->asString()));
    }

    public function withAbsolutePath(): Filename {
        return $this->getDirectory()->withAbsolutePath()->file($this->getBasename());
    }

    public function getDirectory(): Directory {
        return new Directory(dirname($this->asString()));
    }

    public function getRelativePathTo(Directory $directory): Filename {
        return new Filename($this->getDirectory()->getRelativePathTo($directory) . $this->getBasename());
    }

    public function putContent(string $content): int {
        return file_put_contents($this->asString(), $content);
    }

    public function delete(): bool {
        return unlink($this->asString());
    }

    /**
     * @throws FilenameException
     */
    public function renameTo(string $newName): Filename {
        $newNameFile = $this->getDirectory()->file($newName);
        $result = @rename($this->asString(), $newNameFile->asString());
        if ($result === false) {
            $lastError = error_get_last();
            $nativeError = new \RuntimeException(
                sprintf('%s (line %d): %s', $lastError['file'], $lastError['line'], $lastError['message']),
                $lastError['type']
            );
            throw new FilenameException('Unable to rename the file.', 0, $nativeError);
        }
        return $newNameFile;
    }

    /**
     * @return bool
     */
    public function isOlderThan(\DateTimeImmutable $date): bool {
        return $this->getLastModified()->isOlderThan($date);
    }

    public function withoutExtension(): Filename {
        $pathinfo = pathinfo($this->asString());

        return new Filename($pathinfo['dirname'] . '/' . $pathinfo['filename']);
    }

    private function getLastModified(): LastModifiedDate {
        return LastModifiedDate::fromTimestamp(filemtime($this->asString()));
    }

    private function getBasename(): string {
        return pathinfo($this->asString(), PATHINFO_BASENAME);
    }

}

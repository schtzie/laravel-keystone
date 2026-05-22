<?php declare(strict_types = 1);
namespace PharIo\FileSystem;

class Directory {

    /** @var string */
    private $path;

    public function __construct(string $path) {
        $this->ensureIsDirectory($path);
        $this->path = $path;
    }

    /*
     * Taken from http://stackoverflow.com/questions/2637945/getting-relative-path-from-absolute-path-in-php#comment18071708_2637945
     * Credits go to http://stackoverflow.com/users/208809/gordon
     */
    public function getRelativePathTo(Directory $directory): string {
        $to = $this->asString();
        $from = $directory->asString();

        // some compatibility fixes for Windows paths
        $from = is_dir($from) ? rtrim($from, '\/') . '/' : $from;
        $to = is_dir($to) ? rtrim($to, '\/') . '/' : $to;
        $from = str_replace('\\', '/', $from);
        $to = str_replace('\\', '/', $to);

        $from = explode('/', $from);
        $to = explode('/', $to);
        $relPath = $to;

        foreach ($from as $depth => $dir) {
            // find first non-matching dir
            if ($dir === $to[$depth]) {
                // ignore this directory
                array_shift($relPath);
            } else {
                // get number of remaining dirs to $from
                $remaining = count($from) - $depth;
                if ($remaining > 1) {
                    // add traversals up to first matching dir
                    $padLength = (count($relPath) + $remaining - 1) * -1;
                    $relPath = array_pad($relPath, $padLength, '..');
                    break;
                }

                $relPath[0] = './' . $relPath[0];
            }
        }
        return implode('/', $relPath);
    }

    public function exists(): bool {
        clearstatcache(true, $this->path);
        return file_exists($this->path);
    }

    /**
     * @throws DirectoryException
     */
    public function ensureExists(int $mode = 0755): void {
        $this->ensureValidMode($mode);

        try {
            if (!$this->exists()) {
                if (!@mkdir($this->path, $mode, true)) {
                    throw new DirectoryException(
                        sprintf('Creating directory "%s" failed.', $this->path),
                        DirectoryException::CreateFailed
                    );
                }
            }
            clearstatcache(true, $this->path);

            if ((fileperms($this->path) & 0777) !== $mode) {
                chmod($this->path, $mode);
            }
        } catch (\ErrorException $e) {
            throw new DirectoryException(
                sprintf('Creating directory "%s" failed.', $this->path),
                DirectoryException::CreateFailed,
                $e
            );
        }
    }

    public function child(string $child): Directory {
        return new Directory($this->path . DIRECTORY_SEPARATOR . $child);
    }

    public function hasChild(string $child): bool {
        return file_exists($this->path . DIRECTORY_SEPARATOR . $child);
    }

    public function file(string $filename): Filename {
        return new Filename($this->path . DIRECTORY_SEPARATOR . $filename);
    }

    public function asString(): string {
        return $this->path;
    }

    public function withAbsolutePath(): Directory {
        return new Directory(realpath($this->asString()));
    }

    public function isWritable(): bool {
        return is_writable($this->path);
    }

    /**
     * @throws DirectoryException
     */
    private function ensureIsDirectory(string $path) {
        if (!file_exists($path) || is_dir($path)) {
            return;
        }

        throw new DirectoryException(
            sprintf('Path %s exists but is not a directory', $path),
            DirectoryException::InvalidType
        );
    }

    private function ensureValidMode(int $mode) {
        if ($mode < 0 || $mode > 777) {
            throw new DirectoryException(
                sprintf('Mode %d is not valid', $mode),
                DirectoryException::InvalidMode
            );
        }
    }
}

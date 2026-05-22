<?php declare(strict_types = 1);
namespace PharIo\FileSystem;

class LastModifiedDate {
    /**
     * @var \DateTimeImmutable
     */
    private $dateTime;

    public function __construct(\DateTimeImmutable $dateTime) {
        $this->dateTime = $dateTime;
    }

    /**
     * @throws FilenameException
     */
    public static function fromTimestamp(int $timestamp): LastModifiedDate {
        $dateTime = \DateTimeImmutable::createFromFormat('U',   (string)$timestamp);
        if (!$dateTime) {
            throw new FilenameException('Invalid last modified date');
        }

        return new self($dateTime);
    }

    public function isOlderThan(\DateTimeImmutable $date): bool {
        return $this->dateTime < $date;
    }
}

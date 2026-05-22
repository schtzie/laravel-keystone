<?php
namespace PharIo\FileSystem;

use PHPUnit\Framework\TestCase;

/**
 * @covers \PharIo\FileSystem\LastModifiedDate
 */
class LastModifiedDateTest extends TestCase
{
    public function testCreatesInstanceFromTimestamp() {
        $expected = new LastModifiedDate(
            new \DateTimeImmutable('25.04.2017 10:31:55')
        );

        $actual = LastModifiedDate::fromTimestamp('1493116315');

        $this->assertEquals($expected, $actual);
    }

    /**
     * @dataProvider isOlderTestProvider
     *
     * @param string $fileTime
     * @param string $olderThan
     * @param bool $expectedReturnValue
     */
    public function testIsOlderThanReturnsExpectedValue(
        $fileTime, $olderThan, $expectedReturnValue
    ) {
        $date = new LastModifiedDate(new \DateTimeImmutable($fileTime));

        $this->assertSame($expectedReturnValue, $date->isOlderThan(new \DateTimeImmutable($olderThan)));
    }

    /**
     * @return array
     */
    public function isOlderTestProvider() {
        return [
            ['25.04.2017 12:23:12', '25.04.2017 10:12:00', false],
            ['23.04.2017 12:23:12', '25.04.2017 23:12:00', true],
            ['23.04.2015 12:23:12', '25.04.2017 23:12:00', true]
        ];
    }

}

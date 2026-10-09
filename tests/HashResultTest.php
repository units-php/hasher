<?php

declare(strict_types=1);

namespace Units\Hasher\Tests;

use DateTimeImmutable;
use Error;
use JsonSerializable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Units\Hasher\HashResult;

/**
 * What a digest gives back.
 *
 * A shape, not a unit: it has no identity and no version, and it must not be a
 * Patterns\ValueObject subclass either - it needs neither the base class nor the
 * dependency. It is read far more often than it is built, so the fields are
 * public and readonly.
 */
final class HashResultTest extends TestCase
{
    private function hashResult(): HashResult
    {
        return new HashResult('abc123', 'sha256', 'hex', 'salt', 10000);
    }

    public function testItIsAPlainSerializableShape(): void
    {
        $class = new ReflectionClass(HashResult::class);

        $this->assertInstanceOf(JsonSerializable::class, $this->hashResult());
        $this->assertTrue($class->isFinal());
        $this->assertFalse(
            $class->getParentClass(),
            'a result needs neither equals() nor the props array - so neither the base class nor the dependency'
        );
    }

    public function testEveryFieldIsPublicAndReadonly(): void
    {
        $expected = ['hash', 'algorithm', 'encoding', 'salt', 'iterations', 'timestamp'];
        sort($expected);

        $properties = [];
        foreach ((new ReflectionClass(HashResult::class))->getProperties() as $property) {
            $properties[] = $property->getName();

            $this->assertTrue($property->isPublic(), $property->getName() . ' is read, so it is public');
            $this->assertTrue($property->isReadOnly(), $property->getName() . ' must be readonly');
        }

        sort($properties);

        $this->assertSame($expected, $properties);
    }

    public function testItIsImmutable(): void
    {
        $this->expectException(Error::class);

        /** @phpstan-ignore-next-line deliberately writing to a readonly property */
        $this->hashResult()->hash = 'other';
    }

    public function testItStringifiesToTheDigestItself(): void
    {
        $this->assertSame('abc123', (string) $this->hashResult());
        $this->assertSame('abc123', '' . $this->hashResult());
    }

    public function testItCarriesWhatWasAskedForAndWhatWasUsed(): void
    {
        $result = $this->hashResult();

        $this->assertSame('abc123', $result->hash);
        $this->assertSame('sha256', $result->algorithm);
        $this->assertSame('hex', $result->encoding);
        $this->assertSame('salt', $result->salt);
        $this->assertSame(10000, $result->iterations);
    }

    public function testSaltAndIterationsAreOptional(): void
    {
        $result = new HashResult('abc123', 'sha256', 'hex');

        $this->assertNull($result->salt);
        $this->assertNull($result->iterations);
    }

    public function testTheTimestampDefaultsToNow(): void
    {
        $before = new DateTimeImmutable();
        $result = new HashResult('abc123', 'sha256', 'hex');
        $after = new DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $result->timestamp);
        $this->assertLessThanOrEqual($after, $result->timestamp);
    }

    public function testTheTimestampCanBeInjected(): void
    {
        $when = new DateTimeImmutable('2026-10-09T12:00:00+00:00');

        $this->assertSame($when, (new HashResult('abc123', 'sha256', 'hex', null, null, $when))->timestamp);
    }

    public function testCreateIsAPlainFactory(): void
    {
        $this->assertSame(
            (new HashResult('abc123', 'sha256', 'hex'))->toArray()['hash'],
            HashResult::create('abc123', 'sha256', 'hex')->hash
        );

        $this->assertNull(HashResult::create('abc123', 'sha256', 'hex')->iterations);
    }

    // -----------------------------------------------------------------------
    // Serialization
    // -----------------------------------------------------------------------

    public function testToArrayHasTheWholeShape(): void
    {
        $when = new DateTimeImmutable('2026-10-09T12:00:00+00:00');

        $this->assertSame([
            'hash'       => 'abc123',
            'algorithm'  => 'sha256',
            'encoding'   => 'hex',
            'salt'       => 'salt',
            'iterations' => 10000,
            'timestamp'  => '2026-10-09T12:00:00+00:00',
        ], (new HashResult('abc123', 'sha256', 'hex', 'salt', 10000, $when))->toArray());
    }

    public function testItSerializesToTheSameShape(): void
    {
        $result = $this->hashResult();

        $this->assertSame($result->toArray(), $result->jsonSerialize());
        $this->assertSame($result->toArray(), json_decode((string) json_encode($result), true));
    }

    public function testTheTimestampTravelsAsAnIsoString(): void
    {
        $result = new HashResult('abc123', 'sha256', 'hex');

        $this->assertSame($result->timestamp->format(DATE_ATOM), $result->toArray()['timestamp']);
    }
}

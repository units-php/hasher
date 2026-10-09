<?php

declare(strict_types=1);

namespace Units\Hasher\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Patterns\IUnit;
use Patterns\IUnitSchema;
use ReflectionClass;
use ReflectionMethod;
use Units\Hasher\Hasher;
use Units\Hasher\HasherSchema;
use Units\Hasher\HashResult;

/**
 * What the unit promises: identity, construction, and the digests it makes.
 *
 * Every expected digest here is computed with PHP's own hash()/hash_hmac(),
 * never pasted in as a literal - so the suite says "this is the native
 * function", not "this is what the code happened to print once".
 */
final class HasherContractTest extends TestCase
{
    private function hasher(string $algorithm = 'sha256', string $encoding = 'hex'): Hasher
    {
        return Hasher::create(['algorithm' => $algorithm, 'encoding' => $encoding]);
    }

    // -----------------------------------------------------------------------
    // The unit contract
    // -----------------------------------------------------------------------

    public function testTheHasherIsAUnit(): void
    {
        $this->assertTrue(
            (new ReflectionClass(Hasher::class))->implementsInterface(IUnit::class),
            'Hasher must satisfy the patterns/unit contract'
        );
    }

    public function testTheUnitIsFinalAndNeedsNoBaseClass(): void
    {
        $class = new ReflectionClass(Hasher::class);

        $this->assertTrue($class->isFinal());
        $this->assertFalse($class->getParentClass(), 'a unit composes the pattern, it does not inherit it');
    }

    public function testTheUnitTrioIsPublicAndTyped(): void
    {
        $create = new ReflectionMethod(Hasher::class, 'create');
        $this->assertTrue($create->isPublic());
        $this->assertTrue($create->isStatic());

        $dna = new ReflectionMethod(Hasher::class, 'dna');
        $this->assertTrue($dna->isPublic());
        $this->assertFalse($dna->isStatic());
        $this->assertSame(IUnitSchema::class, (string) $dna->getReturnType());

        $whoami = new ReflectionMethod(Hasher::class, 'whoami');
        $this->assertTrue($whoami->isPublic());
        $this->assertSame('string', (string) $whoami->getReturnType());
    }

    public function testCreateIsTheOnlyWayIn(): void
    {
        $constructor = (new ReflectionClass(Hasher::class))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate(), 'a unit is built through create(), never by hand');
    }

    // -----------------------------------------------------------------------
    // create()
    // -----------------------------------------------------------------------

    public function testCreateDefaultsToSha256InHex(): void
    {
        $hasher = Hasher::create();

        $this->assertSame('sha256', $hasher->algorithm());
        $this->assertSame('hex', $hasher->encoding());
    }

    public function testCreateRefusesAnUnknownAlgorithm(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unsupported algorithm: sha3-256/');

        Hasher::create(['algorithm' => 'sha3-256']);
    }

    public function testCreateRefusesAnUnknownEncoding(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unsupported encoding: base85/');

        Hasher::create(['encoding' => 'base85']);
    }

    public function testCreateIgnoresKeysItDoesNotKnow(): void
    {
        $hasher = Hasher::create(['algorithm' => 'sha512', 'backend' => 'openssl', 'ttl' => 5]);

        $this->assertSame('sha512', $hasher->algorithm());
    }

    public function testCreateKeepsTheDnaItIsGiven(): void
    {
        $dna = HasherSchema::create(['id' => 'custom-hasher', 'version' => '9.9.9']);

        $hasher = Hasher::create(['dna' => $dna->toArray()]);

        $this->assertSame('custom-hasher', $hasher->dna()->id());
        $this->assertSame('custom-hasher v9.9.9 - sha256/hex', $hasher->whoami());
    }

    public function testCreateDefaultsTheDnaToTheUnitsOwnSchema(): void
    {
        $this->assertSame(Hasher::schema()->toArray(), Hasher::create()->dna()->toArray());
    }

    // -----------------------------------------------------------------------
    // Identity
    // -----------------------------------------------------------------------

    public function testTheDnaIsTheUnitsOwnSchemaClass(): void
    {
        $dna = Hasher::create()->dna();

        $this->assertSame(HasherSchema::class, $dna::class);
        $this->assertInstanceOf(IUnitSchema::class, $dna);
    }

    public function testTheDnaNamesAndVersionsTheUnit(): void
    {
        $dna = Hasher::schema();

        $this->assertSame('hasher', $dna->id());
        $this->assertSame(Hasher::VERSION, $dna->version());
        $this->assertStringContainsString(Hasher::VERSION, $dna->label());
    }

    public function testSchemaNeedsNoInstance(): void
    {
        $this->assertTrue((new ReflectionMethod(Hasher::class, 'schema'))->isStatic());
        $this->assertSame('hasher', Hasher::schema()->id());
    }

    public function testTheLedgerRecordsOnlyReleasedVersions(): void
    {
        $versions = Hasher::schema()->versions();

        $this->assertNotEmpty($versions, 'provenance needs a ledger');
        $this->assertSame(
            [Hasher::VERSION],
            array_column($versions, 'version'),
            'the ledger must not claim versions that never shipped'
        );
        $this->assertNotSame('', (string) $versions[0]['notes']);
    }

    public function testTheDnaRoundTripsThroughAnArray(): void
    {
        $dna = Hasher::schema();

        $this->assertSame($dna->toArray(), HasherSchema::create($dna->toArray())->toArray());
    }

    // -----------------------------------------------------------------------
    // The digest is PHP's digest
    // -----------------------------------------------------------------------

    public function testHashIsPhpHashInHex(): void
    {
        $this->assertSame(hash('sha256', 'hello'), $this->hasher()->hash('hello')->hash);
    }

    public function testEveryAdvertisedAlgorithmIsHonestAboutThisBuild(): void
    {
        $phpNames = [
            'sha256'    => 'sha256',
            'sha512'    => 'sha512',
            'sha1'      => 'sha1',
            'md5'       => 'md5',
            'blake2b'   => 'blake2b512',
            'ripemd160' => 'ripemd160',
            'sha3-512'  => 'sha3-512',
        ];

        $hasher = $this->hasher();

        $this->assertSame(array_keys($phpNames), $hasher->algorithms());

        foreach ($phpNames as $algorithm => $phpName) {
            $available = in_array($phpName, hash_algos(), true);

            $this->assertSame($available, $hasher->supports($algorithm), $algorithm . ' support must match this build');

            if ($available) {
                $this->assertSame(
                    hash($phpName, 'x'),
                    $hasher->hash('x', ['algorithm' => $algorithm])->hash,
                    $algorithm . ' must be PHP\'s ' . $phpName
                );

                continue;
            }

            try {
                $hasher->hash('x', ['algorithm' => $algorithm]);
                $this->fail($algorithm . ' is not in this build, so it must refuse to hash');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSupportIsCheckedAgainstThisBuild(): void
    {
        $hasher = $this->hasher();

        $this->assertTrue($hasher->supports('sha256'));
        $this->assertFalse($hasher->supports('sha3-256'));
        $this->assertFalse($hasher->supports(''));
    }

    public function testHashCanChangeAlgorithmAndEncodingPerCall(): void
    {
        $result = $this->hasher()->hash('hello', ['algorithm' => 'sha512', 'encoding' => 'base64']);

        $this->assertSame('sha512', $result->algorithm);
        $this->assertSame('base64', $result->encoding);
        $this->assertSame(base64_encode((string) hex2bin((string) hash('sha512', 'hello'))), $result->hash);
    }

    public function testHashRefusesAnUnknownAlgorithmPerCall(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->hasher()->hash('hello', ['algorithm' => 'sha3-256']);
    }

    public function testTheSameInputAlwaysGivesTheSameDigest(): void
    {
        $hasher = $this->hasher();

        $this->assertSame($hasher->hash('hello')->hash, $hasher->hash('hello')->hash);
        $this->assertNotSame($hasher->hash('hello')->hash, $hasher->hash('Hello')->hash);
        $this->assertNotSame($hasher->hash('hello')->hash, $hasher->hash('hello ')->hash);
    }

    // -----------------------------------------------------------------------
    // Encodings
    // -----------------------------------------------------------------------

    public function testEveryAdvertisedEncodingMatchesTheRawDigest(): void
    {
        $hex = (string) hash('sha256', 'hello');
        $raw = (string) hex2bin($hex);

        $this->assertSame($hex, $this->hasher('sha256', 'hex')->hash('hello')->hash);
        $this->assertSame(base64_encode($raw), $this->hasher('sha256', 'base64')->hash('hello')->hash);
        $this->assertSame(
            rtrim(strtr(base64_encode($raw), '+/', '-_'), '='),
            $this->hasher('sha256', 'base64url')->hash('hello')->hash,
        );
        $this->assertSame($raw, $this->hasher('sha256', 'binary')->hash('hello')->hash);
    }

    public function testBase64UrlCarriesNoPadding(): void
    {
        $encoded = $this->hasher('sha256', 'base64url')->hash('hello')->hash;

        $this->assertStringNotContainsString('=', $encoded);
        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
    }

    // -----------------------------------------------------------------------
    // Salt and iterations
    // -----------------------------------------------------------------------

    public function testSaltIsHashedInFrontOfTheData(): void
    {
        $result = $this->hasher()->hash('hello', ['salt' => 'pepper-corner']);

        $this->assertSame(hash('sha256', 'pepper-corner' . 'hello'), $result->hash);
        $this->assertSame('pepper-corner', $result->salt);
    }

    public function testIterationsRehashTheDigest(): void
    {
        $expected = hash('sha256', 'hello');

        for ($i = 1; $i < 3; $i++) {
            $expected = hash('sha256', $expected);
        }

        $result = $this->hasher()->hash('hello', ['iterations' => 3]);

        $this->assertSame($expected, $result->hash);
        $this->assertSame(3, $result->iterations);
    }

    public function testSingleIterationIsReportedAsNone(): void
    {
        $this->assertNull($this->hasher()->hash('hello')->iterations);
        $this->assertNull($this->hasher()->hash('hello', ['iterations' => 1])->iterations);
    }

    public function testSaltIsRandomEachTime(): void
    {
        $hasher = $this->hasher();

        $first = $hasher->salt();
        $second = $hasher->salt();

        $this->assertNotSame($first, $second);
        $this->assertSame(32, strlen($first), '16 random bytes, hex encoded');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first);
    }

    // -----------------------------------------------------------------------
    // HMAC
    // -----------------------------------------------------------------------

    public function testHmacIsPhpHashHmac(): void
    {
        $this->assertSame(
            hash_hmac('sha256', 'payload', 'secret'),
            $this->hasher()->hmac('payload', 'secret')->hash,
        );
    }

    public function testHmacHonoursAlgorithmAndEncoding(): void
    {
        $result = $this->hasher()->hmac('payload', 'secret', ['algorithm' => 'sha512', 'encoding' => 'base64url']);

        $expected = rtrim(strtr(base64_encode((string) hex2bin((string) hash_hmac('sha512', 'payload', 'secret'))), '+/', '-_'), '=');

        $this->assertSame($expected, $result->hash);
        $this->assertSame('sha512', $result->algorithm);
        $this->assertSame('base64url', $result->encoding);
    }

    public function testHmacDependsOnTheSecret(): void
    {
        $hasher = $this->hasher();

        $this->assertNotSame(
            $hasher->hmac('payload', 'secret')->hash,
            $hasher->hmac('payload', 'other-secret')->hash,
        );
    }

    // -----------------------------------------------------------------------
    // Comparison
    // -----------------------------------------------------------------------

    public function testCompareAcceptsOnlyAnExactMatch(): void
    {
        $hasher = $this->hasher();
        $digest = $hasher->sha256('hello');

        $this->assertTrue($hasher->compare($digest, $digest));
        $this->assertFalse($hasher->compare($digest, $hasher->sha256('hello ')));
        $this->assertFalse($hasher->compare($digest, strtoupper($digest)));
    }

    public function testCompareRejectsWhatItCannotCompare(): void
    {
        $hasher = $this->hasher();

        $this->assertFalse($hasher->compare('', ''));
        $this->assertFalse($hasher->compare('abc', 'abcd'), 'a length mismatch is not a match');
        $this->assertFalse($hasher->compare('abc', ''));
    }

    public function testCompareIsTimingSafeForTheBytesItCompares(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../src/Hasher.php');

        $this->assertStringContainsString('hash_equals', $source, 'comparison must not short-circuit on the first differing byte');
    }

    // -----------------------------------------------------------------------
    // Verification
    // -----------------------------------------------------------------------

    public function testVerifyAcceptsTheDigestItProduced(): void
    {
        $hasher = $this->hasher();
        $digest = $hasher->hash('hello');

        $this->assertTrue($hasher->verify('hello', $digest->hash));
        $this->assertFalse($hasher->verify('nope', $digest->hash));
    }

    public function testVerifyNeverThrows(): void
    {
        $hasher = $this->hasher();

        $this->assertFalse($hasher->verify('hello', ''));
        $this->assertFalse($hasher->verify('hello', 'not-a-digest'));
        $this->assertFalse(
            $hasher->verify('hello', 'x', ['algorithm' => 'sha3-256']),
            'an unusable input is simply not a match'
        );
    }

    // -----------------------------------------------------------------------
    // Stretching - a digest, not a password hash
    // -----------------------------------------------------------------------

    public function testStretchIsSaltedIteratedSha512(): void
    {
        $result = $this->hasher()->stretch('correct horse', 'fixed-salt');

        $this->assertSame('sha512', $result->algorithm);
        $this->assertSame('hex', $result->encoding);
        $this->assertSame(10000, $result->iterations);
        $this->assertSame($this->hasher()->stretch('correct horse', 'fixed-salt')->hash, $result->hash);
    }

    public function testStretchWithoutASaltSalisItself(): void
    {
        $hasher = $this->hasher();

        $this->assertNotSame(
            $hasher->stretch('correct horse')->hash,
            $hasher->stretch('correct horse')->hash,
            'no salt given means a fresh random one'
        );
    }

    public function testStretchIsNotAPasswordHash(): void
    {
        $stretched = $this->hasher()->stretch('correct horse', 'fixed-salt')->hash;

        $this->assertFalse(
            password_verify('correct horse', $stretched),
            'a digest is not a crypt string - passwords belong to units/password'
        );
    }

    // -----------------------------------------------------------------------
    // Statelessness
    // -----------------------------------------------------------------------

    public function testEveryFieldIsReadonly(): void
    {
        foreach ((new ReflectionClass(Hasher::class))->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly(), $property->getName() . ' must be readonly');
        }
    }

    public function testHashingDoesNotChangeTheUnit(): void
    {
        $hasher = $this->hasher();
        $whoami = $hasher->whoami();
        $digest = $hasher->hash('hello')->hash;

        $hasher->hash('something else');
        $hasher->hmac('payload', 'secret');
        $hasher->stretch('correct horse');
        $hasher->verify('hello', 'nope');

        $this->assertSame($whoami, $hasher->whoami());
        $this->assertSame(
            $digest,
            $hasher->hash('hello')->hash,
            'a hasher that remembers what it hashed is a log wearing a digest'
        );
    }

    public function testItOffersNoHistoryAndNoPasswordApi(): void
    {
        foreach (['operations', 'lastHash', 'record', 'history', 'needsRehash', 'password_hash'] as $method) {
            $this->assertFalse(method_exists(Hasher::class, $method), $method . '() does not belong to this unit');
        }
    }

    public function testWhoamiNamesTheAlgorithmAndTheEncoding(): void
    {
        $this->assertSame('Hasher v' . Hasher::VERSION . ' - sha256/hex', $this->hasher()->whoami());
        $this->assertSame('Hasher v' . Hasher::VERSION . ' - sha512/base64', $this->hasher('sha512', 'base64')->whoami());
    }

    // -----------------------------------------------------------------------
    // Living documentation
    // -----------------------------------------------------------------------

    public function testHelpIsRenderedFromTheUnitItself(): void
    {
        $help = $this->hasher()->help();

        $this->assertStringContainsString($this->hasher()->whoami(), $help);
        $this->assertStringContainsString('sha256', $help);
        $this->assertStringContainsString('hmac(data, secret, options?)', $help);
        $this->assertStringContainsString('stretch(password, salt?, iter?)', $help);
    }

    public function testHelpFollowsTheConfiguredDna(): void
    {
        $dna = HasherSchema::create(['id' => 'custom-hasher', 'version' => '9.9.9', 'description' => 'Custom.']);

        $help = Hasher::create(['dna' => $dna->toArray()])->help();

        $this->assertStringContainsString('custom-hasher v9.9.9', $help);
        $this->assertStringContainsString('Custom.', $help);
    }
}

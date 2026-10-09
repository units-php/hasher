<?php

declare(strict_types=1);

namespace Units\Hasher;

use InvalidArgumentException;
use Patterns\IUnit;
use Patterns\IUnitSchema;

/**
 * Hasher - the hashing unit: an identity plus native hashing.
 *
 * Zero dependencies - PHP's own hash(), hash_hmac() and hash_equals() are the
 * whole implementation.
 *
 * And no state: the configuration is readonly, and every call is a pure function
 * of its arguments. A hasher that remembers what it hashed is a log wearing a
 * digest.
 *
 *   $hasher = Hasher::create(['algorithm' => 'sha256', 'encoding' => 'hex']);
 *
 *   $hasher->sha256('hello');                       // quick digest
 *   $hasher->hash('hello')->hash;                   // typed result
 *   $hasher->hmac('payload', $secret)->hash;        // keyed
 *   $hasher->compare($a, $b);                       // timing-safe
 *   $hasher->stretch($password)->hash;              // salted, iterated digest
 *
 * Not an id obfuscator (that is a reversible encoding, not a digest), and not
 * the password hasher either - credentials are Units\Password\Password. This
 * unit makes fast digests. Which secret, if any, is mixed in, and which scheme
 * protects a password, are decisions for the application that composes it.
 */
final class Hasher implements IUnit
{
    public const VERSION = '1.0.0';

    /**
     * Canonical algorithm → PHP hash() name.
     *
     * @var array<string, string>
     */
    private const ALGORITHMS = [
        'sha256'   => 'sha256',
        'sha512'   => 'sha512',
        'sha1'     => 'sha1',
        'md5'      => 'md5',
        'blake2b'  => 'blake2b512',
        'ripemd160' => 'ripemd160',
        'sha3-512' => 'sha3-512',
    ];

    /** @var list<string> */
    private const ENCODINGS = ['hex', 'base64', 'base64url', 'binary'];

    private readonly HasherSchema $dna;

    private readonly string $algorithm;

    private readonly string $encoding;

    private function __construct(HasherSchema $dna, string $algorithm, string $encoding)
    {
        $this->dna = $dna;
        $this->algorithm = $algorithm;
        $this->encoding = $encoding;
    }

    /**
     * @param array{algorithm?: string, encoding?: string, dna?: array<string, mixed>} $props
     */
    public static function create(array $props = []): self
    {
        $algorithm = (string) ($props['algorithm'] ?? 'sha256');
        $encoding = (string) ($props['encoding'] ?? 'hex');

        if (!isset(self::ALGORITHMS[$algorithm])) {
            throw new InvalidArgumentException(sprintf(
                '[hasher] unsupported algorithm: %s - supported: %s',
                $algorithm,
                implode(', ', array_keys(self::ALGORITHMS))
            ));
        }

        if (!in_array($encoding, self::ENCODINGS, true)) {
            throw new InvalidArgumentException(sprintf(
                '[hasher] unsupported encoding: %s - supported: %s',
                $encoding,
                implode(', ', self::ENCODINGS)
            ));
        }

        return new self(
            HasherSchema::create($props['dna'] ?? self::schema()->toArray()),
            $algorithm,
            $encoding
        );
    }

    /**
     * The unit's DNA, available without constructing an instance.
     *
     * The ledger records **released** versions only: it is what lets a stored
     * hash say which version produced it, so it must not claim versions that
     * never shipped.
     */
    public static function schema(): HasherSchema
    {
        return HasherSchema::create([
            'id'          => 'hasher',
            'version'     => self::VERSION,
            'label'       => 'Hasher v' . self::VERSION,
            'description' => 'Hashing unit - native digests, HMAC and keyed verification (Patterns\\IUnit).',
            'versions'    => [
                [
                    'version' => '1.0.0',
                    'date'    => '2026-10-07',
                    'notes'   => 'First release - hashing unit based on patterns/unit contract',
                ],
            ],
        ]);
    }

    // -----------------------------------------------------------------------
    // IUnit
    // -----------------------------------------------------------------------

    public function dna(): IUnitSchema
    {
        return $this->dna;
    }

    public function whoami(): string
    {
        return sprintf('%s - %s/%s', $this->dna->label(), $this->algorithm, $this->encoding);
    }

    // -----------------------------------------------------------------------
    // State
    // -----------------------------------------------------------------------

    public function algorithm(): string
    {
        return $this->algorithm;
    }

    public function encoding(): string
    {
        return $this->encoding;
    }

    /**
     * @return list<string>
     */
    public function algorithms(): array
    {
        return array_keys(self::ALGORITHMS);
    }

    public function supports(string $algorithm): bool
    {
        return isset(self::ALGORITHMS[$algorithm])
            && in_array(self::ALGORITHMS[$algorithm], hash_algos(), true);
    }

    // -----------------------------------------------------------------------
    // Hashing
    // -----------------------------------------------------------------------

    /**
     * @param array{algorithm?: string, encoding?: string, salt?: string, iterations?: int} $options
     */
    public function hash(string $data, array $options = []): HashResult
    {
        $algorithm = (string) ($options['algorithm'] ?? $this->algorithm);
        $encoding = (string) ($options['encoding'] ?? $this->encoding);
        $salt = $options['salt'] ?? null;
        $iterations = (int) ($options['iterations'] ?? 1);

        $this->assertAlgorithm($algorithm);
        $this->assertEncoding($encoding);

        $hex = $this->computeHex($data, $algorithm, $salt, $iterations);

        return new HashResult(
            $this->encodeHex($hex, $encoding),
            $algorithm,
            $encoding,
            $salt,
            $iterations > 1 ? $iterations : null
        );
    }

    /**
     * @param array{algorithm?: string, encoding?: string} $options
     */
    public function hashBytes(string $bytes, array $options = []): HashResult
    {
        return $this->hash($bytes, $options);
    }

    /**
     * @param array{algorithm?: string, encoding?: string, salt?: string, iterations?: int} $options
     */
    public function verify(string $data, string $expected, array $options = []): bool
    {
        try {
            return $this->compare($this->hash($data, $options)->hash, $expected);
        } catch (\Throwable) {
            // Verification never throws: an unusable input is simply not a match.
            return false;
        }
    }

    /**
     * Timing-safe comparison. Length is checked first, so this is not a
     * byte-by-byte oracle for the length of the expected hash.
     */
    public function compare(string $a, string $b): bool
    {
        if ($a === '' || $b === '' || strlen($a) !== strlen($b)) {
            return false;
        }

        return hash_equals($a, $b);
    }

    /**
     * Keyed hashing (HMAC).
     *
     * @param array{algorithm?: string, encoding?: string} $options
     */
    public function hmac(string $data, string $secret, array $options = []): HashResult
    {
        $algorithm = (string) ($options['algorithm'] ?? $this->algorithm);
        $encoding = (string) ($options['encoding'] ?? $this->encoding);

        $this->assertAlgorithm($algorithm);
        $this->assertEncoding($encoding);

        $hex = hash_hmac(self::ALGORITHMS[$algorithm], $data, $secret);

        return new HashResult($this->encodeHex($hex, $encoding), $algorithm, $encoding);
    }

    /**
     * Salted, iterated digest (key stretching).
     *
     * A portable, dependency-free stretch - a DIGEST, not a password hash. For
     * passwords use Units\Password\Password, which is backed by password_hash()
     * and carries its own salt and parameters.
     */
    public function stretch(string $password, ?string $salt = null, int $iterations = 10000): HashResult
    {
        $salt ??= $this->salt();

        return $this->hash($password, [
            'algorithm'  => 'sha512',
            'encoding'   => 'hex',
            'salt'       => $salt,
            'iterations' => $iterations,
        ]);
    }

    // -----------------------------------------------------------------------
    // Shortcuts
    // -----------------------------------------------------------------------

    public function sha256(string $data, string $encoding = 'hex'): string
    {
        return $this->hash($data, ['algorithm' => 'sha256', 'encoding' => $encoding])->hash;
    }

    public function sha512(string $data, string $encoding = 'hex'): string
    {
        return $this->hash($data, ['algorithm' => 'sha512', 'encoding' => $encoding])->hash;
    }

    public function md5(string $data, string $encoding = 'hex'): string
    {
        return $this->hash($data, ['algorithm' => 'md5', 'encoding' => $encoding])->hash;
    }

    public function sha3_512(string $data, string $encoding = 'hex'): string
    {
        return $this->hash($data, ['algorithm' => 'sha3-512', 'encoding' => $encoding])->hash;
    }

    public function salt(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }

    // -----------------------------------------------------------------------
    // Living documentation (not part of the pattern)
    // -----------------------------------------------------------------------

    /**
     * Rendered from the DNA and the algorithm map, never hand-written - so it
     * cannot drift from what the unit actually is.
     */
    public function help(): string
    {
        $operations = [
            'hash(data, options?)'                  => 'digest with algorithm/encoding/salt/iterations',
            'hashBytes(bytes, options?)'            => 'digest binary data',
            'verify(data, expected, options?)'      => 'true when the digest matches',
            'compare(a, b)'                         => 'timing-safe comparison of two digests',
            'hmac(data, secret, options?)'          => 'keyed digest',
            'sha256/sha512/md5/sha3_512(data)'      => 'quick digests, hex by default',
            'stretch(password, salt?, iter?)'       => 'salted, iterated digest (not a password hash)',
        ];

        $lines = [
            $this->whoami(),
            '',
            '  ' . $this->dna->description(),
            '  algorithms : ' . implode(', ', $this->algorithms()),
            '',
        ];

        foreach ($operations as $signature => $description) {
            $lines[] = sprintf('  %-38s %s', $signature, $description);
        }

        $lines[] = '';
        $lines[] = '  $hasher = Hasher::create([\'algorithm\' => \'sha256\']);';
        $lines[] = '  $hasher->sha256(\'hello\');';

        return implode(PHP_EOL, $lines);
    }

    // -----------------------------------------------------------------------
    // Private
    // -----------------------------------------------------------------------

    private function computeHex(string $data, string $algorithm, ?string $salt, int $iterations): string
    {
        $hex = hash(self::ALGORITHMS[$algorithm], $salt !== null ? $salt . $data : $data);

        for ($i = 1; $i < max(1, $iterations); $i++) {
            $hex = hash(self::ALGORITHMS[$algorithm], $hex);
        }

        return $hex;
    }

    private function encodeHex(string $hex, string $encoding): string
    {
        $raw = static fn (): string => (string) hex2bin($hex);

        return match ($encoding) {
            'hex'       => $hex,
            'base64'    => base64_encode($raw()),
            'base64url' => rtrim(strtr(base64_encode($raw()), '+/', '-_'), '='),
            'binary'    => $raw(),
            default     => throw new InvalidArgumentException('[hasher] unsupported encoding: ' . $encoding),
        };
    }

    private function assertAlgorithm(string $algorithm): void
    {
        if (!$this->supports($algorithm)) {
            throw new InvalidArgumentException(sprintf(
                '[hasher] unsupported algorithm: %s - supported: %s',
                $algorithm,
                implode(', ', $this->algorithms())
            ));
        }
    }

    private function assertEncoding(string $encoding): void
    {
        if (!in_array($encoding, self::ENCODINGS, true)) {
            throw new InvalidArgumentException(sprintf(
                '[hasher] unsupported encoding: %s - supported: %s',
                $encoding,
                implode(', ', self::ENCODINGS)
            ));
        }
    }
}

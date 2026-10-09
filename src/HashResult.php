<?php

declare(strict_types=1);

namespace Units\Hasher;

use DateTimeImmutable;
use JsonSerializable;

/**
 * HashResult - what a hashing operation gives back.
 *
 * A shape, not a Unit: data about one operation, with no identity or version of
 * its own. A plain immutable class - public readonly properties, because this
 * one is read far more often than it is built, and there is nothing to validate.
 */
final class HashResult implements JsonSerializable
{
    public readonly DateTimeImmutable $timestamp;

    public function __construct(
        public readonly string $hash,
        public readonly string $algorithm,
        public readonly string $encoding,
        public readonly ?string $salt = null,
        public readonly ?int $iterations = null,
        ?DateTimeImmutable $timestamp = null,
    ) {
        $this->timestamp = $timestamp ?? new DateTimeImmutable();
    }

    public static function create(
        string $hash,
        string $algorithm,
        string $encoding,
        ?string $salt = null,
        ?int $iterations = null,
    ): self {
        return new self($hash, $algorithm, $encoding, $salt, $iterations);
    }

    public function __toString(): string
    {
        return $this->hash;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'hash'       => $this->hash,
            'algorithm'  => $this->algorithm,
            'encoding'   => $this->encoding,
            'salt'       => $this->salt,
            'iterations' => $this->iterations,
            'timestamp'  => $this->timestamp->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

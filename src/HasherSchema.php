<?php

declare(strict_types=1);

namespace Units\Hasher;

use InvalidArgumentException;
use Patterns\IUnitSchema;
use Patterns\ValueObject;

/**
 * HasherSchema - this unit's DNA (Patterns\IUnitSchema).
 *
 * Local on purpose: a unit ships its own schema class, so its identity can
 * change without touching the pattern or any other unit.
 *
 * Methods, not public fields. Equality and JSON serialization come from
 * Patterns\ValueObject, which is what makes the DNA persistable - and that is
 * what provenance needs: a stored hash can say which unit, at which version,
 * produced it.
 */
final class HasherSchema extends ValueObject implements IUnitSchema
{
    /**
     * @param array<string, mixed> $props
     */
    private function __construct(array $props)
    {
        parent::__construct($props);
    }

    /**
     * @param array<string, mixed> $props
     */
    public static function create(array $props): self
    {
        $id      = (string) ($props['id'] ?? '');
        $version = (string) ($props['version'] ?? '');

        if ($id === '') {
            throw new InvalidArgumentException('HasherSchema: id is required');
        }

        if ($version === '') {
            throw new InvalidArgumentException('HasherSchema: version is required');
        }

        $label = (string) ($props['label'] ?? '');

        return new self([
            'id'          => $id,
            'version'     => $version,
            'label'       => $label === '' ? $id . ' v' . $version : $label,
            'description' => (string) ($props['description'] ?? ''),
            'versions'    => array_values((array) ($props['versions'] ?? [])),
        ]);
    }

    public function id(): string
    {
        return $this->props['id'];
    }

    public function version(): string
    {
        return $this->props['version'];
    }

    public function label(): string
    {
        return $this->props['label'];
    }

    public function description(): string
    {
        return $this->props['description'];
    }

    /**
     * @return list<array<string, string>>
     */
    public function versions(): array
    {
        return $this->props['versions'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->toProps();
    }
}

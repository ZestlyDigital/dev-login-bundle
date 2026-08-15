<?php

declare(strict_types=1);

namespace Zestly\DevLoginBundle\Identity;

/**
 * A login target advertised by the discovery endpoint.
 *
 * This is descriptive metadata only — it is a menu, not a permission. Whether an identifier
 * can actually be logged in as is decided entirely by the host application's user provider,
 * which is the single source of truth. Listing an identity here grants nothing, and omitting
 * one forbids nothing.
 */
final readonly class Identity implements \JsonSerializable
{
    /**
     * @param list<string> $roles Informational only — displayed to help a human or an agent
     *                            choose. Never consulted when authenticating.
     */
    public function __construct(
        public string $identifier,
        public ?string $label = null,
        public ?string $description = null,
        public array $roles = [],
    ) {}

    public function displayName(): string
    {
        return $this->label ?? $this->identifier;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter([
            'identifier' => $this->identifier,
            'label' => $this->label,
            'description' => $this->description,
            'roles' => $this->roles,
        ], static fn (mixed $v): bool => null !== $v && [] !== $v);
    }
}

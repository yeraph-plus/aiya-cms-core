<?php

declare(strict_types=1);

namespace Aiya\Core\Metadata;

use Aiya\Core\Settings\Schema\Field;
use InvalidArgumentException;

/**
 * Registry for code-declared field groups (post boxes, term boxes, user
 * fields). Populated through the aiya_core_register seam; consumed by the
 * admin metabox module.
 */
final class Registry
{
    /** @var array<string, PostBox> */
    private array $postBoxes = [];

    /** @var array<string, TermBox> */
    private array $termBoxes = [];

    /** @var list<Field> */
    private array $userFields = [];

    /** @param PostBox|array<string, mixed> $box */
    public function addPostBox(PostBox|array $box): PostBox
    {
        $box = is_array($box) ? PostBox::fromArray($box) : $box;
        if (isset($this->postBoxes[$box->id()])) {
            throw new InvalidArgumentException(sprintf('Post box "%s" is already registered.', $box->id()));
        }

        $this->postBoxes[$box->id()] = $box;

        return $box;
    }

    /** @param TermBox|array<string, mixed> $box */
    public function addTermBox(TermBox|array $box): TermBox
    {
        $box = is_array($box) ? TermBox::fromArray($box) : $box;
        if (isset($this->termBoxes[$box->id()])) {
            throw new InvalidArgumentException(sprintf('Term box "%s" is already registered.', $box->id()));
        }

        $this->termBoxes[$box->id()] = $box;

        return $box;
    }

    /**
     * Appends user profile fields; rendered as one shared section. Values
     * are stored under per-field user meta keys.
     *
     * @param list<array<string, mixed>> $fields
     */
    public function addUserFields(array $fields): void
    {
        $known = [];
        foreach ($this->userFields as $field) {
            $known[$field->id()] = true;
        }

        foreach (array_filter($fields, 'is_array') as $definition) {
            $field = Field::fromArray($definition);
            if (isset($known[$field->id()])) {
                throw new InvalidArgumentException(sprintf('User field "%s" is already registered.', $field->id()));
            }
            $known[$field->id()] = true;
            $this->userFields[] = $field;
        }
    }

    public function postBox(string $id): ?PostBox
    {
        return $this->postBoxes[$id] ?? null;
    }

    public function termBox(string $id): ?TermBox
    {
        return $this->termBoxes[$id] ?? null;
    }

    /** @return list<PostBox> */
    public function postBoxes(): array
    {
        return array_values($this->postBoxes);
    }

    /** @return list<TermBox> */
    public function termBoxes(): array
    {
        return array_values($this->termBoxes);
    }

    /** @return list<Field> */
    public function userFields(): array
    {
        return $this->userFields;
    }
}

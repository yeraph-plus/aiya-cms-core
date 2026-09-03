<?php

declare(strict_types=1);

namespace Aiya\Core\Settings;

use Aiya\Core\Settings\Schema\Field;
use Aiya\Core\Settings\Schema\Page;
use InvalidArgumentException;

final class Registry
{
    /** @var array<string, Page> */
    private array $pages = [];

    /** @param Page|array<string, mixed> $page */
    public function addPage(Page|array $page): Page
    {
        $page = is_array($page) ? Page::fromArray($page) : $page;
        if (isset($this->pages[$page->slug()])) {
            throw new InvalidArgumentException(sprintf('Settings page "%s" is already registered.', $page->slug()));
        }

        $this->pages[$page->slug()] = $page;
        return $page;
    }

    public function page(string $slug): ?Page
    {
        return $this->pages[sanitize_key($slug)] ?? null;
    }

    /**
     * Appends field definitions to an already-registered page so multiple
     * modules can contribute to one shared settings page.
     *
     * @param list<array<string, mixed>> $fields
     */
    public function addFields(string $page, array $fields): void
    {
        $existing = $this->page($page);
        if ($existing === null) {
            throw new InvalidArgumentException(sprintf('Settings page "%s" is not registered.', $page));
        }

        $existing->appendFields(array_map(
            static fn (array $field): Field => Field::fromArray($field),
            array_values(array_filter($fields, 'is_array'))
        ));
    }

    /** @return list<Page> */
    public function pages(): array
    {
        return array_values($this->pages);
    }
}

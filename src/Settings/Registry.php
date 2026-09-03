<?php

declare(strict_types=1);

namespace Aiya\Core\Settings;

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

    /** @return list<Page> */
    public function pages(): array
    {
        return array_values($this->pages);
    }
}


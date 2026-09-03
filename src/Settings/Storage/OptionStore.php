<?php

declare(strict_types=1);

namespace Aiya\Core\Settings\Storage;

final class OptionStore implements ValueStore
{
    public function __construct(private string $optionName, private bool $network = false)
    {
    }

    public function all(): array
    {
        $value = $this->network ? get_site_option($this->optionName, []) : get_option($this->optionName, []);
        return is_array($value) ? $value : [];
    }

    public function replace(array $values): void
    {
        if ($this->network) {
            update_site_option($this->optionName, $values);
            return;
        }
        update_option($this->optionName, $values, false);
    }

    public function delete(): void
    {
        $this->network ? delete_site_option($this->optionName) : delete_option($this->optionName);
    }
}

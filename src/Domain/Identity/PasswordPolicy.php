<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Identity;

/**
 * Shared password rules for registration, reset and change flows, matching
 * the legacy front-end contract: at least 8 characters with both letters
 * and digits.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    /**
     * @return list<string> Violation messages; empty when the pair is valid.
     */
    public function validate(string $password, string $confirmation): array
    {
        $errors = [];

        if (mb_strlen($password) < self::MIN_LENGTH) {
            $errors[] = sprintf(
                /* translators: %d: minimum password length. */
                __('Password must be at least %d characters long.', 'aiya-core'),
                self::MIN_LENGTH
            );
        }

        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $errors[] = __('Password must contain both letters and digits.', 'aiya-core');
        }

        if ($password !== $confirmation) {
            $errors[] = __('The two passwords do not match.', 'aiya-core');
        }

        return $errors;
    }
}

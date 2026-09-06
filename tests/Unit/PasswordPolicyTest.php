<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Identity\PasswordPolicy;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    private PasswordPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new PasswordPolicy();
    }

    public function testAcceptsAPasswordWithLettersAndDigits(): void
    {
        self::assertSame([], $this->policy->validate('abc12345', 'abc12345'));
    }

    public function testRejectsShortPasswords(): void
    {
        $errors = $this->policy->validate('ab1', 'ab1');

        self::assertCount(1, $errors);
        self::assertStringContainsString('8', $errors[0]);
    }

    public function testRejectsPasswordsWithoutLettersOrDigits(): void
    {
        self::assertNotEmpty($this->policy->validate('12345678', '12345678'));
        self::assertNotEmpty($this->policy->validate('abcdefgh', 'abcdefgh'));
    }

    public function testRejectsMismatchedConfirmation(): void
    {
        $errors = $this->policy->validate('abc12345', 'abc54321');

        self::assertCount(1, $errors);
    }

    public function testCollectsAllViolationsAtOnce(): void
    {
        self::assertCount(3, $this->policy->validate('a', 'b'));
    }
}

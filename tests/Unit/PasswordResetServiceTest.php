<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Identity\PasswordResetService;
use PHPUnit\Framework\TestCase;

/**
 * Origin resolution for the reset link. The configured frontend domain
 * is authoritative; without it a client-reported origin survives only
 * when it names the site's own host. The home_url shim serves
 * https://aiya.test, so that is the site.
 */
final class PasswordResetServiceTest extends TestCase
{
    private PasswordResetService $service;

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
        $this->service = new PasswordResetService();
    }

    public function testConfiguredFrontendDomainWinsOverTheClientReport(): void
    {
        $GLOBALS['__aiya_test_options']['frontend']['frontend_domain'] = 'http://localhost:4321';

        $url = $this->service->buildResetUrl('https://evil.example', 'uuid-login', 'key123');

        self::assertSame('http://localhost:4321/reset-password?login=uuid-login&key=key123', $url);
    }

    public function testConfiguredDomainNormalizesSchemeAndKeepsThePort(): void
    {
        // Site owners write the dev origin bare; scheme-less reads as https.
        $GLOBALS['__aiya_test_options']['frontend']['frontend_domain'] = 'front.example:4321';

        $url = $this->service->buildResetUrl('https://evil.example', 'uuid-login', 'key123');

        self::assertSame('https://front.example:4321/reset-password?login=uuid-login&key=key123', $url);
    }

    public function testConfiguredValueWithCredentialsIsRejectedAndFallsBack(): void
    {
        // Userinfo in an origin is never legitimate — owner-entered or not,
        // the value is rejected wholesale and the site URL answers.
        $GLOBALS['__aiya_test_options']['frontend']['frontend_domain'] = 'https://u:p@front.example/path?q=1';

        $url = $this->service->buildResetUrl('', 'uuid-login', 'key123');

        self::assertSame('https://aiya.test/reset-password?login=uuid-login&key=key123', $url);
    }

    public function testUnusableConfiguredValueFallsBackToTheSiteUrl(): void
    {
        // No host: normalizeOrigin rejects it and the site URL answers.
        $GLOBALS['__aiya_test_options']['frontend']['frontend_domain'] = 'http://';

        $url = $this->service->buildResetUrl('https://evil.example', 'uuid-login', 'key123');

        self::assertSame('https://aiya.test/reset-password?login=uuid-login&key=key123', $url);
    }

    public function testWithoutConfigurationTheSiteHostIsKeptOnAnyPort(): void
    {
        // Host-only comparison on purpose: the site's own host on another
        // port is still the site.
        $url = $this->service->buildResetUrl('http://aiya.test:9000', 'uuid-login', 'key123');

        self::assertSame('http://aiya.test:9000/reset-password?login=uuid-login&key=key123', $url);
    }

    public function testWithoutConfigurationAForeignOriginFallsBackToTheSiteUrl(): void
    {
        $url = $this->service->buildResetUrl('https://evil.example', 'uuid-login', 'key123');

        self::assertSame('https://aiya.test/reset-password?login=uuid-login&key=key123', $url);
    }

    public function testWithoutConfigurationGarbageInputFallsBackToTheSiteUrl(): void
    {
        self::assertSame(
            'https://aiya.test/reset-password?login=uuid-login&key=key123',
            $this->service->buildResetUrl('::::', 'uuid-login', 'key123'),
        );
        self::assertSame(
            'https://aiya.test/reset-password?login=uuid-login&key=key123',
            $this->service->buildResetUrl('javascript:alert(1)', 'uuid-login', 'key123'),
        );
    }
}

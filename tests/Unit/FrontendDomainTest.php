<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\FrontendDomain;
use PHPUnit\Framework\TestCase;

/**
 * The single normalizer for the Frontend page's "frontend domain".
 * Every consumer of the value (reset links, the admin-bar shortcut,
 * future features) resolves through this class.
 */
final class FrontendDomainTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
    }

    public function testUnsetOptionAnswersNull(): void
    {
        self::assertNull(FrontendDomain::origin());
    }

    public function testBlankOptionAnswersNull(): void
    {
        $GLOBALS['__aiya_test_options']['frontend']['frontend_domain'] = '   ';
        self::assertNull(FrontendDomain::origin());
    }

    public function testReadsTheConfiguredOrigin(): void
    {
        $GLOBALS['__aiya_test_options']['frontend']['frontend_domain'] = 'http://localhost:4321';
        self::assertSame('http://localhost:4321', FrontendDomain::origin());
    }

    public function testSchemelessInputReadsAsHttps(): void
    {
        self::assertSame('https://front.example', FrontendDomain::normalize('front.example'));
    }

    public function testExplicitPortSurvives(): void
    {
        self::assertSame('https://front.example:4321', FrontendDomain::normalize('front.example:4321'));
    }

    public function testSchemeAndHostAreLowercased(): void
    {
        self::assertSame('https://front.example', FrontendDomain::normalize('HTTPS://Front.Example'));
    }

    public function testPathAndQueryAreDropped(): void
    {
        self::assertSame('https://front.example', FrontendDomain::normalize('https://front.example/path?q=1'));
    }

    public function testCredentialsRejectTheWholeValue(): void
    {
        self::assertNull(FrontendDomain::normalize('https://u:p@front.example'));
    }

    public function testMissingHostRejectsTheValue(): void
    {
        self::assertNull(FrontendDomain::normalize('http://'));
    }

    public function testGarbageRejectsTheValue(): void
    {
        self::assertNull(FrontendDomain::normalize('::::'));
        self::assertNull(FrontendDomain::normalize('not a host'));
    }

    public function testBracketedIpv6LiteralSurvives(): void
    {
        self::assertSame('https://[::1]:4321', FrontendDomain::normalize('https://[::1]:4321'));
    }
}

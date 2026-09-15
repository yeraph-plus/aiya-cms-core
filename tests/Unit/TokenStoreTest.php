<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Identity\TokenStore;
use PHPUnit\Framework\TestCase;

/**
 * The resolve mirror and its revocation-generation invalidation: a warm
 * mirror must serve repeat reads from a single table query, and a
 * revocation (logout / password reset) must kill warm mirrors immediately
 * — not within the mirror TTL. Runs against the wpdb stand-in, which
 * counts table reads.
 */
final class TokenStoreTest extends TestCase
{
    private const USER_ID = 72;

    private \wpdb $db;

    private TokenStore $tokens;

    protected function setUp(): void
    {
        global $wpdb;
        $this->db = new \wpdb();
        $wpdb = $this->db;
        $GLOBALS['__aiya_test_users'] = [self::USER_ID => true];
        $GLOBALS['__aiya_test_user_meta'] = [];
        wp_cache_flush();
        $this->tokens = new TokenStore();
    }

    public function testMirrorServesRepeatedResolvesFromOneRead(): void
    {
        $token = $this->tokens->issue(self::USER_ID)->token;

        self::assertSame(self::USER_ID, $this->tokens->resolve($token));
        self::assertSame(self::USER_ID, $this->tokens->resolve($token), 'the mirror carries the same generation');
        self::assertSame(1, $this->db->aiya_test_reads, 'the second resolve never touches the table');
    }

    public function testLogoutKillsAWarmMirrorImmediately(): void
    {
        $token = $this->tokens->issue(self::USER_ID)->token;
        $this->tokens->resolve($token); // warm the mirror

        $this->tokens->revoke($token);

        self::assertSame(0, $this->tokens->resolve($token), 'stale generation falls through to the table');
        self::assertSame(2, $this->db->aiya_test_reads, 'the fall-through re-reads the (now empty) table');
        self::assertSame(0, $this->tokens->resolve($token), 'the dead token mirrors as negative from here on');
        self::assertSame(2, $this->db->aiya_test_reads);
    }

    public function testRevokeAllKillsWarmMirrorsOfEveryToken(): void
    {
        $first = $this->tokens->issue(self::USER_ID)->token;
        $second = $this->tokens->issue(self::USER_ID)->token;
        self::assertSame(self::USER_ID, $this->tokens->resolve($first));
        self::assertSame(self::USER_ID, $this->tokens->resolve($second));

        $this->tokens->revokeAll(self::USER_ID);

        self::assertSame(0, $this->tokens->resolve($first));
        self::assertSame(0, $this->tokens->resolve($second));
        self::assertSame(4, $this->db->aiya_test_reads, 'both warm mirrors rejected by the generation bump');
    }

    public function testUnknownTokenMirrorsNegative(): void
    {
        self::assertSame(0, $this->tokens->resolve('71.no-such-secret'));
        self::assertSame(0, $this->tokens->resolve('71.no-such-secret'), 'validity only shrinks — the negative mirror is final');
        self::assertSame(1, $this->db->aiya_test_reads);
    }

    public function testMalformedTokenShortCircuitsWithoutReads(): void
    {
        self::assertSame(0, $this->tokens->resolve('not-a-token'));
        self::assertSame(0, $this->tokens->resolve('.secret'));
        self::assertSame(0, $this->tokens->resolve('72.'));
        self::assertSame(0, $this->db->aiya_test_reads);
    }
}

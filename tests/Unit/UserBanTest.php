<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Admin\MetaboxAdmin;
use Aiya\Core\Api\Presenter\UserPresenter;
use Aiya\Core\Domain\Credit\LedgerService;
use Aiya\Core\Domain\Identity\UserBan;
use Aiya\Core\Domain\Sponsorship\MembershipService;
use Aiya\Core\Metadata\Registry as MetadataRegistry;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_User;

require_once __DIR__ . '/../wp-shims.php';

/**
 * The account-level disable switch: the meta round trip, the three
 * enforcement points it feeds (credits in, credits out, membership), and
 * the profile-form write path around it (the field is admin-gated, never
 * on the holder's own screen, and clearing deletes the key).
 *
 * Every assertion rides a short-circuit that answers before the database
 * is reached — which is the design's point: the ban is a policy override
 * consulted first, not a filter applied to results. The wpdb stand-in
 * counts reads, so "answered before touching the ledger/queue" is a
 * measurable claim, not a reading of the source.
 */
final class UserBanTest extends TestCase
{
    private \wpdb $db;

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_user_meta'] = [];
        $GLOBALS['__aiya_test_caps'] = true;
        $GLOBALS['__aiya_test_current_user_id'] = 0;
        $_POST = [];

        global $wpdb;
        $this->db = new \wpdb();
        $wpdb = $this->db;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        $_POST = [];
    }

    private function user(int $id = 7): WP_User
    {
        $user = new WP_User();
        $user->ID = $id;

        return $user;
    }

    // -------------------------------------------------------- the profile form

    /**
     * The profile-screen write path (MetaboxAdmin::saveUserFields) around
     * the ban field, registered exactly as the Identity module does —
     * admin-gated. The save loop filters the field list by the same rule
     * the render uses before normalizing, so a forged form key cannot
     * land, and a capability-gated field never participates on the
     * holder's own screen: an administrator cannot disable themselves,
     * and a disabled user saving their own profile cannot lift the switch.
     */
    private function banFieldRegistry(): MetadataRegistry
    {
        $registry = new MetadataRegistry();
        $registry->addUserFields([
            [
                'id' => UserBan::META_KEY,
                'type' => 'switch',
                'label' => 'Disable this account',
                'default' => false,
                'capability' => 'manage_options',
            ],
        ]);

        return $registry;
    }

    /** @param array<string, mixed> $form the $_POST shape the profile form would carry */
    private function saveProfile(int $userId, array $form): void
    {
        $_POST = $form;
        (new MetaboxAdmin($this->banFieldRegistry()))->saveUserFields($userId);
    }

    private function renderProfileFields(int $userId): string
    {
        ob_start();
        (new MetaboxAdmin($this->banFieldRegistry()))->renderUserFields($this->user($userId));

        return (string) ob_get_clean();
    }

    public function testASelfSaveNeverLiftsAnExistingBan(): void
    {
        $GLOBALS['__aiya_test_current_user_id'] = 7; // editing their own profile
        $GLOBALS['__aiya_test_caps'] = true; // and the holder is an administrator
        update_user_meta(7, UserBan::META_KEY, '1');

        // The form never carries the key, so a value here is a forgery —
        // exactly what a crafted POST could attempt.
        $this->saveProfile(7, ['aiya_core_user' => [UserBan::META_KEY => '0']]);

        self::assertSame('1', get_user_meta(7, UserBan::META_KEY, true), 'the holder cannot lift their own switch');
    }

    public function testAnAdministratorCannotDisableThemselves(): void
    {
        $GLOBALS['__aiya_test_current_user_id'] = 1;
        $GLOBALS['__aiya_test_caps'] = true;

        $this->saveProfile(1, ['aiya_core_user' => [UserBan::META_KEY => '1']]);

        self::assertSame('', get_user_meta(1, UserBan::META_KEY, true), 'capability-gated fields are absent from the holder\'s own screen, save included');
    }

    public function testAnAdministratorFlipsAnotherHoldersSwitch(): void
    {
        $GLOBALS['__aiya_test_current_user_id'] = 1;
        $GLOBALS['__aiya_test_caps'] = true;

        $this->saveProfile(8, ['aiya_core_user' => [UserBan::META_KEY => '1']]);
        self::assertTrue(UserBan::isBanned(8), 'the form stores the normalized truthy switch (the shim keeps the raw bool; real wpdb stringifies to "1")');

        // Unticking posts the hidden "0" input: clearing deletes the key.
        $this->saveProfile(8, ['aiya_core_user' => [UserBan::META_KEY => '0']]);
        self::assertFalse(UserBan::isBanned(8), 'false deletes the key — one representation for "not banned"');
        self::assertArrayNotHasKey(UserBan::META_KEY, $GLOBALS['__aiya_test_user_meta'][8]);
    }

    public function testASaveWithoutTheOuterCapabilityChangesNothing(): void
    {
        $GLOBALS['__aiya_test_current_user_id'] = 1;
        $GLOBALS['__aiya_test_caps'] = false; // the viewer may not even edit this user
        update_user_meta(8, UserBan::META_KEY, '1');

        $this->saveProfile(8, ['aiya_core_user' => [UserBan::META_KEY => '0']]);

        self::assertSame('1', get_user_meta(8, UserBan::META_KEY, true), 'an unauthorized save leaves the stored switch untouched');
    }

    public function testTheSwitchIsHiddenFromViewersWithoutTheCapability(): void
    {
        $GLOBALS['__aiya_test_current_user_id'] = 2;
        $GLOBALS['__aiya_test_caps'] = false;

        self::assertSame('', $this->renderProfileFields(8), 'no capability, no field — not even the section heading');
    }

    public function testTheSwitchIsAbsentFromTheHoldersOwnProfileScreen(): void
    {
        $GLOBALS['__aiya_test_current_user_id'] = 7;
        $GLOBALS['__aiya_test_caps'] = true;

        self::assertSame('', $this->renderProfileFields(7), 'a capability-gated field never renders on the holder\'s own screen');
    }

    public function testTheSwitchRendersForAnAdministratorEditingAnotherHolder(): void
    {
        $GLOBALS['__aiya_test_current_user_id'] = 1;
        $GLOBALS['__aiya_test_caps'] = true;

        $html = $this->renderProfileFields(8);

        self::assertStringContainsString('name="aiya_core_user[aiya_core_banned]"', $html);
    }

    // ----------------------------------------------------------------- the flag

    public function testAbsentMetaMeansNotBanned(): void
    {
        self::assertFalse(UserBan::isBanned(7));
    }

    public function testSetWritesAndClearsTheSwitch(): void
    {
        UserBan::set(7, true);
        self::assertTrue(UserBan::isBanned(7));

        UserBan::set(7, false);
        self::assertFalse(UserBan::isBanned(7), 'clearing deletes the key: one representation for "not banned"');
        self::assertArrayNotHasKey(UserBan::META_KEY, $GLOBALS['__aiya_test_user_meta'][7]);
    }

    public function testUnknownHoldersAreNeverBanned(): void
    {
        self::assertFalse(UserBan::isBanned(0), 'a missing holder is a different failure (invalid_user)');
        self::assertFalse(UserBan::isBanned(-3));

        UserBan::set(0, true);
        self::assertFalse(UserBan::isBanned(0), 'setting on id 0 is a no-op');
    }

    // ------------------------------------------------------------------ spending

    public function testBannedHolderCannotSpendAndTheLedgerIsUntouched(): void
    {
        UserBan::set(7, true);

        $result = (new LedgerService())->spend(7, 10, LedgerService::SOURCE_SPEND_DOWNLOAD, 'probe');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('aiya_account_disabled', $result->get_error_code());
        self::assertSame(403, $result->get_error_data()['status']);
        self::assertSame([], $this->db->aiya_test_rows, 'the refusal lands before the transaction opens');
        self::assertSame(0, $this->db->aiya_test_reads, 'no bucket is even looked at');
    }

    // ----------------------------------------------------------------- membership

    public function testBannedHolderIsNotAnActiveMember(): void
    {
        UserBan::set(7, true);
        $membership = new MembershipService();

        self::assertFalse($membership->isActive(7));
        self::assertSame(0, $membership->expiresAt(7), 'derived readers must agree with the gate');
        self::assertSame(0, $this->db->aiya_test_reads, 'the queue is not consulted at all');
    }

    public function testBanOutranksTheStaffBypass(): void
    {
        $GLOBALS['__aiya_test_caps'] = true; // this fixture is an editor
        $membership = new MembershipService();

        self::assertTrue($membership->isSponsor(7), 'control: staff qualify through the bypass');

        UserBan::set(7, true);
        self::assertFalse($membership->isSponsor(7), 'a disabled account is never a member, staff or not');
    }

    public function testBannedStaffKeepsTheirSiteRoleWhileLosingTheMemberRole(): void
    {
        $presenter = new UserPresenter();
        UserBan::set(7, true);

        // The staff level is a site-internal fact and survives the ban; the
        // membership level does not (isSponsor answers no). The DTO carries
        // both, so the front end can tell "editor" from "disabled editor".
        self::assertSame('administrator', $presenter->role($this->user(7)));

        $GLOBALS['__aiya_test_caps'] = false;
        self::assertSame('subscriber', $presenter->role($this->user(7)));
    }
}

<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Admin\BulkDialogBehavior;
use PHPUnit\Framework\TestCase;

/**
 * The list-screen bulk dialogs ride the Ui kit's modal part since
 * 0.114.0: ModalView owns the dialog widget (the shell renders
 * server-side through Ui::modal), so the shared behavior script only
 * intercepts the list form, fills the count line, opens the
 * already-initialized dialog, and injects the target choice. These
 * contract assertions pin that division — no widget construction in
 * the script, kit-class hooks for the buttons.
 */
final class BulkDialogBehaviorTest extends TestCase
{
    private string $script;

    protected function setUp(): void
    {
        $this->script = BulkDialogBehavior::script();
    }

    public function testTheScriptNeverInitializesOrRestylesTheDialogWidget(): void
    {
        self::assertStringNotContainsString('.dialog({', $this->script, 'widget construction belongs to ModalView');
        self::assertStringNotContainsString("dialog('option'", $this->script, 'no button wiring through widget options');
        self::assertStringNotContainsString('buttons:', $this->script);
    }

    public function testTheButtonsLiveInTheServerRenderedBody(): void
    {
        self::assertStringContainsString('[data-aiya-dialog-apply]', $this->script, 'apply is a body button the kit shell rendered');
        self::assertStringNotContainsString('button-ok', $this->script, 'the old widget-button class is retired');
        self::assertStringContainsString("dialog('close')", $this->script, 'apply closes through the open widget');
        self::assertStringContainsString("dialog('open')", $this->script);
        // Cancel is not the script's business — ModalView binds the kit's close hook.
        self::assertStringNotContainsString('cancel', $this->script);
    }

    public function testTheRoundTripIsGuardedAndInjectsTheChoice(): void
    {
        self::assertStringContainsString("actionValue() !== c.action", $this->script, 'the dialog only opens for the configured bulk action');
        self::assertStringContainsString("event.preventDefault()", $this->script, 'the submit round trip waits for the choice');
        self::assertStringContainsString("name: c.targetParam", $this->script, 'the choice rides a hidden field on the list form');
        self::assertStringContainsString('count-line', $this->script, 'the count line fills before the open');
        self::assertStringContainsString('c.countOne', $this->script, 'the singular/plural pair still rides the config');
    }
}

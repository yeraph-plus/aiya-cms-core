<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Admin\Ui;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the shared admin render pieces: the escaping and
 * whitelisting behaviour that keeps caller-supplied strings inert, and
 * the shape of the data-* contracts the behavior layer consumes.
 */
final class UiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($_GET['ui_probe']);
    }

    public function testFlashRendersOnlyKnownStringNotes(): void
    {
        $render = static fn (): string => self::captureOut(fn () => Ui::flash('ui_probe', ['ok' => ['All good.', 'success']]));

        self::assertSame('', $render(), 'unset note renders nothing');

        $_GET['ui_probe'] = ['array'];
        self::assertSame('', $render(), 'array-shaped query value is read as unset');

        $_GET['ui_probe'] = 'ok';
        $out = $render();
        self::assertStringContainsString('notice-success', $out);
        self::assertStringContainsString('All good.', $out);
    }

    public function testFlashWhitelistsVariants(): void
    {
        $_GET['ui_probe'] = 'boom';
        $out = $this->captureOut(fn () => Ui::flash('ui_probe', ['boom' => ['Text', 'error"><script>']]));
        self::assertStringContainsString('notice-success', $out, 'unknown variants fall back to success');
        self::assertStringNotContainsString('notice-error', $out);
        self::assertStringNotContainsString('<script>', $out);
    }

    public function testNoticeClampsVariantAndFiltersText(): void
    {
        $plain = $this->captureOut(fn () => Ui::notice('Watch the <strong>limit</strong>'));
        self::assertStringContainsString('<div class="notice notice-info"><p>', $plain, 'default variant is info and the banner is not dismissible');
        self::assertStringContainsString('<strong>', $plain, 'kses-allowed markup passes through');

        $flags = $this->captureOut(fn () => Ui::notice('Text', ['variant' => 'warning', 'dismissible' => true, 'inline' => true]));
        self::assertStringContainsString('<div class="notice notice-warning is-dismissible inline"><p>Text</p></div>', $flags);

        $evil = $this->captureOut(fn () => Ui::notice('T', ['variant' => 'error"><script>']));
        self::assertStringContainsString('notice-info', $evil, 'unknown variants fall back to info');
        self::assertStringNotContainsString('<script>', $evil);
    }

    public function testHeadingClampsLevelAndEscapes(): void
    {
        $nested = $this->captureOut(fn () => Ui::heading('Section <x>', 3));
        self::assertSame('<h3 class="aiya-core-heading">Section &lt;x&gt;</h3>', $nested);

        $default = $this->captureOut(fn () => Ui::heading('Title'));
        self::assertSame('<h2 class="aiya-core-heading">Title</h2>', $default, 'level defaults to 2');

        $clamped = $this->captureOut(fn () => Ui::heading('Title', 9));
        self::assertSame('<h2 class="aiya-core-heading">Title</h2>', $clamped, 'out-of-range levels clamp to 2');
    }

    public function testListTableEscapesHeadersAndWidths(): void
    {
        $out = $this->captureOut(fn () => Ui::listTable(
            ['a' => ['label' => '<script>alert(1)</script>', 'width' => '50px" onmouseover="alert(1)']],
            [],
            static function (): void {
            },
            'Nothing.'
        ));
        self::assertStringNotContainsString('<script>', $out);
        self::assertStringContainsString('&quot; onmouseover', $out, 'quotes inside the width attr are encoded');
        self::assertStringContainsString('<td colspan="1">Nothing.</td>', $out);
    }

    public function testTabsSanitizeIdAndEscapeLabels(): void
    {
        $out = $this->captureOut(fn () => Ui::tabs('a" onclick="x', [
            'Ti<tle>' => static function (): void {
                echo 'panel-body';
            },
            'Second' => static function (): void {
                echo 'panel-two';
            },
        ]));
        self::assertStringNotContainsString('onclick=', $out);
        self::assertStringNotContainsString('Ti<tle>', $out);
        self::assertStringContainsString('aonclickx-panel-0', $out);
        self::assertStringContainsString('panel-body', $out);
        self::assertStringContainsString('aonclickx-panel-1" hidden', $out, 'only the first panel starts visible');
    }

    public function testChartConfigCannotEscapeScriptBlock(): void
    {
        $out = $this->captureOut(fn () => Ui::chart('c1', [
            'type' => 'line',
            'data' => ['labels' => ['</script><script>alert(1)</script>']],
        ]));
        self::assertStringNotContainsString('</script><script', $out, 'raw close+open must not survive');
        self::assertStringContainsString('<\/script>', $out, 'wp_json_encode escapes the slash');
        self::assertStringContainsString('type="application/json"', $out);
    }

    public function testCopyTextEscapesPayload(): void
    {
        $out = $this->captureOut(fn () => Ui::copyText('"><img src=x onerror=alert(1)>'));
        self::assertStringNotContainsString('<img', $out);
        self::assertStringContainsString('data-aiya-copy="&quot;&gt;&lt;img', $out);
    }

    public function testBulkTableCarriesNonceAndEscapesActionSpec(): void
    {
        $out = $this->captureOut(fn () => Ui::bulkTable(
            'demo_action',
            ['del' => 'Delete'],
            ['t' => ['label' => 'T & <co>']],
            [
                ['id' => 7],
                ['id' => 9],
            ],
            static function ($row, string $column): void {
                echo (string) $row['id'];
            },
            static fn ($row) => $row['id'],
            ['confirm' => ['del' => 'Sure? "really"']]
        ));
        self::assertStringContainsString('name="_wpnonce"', $out, 'the POST round trip carries its nonce');
        self::assertStringContainsString('name="ids[]" value="7"', $out);
        self::assertStringContainsString('<td class="check-column">', $out);
        self::assertStringContainsString('<div class="aiya-core-listnav top aiya-core-listnav--actions">', $out, 'the bulk controls render in a bare operation bar without nav');
        self::assertStringContainsString('T &amp; &lt;co&gt;', $out);
        self::assertStringContainsString('data-confirms="{', $out);
        self::assertStringNotContainsString('"really"', $out, 'no raw quote may sit inside the JSON attribute');
        self::assertStringContainsString('disabled', $out, 'apply starts disabled until BulkView enables it');
    }

    public function testModalSanitizesIdAndStartsHidden(): void
    {
        $out = $this->captureOut(fn () => Ui::modal('m" onmouseover="x', 'Title <b>bold</b>', static function (): void {
        }));
        // The sanitized id is inert: no quotes, no event-handler attribute.
        self::assertStringContainsString('id="monmouseoverx"', $out);
        self::assertStringNotContainsString('onmouseover=', $out);
        self::assertStringNotContainsString('<b>', $out);
        self::assertStringContainsString('style="display:none;"', $out);
    }

    public function testCardDefaultsToOpenAndCarriesDashicon(): void
    {
        $open = $this->captureOut(fn () => Ui::card('S <x>', static function (): void {
        }));
        self::assertStringContainsString('<details class="aiya-core-card" open>', $open);
        self::assertStringContainsString('dashicons-arrow-right-alt2', $open);
        self::assertStringNotContainsString('<x>', $open);

        $collapsed = $this->captureOut(fn () => Ui::card('S', static function (): void {
        }, false));
        self::assertStringContainsString('<details class="aiya-core-card">', $collapsed);
        self::assertStringNotContainsString(' open>', $collapsed);
    }

    public function testStaticCardIsTheSameShellWithoutToggleSemantics(): void
    {
        $out = $this->captureOut(fn () => Ui::staticCard('S <x>', static function (): void {
            echo 'body-content';
        }));
        self::assertStringContainsString('<div class="aiya-core-card aiya-core-card--static">', $out);
        self::assertStringContainsString('<div class="aiya-core-card__summary">S &lt;x&gt;</div>', $out);
        self::assertStringContainsString('<div class="aiya-core-card__body">body-content</div>', $out);
        self::assertStringNotContainsString('<details', $out);
        self::assertStringNotContainsString('<summary', $out);
        self::assertStringNotContainsString('dashicons', $out);
    }

    public function testListNavWrapsInKitBar(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=demo';
        $top = $this->captureOut(fn () => Ui::listNav(23, 1, 8, 'top'));
        self::assertStringContainsString('<div class="aiya-core-listnav top"><div class="aiya-core-listnav-pages"><span class="aiya-core-listnav-num">', $top, 'the bar is kit-owned markup, no core tablenav classes');
        self::assertStringNotContainsString('tablenav', $top);
        $bottom = $this->captureOut(fn () => Ui::listNav(23, 1, 8, 'bottom'));
        self::assertStringContainsString('<div class="aiya-core-listnav bottom">', $bottom);

        $single = $this->captureOut(fn () => Ui::listNav(5, 1, 8, 'top'));
        self::assertStringContainsString(' one-page', $single, 'a single page flags itself so the stylesheet hides the links');
    }

    public function testListNavTopComposesActionsLeftOfPagination(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=demo';
        $out = $this->captureOut(fn () => Ui::listNav(23, 1, 8, 'top', [
            'actions' => static function (): void {
                echo '<form class="aiya-core-filters"></form>';
            },
        ]));
        self::assertStringContainsString('aiya-core-listnav top aiya-core-listnav--actions', $out, 'the composed row declares its layout');
        self::assertStringContainsString('<div class="aiya-core-listnav-actions"><form class="aiya-core-filters"></form></div>', $out);
        self::assertLessThan(
            (int) strpos($out, 'aiya-core-listnav-pages'),
            (int) strpos($out, 'aiya-core-listnav-actions'),
            'operations render left of the pagination'
        );
    }

    public function testListNavTopWithoutActionsStaysABarePaginationRow(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=demo';
        $out = $this->captureOut(fn () => Ui::listNav(23, 1, 8, 'top'));
        self::assertStringNotContainsString('aiya-core-listnav--actions', $out);
        self::assertStringNotContainsString('aiya-core-listnav-actions', $out);
    }

    public function testListNavBottomNeverHostsActions(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=demo';
        $out = $this->captureOut(fn () => Ui::listNav(23, 1, 8, 'bottom', [
            'actions' => static function (): void {
                echo '<form class="aiya-core-filters"></form>';
            },
        ]));
        self::assertStringNotContainsString('aiya-core-listnav-actions', $out, 'the bottom bar is a pure pagination readout');
    }

    public function testButtonMountsIconLeftOfLabel(): void
    {
        $out = $this->captureOut(fn () => Ui::button('Run now', ['icon' => 'controls-play']));
        self::assertStringContainsString('class="button aiya-core-button aiya-core-button--icon"', $out, 'icon-bearing buttons declare their layout');
        self::assertStringContainsString('<span class="dashicons dashicons-controls-play" aria-hidden="true"></span>', $out);
        self::assertLessThan(
            (int) strpos($out, 'Run now'),
            (int) strpos($out, 'dashicons-controls-play'),
            'the icon renders left of the label'
        );

        $plain = $this->captureOut(fn () => Ui::button('Filter'));
        self::assertStringContainsString('class="button aiya-core-button"', $plain, 'every kit button carries the kit class so mixed rows align');
        self::assertStringNotContainsString('aiya-core-button--icon', $plain);
        self::assertStringContainsString('>Filter</button>', $plain);
    }

    public function testInputTypeaheadSwitchRendersConfig(): void
    {
        $out = $this->captureOut(fn () => Ui::input('user', 'text', '', [
            'typeahead' => ['action' => 'my_action', 'nonce' => 'tok', 'fill' => 'id'],
            'autocomplete' => 'off',
        ]));
        self::assertStringContainsString('data-aiya-typeahead="1"', $out);
        self::assertStringContainsString('data-action="my_action"', $out);
        self::assertStringContainsString('data-nonce="tok"', $out);
        self::assertStringContainsString('data-fill="id"', $out);
        self::assertStringContainsString('data-min-chars="2"', $out);
        self::assertStringContainsString('autocomplete="off"', $out);

        $plain = $this->captureOut(fn () => Ui::input('q', 'text', ''));
        self::assertStringNotContainsString('data-aiya-typeahead', $plain);
    }

    public function testBulkTableComposesNavAndSelectPart(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=demo';
        $rows = [['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]];
        $out = $this->captureOut(fn () => Ui::bulkTable(
            'demo_action',
            ['del' => 'Delete'],
            ['t' => ['label' => 'T']],
            $rows,
            static function ($row, string $column): void {
                echo (string) $row['id'];
            },
            static fn ($row) => $row['id'],
            ['nav' => true, 'paged' => 1, 'per_page' => 3, 'per_page_choices' => [3, 6], 'per_page_nav' => true]
        ));
        self::assertStringContainsString('<div class="aiya-core-listnav top aiya-core-listnav--actions">', $out, 'bulk controls compose into the shared nav bar');
        self::assertStringContainsString('<div class="aiya-core-listnav bottom">', $out);
        self::assertStringContainsString('name="bulk_action"', $out, 'the action dropdown rides the shared select part');
        self::assertLessThan(
            (int) strpos($out, 'aiya-core-listnav-pages'),
            (int) strpos($out, 'name="bulk_action"'),
            'bulk controls render left of the pagination'
        );
        self::assertStringContainsString('aria-label="Bulk actions"', $out);
        self::assertStringContainsString("value=\"3\" selected='selected'", $out);
        self::assertStringContainsString('4 items', $out);
        self::assertStringContainsString('name="ids[]" value="3"', $out);
        self::assertStringNotContainsString('name="ids[]" value="4"', $out, 'nav mode slices rows to the current page');
    }

    public function testUserPickerEscapesConfig(): void
    {
        $out = $this->captureOut(fn () => Ui::userPicker([
            'action' => 'my" action',
            'nonce' => 'abc',
            'fill' => 'id',
            'hidden' => 'h',
            'search_name' => 's',
            'placeholder' => '<x>',
        ]));
        self::assertStringNotContainsString('my" action', $out);
        self::assertStringNotContainsString('<x>', $out);
        self::assertStringContainsString('data-aiya-typeahead="1"', $out, 'config rides the input through the shared switch');
        self::assertStringContainsString('data-min-chars="2"', $out);
        self::assertStringContainsString('autocomplete="off"', $out);
        self::assertStringContainsString('class="aiya-user-id"', $out);
    }

    public function testEmailFillOmitsHiddenInput(): void
    {
        $out = $this->captureOut(fn () => Ui::userPicker([
            'action' => 'a',
            'nonce' => 'n',
            'fill' => 'email',
            'hidden' => 'ignored',
        ]));
        self::assertStringNotContainsString('aiya-user-id', $out, 'email pickers carry no hidden id input');
        self::assertStringContainsString('data-fill="email"', $out);
    }

    public function testBulkTableTotalOverrideReadsRealSizeForSqlPaginatedCallers(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=demo';
        // The caller already sliced in SQL: two rows on page 2 of a
        // 23-row match at 2 per page.
        $slice = [['id' => 1], ['id' => 2]];
        $out = $this->captureOut(fn () => Ui::bulkTable(
            'demo_action',
            ['del' => 'Delete'],
            ['t' => ['label' => 'T']],
            $slice,
            static function ($row, string $column): void {
                echo (string) $row['id'];
            },
            static fn ($row) => $row['id'],
            ['nav' => true, 'paged' => 2, 'per_page' => 2, 'total' => 23]
        ));
        self::assertStringContainsString('23 items', $out, 'the nav count reads the matched total, not the slice length');
        self::assertStringContainsString('2 of 12', $out);
        self::assertStringContainsString('name="ids[]" value="1"', $out);
        self::assertStringContainsString('name="ids[]" value="2"', $out, 'the slice renders as-is, no re-slicing');
        self::assertStringNotContainsString('name="ids[]" value="3"', $out);
    }

    public function testListNavIsPassiveWithoutSwitches(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=demo';
        $out = $this->captureOut(fn () => Ui::listNav(23, 1, 8, 'bottom'));
        self::assertStringNotContainsString('data-aiya-jump-page', $out, 'jump navigation is opt-in');
        self::assertStringNotContainsString('data-aiya-per-page', $out, 'the per-page select is opt-in');
        self::assertStringContainsString('1 of 3', $out, 'the static anatomy always renders');
    }

    public function testListNavSwitchesRenderTriggerAttributes(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=demo';
        $out = $this->captureOut(fn () => Ui::listNav(23, 1, 8, 'top', ['jump_nav' => true]));
        self::assertStringContainsString('data-aiya-jump-page="1"', $out);

        $bottom = $this->captureOut(fn () => Ui::listNav(23, 1, 8, 'bottom', ['per_page_nav' => true, 'per_page_choices' => [8]]));
        self::assertStringContainsString('data-aiya-per-page', $bottom);
        self::assertStringContainsString("value=\"8\" selected='selected'", $bottom);
    }

    public function testInputJumpPageSwitch(): void
    {
        $out = $this->captureOut(fn () => Ui::input('paged', 'text', '2', ['jump_page' => true]));
        self::assertStringContainsString('data-aiya-jump-page="1"', $out);

        $plain = $this->captureOut(fn () => Ui::input('paged', 'text', '2'));
        self::assertStringNotContainsString('data-aiya-jump-page', $plain);
    }

    public function testListNavTopHasJumpInputAndDisabledBounds(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=demo';
        $out = $this->captureOut(fn () => Ui::listNav(23, 1, 8, 'top'));
        self::assertStringContainsString('aiya-core-listnav-links', $out);
        self::assertStringContainsString('23 items', $out);
        self::assertStringContainsString('name="paged" value="1"', $out, 'the top bar carries the jump input');
        self::assertStringContainsString('button aiya-core-button disabled" aria-hidden="true">&laquo;</span>', $out, 'first is inert on page 1');
        self::assertStringContainsString('button aiya-core-button disabled" aria-hidden="true">&lsaquo;</span>', $out, 'prev is inert on page 1');
        self::assertStringNotContainsString('first-page button', $out);
        self::assertStringContainsString('next-page button aiya-core-button" href="?paged=2', $out);
        self::assertStringContainsString('last-page button aiya-core-button" href="?paged=3', $out);
        self::assertStringContainsString('1 of 3', $out);
        self::assertStringNotContainsString('data-aiya-per-page', $out, 'the top bar carries no per-page select');
    }

    public function testListNavBottomIsStaticAndCarriesPerPage(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=demo';
        $out = $this->captureOut(fn () => Ui::listNav(23, 2, 8, 'bottom', ['per_page_nav' => true, 'per_page_choices' => [8, 20, 50]]));
        self::assertStringNotContainsString('name="paged"', $out, 'the bottom bar reads as static text');
        self::assertStringContainsString('2 of 3', $out);
        self::assertStringContainsString('first-page button aiya-core-button" href="/wp-admin/admin.php?page=demo"', $out, 'first drops paged entirely');
        self::assertStringContainsString('data-aiya-per-page', $out);
        self::assertStringContainsString("value=\"8\" selected='selected'", $out);
        self::assertStringContainsString('8 / page', $out);
    }

    public function testListNavDisablesForwardArrowsOnLastPage(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=demo';
        $out = $this->captureOut(fn () => Ui::listNav(23, 9, 8, 'top'));
        self::assertStringContainsString('button aiya-core-button disabled" aria-hidden="true">&rsaquo;</span>', $out);
        self::assertStringContainsString('button aiya-core-button disabled" aria-hidden="true">&raquo;</span>', $out);
        self::assertStringNotContainsString('paged=4', $out, 'clamped current never links past the last page');
        self::assertStringContainsString('name="paged" value="3"', $out, 'jump input shows the clamped page');
    }

    public function testSelectEscapesOptionsAndMarksSelected(): void
    {
        $out = $this->captureOut(fn () => Ui::select('kind', ['a' => '<x>', 'b' => 'B'], 'b'));
        self::assertStringNotContainsString('<x>', $out);
        self::assertStringContainsString("value=\"b\" selected='selected'", $out);
        self::assertStringNotContainsString("value=\"a\" selected", $out);
    }

    public function testInputWhitelistsType(): void
    {
        $out = $this->captureOut(fn () => Ui::input('evil', 'script" onmouseover="x', 'v', ['placeholder' => '<p>']));
        self::assertStringContainsString('type="text"', $out, 'unknown types fall back to text');
        self::assertStringNotContainsString('onmouseover=', $out);
        self::assertStringNotContainsString('<p>', $out);

        $out = $this->captureOut(fn () => Ui::input('rows', 'number', 20, ['min' => 1, 'max' => 99]));
        self::assertStringContainsString('type="number"', $out);
        self::assertStringContainsString('value="20"', $out);
    }

    public function testFilterBarCarriesPerPageAcrossSubmissions(): void
    {
        $render = fn (): string => $this->captureOut(fn () => Ui::filterBar(
            'Go',
            static function (): void {
            },
            ['page' => 'demo']
        ));

        unset($_GET['per_page']);
        self::assertStringNotContainsString('name="per_page"', $render(), 'nothing to carry when unset');

        $_GET['per_page'] = '50';
        self::assertStringContainsString('name="per_page" value="50"', $render(), 'the current choice survives a GET submission');

        $_GET['per_page'] = ['array'];
        self::assertStringNotContainsString('name="per_page"', $render(), 'array-shaped values are ignored');
    }

    private static function captureOut(callable $render): string
    {
        ob_start();
        $render();

        return (string) ob_get_clean();
    }
}

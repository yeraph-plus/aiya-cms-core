<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Smilies;

use Aiya\Core\Contracts\Module;

/**
 * Domain entry point for the directory-convention smilies packs. Besides
 * housing the registry and the renderer, the domain pins the core ASCII
 * emoticon option off: `use_smilies` is filtered (never persisted) to a
 * falsy value so convert_smilies exits early on every surface and the
 * site's only emoticon markup is the `::code::` renderer. The Writing
 * screen keeps its checkbox but it stops mattering while the plugin is
 * active; deactivating restores core behaviour.
 *
 * HeadlessModule's `disable_emoji` switch is a different feature — the
 * s.w.org emoji scripts and feed staticization — and stays independent.
 */
final class SmiliesModule implements Module
{
    public function register(): void
    {
        // get_option short-circuits on any non-false pre value, so the
        // falsy string '0' is how an option gets pinned off.
        add_filter('pre_option_use_smilies', static fn (): string => '0');
    }
}

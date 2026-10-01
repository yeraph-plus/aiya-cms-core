<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Integrations;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Registry;

/**
 * Owns the generic companion-service binding surface's domain side: one
 * shared service key, fielded at the top of the file-serve page (the
 * download-services page this integration exists for), plus the ticket
 * and service-key vocabulary the machine endpoints consume (ticket
 * sign-in, credit spending, balance reads — the controller mounts those
 * and announces their namespace to the headless REST gate). The first
 * consumer is the Eh Downloader; any future self-hosted service binds
 * the same way with zero further core changes — mint a ticket against a
 * site user's bearer, redeem it with the key, spend credits with the key.
 */
final class IntegrationsModule implements Module
{
    public function __construct(private Registry $settings)
    {
    }

    public function register(): void
    {
        // Priority after the page owner (10), with prependFields putting
        // the group at the very top.
        add_action('aiya_core_register', [$this, 'settings'], 11, 0);
    }

    public function settings(): void
    {
        $this->settings->prependFields('fileserve', [
            [
                'id' => 'heading_integrations',
                'type' => 'heading',
                'label' => __('Service integrations', 'aiya-core'),
                'level' => '2',
            ],
            [
                'id' => 'service_key',
                'type' => 'password',
                'label' => __('Service key', 'aiya-core'),
                'description' => __('One shared key for every self-hosted companion service that binds site sign-in and credit spending. Generate a random key with the button (or paste your own) and configure it in each service; leave empty to switch the integration endpoints off. Rotating the key means updating every bound service.', 'aiya-core'),
                'generate' => true,
                'default' => '',
            ],
        ]);
    }
}

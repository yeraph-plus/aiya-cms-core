<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Registry;

/**
 * Developer sandbox page of the Dev Tools domain: every persisted field
 * type of the settings framework on one screen, detached from the
 * production pages. Loads only when WP_DEBUG is on and renders as a
 * submenu of the Dev Tools menu through the shared settings pipeline —
 * the fields are the point (the page exercises the framework's field
 * renderers and save flow), so it stays a registry page even though the
 * Dev Tools domain owns its definition, slug family and menu slot.
 */
final class SamplePage implements Module
{
    public function __construct(private Registry $registry)
    {
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'settings'], 10, 0);
    }

    public function settings(): void
    {
        if (!(defined('WP_DEBUG') && WP_DEBUG)) {
            return;
        }

        $this->registry->addPage([
            'slug' => 'devtools-sample',
            'title' => __('Settings Kit', 'aiya-core'),
            'menu_title' => __('Settings Kit', 'aiya-core'),
            'parent' => 'aiya-core-devtools',
            'menu_position' => 7, // after the server status mirror, ahead of the UI Kit sandbox
            // The sandbox is display-only: the field parts stay flat, no tab sections.
            'tabs' => false,
            'option_name' => 'aiya_core_sample',
            'fields' => [
                [
                    'id' => 'sandbox_note',
                    'type' => 'note',
                    'label' => __('Declarative field form parts: a field definition array renders as form-table rows through the settings pipeline, with one save and reset endpoint for the whole page. Every persisted field type of the framework is exercised below.', 'aiya-core'),
                    'variant' => 'info',
                ],
                [
                    'id' => 'text_heading',
                    'type' => 'heading',
                    'label' => __('Text inputs', 'aiya-core'),
                ],
                [
                    'id' => 'site_title',
                    'type' => 'text',
                    'label' => __('Site title', 'aiya-core'),
                    'description' => __('Required text field.', 'aiya-core'),
                    'default' => 'AIYA CMS Core',
                    'required' => true,
                    'attributes' => ['autocomplete' => 'off'],
                ],
                [
                    'id' => 'site_description',
                    'type' => 'textarea',
                    'label' => __('Site description', 'aiya-core'),
                    'description' => __('Multiline text stored through sanitize_textarea_field.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'cache_ttl',
                    'type' => 'number',
                    'label' => __('Cache lifetime', 'aiya-core'),
                    'description' => __('Numeric field with min, max and step constraints.', 'aiya-core'),
                    'default' => 3600,
                    'min' => 0,
                    'max' => 86400,
                    'step' => 60,
                ],
                [
                    'id' => 'contact_email',
                    'type' => 'email',
                    'label' => __('Contact email', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'public_url',
                    'type' => 'url',
                    'label' => __('Public URL', 'aiya-core'),
                    'description' => __('URL field, validated on save.', 'aiya-core'),
                    'default' => '',
                    'attributes' => ['placeholder' => 'https://example.com'],
                ],
                [
                    'id' => 'sample_api_key',
                    'type' => 'password',
                    'label' => __('Sample API key', 'aiya-core'),
                    'description' => __('Write-only password field, the stored value is never echoed back.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'tracking_id',
                    'type' => 'hidden',
                    'default' => 'sample-hidden',
                ],
                [
                    'id' => 'choices_heading',
                    'type' => 'heading',
                    'label' => __('Choices', 'aiya-core'),
                ],
                [
                    'id' => 'feature_enabled',
                    'type' => 'checkbox',
                    'label' => __('Feature flag', 'aiya-core'),
                    'checkbox_label' => __('Enable the sample feature', 'aiya-core'),
                    'description' => __('The plain checkbox: a hidden value=0 input carries an unticked state through the submission.', 'aiya-core'),
                    'default' => true,
                ],
                [
                    'id' => 'debug_mode',
                    'type' => 'switch',
                    'label' => __('Debug mode', 'aiya-core'),
                    'checkbox_label' => __('Enable debug mode', 'aiya-core'),
                    'description' => __('The boolean switch: native checkbox semantics under a styled track, the stored value stays 0 or 1.', 'aiya-core'),
                    'default' => false,
                ],
                [
                    'id' => 'content_mode',
                    'type' => 'select',
                    'label' => __('Content mode', 'aiya-core'),
                    'default' => 'hybrid',
                    'options' => [
                        'classic' => __('Classic', 'aiya-core'),
                        'hybrid' => __('Hybrid', 'aiya-core'),
                        'headless' => __('Headless', 'aiya-core'),
                    ],
                ],
                [
                    'id' => 'default_locale',
                    'type' => 'radio',
                    'label' => __('Default locale', 'aiya-core'),
                    'description' => __('Radio options render inline with even spacing.', 'aiya-core'),
                    'default' => 'zh_CN',
                    'options' => [
                        'zh_CN' => __('Simplified Chinese', 'aiya-core'),
                        'zh_TW' => __('Traditional Chinese', 'aiya-core'),
                        'en_US' => __('English', 'aiya-core'),
                    ],
                ],
                [
                    'id' => 'sample_features',
                    'type' => 'multicheck',
                    'label' => __('Enabled modules', 'aiya-core'),
                    'description' => __('Checkbox options render inline with even spacing.', 'aiya-core'),
                    'default' => ['seo', 'cache'],
                    'options' => [
                        'seo' => __('SEO', 'aiya-core'),
                        'cache' => __('Cache', 'aiya-core'),
                        'translate' => __('Translation', 'aiya-core'),
                        'cdn' => __('CDN', 'aiya-core'),
                    ],
                ],
                [
                    'id' => 'values_heading',
                    'type' => 'heading',
                    'label' => __('Media and value lists', 'aiya-core'),
                ],
                [
                    'id' => 'color_warning',
                    'type' => 'note',
                    'label' => __('Media fields store the attachment ID.', 'aiya-core'),
                    'variant' => 'warning',
                ],
                [
                    'id' => 'accent_color',
                    'type' => 'color',
                    'label' => __('Accent color', 'aiya-core'),
                    'default' => '#2271b1',
                ],
                [
                    'id' => 'logo',
                    'type' => 'media',
                    'label' => __('Logo', 'aiya-core'),
                    'description' => __('The field stores a WordPress attachment ID rather than a URL.', 'aiya-core'),
                    'default' => 0,
                ],
                [
                    'id' => 'allowed_hosts',
                    'type' => 'array',
                    'label' => __('Allowed hosts', 'aiya-core'),
                    'description' => __('Enter comma-separated values.', 'aiya-core'),
                    'default' => [],
                ],
                [
                    'id' => 'http_headers',
                    'type' => 'key_value',
                    'label' => __('Custom headers', 'aiya-core'),
                    'description' => __('One "key: value" pair per line; keys are normalized through sanitize_key().', 'aiya-core'),
                    'default' => [],
                ],
                [
                    'id' => 'editor_heading',
                    'type' => 'heading',
                    'label' => __('Editor content', 'aiya-core'),
                ],
                [
                    'id' => 'sample_json',
                    'type' => 'code',
                    'label' => __('JSON configuration', 'aiya-core'),
                    'description' => __('Uses the WP code editor (wp.codeEditor).', 'aiya-core'),
                    'mime' => 'application/json',
                    'default' => "{\n  \"enabled\": true\n}",
                ],
                [
                    'id' => 'editor_content',
                    'type' => 'tinymce',
                    'label' => __('Classic editor content', 'aiya-core'),
                    'description' => __('Uses the classic editor through wp_editor with TinyMCE.', 'aiya-core'),
                    'default' => '<p>AIYA CMS Core classic editor sample.</p>',
                ],
                [
                    'id' => 'repeater_heading',
                    'type' => 'heading',
                    'label' => __('Dynamic rows', 'aiya-core'),
                ],
                [
                    'id' => 'navigation_links',
                    'type' => 'repeater',
                    'label' => __('Navigation links', 'aiya-core'),
                    'description' => __('Backbone manages item creation; jQuery UI provides drag sorting.', 'aiya-core'),
                    'default' => [],
                    'children' => [
                        [
                            'id' => 'label',
                            'type' => 'text',
                            'label' => __('Label', 'aiya-core'),
                            'required' => true,
                        ],
                        [
                            'id' => 'url',
                            'type' => 'url',
                            'label' => __('URL', 'aiya-core'),
                            'required' => true,
                        ],
                        [
                            'id' => 'priority',
                            'type' => 'number',
                            'label' => __('Priority', 'aiya-core'),
                            'default' => 0,
                            'min' => 0,
                            'max' => 100,
                        ],
                        [
                            'id' => 'enabled',
                            'type' => 'checkbox',
                            'label' => __('Enabled', 'aiya-core'),
                            'default' => true,
                        ],
                    ],
                ],
            ],
        ]);
    }
}

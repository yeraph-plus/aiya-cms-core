<?php
/**
 * Fallback template. The public frontend is served by Astro; this page only
 * guarantees that direct WordPress hits render something harmless.
 *
 * Deliberately free of the_* outlets: WordPress is the data backend of this
 * site, never its rendering surface. Only the shell parts stay — wp_head(),
 * wp_footer(), wp_body_open() and the body classes — so plugins keep their
 * hooks and direct hits still get a valid document.
 *
 * @package AIYA_Headless_Shell
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php /* wp_get_document_title() is display-ready text, echoed unescaped exactly as core's _wp_render_title_tag() does. */ ?>
    <title><?php echo wp_get_document_title(); ?></title>
    <?php wp_head(); ?>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            box-sizing: border-box;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 20px;
            background: #f6f7f9;
            color: #1f2328;
            font: 16px/1.7 system-ui, -apple-system, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
        }
        .shell-main {
            width: 100%;
            max-width: 620px;
            padding: 32px 36px;
            background: #fff;
            border: 1px solid #e2e4e7;
            border-radius: 12px;
            box-shadow: 0 1px 2px rgb(0 0 0 / 4%);
        }
        .shell-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0 0 16px;
        }
        .shell-logo-img {
            flex: none;
            width: 48px;
            height: 48px;
            object-fit: contain;
            border-radius: 10px;
        }
        .shell-title {
            margin: 0;
            font-size: 22px;
            line-height: 1.4;
        }
        .shell-text p {
            margin: 0 0 12px;
        }
        .shell-text p:last-child {
            margin-bottom: 0;
        }
        .shell-admin-entry {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 6px 14px;
            border: 1px solid #d0d3d8;
            border-radius: 999px;
            background: #fff;
            color: #1f2328;
            font-size: 13px;
            line-height: 1.6;
            text-decoration: none;
        }
        .shell-admin-entry:hover,
        .shell-admin-entry:focus {
            border-color: #8c8f94;
        }
    </style>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<?php if (is_user_logged_in()) : ?>
    <a class="shell-admin-entry" href="<?php echo esc_url(admin_url()); ?>"><?php esc_html_e('Dashboard', 'aiya-headless'); ?></a>
<?php endif; ?>

<main class="shell-main">
    <div class="shell-brand">
        <?php
        // The core Site Icon doubles as the shell logo (managed in Settings →
        // General, same attachment the favicon links come from).
        $aiya_shell_icon_id = (int) get_option('site_icon');
        if ($aiya_shell_icon_id > 0) {
            echo wp_get_attachment_image($aiya_shell_icon_id, 'thumbnail', false, ['class' => 'shell-logo-img', 'alt' => get_bloginfo('name')]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapes its own attributes.
        }
        ?>
        <h1 class="shell-title"><?php echo esc_html(get_bloginfo('name')); ?></h1>
    </div>
    <div class="shell-text">
        <?php
        // Editable via Appearance → Shell appearance; kses'd on write AND
        // on read (idempotent). Empty keeps the built-in placeholder.
        $aiya_shell_intro = trim((string) get_theme_mod('aiya_shell_intro', ''));
        if ($aiya_shell_intro !== '') {
            echo wp_kses_post($aiya_shell_intro); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kses whitelist output.
        } else {
            ?>
            <p><?php esc_html_e('This WordPress install is headless: it stores content, media and users, and serves the versioned REST API. The public site is rendered by the Astro front end.', 'aiya-headless'); ?></p>
            <p><?php esc_html_e('Any direct hit on this domain lands here. Everything else is managed from the dashboard.', 'aiya-headless'); ?></p>
            <?php
        }
        ?>
    </div>
</main>

<?php wp_footer(); ?>
</body>
</html>

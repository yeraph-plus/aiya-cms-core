<?php
/**
 * Shell header: document head and the brand row.
 *
 * The admin bar is core's own behavior (logged-in sessions get it on the
 * frontend, styles and body class included); this theme neither enables
 * nor disables it.
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
</head>
<body <?php body_class('wp-core-ui wp-admin'); ?>>
<?php wp_body_open(); ?>

<div class="aiya-shell">
    <header class="aiya-brand">
        <?php
        // The core Site Icon doubles as the shell logo (managed in Settings → General).
        $aiya_shell_icon_id = (int) get_option('site_icon');
        if ($aiya_shell_icon_id > 0) {
            echo wp_get_attachment_image($aiya_shell_icon_id, 'thumbnail', false, ['class' => 'aiya-logo', 'alt' => get_bloginfo('name')]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapes its own attributes.
        }
        ?>
        <?php if (is_front_page() && is_home()) : ?>
            <?php /* The site title is the page's h1 here; the list template prints no heading on the front page. */ ?>
            <h1 class="aiya-site-title"><a href="<?php echo esc_url(home_url('/')); ?>"><?php echo esc_html((string) get_bloginfo('name')); ?></a></h1>
        <?php else : ?>
            <p class="aiya-site-title"><a href="<?php echo esc_url(home_url('/')); ?>"><?php echo esc_html((string) get_bloginfo('name')); ?></a></p>
        <?php endif; ?>
    </header>

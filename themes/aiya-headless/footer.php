<?php
/**
 * Shell footer.
 *
 * @package AIYA_Headless_Shell
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
    <footer class="aiya-footer">
        &copy; <?php echo esc_html((string) get_bloginfo('name')); ?>
    </footer>
</div><!-- .aiya-shell -->

<?php wp_footer(); ?>
</body>
</html>

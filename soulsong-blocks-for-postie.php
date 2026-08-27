<?php
/**
 * Plugin Name: Soulsong HTML and MD Blocks for Postie
 * Plugin URI:  https://github.com/soulsong-uk/soulsong-blocks-for-postie
 * Description: Addon for Postie that converts emailed content into native Gutenberg blocks (paragraphs, headings, images, lists, quotes) instead of Postie's default raw HTML output - with optional Markdown parsing for senders who want to style their email more expressively.
 * Version:     0.1.14
 * Requires at least: 6.2
 * Requires PHP: 8.2
 * Requires Plugins: postie
 * Author:      James Harvey 
 * License:     GPLv2 or later
 * Text Domain: html-md-blocks-for-postie
 */

defined('ABSPATH') || exit;

define('HTML_MD_BLOCKS_FOR_POSTIE_VERSION', '0.1.14');
define('HTML_MD_BLOCKS_FOR_POSTIE_PLUGIN_FILE', __FILE__);
define('HTML_MD_BLOCKS_FOR_POSTIE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HTML_MD_BLOCKS_FOR_POSTIE_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * PSR-4 autoloader for the HtmlMdBlocksForPostie\ namespace (source lives
 * under src/, no Composer/build step - matches the workspace's other
 * plugins).
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'HtmlMdBlocksForPostie\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file     = HTML_MD_BLOCKS_FOR_POSTIE_PLUGIN_DIR . 'src' . DIRECTORY_SEPARATOR . $relative . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Boot on plugins_loaded, priority 20 - after Postie's own plugins_loaded
 * registration (postie.php uses the default priority 10), so Postie's
 * classes and globals ($g_postie) are guaranteed to exist by the time we
 * check for them and start hooking postie_* actions/filters.
 */
add_action('plugins_loaded', function (): void {
    if (!class_exists('Postie')) {
        add_action('admin_notices', function (): void {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html(\HtmlMdBlocksForPostie\Strings::get('requires_postie_notice'))
            );
        });
        return;
    }

    \HtmlMdBlocksForPostie\Plugin::instance()->boot();
}, 20);

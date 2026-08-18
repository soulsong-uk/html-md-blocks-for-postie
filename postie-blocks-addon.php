<?php
/**
 * Plugin Name: Postie Blocks Addon
 * Plugin URI:  https://github.com/soulsong/postie-blocks-addon
 * Description: Addon for Postie that converts emailed content into native Gutenberg blocks (paragraphs, headings, images, lists, quotes) instead of Postie's default raw HTML output - with optional Markdown parsing for senders who want to style their email more expressively.
 * Version:     0.1.7
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Requires Plugins: postie
 * Author:      James Harvey
 * License:     GPLv2 or later
 * Text Domain: postie-blocks-addon
 */

defined('ABSPATH') || exit;

define('POSTIE_BLOCKS_VERSION', '0.1.7');
define('POSTIE_BLOCKS_PLUGIN_FILE', __FILE__);
define('POSTIE_BLOCKS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('POSTIE_BLOCKS_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * PSR-4 autoloader for the PostieBlocksAddon\ namespace (source lives under
 * src/, no Composer/build step - matches the workspace's other plugins).
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'PostieBlocksAddon\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file     = POSTIE_BLOCKS_PLUGIN_DIR . 'src' . DIRECTORY_SEPARATOR . $relative . '.php';
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
                esc_html__('Postie Blocks Addon requires the Postie plugin to be installed and active - it has no effect on its own.', 'postie-blocks-addon')
            );
        });
        return;
    }

    \PostieBlocksAddon\Plugin::instance()->boot();
}, 20);

<?php

namespace PostieMd;

defined('ABSPATH') || exit;

/**
 * Central bootstrap. A singleton purely to give plugins_loaded a single
 * stable entry point - nothing here depends on repeated instantiation
 * being safe.
 */
class Plugin
{
    private static ?Plugin $instance = null;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
    }

    public function boot(): void
    {
        load_plugin_textdomain('postie-md-plugin', false, dirname(plugin_basename(POSTIE_MD_PLUGIN_FILE)) . '/languages');

        // Registered unconditionally, not just is_admin() - Postie's own
        // email processing runs via WP-Cron and the "?postie=get-mail"
        // front-end trigger, neither of which is an admin request.
        (new Hooks())->register();

        if (is_admin()) {
            (new Settings())->register();
        }
    }
}

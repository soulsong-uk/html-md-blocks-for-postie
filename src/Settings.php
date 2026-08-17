<?php

namespace PostieMd;

defined('ABSPATH') || exit;

/**
 * A single v1 toggle - everything else (which tags map to which blocks, the
 * core/html fallback) is hardcoded rather than configurable, to keep launch
 * scope tight.
 */
class Settings
{
    private const OPTION = 'postie_md_settings';

    public static function isMarkdownEnabled(): bool
    {
        $settings = get_option(self::OPTION, []);
        if (!is_array($settings) || !array_key_exists('markdown_enabled', $settings)) {
            return true; // default on
        }
        return (bool) $settings['markdown_enabled'];
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSetting']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addMenuPage(): void
    {
        // Submenu under Postie's own top-level menu (registered by Postie
        // itself with slug "postie-settings") rather than a new top-level
        // entry, so this addon reads as part of Postie's settings surface.
        add_submenu_page(
            'postie-settings',
            __('Markdown Blocks', 'postie-md-plugin'),
            __('Markdown Blocks', 'postie-md-plugin'),
            'manage_options',
            'postie-md-settings',
            [$this, 'renderPage']
        );
    }

    public function registerSetting(): void
    {
        register_setting('postie_md_settings_group', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default' => ['markdown_enabled' => true],
        ]);
    }

    /**
     * @param mixed $input
     * @return array{markdown_enabled: bool}
     */
    public function sanitize($input): array
    {
        return [
            'markdown_enabled' => !empty($input['markdown_enabled']),
        ];
    }

    public function enqueueAssets(): void
    {
        // Checked via $_GET['page'] rather than the admin_enqueue_scripts
        // $hook_suffix argument - that suffix is derived from the parent
        // menu's slug ("postie-settings_page_postie-md-settings") which is
        // easy to get subtly wrong; the page slug itself is a stable target.
        if (!isset($_GET['page']) || $_GET['page'] !== 'postie-md-settings') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        wp_enqueue_style(
            'postie-md-admin',
            POSTIE_MD_PLUGIN_URL . 'assets/css/admin.css',
            [],
            POSTIE_MD_VERSION
        );
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $markdownEnabled = self::isMarkdownEnabled();
        ?>
        <div class="wrap postie-md-settings-wrap">
            <h1><?php esc_html_e('Postie Markdown Blocks', 'postie-md-plugin'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('postie_md_settings_group'); ?>
                <div class="postie-md-card">
                    <div class="postie-md-subsection">
                        <p class="postie-md-field-heading"><?php esc_html_e('Content Conversion', 'postie-md-plugin'); ?></p>
                        <label>
                            <input type="checkbox" name="postie_md_settings[markdown_enabled]" value="1" <?php checked($markdownEnabled); ?> />
                            <?php esc_html_e('Parse Markdown syntax (#, **, etc.) in plain-text emails', 'postie-md-plugin'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When off, plain-text emails still convert to Gutenberg paragraph blocks, but Markdown syntax such as "# Heading" is left as literal text instead of becoming a heading block. HTML emails are always normalized into blocks regardless of this setting.', 'postie-md-plugin'); ?>
                        </p>
                    </div>
                </div>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

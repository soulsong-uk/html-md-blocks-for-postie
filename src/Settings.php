<?php

namespace PostieBlocksAddon;

defined('ABSPATH') || exit;

/**
 * Two toggles - Markdown parsing and HTML-to-blocks normalization can each
 * be switched off independently. Everything else (which tags map to which
 * blocks, the core/html fallback) is hardcoded rather than configurable, to
 * keep scope tight. All user-facing copy rendered here is centralized in
 * Strings.php, not written literally in this file - edit copy there.
 */
class Settings
{
    private const OPTION = 'postie_blocks_settings';

    /**
     * The plugin's original option name, from when it was named "Postie
     * Markdown Blocks" (slug postie-md-plugin). migrateOldOption() carries
     * a site's already-saved toggle state forward under the new name so
     * the rename doesn't silently reset it to defaults.
     */
    private const LEGACY_OPTION = 'postie_md_settings';

    public static function isMarkdownEnabled(): bool
    {
        return self::flag('markdown_enabled');
    }

    /**
     * Master switch for HtmlToBlocks - the pass that turns final HTML
     * (Markdown-converted or original) into real Gutenberg block markup.
     * Off means Postie's content is left exactly as Postie itself produced
     * it (or as Markdown-converted semantic HTML, if that ran) - no
     * <!-- wp:x --> block comments get added at all.
     */
    public static function isHtmlEnabled(): bool
    {
        return self::flag('html_enabled');
    }

    private static function flag(string $key): bool
    {
        $settings = get_option(self::OPTION, null);
        if ($settings === null) {
            $settings = self::migrateOldOption();
        }
        if (!is_array($settings) || !array_key_exists($key, $settings)) {
            return true; // default on
        }
        return (bool) $settings[$key];
    }

    /**
     * Runs in any context (admin, cron, the "?postie=get-mail" front-end
     * trigger) - not just admin_init - since Settings::isMarkdownEnabled()/
     * isHtmlEnabled() are called from Hooks::onPostBefore() during normal
     * email processing, which happens outside is_admin() entirely. Safe to
     * call repeatedly: only ever writes once, when the new option doesn't
     * exist yet but the legacy one does; every call after that finds the
     * new option already set and returns immediately via the caller's own
     * get_option() check, without reaching this method again.
     *
     * @return array<string,mixed>|null
     */
    private static function migrateOldOption(): ?array
    {
        $old = get_option(self::LEGACY_OPTION, null);
        if (!is_array($old)) {
            return null;
        }
        update_option(self::OPTION, $old);
        return $old;
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
            Strings::get('menu_title'),
            Strings::get('menu_title'),
            'manage_options',
            'postie-blocks',
            [$this, 'renderPage']
        );
    }

    public function registerSetting(): void
    {
        register_setting('postie_blocks_settings_group', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default' => ['markdown_enabled' => true, 'html_enabled' => true],
        ]);
    }

    /**
     * @param mixed $input
     * @return array{markdown_enabled: bool, html_enabled: bool}
     */
    public function sanitize($input): array
    {
        return [
            'markdown_enabled' => !empty($input['markdown_enabled']),
            'html_enabled'     => !empty($input['html_enabled']),
        ];
    }

    public function enqueueAssets(): void
    {
        // Checked via $_GET['page'] rather than the admin_enqueue_scripts
        // $hook_suffix argument - that suffix is derived from the parent
        // menu's slug ("postie-settings_page_postie-blocks") which is
        // easy to get subtly wrong; the page slug itself is a stable target.
        if (!isset($_GET['page']) || $_GET['page'] !== 'postie-blocks') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        wp_enqueue_style(
            'postie-blocks-admin',
            POSTIE_BLOCKS_PLUGIN_URL . 'assets/css/admin.css',
            [],
            POSTIE_BLOCKS_VERSION
        );
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $markdownEnabled = self::isMarkdownEnabled();
        $htmlEnabled     = self::isHtmlEnabled();
        ?>
        <div class="wrap postie-blocks-settings-wrap">
            <h1><?php echo esc_html(Strings::get('page_heading')); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('postie_blocks_settings_group'); ?>
                <div class="postie-blocks-card">
                    <div class="postie-blocks-subsection">
                        <p class="postie-blocks-field-heading"><?php echo esc_html(Strings::get('content_conversion_heading')); ?></p>
                        <label>
                            <input type="checkbox" name="postie_blocks_settings[markdown_enabled]" value="1" <?php checked($markdownEnabled); ?> />
                            <?php echo esc_html(Strings::get('markdown_toggle_label')); ?>
                        </label>
                        <p class="description">
                            <?php echo esc_html(Strings::get('markdown_toggle_desc')); ?>
                        </p>
                        <p class="description">
                            <?php echo esc_html(Strings::get('markdown_toggle_desc_outside')); ?>
                        </p>
                        <p class="description">
                            <strong><?php echo esc_html(Strings::get('markdown_toggle_example_label')); ?></strong>
                        </p>
                        <pre class="postie-blocks-example"><code><?php echo esc_html(Strings::get('markdown_toggle_example')); ?></code></pre>
                        <label>
                            <input type="checkbox" name="postie_blocks_settings[html_enabled]" value="1" <?php checked($htmlEnabled); ?> />
                            <?php echo esc_html(Strings::get('html_toggle_label')); ?>
                        </label>
                        <p class="description">
                            <?php echo esc_html(Strings::get('html_toggle_desc')); ?>
                        </p>
                    </div>
                </div>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

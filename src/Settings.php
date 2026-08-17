<?php

namespace PostieMd;

defined('ABSPATH') || exit;

/**
 * Two toggles - Markdown parsing and HTML-to-blocks normalization can each
 * be switched off independently. Everything else (which tags map to which
 * blocks, the core/html fallback) is hardcoded rather than configurable, to
 * keep scope tight.
 */
class Settings
{
    private const OPTION = 'postie_md_settings';

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
        $settings = get_option(self::OPTION, []);
        if (!is_array($settings) || !array_key_exists($key, $settings)) {
            return true; // default on
        }
        return (bool) $settings[$key];
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
        $htmlEnabled     = self::isHtmlEnabled();
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
                            <?php esc_html_e('When off, Markdown syntax such as "# Heading" or "**bold**" is left as literal text instead of being parsed - including inside an explicit <md>...</md> block. Doesn\'t affect HTML-to-blocks normalization below.', 'postie-md-plugin'); ?>
                        </p>
                        <label>
                            <input type="checkbox" name="postie_md_settings[html_enabled]" value="1" <?php checked($htmlEnabled); ?> />
                            <?php esc_html_e('Convert content into native Gutenberg blocks', 'postie-md-plugin'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When off, this plugin does not add any <!-- wp:... --> block markup at all - Postie\'s own content (or Markdown-converted HTML, if Markdown parsing above is on) is saved as-is, opening as a single Classic/HTML block in the editor. Turn this off if you only want Markdown parsing without the block conversion.', 'postie-md-plugin'); ?>
                        </p>
                    </div>
                    <div class="postie-md-subsection">
                        <p class="postie-md-field-heading"><?php esc_html_e('Known Conflict: "---" and Signature Stripping', 'postie-md-plugin'); ?></p>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: link to Postie's own settings page (its "Message" tab holds the "Signature Patterns" field). */
                                esc_html__('Postie\'s own default settings treat a line containing only "---" as the start of an email signature and remove everything after it, before this plugin ever sees the content - Postie\'s "Signature Patterns" list, under %s (Message tab), includes "---" by default. Since Markdown commonly uses "---" on its own line as a horizontal rule, a Markdown email using that syntax will lose everything past it. Use "***" or "___" for a horizontal rule instead, or remove "---" from Postie\'s Signature Patterns list if you don\'t rely on automatic signature stripping.', 'postie-md-plugin'),
                                '<a href="' . esc_url(admin_url('admin.php?page=postie-settings')) . '">' . esc_html__("Postie's settings", 'postie-md-plugin') . '</a>'
                            );
                            ?>
                        </p>
                    </div>
                    <div class="postie-md-subsection">
                        <p class="postie-md-field-heading"><?php esc_html_e('Known Conflict: "#" and Allow Subject In Mail', 'postie-md-plugin'); ?></p>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: link to Postie's own settings page (its "Message" tab holds the "Allow Subject In Mail" field). */
                                esc_html__('If Postie\'s "Allow Subject In Mail" setting is on (%s, Message tab) and the email body starts with "#", Postie treats everything up to the NEXT "#" anywhere in the body as the subject and strips it out of the content - intended for a deliberate "#subject#" marker on the first line, but a Markdown email starting with a "# Heading" (with a later "##"/"###" heading further down) gets its entire opening section pulled into the post title instead. Turn off "Allow Subject In Mail" if you don\'t use that feature, or avoid starting the email body with a "#" heading as the very first character.', 'postie-md-plugin'),
                                '<a href="' . esc_url(admin_url('admin.php?page=postie-settings')) . '">' . esc_html__("Postie's settings", 'postie-md-plugin') . '</a>'
                            );
                            ?>
                        </p>
                    </div>
                    <div class="postie-md-subsection">
                        <p class="postie-md-field-heading"><?php esc_html_e('Recommended: Wrap Markdown in <md>...</md>', 'postie-md-plugin'); ?></p>
                        <p class="description">
                            <?php esc_html_e('Postie\'s own newline-collapsing preserves a blank-line paragraph break but reduces a single line break to just a space - the exact line-break style Markdown lists use between items. Wrapping the Markdown portion of an email in <md> and </md> tags shields it from that (and from the "---"/"#" conflicts above) entirely, using your email\'s exact original line breaks. Recommended for any email containing a list.', 'postie-md-plugin'); ?>
                        </p>
                    </div>
                </div>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

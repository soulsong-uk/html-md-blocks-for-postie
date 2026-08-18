<?php

namespace PostieBlocksAddon;

defined('ABSPATH') || exit;

/**
 * Single editable source for this plugin's user-facing PHP-rendered
 * strings - one place to find and change copy instead of hunting across
 * Settings.php and the bootstrap file. This is additive, not a replacement
 * for WordPress i18n. No JS in this plugin currently renders user-facing
 * text (it's a server-rendered admin settings page), so there is no
 * companion strings.js.
 *
 * Each string is wrapped in its own literal __() call right here, rather
 * than routed through one shared __($variable, ...) call in get() - WP.org
 * Plugin Check's WordPress.WP.I18n.NonSingularStringLiteralText sniff (and
 * WordPress's own .pot-generation tooling) require __()'s first argument to
 * be a literal string at the call site, not a variable/array lookup, since
 * both work by statically scanning source rather than executing it.
 */
class Strings
{
    public static function get(string $key): string
    {
        return self::all()[$key] ?? $key;
    }

    /**
     * @return array<string,string>
     */
    private static function all(): array
    {
        return [
            // Bootstrap (postie-blocks-addon.php) - missing-dependency admin notice
            'requires_postie_notice' => __('Postie Blocks Addon requires the Postie plugin to be installed and active - it has no effect on its own.', 'postie-blocks-addon'),

            // Settings page (src/Settings.php) - menu + page heading
            'menu_title'   => __('Postie Blocks', 'postie-blocks-addon'),
            'page_heading' => __('Postie Blocks Addon', 'postie-blocks-addon'),

            // Settings page - Content Conversion subsection
            'content_conversion_heading'    => __('Content Conversion', 'postie-blocks-addon'),
            'markdown_toggle_label'         => __('Convert Markdown to Gutenberg Blocks', 'postie-blocks-addon'),
            'markdown_toggle_desc'          => __('Wrap any part of your email in <md> and </md> tags to turn it into nicely styled content — headings, bold, italic, links, images, and lists all get formatted automatically.', 'postie-blocks-addon'),
            'markdown_toggle_desc_outside'  => __('Anything outside the <md> tags stays as plain text, exactly as written.', 'postie-blocks-addon'),
            'markdown_toggle_example_label' => __('Example:', 'postie-blocks-addon'),
            /* translators: this is a literal <md> Markdown syntax example shown verbatim in a <pre> block, not prose - kept translatable in case a locale wants to show a translated sample. */
            'markdown_toggle_example'       => __("<md>\n# Welcome!\nThis is **bold** and this is *italic*.\n- Item one\n- Item two\n</md>", 'postie-blocks-addon'),
            'html_toggle_label' => __('Convert content into native Gutenberg blocks', 'postie-blocks-addon'),
            'html_toggle_desc'  => __('Wraps the finished content - including any Markdown conversion above - in real Gutenberg block markup (paragraphs, headings, images, lists, quotes), so the post opens as separate, individually editable blocks instead of one Classic/HTML block.', 'postie-blocks-addon'),
        ];
    }
}

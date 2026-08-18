<?php

namespace PostieBlocksAddon;

defined('ABSPATH') || exit;

/**
 * Single editable source for this plugin's user-facing PHP-rendered
 * strings - one place to find and change copy instead of hunting across
 * Settings.php and the bootstrap file. Strings::get() still routes every
 * lookup through __() internally, so WordPress's own translation tooling
 * (.pot/.po/.mo, WordPress.org translate) is unaffected; this is additive,
 * not a replacement for WordPress i18n. No JS in this plugin currently
 * renders user-facing text (it's a server-rendered admin settings page),
 * so there is no companion strings.js.
 */
class Strings
{
    private const ALL = [
        // Bootstrap (postie-blocks-addon.php) - missing-dependency admin notice
        'requires_postie_notice' => 'Postie Blocks Addon requires the Postie plugin to be installed and active - it has no effect on its own.',

        // Settings page (src/Settings.php) - menu + page heading
        'menu_title'   => 'Postie Blocks',
        'page_heading' => 'Postie Blocks Addon',

        // Settings page - Content Conversion subsection
        'content_conversion_heading' => 'Content Conversion',
        'markdown_toggle_label'         => 'Convert Markdown to Gutenberg Blocks',
        'markdown_toggle_desc'          => 'Wrap any part of your email in <md> and </md> tags to turn it into nicely styled content — headings, bold, italic, links, images, and lists all get formatted automatically.',
        'markdown_toggle_desc_outside'  => 'Anything outside the <md> tags stays as plain text, exactly as written.',
        'markdown_toggle_example_label' => 'Example:',
        'markdown_toggle_example'       => "<md>\n# Welcome!\nThis is **bold** and this is *italic*.\n- Item one\n- Item two\n</md>",
        'html_toggle_label'          => 'Convert content into native Gutenberg blocks',
        'html_toggle_desc'           => 'Wraps the finished content - including any Markdown conversion above - in real Gutenberg block markup (paragraphs, headings, images, lists, quotes), so the post opens as separate, individually editable blocks instead of one Classic/HTML block.',
    ];

    public static function get(string $key): string
    {
        return __(self::ALL[$key] ?? $key, 'postie-blocks-addon');
    }
}

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
        'markdown_toggle_label'      => 'Parse Markdown syntax (#, **, etc.) in plain-text emails',
        'markdown_toggle_desc'       => 'When off, Markdown syntax such as "# Heading" or "**bold**" is left as literal text instead of being parsed - including inside an explicit <md>...</md> block. Doesn\'t affect HTML-to-blocks normalization below.',
        'html_toggle_label'          => 'Convert content into native Gutenberg blocks',
        'html_toggle_desc'           => 'When off, this plugin does not add any <!-- wp:... --> block markup at all - Postie\'s own content (or Markdown-converted HTML, if Markdown parsing above is on) is saved as-is, opening as a single Classic/HTML block in the editor. Turn this off if you only want Markdown parsing without the block conversion.',

        // Settings page - "wrap in <md>" recommendation subsection
        'md_wrapper_heading' => 'Recommended: Wrap Markdown in <md>...</md>',
        'md_wrapper_desc'    => 'Postie has several of its own content-processing features - collapsing single line breaks, stripping anything after a "---" line as a signature, pulling an inline "#subject#" out of the body - that all run before this plugin ever sees your email, and each can silently damage Markdown syntax that happens to resemble one of Postie\'s own conventions (see the two notes below for specifics). Wrapping the Markdown portion of an email in <md> and </md> tags shields it from all three at once, converting using your email\'s exact original line breaks. This is the single most reliable way to guarantee any Markdown email - especially one with a list, a "---" rule, or a heading as the very first line - converts correctly.',

        // Settings page - "---" signature-stripping conflict subsection
        // translators: %s: link to Postie's own settings page (its "Message" tab holds the "Signature Patterns" field).
        'conflict_signature_heading' => 'Known Conflict: "---" and Signature Stripping',
        'conflict_signature_desc'    => 'Postie\'s own default settings treat a line containing only "---" as the start of an email signature and remove everything after it, before this plugin ever sees the content - Postie\'s "Signature Patterns" list, under %s (Message tab), includes "---" by default. Since Markdown commonly uses "---" on its own line as a horizontal rule, a Markdown email using that syntax will lose everything past it. Fixed by wrapping in <md>...</md> above. Alternatively, use "***" or "___" for a horizontal rule instead, or remove "---" from Postie\'s Signature Patterns list if you don\'t rely on automatic signature stripping.',

        // Settings page - "#" / Allow Subject In Mail conflict subsection
        // translators: %s: link to Postie's own settings page (its "Message" tab holds the "Allow Subject In Mail" field).
        'conflict_subject_heading' => 'Known Conflict: "#" and Allow Subject In Mail',
        'conflict_subject_desc'    => 'If Postie\'s "Allow Subject In Mail" setting is on (%s, Message tab) and the email body starts with "#", Postie treats everything up to the NEXT "#" anywhere in the body as the subject and strips it out of the content - intended for a deliberate "#subject#" marker on the first line, but a Markdown email starting with a "# Heading" (with a later "##"/"###" heading further down) gets its entire opening section pulled into the post title instead. Fixed by wrapping in <md>...</md> above, with the <md> tag itself as the very first thing in the body. Alternatively, turn off "Allow Subject In Mail" if you don\'t use that feature, or avoid starting the email body with a "#" heading as the very first character.',

        // Shared - link text reused by both conflict subsections above
        'postie_settings_link_text' => "Postie's settings",
    ];

    public static function get(string $key): string
    {
        return __(self::ALL[$key] ?? $key, 'postie-blocks-addon');
    }
}

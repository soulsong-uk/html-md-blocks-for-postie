<?php

namespace PostieMd;

defined('ABSPATH') || exit;

/**
 * Converts a plain-text email body into semantic HTML via the vendored
 * Parsedown library (vendor/Parsedown.php, MIT). Only handles Markdown ->
 * HTML; block-type decisions (which tag becomes which Gutenberg block,
 * image attachment IDs) are made afterwards by HtmlToBlocks, uniformly for
 * both Markdown- and HTML-sourced emails.
 */
class MarkdownConverter
{
    /**
     * Postie's own filter_Newlines() (postie-filters.php) always runs on
     * post content before postie_post_before fires, regardless of whether
     * the email was plain-text or HTML - it is gated on the site-wide
     * "filternewlines"/"convertnewline" settings, not on content type. By
     * the time we see the content, blank-line paragraph breaks may already
     * have been collapsed into a single line break or converted to <br>
     * tags. This reverses that specific transform (only) so Parsedown sees
     * genuine paragraph boundaries again - it does not touch anything else
     * Postie's pipeline did (attachment HTML, auto-linked URLs, etc.).
     */
    public function convert(string $content): string
    {
        $config = function_exists('postie_config_read') ? postie_config_read() : null;
        $source = $this->recoverParagraphBreaks($content, $config);

        return $this->parsedownToHtml($source);
    }

    private function recoverParagraphBreaks(string $content, $config): string
    {
        $filternewlines = $config ? (bool) ($config->filternewlines ?? true) : true;
        $convertnewline = $config ? (bool) ($config->convertnewline ?? false) : false;

        if (!$filternewlines) {
            // Untouched by Postie - already genuine Markdown source.
            return $content;
        }

        if ($convertnewline) {
            // filter_Newlines turned a blank line into the literal string
            // "<br />\n<br />\n" and a single line break into a lone
            // "<br />\n" - reverse both, longest (paragraph-break) match
            // first so it isn't consumed by the single-<br> pass. The \s*
            // after each tag also consumes that trailing "\n" Postie itself
            // always emits, rather than leaving it as a stray extra newline
            // alongside the one this replacement inserts.
            $content = (string) preg_replace('/(?:<br\s*\/?>\s*){2,}/i', "\n\n", $content);
            return (string) preg_replace('/<br\s*\/?>\s*/i', "\n", $content);
        }

        // Postie's default: filternewlines on, convertnewline off. Every
        // paragraph break collapsed to a single "\r\n" and every mid-paragraph
        // break collapsed to a space - so every remaining line break already
        // marks a genuine paragraph boundary. Widen each one back to a blank
        // line for Parsedown.
        return (string) preg_replace('/\r\n|\r|\n/', "\n\n", $content);
    }

    private function parsedownToHtml(string $markdown): string
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return '';
        }

        if (!class_exists('Parsedown')) {
            $file = POSTIE_MD_PLUGIN_DIR . 'vendor/Parsedown.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }

        if (!class_exists('Parsedown')) {
            // Vendored library missing for some reason - fall back to a
            // single escaped paragraph rather than losing the content.
            return '<p>' . esc_html($markdown) . '</p>';
        }

        $parser = new \Parsedown();
        // Safe mode (escaping raw inline HTML in the source) is deliberately
        // left OFF: by the time this runs, Postie's own filter_Linkify has
        // already turned bare URLs in the original text into real <a href>
        // tags, and filter_AttachmentTemplates/filter_ReplaceImagePlaceHolders
        // may already have injected real <img>/<div> markup. Safe mode would
        // HTML-escape all of that Postie-generated markup as literal text.
        // Sanitization of anything genuinely unsafe still happens the normal
        // WordPress way at wp_insert_post() via kses.
        $parser->setBreaksEnabled(true);

        return $parser->text($markdown);
    }
}

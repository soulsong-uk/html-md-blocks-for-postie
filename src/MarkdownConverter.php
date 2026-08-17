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
        $content = $this->stripWrapperTags($content);

        $config = function_exists('postie_config_read') ? postie_config_read() : null;
        $source = $this->recoverParagraphBreaks($content, $config);

        return $this->parsedownToHtml($source);
    }

    /**
     * Strips generic non-semantic wrapper tags (div, span) entirely -
     * opening and closing, attributes and all - leaving their inner content
     * in place. Only reached once Hooks::onPostBefore's looksLikeMarkdown()
     * check has already decided this content is Markdown-flavored text, not
     * meaningful HTML, so a div/span here carries no structure worth
     * preserving - it is reliably just a mail client's own rendering
     * artifact (e.g. Gmail/Outlook wrapping a plain-text-composed message in
     * a single <div dir="ltr">...</div> for its HTML alternative part).
     *
     * This matters because Parsedown (like CommonMark generally) treats any
     * block starting with a literal HTML tag as a raw HTML passthrough block
     * and never parses Markdown syntax inside it - so without this, content
     * arriving as "<div># Heading...</div>" would never get its heading (or
     * anything else inside the div) recognized at all.
     */
    private function stripWrapperTags(string $content): string
    {
        return (string) preg_replace('/<\/?(?:div|span)\b[^>]*>/i', '', $content);
    }

    private function recoverParagraphBreaks(string $content, $config): string
    {
        // Always normalize literal <br> tags into paragraph/line breaks
        // first, regardless of Postie's own filternewlines/convertnewline
        // settings. These tags can come from two different places that this
        // function can't tell apart and doesn't need to: Postie's own
        // filter_Newlines() (only when convertnewline is on), OR - just as
        // commonly - the sending mail client's own HTML rendering (e.g.
        // Gmail/Outlook wrapping a plain-text-composed message in a single
        // <div> with <br> tags for what the user saw as blank lines). Either
        // way a <br> means the same thing: a line break the sender intended.
        $content = (string) preg_replace('/(?:<br\s*\/?>\s*){2,}/i', "\n\n", $content);
        $content = (string) preg_replace('/<br\s*\/?>\s*/i', "\n", $content);

        $filternewlines = $config ? (bool) ($config->filternewlines ?? true) : true;
        $convertnewline = $config ? (bool) ($config->convertnewline ?? false) : false;

        if (!$filternewlines || $convertnewline) {
            // Either untouched by Postie (genuine paragraph breaks already
            // present), or already normalized by the <br> pass above.
            return $content;
        }

        // Postie's default: filternewlines on, convertnewline off. Every
        // paragraph break collapsed to a single "\r\n" and every mid-paragraph
        // break collapsed to a space - so every remaining line break already
        // marks a genuine paragraph boundary. Widen each one back to a blank
        // line for Parsedown.
        return (string) preg_replace('/\r\n|\r|\n/', "\n\n", $content);
    }

    /**
     * Content-pattern-based Markdown detection, used by Hooks::onPostBefore
     * instead of trusting which MIME part (html vs text) the email happened
     * to populate. That MIME-based signal is unreliable: many mail clients
     * (Gmail, Outlook) send an "HTML" part that is just the user's literally
     * typed Markdown text wrapped in a single <div>/<br> structure, not real
     * semantic HTML - so relying on "$email['html'] is non-empty" to mean
     * "don't run Markdown parsing" silently drops all structure for exactly
     * the senders most likely to be writing Markdown in the first place.
     *
     * @param string $plainText Content with HTML tags already stripped
     *                          (e.g. via wp_strip_all_tags()).
     */
    public static function looksLikeMarkdown(string $plainText): bool
    {
        $patterns = [
            '/(^|\n)[ \t]{0,3}#{1,6}[ \t]+\S/',   // # / ## / ... heading
            '/(^|\n)[ \t]{0,3}[-*+][ \t]+\S/',    // - / * / + list item
            '/!\[[^\]]*\]\([^)]+\)/',              // ![alt](url) image
            '/\[[^\]]+\]\([^)]+\)/',               // [text](url) link
            '/\*\*[^*\n]+\*\*/',                   // **bold**
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $plainText) === 1) {
                return true;
            }
        }
        return false;
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

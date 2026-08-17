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
    /**
     * @param bool $alreadyHasGenuineNewlines True for content decoded from
     *             an explicit <md>...</md> marker (see Hooks::onPostPre()/
     *             restoreProtectedBlocks()) - that content was shielded from
     *             Postie's filter_Newlines() entirely (never touched by it),
     *             so its newlines are already exactly what the sender typed.
     *             Skips recoverParagraphBreaks() in that case: widening
     *             already-genuine single line breaks (e.g. between Markdown
     *             list items, which use single breaks by convention, not
     *             blank lines) into blank-line paragraph breaks would
     *             over-loosen tight lists and could split a soft-wrapped
     *             sentence into separate paragraphs. stripWrapperTags(),
     *             unwrapFreetextLinks(), and collapseRepeatedSpaces() still
     *             run either way - a decoded block extracted from a mail
     *             client's HTML part can still contain that same client's
     *             own <p>-wrapping and line-wrap padding artifacts inside
     *             the <md> boundaries.
     */
    public function convert(string $content, bool $alreadyHasGenuineNewlines = false): string
    {
        $content = $this->unwrapFreetextLinks($content);
        $content = $this->stripWrapperTags($content);

        if ($alreadyHasGenuineNewlines) {
            $source = $content;
        } else {
            $config = function_exists('postie_config_read') ? postie_config_read() : null;
            $source = $this->recoverParagraphBreaks($content, $config);
        }
        $source = $this->collapseRepeatedSpaces($source);

        return $this->parsedownToHtml($source);
    }

    /**
     * Collapses runs of 2+ literal space/tab characters down to one -
     * confirmed source: mail clients (Thunderbird observed directly) pad
     * their line-wrapped text with runs of multiple spaces at wrap points
     * (e.g. "Maintenance          Notice", "successfully        completed"),
     * which is cosmetically odd in ordinary paragraph text but structurally
     * breaks list parsing specifically: CommonMark/Parsedown treats content
     * indented 4+ spaces after a list marker as an INDENTED CODE BLOCK
     * inside that list item rather than plain text, so "*          Updated
     * ..." (marker plus a wide run of padding spaces) turns "Updated ..."
     * into a <pre><code> block nested in the <li> instead of ordinary list
     * item text. Only touches space/tab runs, never the "\n\n"
     * paragraph-break markers recoverParagraphBreaks() just built, so this
     * can't undo that work. Trade-off: a genuine 4-space-indented code
     * block (no fenced ``` delimiters) would also be collapsed to a normal
     * paragraph - accepted for v1, since fenced code blocks are unaffected
     * and indented-only code blocks are rare in emailed Markdown compared
     * to this now-confirmed, structurally-breaking mail-client artifact.
     */
    private function collapseRepeatedSpaces(string $content): string
    {
        return (string) preg_replace('/[ \t]{2,}/', ' ', $content);
    }

    /**
     * Unwraps "freetext" auto-linked bare URLs - confirmed source: mail
     * clients (Thunderbird's moz-txt-link-freetext class is the specific
     * one observed) automatically turn a bare URL the sender typed into
     * <a href="URL">URL</a> when generating the HTML alternative part of a
     * plain-text compose, matched here generically by link text equalling
     * the href rather than by that one class name. Must run before Parsedown
     * sees the content: a URL the sender wrote inside genuine Markdown
     * syntax, e.g. "![alt](URL)", would otherwise arrive as
     * "![alt](<a href="URL">URL</a>)" - a literal anchor tag nested inside
     * the parens instead of a plain URL - which Parsedown does not
     * recognize as valid image/link syntax, silently leaving it as literal
     * text instead of a real image.
     */
    private function unwrapFreetextLinks(string $content): string
    {
        return (string) preg_replace_callback(
            '/<a\b[^>]*\bhref=(["\'])(.*?)\1[^>]*>(.*?)<\/a>/i',
            static function ($m) {
                $href = trim($m[2]);
                $text = trim(wp_strip_all_tags($m[3]));
                return $href === $text ? $href : $m[0];
            },
            $content
        );
    }

    /**
     * Strips generic non-semantic wrapper tags entirely - opening and
     * closing, attributes and all - leaving their inner content in place.
     * Only reached once Hooks::onPostBefore's looksLikeMarkdown() check has
     * already decided this content is Markdown-flavored text, not
     * meaningful HTML, so these tags carry no structure worth preserving as
     * HTML - they are reliably just a mail client's own rendering artifact.
     *
     * This matters because Parsedown (like CommonMark generally) treats any
     * block starting with a literal HTML tag as a raw HTML passthrough block
     * and never parses Markdown syntax inside it - so without this, content
     * arriving wrapped in any of these tags would never get its Markdown
     * syntax recognized at all.
     *
     * Two different mail-client shapes are handled differently:
     *  - div/span: reliably just a single outer wrapper (e.g. Gmail/Outlook
     *    wrapping a whole plain-text-composed message in one
     *    "<div dir="ltr">...</div>", with <br> tags - handled separately by
     *    recoverParagraphBreaks() - marking the line breaks). Stripped to
     *    nothing; no paragraph-boundary information would be lost.
     *  - p: mail clients (confirmed: Thunderbird's auto-generated HTML
     *    alternative for a plain-text compose) commonly wrap EACH paragraph
     *    in its own real <p>...</p>, one per original blank-line-separated
     *    paragraph. Simply deleting these tags (like div/span) would merge
     *    every paragraph into one run-on line, since removing a tag doesn't
     *    insert whitespace where it was - so <p>/</p> are replaced with a
     *    blank-line marker instead, preserving the paragraph break they
     *    represented. Two blank-line markers landing back to back (one
     *    paragraph's trailing marker next to the next one's leading marker)
     *    is harmless - Parsedown treats consecutive blank lines the same as
     *    a single one.
     */
    private function stripWrapperTags(string $content): string
    {
        $content = (string) preg_replace('/<\/?p\b[^>]*>/i', "\n\n", $content);
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

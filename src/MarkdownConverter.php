<?php

namespace PostieBlocksAddon;

defined('ABSPATH') || exit;

/**
 * Converts a Markdown email body into semantic HTML via the vendored
 * Parsedown library (vendor/Parsedown.php, MIT). Only handles Markdown ->
 * HTML; block-type decisions (which tag becomes which Gutenberg block,
 * image attachment IDs) are made afterwards by HtmlToBlocks, uniformly for
 * both Markdown-converted and originally-HTML content.
 *
 * Only ever called by Hooks::onPostBefore() on content decoded from an
 * explicit <md>...</md> block (see Hooks::restoreProtectedBlocks()) - never
 * on a heuristic guess at whether some arbitrary content "looks like"
 * Markdown. That guess used to exist and caused real problems: it fired on
 * ordinary prose that happened to contain a stray "#" or "*", and worse, it
 * ran on content Postie's own filter_Newlines()/filter_RemoveSignature()/
 * extract_subject_body() had already silently mangled before this plugin
 * ever saw it, since those Postie behaviours run unconditionally regardless
 * of whether the email was actually meant to contain Markdown. Because this
 * class now only ever receives content that was shielded from all of that
 * at postie_post_pre (see Hooks::protectMarkdownBlocks()), its newlines are
 * always exactly what the sender typed - there is no "recover paragraph
 * breaks Postie's own processing collapsed" step to run here any more.
 */
class MarkdownConverter
{
    public function convert(string $content): string
    {
        $content = $this->unwrapFreetextLinks($content);
        $content = $this->stripWrapperTags($content);
        $content = $this->normalizeLineBreaks($content);
        $content = $this->collapseRepeatedSpaces($content);

        return $this->parsedownToHtml($content);
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
     * paragraph-break markers normalizeLineBreaks() just built, so this
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
     * These tags carry no structure worth preserving as HTML - they are
     * reliably just a mail client's own rendering artifact from generating
     * the HTML alternative part of what the sender composed as plain text.
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
     *    normalizeLineBreaks() - marking the line breaks). Stripped to
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

    /**
     * Normalizes literal <br> tags into paragraph/line breaks - the only
     * newline-related cleanup this class does now that Markdown only ever
     * reaches it via an explicit <md>...</md> block already shielded from
     * Postie's own processing (see the class docblock). A <br> tag here can
     * only have come from the sending mail client's own HTML rendering (e.g.
     * Gmail/Outlook wrapping a plain-text-composed message in a single
     * <div> with <br> tags for what the sender saw as line breaks) - never
     * from Postie, which this content was never exposed to.
     */
    private function normalizeLineBreaks(string $content): string
    {
        $content = (string) preg_replace('/(?:<br\s*\/?>\s*){2,}/i', "\n\n", $content);
        return (string) preg_replace('/<br\s*\/?>\s*/i', "\n", $content);
    }

    private function parsedownToHtml(string $markdown): string
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return '';
        }

        if (!class_exists('Parsedown')) {
            $file = POSTIE_BLOCKS_PLUGIN_DIR . 'vendor/Parsedown.php';
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
        // left OFF: unwrapFreetextLinks()/stripWrapperTags() above already
        // handle the specific mail-client HTML artifacts this content can
        // contain (freetext-linked URLs, div/span/p wrappers), so any HTML
        // still present at this point is more likely genuine inline
        // formatting from a rich-text-composed email (<b>, <i>, etc.) that
        // should pass through, not something to escape into visible literal
        // tags. Sanitization of anything genuinely unsafe still happens the
        // normal WordPress way at wp_insert_post() via kses.
        $parser->setBreaksEnabled(true);

        return $parser->text($markdown);
    }
}

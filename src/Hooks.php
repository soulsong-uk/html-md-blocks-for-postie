<?php

namespace PostieMd;

defined('ABSPATH') || exit;

/**
 * Wires this plugin into Postie's confirmed pipeline hooks
 * (postie-message.php, PostieMessage class):
 *
 *  - postie_post_pre    (before Postie's own content filters run) - resets
 *    the attachment registry for the new email, and shields any explicit
 *    <md>...</md> marker the sender wrapped their Markdown in (see
 *    protectMarkdownBlocks()) before any of Postie's own content filters
 *    - filter_Newlines(), filter_RemoveSignature(), extract_subject_body()
 *    - get a chance to damage it.
 *  - postie_file_added   (fires once per emailed attachment, after it
 *    becomes a real WP attachment) - records the URL -> attachment ID
 *    mapping used later for core/image blocks.
 *  - postie_post_before  (last content-touching filter, right before
 *    wp_insert_post()) - unshields any protected block (restoreProtectedBlocks()),
 *    then runs the actual Markdown/HTML -> blocks conversion, once, on
 *    Postie's fully-assembled post_content.
 *
 * Note deliberately absent: no kses_remove_filters()/kses_init_filters()
 * here. See CLAUDE.md's "kses does NOT strip block comments" note - that
 * was checked directly against WordPress core source and found false, so
 * this plugin does not touch kses at all.
 */
class Hooks
{
    /**
     * Sentinel wrapping a base64-encoded protected block, chosen so it
     * cannot collide with any of Postie's own per-line pattern matching:
     * no newlines (filter_Newlines has nothing to collapse), doesn't start
     * with "#" and isn't a "---"/"--" line (extract_subject_body()/
     * filter_RemoveSignature() can't match it), and standard base64's
     * alphabet (A-Za-z0-9+/=) plus these literal characters won't
     * plausibly collide with real email content.
     */
    private const SENTINEL_PREFIX = 'POSTIEMDPROTECTED0';
    private const SENTINEL_SUFFIX = '0POSTIEMDPROTECTEDEND';

    private AttachmentRegistry $registry;
    private MarkdownConverter $markdown;
    private HtmlToBlocks $htmlToBlocks;

    public function __construct()
    {
        $this->registry     = new AttachmentRegistry();
        $this->markdown     = new MarkdownConverter();
        $this->htmlToBlocks = new HtmlToBlocks();
    }

    public function register(): void
    {
        add_filter('postie_post_pre', [$this, 'onPostPre'], 10, 1);
        add_action('postie_file_added', [$this, 'onFileAdded'], 10, 3);
        add_filter('postie_post_before', [$this, 'onPostBefore'], 10, 2);
    }

    /**
     * @param array<string,mixed> $email
     * @return array<string,mixed>
     */
    public function onPostPre($email)
    {
        if (is_array($email)) {
            $this->registry->reset();
            $email = $this->protectMarkdownBlocks($email);
        }

        return $email;
    }

    /**
     * Finds any <md>...</md> region (literal, or &lt;md&gt;...&lt;/md&gt;
     * - most mail clients HTML-escape a literal "<md>" the sender typed in
     * a plain-text compose when generating the HTML alternative part, so
     * both forms must be checked) in the raw email body and replaces it
     * with an opaque, byte-exact-preserving sentinel before Postie's own
     * content filters ever run. Checks both html and text - extract_content()
     * (called later, inside Postie's own pipeline) picks whichever one
     * Postie will actually use, so protecting both costs nothing and covers
     * either outcome.
     *
     * @param array<string,mixed> $email
     * @return array<string,mixed>
     */
    private function protectMarkdownBlocks(array $email): array
    {
        foreach (['html', 'text'] as $key) {
            if (!empty($email[$key]) && is_string($email[$key])) {
                $email[$key] = $this->encodeMarkdownMarkers($email[$key]);
            }
        }

        return $email;
    }

    private function encodeMarkdownMarkers(string $content): string
    {
        $patterns = [
            '/<md>(.*?)<\/md>/is',
            '/&lt;md&gt;(.*?)&lt;\/md&gt;/is',
        ];

        foreach ($patterns as $pattern) {
            $content = (string) preg_replace_callback(
                $pattern,
                static function ($m) {
                    return self::SENTINEL_PREFIX . base64_encode($m[1]) . self::SENTINEL_SUFFIX;
                },
                $content
            );
        }

        return $content;
    }

    /**
     * Reverses encodeMarkdownMarkers(): finds any surviving sentinel in the
     * fully-assembled post_content and decodes it back to the sender's
     * exact original text - never touched by Postie's own filter_Newlines()
     * etc. in between, since the sentinel carried no meaningful structure
     * for any of those filters to act on.
     *
     * @return array{0:string,1:bool} [content with sentinels decoded back
     *         to raw text, whether any protected block was found]
     */
    private function restoreProtectedBlocks(string $content): array
    {
        $found   = false;
        $pattern = '/' . preg_quote(self::SENTINEL_PREFIX, '/') . '(.*?)' . preg_quote(self::SENTINEL_SUFFIX, '/') . '/';

        $content = (string) preg_replace_callback(
            $pattern,
            static function ($m) use (&$found) {
                $found   = true;
                $decoded = base64_decode($m[1], true);
                return $decoded !== false ? $decoded : '';
            },
            $content
        );

        return [$content, $found];
    }

    /**
     * @param mixed $postId
     * @param mixed $attachmentId
     * @param mixed $fileArray
     */
    public function onFileAdded($postId, $attachmentId, $fileArray): void
    {
        $this->registry->record((int) $attachmentId, (array) $fileArray);
    }

    /**
     * @param array<string,mixed> $details
     * @param mixed $headers
     * @return array<string,mixed>
     */
    public function onPostBefore($details, $headers)
    {
        if (!is_array($details) || empty($details['post_content'])) {
            return $details;
        }

        try {
            $content = (string) $details['post_content'];

            // Unshield any explicit <md>...</md> block first - decoded back
            // to the sender's exact original text regardless of whether
            // Markdown parsing ends up running below, so a disabled setting
            // (or some other early exit) never leaves the raw sentinel
            // gibberish sitting in the published post.
            [$content, $hasProtectedMarkdown] = $this->restoreProtectedBlocks($content);

            // Detected from the actual content, not from which MIME part
            // (html vs text) the email happened to populate - see
            // MarkdownConverter::looksLikeMarkdown()'s docblock for why the
            // MIME-based signal is unreliable (many mail clients wrap
            // literally-typed Markdown in an HTML <div>/<br> structure).
            // A restored protected block is treated as Markdown
            // unconditionally - the sender said so explicitly by wrapping
            // it, no heuristic needed - and its newlines are passed through
            // as-is (see MarkdownConverter::convert()'s $alreadyHasGenuineNewlines
            // docblock) since they were never touched by Postie's own
            // filter_Newlines() in the first place.
            if (Settings::isMarkdownEnabled()
                && ($hasProtectedMarkdown || MarkdownConverter::looksLikeMarkdown(wp_strip_all_tags($content)))) {
                $content = $this->markdown->convert($content, $hasProtectedMarkdown);
            }

            $converted = $this->htmlToBlocks->convert($content, $this->registry);

            if (trim($converted) !== '') {
                // Postie's own save_post() (postie-message.php) unconditionally
                // runs str_replace('\\', '\\\\', $details['post_content']) AFTER
                // this filter returns, to protect single backslashes from being
                // eaten elsewhere in its own pipeline - but it doubles every
                // backslash indiscriminately, including ordinary literal
                // backslashes in real content (e.g. a Windows file path like
                // "D:\Dev\projects\x" typed in the email), which would render
                // doubled ("D:\\Dev\\projects\\x") in the published post.
                // Converting to the HTML entity here leaves nothing for that
                // later str_replace to find - it decodes back to a normal
                // single backslash when rendered, unaffected by the doubling.
                $converted = str_replace('\\', '&#92;', $converted);
                $details['post_content'] = $converted;
            }
        } catch (\Throwable $e) {
            // Never risk corrupting the post - Postie's own content is left
            // untouched if anything above throws.
        }

        return $details;
    }
}

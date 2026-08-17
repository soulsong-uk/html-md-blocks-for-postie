<?php

namespace PostieMd;

defined('ABSPATH') || exit;

/**
 * Wires this plugin into Postie's confirmed pipeline hooks
 * (postie-message.php, PostieMessage class):
 *
 *  - postie_post_pre    (before Postie's own content filters run) - only
 *    used here to reset the attachment registry for the new email; content
 *    itself is left untouched here (see MarkdownConverter's docblock for
 *    why the actual conversion waits until postie_post_before instead).
 *  - postie_file_added   (fires once per emailed attachment, after it
 *    becomes a real WP attachment) - records the URL -> attachment ID
 *    mapping used later for core/image blocks.
 *  - postie_post_before  (last content-touching filter, right before
 *    wp_insert_post()) - runs the actual Markdown/HTML -> blocks
 *    conversion, once, on Postie's fully-assembled post_content.
 *
 * Note deliberately absent: no kses_remove_filters()/kses_init_filters()
 * here. See CLAUDE.md's "kses does NOT strip block comments" note - that
 * was checked directly against WordPress core source and found false, so
 * this plugin does not touch kses at all.
 */
class Hooks
{
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
        }

        return $email;
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

            // Detected from the actual content, not from which MIME part
            // (html vs text) the email happened to populate - see
            // MarkdownConverter::looksLikeMarkdown()'s docblock for why the
            // MIME-based signal is unreliable (many mail clients wrap
            // literally-typed Markdown in an HTML <div>/<br> structure).
            if (Settings::isMarkdownEnabled()
                && MarkdownConverter::looksLikeMarkdown(wp_strip_all_tags($content))) {
                $content = $this->markdown->convert($content);
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

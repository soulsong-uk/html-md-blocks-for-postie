<?php

namespace PostieMd;

defined('ABSPATH') || exit;

/**
 * Wires this plugin into Postie's confirmed pipeline hooks
 * (postie-message.php, PostieMessage class):
 *
 *  - postie_post_pre    (before Postie's own content filters run) - only
 *    used here to record per-email state (was this a plain-text/Markdown
 *    body?) and reset the attachment registry; content itself is left
 *    untouched here, see the class docblock on MarkdownConverter for why.
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
    private bool $isPlainTextSource = false;

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
        if (!is_array($email)) {
            return $email;
        }

        $this->registry->reset();

        // Mirrors the auto-swap Postie itself does before this point (see
        // postie-message.php ~line 38-44): whichever body is actually
        // non-empty is the one extract_content() will use, regardless of
        // the site's configured "prefer_text_type" default.
        $html = trim((string) ($email['html'] ?? ''));
        $text = trim((string) ($email['text'] ?? ''));
        $this->isPlainTextSource = ($html === '' && $text !== '');

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

            if ($this->isPlainTextSource && Settings::isMarkdownEnabled()) {
                $content = $this->markdown->convert($content);
            }

            $converted = $this->htmlToBlocks->convert($content, $this->registry);

            if (trim($converted) !== '') {
                $details['post_content'] = $converted;
            }
        } catch (\Throwable $e) {
            // Never risk corrupting the post - Postie's own content is left
            // untouched if anything above throws.
        }

        return $details;
    }
}

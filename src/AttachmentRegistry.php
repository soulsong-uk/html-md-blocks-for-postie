<?php

namespace HtmlMdBlocksForPostie;

defined('ABSPATH') || exit;

/**
 * Maps an emailed image's real URL to the WP attachment ID Postie created
 * for it, for the single email currently being processed. Populated from
 * the postie_file_added action (fires per-attachment, earlier in Postie's
 * pipeline than postie_post_before) and consulted later when building
 * core/image blocks.
 *
 * Reset at the top of each postie_post_pre call. Safe as a per-email
 * accumulate-then-clear: Postie's fetch_mail() loop runs one email fully
 * through postie_post_after before starting the next, so there is no
 * cross-email overlap within a single request.
 */
class AttachmentRegistry
{
    /** @var array<string,int> attachment URL => attachment post ID */
    private array $byUrl = [];

    public function reset(): void
    {
        $this->byUrl = [];
    }

    public function record(int $attachmentId, array $fileArray): void
    {
        if ($attachmentId <= 0) {
            return;
        }
        $url = wp_get_attachment_url($attachmentId);
        if ($url) {
            $this->byUrl[$url] = $attachmentId;
        }
    }

    /**
     * Resolves a <img src> back to an attachment ID, tolerating WordPress's
     * own "-{width}x{height}" size-derivative suffix (e.g. an <img> pointing
     * at a "photo-300x200.jpg" thumbnail when the registry only recorded the
     * full-size "photo.jpg" URL, or vice versa).
     */
    public function resolve(string $src): int
    {
        if (isset($this->byUrl[$src])) {
            return $this->byUrl[$src];
        }

        $normalizedSrc = $this->stripSizeSuffix($src);
        foreach ($this->byUrl as $url => $id) {
            if ($this->stripSizeSuffix($url) === $normalizedSrc) {
                return $id;
            }
        }

        return 0;
    }

    private function stripSizeSuffix(string $url): string
    {
        return (string) preg_replace('/-\d+x\d+(?=\.[a-z0-9]+(?:\?.*)?$)/i', '', $url);
    }
}

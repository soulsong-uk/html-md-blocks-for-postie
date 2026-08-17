<?php

namespace PostieMd;

defined('ABSPATH') || exit;

/**
 * Walks the final assembled HTML (Markdown already converted to semantic
 * HTML by MarkdownConverter, or the original markup from a rich HTML email)
 * and emits real Gutenberg block markup via WordPress core's own
 * serialize_blocks(), rather than hand-written "<!-- wp:x -->" strings.
 *
 * v1 maps: h1-h6 -> core/heading, p -> core/paragraph, img -> core/image
 * (attachment ID resolved where possible). Anything else (lists, quotes,
 * code, tables, leftover styled markup from HTML mail clients) falls back
 * to a core/html block so no content is ever silently dropped.
 *
 * Uses Postie's own bundled simple_html_dom parser (via $g_postie->load_html())
 * rather than adding a second vendored dependency or requiring ext-dom -
 * it is guaranteed loaded, since this class only ever runs while Postie
 * itself is active and mid-pipeline.
 */
class HtmlToBlocks
{
    /**
     * @return string Serialized block markup, or the original $html
     *                unchanged if nothing could be converted or anything
     *                goes wrong - never risk corrupting the post.
     */
    public function convert(string $html, AttachmentRegistry $registry): string
    {
        $html = trim($html);
        if ($html === '') {
            return $html;
        }

        try {
            return $this->convertInternal($html, $registry);
        } catch (\Throwable $e) {
            return $html;
        }
    }

    private function convertInternal(string $html, AttachmentRegistry $registry): string
    {
        global $g_postie;
        if (empty($g_postie) || !method_exists($g_postie, 'load_html')) {
            return $html;
        }

        $dom = $g_postie->load_html($html);
        if (!$dom) {
            return $html;
        }

        $bodies = $dom->find('body');
        $root   = !empty($bodies) ? $bodies[0] : ($dom->root ?? null);
        if (!$root) {
            return $html;
        }

        $blocks = [];
        foreach ($this->childNodes($root) as $node) {
            $blocks = array_merge($blocks, $this->nodeToBlocks($node, $registry));
        }

        if (empty($blocks)) {
            return $html;
        }

        if (!function_exists('serialize_blocks')) {
            return $html;
        }

        return serialize_blocks($blocks);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function nodeToBlocks($node, AttachmentRegistry $registry): array
    {
        if (!isset($node->nodetype)) {
            return [];
        }

        if ($node->nodetype === HDOM_TYPE_TEXT) {
            $text = trim((string) $node->outertext);
            return $text === '' ? [] : [$this->paragraphBlock($text)];
        }

        if ($node->nodetype !== HDOM_TYPE_ELEMENT) {
            return [];
        }

        $tag = strtolower((string) $node->tag);

        if ($tag === 'br') {
            return [];
        }

        if ($tag === 'hr') {
            return [$this->separatorBlock()];
        }

        if (preg_match('/^h([1-6])$/', $tag, $m)) {
            $inner = trim((string) $node->innertext);
            return $inner === '' ? [] : [$this->headingBlock($inner, (int) $m[1])];
        }

        if ($tag === 'p') {
            // Don't just wrap the whole paragraph's innertext verbatim - an
            // <img> inline in running text (e.g. Markdown "some text
            // ![alt](url) more text" with no blank line around the image,
            // so Parsedown keeps it as inline content of the same <p>) would
            // otherwise get baked into the paragraph block's HTML instead of
            // becoming its own core/image block. Split around any images.
            return $this->splitParagraphContent($node, $registry);
        }

        if ($tag === 'img') {
            $block = $this->imageBlock($node, null, $registry);
            return $block ? [$block] : [];
        }

        if ($tag === 'a') {
            $imgChildren = array_values(array_filter(
                $this->childNodes($node),
                static function ($child) {
                    return isset($child->nodetype) && $child->nodetype === HDOM_TYPE_ELEMENT
                        && strtolower((string) $child->tag) === 'img';
                }
            ));
            $textOnly = trim(wp_strip_all_tags((string) $node->innertext));

            if (count($imgChildren) === 1 && $textOnly === '') {
                $block = $this->imageBlock($imgChildren[0], $node, $registry);
                return $block ? [$block] : [];
            }

            $outer = trim((string) $node->outertext);
            return $outer === '' ? [] : [$this->paragraphBlock($outer)];
        }

        // Transparent containers - e.g. Postie's own <div class="postie-attachments">
        // wrapper around attachment templates - have no meaningful text of
        // their own, so recurse into their children instead of collapsing
        // the whole thing into one opaque HTML block. This is what lets
        // each emailed image inside that wrapper become its own proper
        // core/image block.
        if ($tag === 'div' && $this->isTransparentContainer($node)) {
            $blocks = [];
            foreach ($this->childNodes($node) as $child) {
                $blocks = array_merge($blocks, $this->nodeToBlocks($child, $registry));
            }
            return $blocks;
        }

        // Deferred v1 block types (lists, quotes, code, tables) and anything
        // else unrecognized (styled markup from HTML mail clients) - preserve
        // verbatim rather than drop.
        $outer = trim((string) $node->outertext);
        return $outer === '' ? [] : [$this->htmlFallbackBlock($outer)];
    }

    /**
     * Walks a <p>'s direct children and splits out any embedded image (bare
     * <img>, or an <a> wrapping nothing but a single <img>) into its own
     * core/image block, flushing accumulated inline text/formatting around
     * it into core/paragraph blocks. A paragraph containing only an image
     * (no surrounding text) yields just the image block, no empty paragraph.
     *
     * @return array<int,array<string,mixed>>
     */
    private function splitParagraphContent($node, AttachmentRegistry $registry): array
    {
        $blocks = [];
        $buffer = '';

        foreach ($this->childNodes($node) as $child) {
            if (!isset($child->nodetype) || $child->nodetype !== HDOM_TYPE_ELEMENT) {
                $buffer .= (string) $child->outertext;
                continue;
            }

            $childTag   = strtolower((string) $child->tag);
            $imgNode    = null;
            $anchorNode = null;

            if ($childTag === 'img') {
                $imgNode = $child;
            } elseif ($childTag === 'a') {
                $imgChildren = array_values(array_filter(
                    $this->childNodes($child),
                    static function ($c) {
                        return isset($c->nodetype) && $c->nodetype === HDOM_TYPE_ELEMENT
                            && strtolower((string) $c->tag) === 'img';
                    }
                ));
                $textOnly = trim(wp_strip_all_tags((string) $child->innertext));
                if (count($imgChildren) === 1 && $textOnly === '') {
                    $imgNode    = $imgChildren[0];
                    $anchorNode = $child;
                }
            }

            if ($imgNode === null) {
                $buffer .= (string) $child->outertext;
                continue;
            }

            $pending = trim($buffer);
            if ($pending !== '') {
                $blocks[] = $this->paragraphBlock($pending);
            }
            $buffer = '';

            $imageBlock = $this->imageBlock($imgNode, $anchorNode, $registry);
            if ($imageBlock) {
                $blocks[] = $imageBlock;
            }
        }

        $pending = trim($buffer);
        if ($pending !== '') {
            $blocks[] = $this->paragraphBlock($pending);
        }

        return $blocks;
    }

    /**
     * @return array<string,mixed>
     */
    private function separatorBlock(): array
    {
        $html = '<hr class="wp-block-separator"/>';
        return [
            'blockName'    => 'core/separator',
            'attrs'        => [],
            'innerBlocks'  => [],
            'innerHTML'    => $html,
            'innerContent' => [$html],
        ];
    }

    private function isTransparentContainer($node): bool
    {
        foreach ($this->childNodes($node) as $child) {
            if (isset($child->nodetype) && $child->nodetype === HDOM_TYPE_TEXT
                && trim((string) $child->outertext) !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * simple_html_dom's own children() method returns only ELEMENT nodes -
     * it silently excludes plain text nodes entirely. That's harmless for
     * walking purely-structural markup, but for anything that mixes real
     * text with elements (a <p> with inline text around an <img>, a
     * text-only <p>) it drops the text completely. The public "nodes"
     * property holds every direct child node (text included) in source
     * order, which is what every walk in this class actually needs.
     *
     * @return array<int,mixed>
     */
    private function childNodes($node): array
    {
        return is_array($node->nodes ?? null) ? $node->nodes : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function paragraphBlock(string $innerHtml): array
    {
        $html = '<p>' . $innerHtml . '</p>';
        return [
            'blockName'    => 'core/paragraph',
            'attrs'        => [],
            'innerBlocks'  => [],
            'innerHTML'    => $html,
            'innerContent' => [$html],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function headingBlock(string $innerHtml, int $level): array
    {
        $level = max(1, min(6, $level));
        $attrs = $level !== 2 ? ['level' => $level] : [];
        $html  = "<h{$level}>{$innerHtml}</h{$level}>";
        return [
            'blockName'    => 'core/heading',
            'attrs'        => $attrs,
            'innerBlocks'  => [],
            'innerHTML'    => $html,
            'innerContent' => [$html],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function htmlFallbackBlock(string $outerHtml): array
    {
        return [
            'blockName'    => 'core/html',
            'attrs'        => [],
            'innerBlocks'  => [],
            'innerHTML'    => $outerHtml,
            'innerContent' => [$outerHtml],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function imageBlock($imgNode, $anchorNode, AttachmentRegistry $registry): ?array
    {
        $src = trim((string) ($imgNode->attr['src'] ?? ''));
        if ($src === '') {
            return null;
        }
        $alt   = (string) ($imgNode->attr['alt'] ?? '');
        $class = (string) ($imgNode->attr['class'] ?? '');

        // Postie's own default "wordpress_default" image attachment template
        // (templates/image_templates.php) already embeds the real attachment
        // ID as a "wp-image-{ID}" class - the same convention Gutenberg's own
        // image block uses - so this is checked first before falling back to
        // the registry/URL-lookup for templates that don't include it.
        $id = 0;
        if (preg_match('/\bwp-image-(\d+)\b/', $class, $m)) {
            $id = (int) $m[1];
        }
        if (!$id) {
            $id = $registry->resolve($src);
        }
        if (!$id && function_exists('attachment_url_to_postid')) {
            $id = (int) attachment_url_to_postid($src);
        }

        $blockAttrs    = ['url' => $src];
        $figureClasses = ['wp-block-image'];
        $imgAttrs      = 'src="' . esc_url($src) . '"';

        if ($alt !== '') {
            $imgAttrs .= ' alt="' . esc_attr($alt) . '"';
        }
        if ($id) {
            $blockAttrs['id'] = $id;
            $imgAttrs .= ' class="wp-image-' . $id . '"';
        }
        if (preg_match('/\bsize-([a-z0-9_-]+)\b/i', $class, $sizeMatch)) {
            $blockAttrs['sizeSlug'] = $sizeMatch[1];
            $figureClasses[]        = 'size-' . $sizeMatch[1];
        }

        $imgHtml = '<img ' . $imgAttrs . '/>';

        $href = $anchorNode ? trim((string) ($anchorNode->attr['href'] ?? '')) : '';
        if ($href !== '') {
            $blockAttrs['linkDestination'] = 'custom';
            $blockAttrs['href']            = $href;
            $imgHtml                       = '<a href="' . esc_url($href) . '">' . $imgHtml . '</a>';
        }

        $html = '<figure class="' . implode(' ', $figureClasses) . '">' . $imgHtml . '</figure>';

        return [
            'blockName'    => 'core/image',
            'attrs'        => $blockAttrs,
            'innerBlocks'  => [],
            'innerHTML'    => $html,
            'innerContent' => [$html],
        ];
    }
}

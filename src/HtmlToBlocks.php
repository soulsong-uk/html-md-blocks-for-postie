<?php

namespace HtmlMdBlocksForPostie;

defined('ABSPATH') || exit;

/**
 * Walks the final assembled HTML (Markdown already converted to semantic
 * HTML by MarkdownConverter, or the original markup from a rich HTML email)
 * and emits real Gutenberg block markup via WordPress core's own
 * serialize_blocks(), rather than hand-written "<!-- wp:x -->" strings.
 *
 * Maps: h1-h6 -> core/heading, p -> core/paragraph, img -> core/image
 * (attachment ID resolved where possible), ul/ol -> core/list with each li
 * as a core/list-item inner block (nested sub-lists supported),
 * blockquote -> core/quote (contents recursed as inner blocks, so a
 * paragraph/image/list inside a quote convert the same as at the top
 * level). Anything else (code, tables, leftover styled markup from HTML
 * mail clients) falls back to a core/html block so no content is ever
 * silently dropped. Paragraph/heading/image alignment (from a source
 * "align" attribute or an inline "text-align" style - see detectAlign())
 * carries through onto the matching block using whichever attrs shape this
 * WP version's own Gutenberg actually expects for each - see
 * textAlignAttrs() and imageBlock()'s own align handling for the two
 * different conventions involved.
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
            if ($text === '' || $this->isOrphanedTagFragment($text)) {
                return [];
            }
            return [$this->paragraphBlock($text)];
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
            return $inner === '' ? [] : [$this->headingBlock($inner, (int) $m[1], $this->detectAlign($node))];
        }

        if ($tag === 'p') {
            // Don't just wrap the whole paragraph's innertext verbatim - an
            // <img> inline in running text (e.g. Markdown "some text
            // ![alt](url) more text" with no blank line around the image,
            // so Parsedown keeps it as inline content of the same <p>) would
            // otherwise get baked into the paragraph block's HTML instead of
            // becoming its own core/image block. Split around any images.
            return $this->splitParagraphContent($node, $registry, $this->detectAlign($node));
        }

        if ($tag === 'img') {
            $block = $this->imageBlock($node, null, $registry);
            return $block ? [$block] : [];
        }

        if ($tag === 'a') {
            $img = $this->soleImageDescendant($node);
            if ($img !== null) {
                $block = $this->imageBlock($img, $node, $registry, $this->detectAlign($node));
                return $block ? [$block] : [];
            }

            $outer = trim((string) $node->outertext);
            return $outer === '' ? [] : [$this->paragraphBlock($outer)];
        }

        // A bare <span> wrapping nothing but an image - e.g. Word's own
        // <span style="mso-no-proof:yes"><img .../></span> around an inline
        // pasted image - is content-less itself, so unwrap it to the image
        // the same way a <div>/<a> wrapper does. A span with any real text
        // of its own falls through to the generic fallback below instead.
        if ($tag === 'span') {
            $img = $this->soleImageDescendant($node);
            if ($img !== null) {
                $block = $this->imageBlock($img, null, $registry, $this->detectAlign($node));
                return $block ? [$block] : [];
            }
        }

        if ($tag === 'ul' || $tag === 'ol') {
            $block = $this->listBlock($node, $tag === 'ol');
            return $block ? [$block] : [];
        }

        if ($tag === 'blockquote') {
            $block = $this->quoteBlock($node, $registry);
            return $block ? [$block] : [];
        }

        // Divs have no block-level meaning of their own in Gutenberg terms -
        // e.g. Postie's own <div class="postie-attachments"> wrapper around
        // attachment templates, or the outer <div> a mail client (Word,
        // Gmail, Outlook) wraps its whole HTML export in - so always recurse
        // into a div's children instead of collapsing the whole thing into
        // one opaque HTML block. Any loose/free text directly inside a div
        // is still handled correctly by the ordinary TEXT-node branch above
        // (it becomes its own paragraph), so there's no need to gate this on
        // "does the div have its own free text" first - and that gate used
        // to cause a real bug: a stray orphaned closing tag left behind by
        // malformed mail-client HTML (see isOrphanedTagFragment()) counted
        // as "real text", which made the whole div bail out to one giant
        // core/html block instead of converting at all.
        if ($tag === 'div') {
            $blocks = [];
            foreach ($this->childNodes($node) as $child) {
                $blocks = array_merge($blocks, $this->nodeToBlocks($child, $registry));
            }
            return $blocks;
        }

        // Deferred v1 block types (quotes, code, tables) and anything else
        // unrecognized (styled markup from HTML mail clients) - preserve
        // verbatim rather than drop.
        $outer = trim((string) $node->outertext);
        return $outer === '' ? [] : [$this->htmlFallbackBlock($outer)];
    }

    /**
     * Maps a <blockquote> to core/quote, with its contents (typically one
     * or more <p> paragraphs from Parsedown's Markdown "> quoted text"
     * output) as inner blocks - reuses the normal nodeToBlocks() dispatch
     * for each child, so a paragraph, an image, or even a nested list
     * inside the quote all convert exactly as they would at the top level,
     * matching current Gutenberg's actual core/quote structure (inner
     * blocks, not raw paragraph markup baked into the blockquote itself).
     *
     * @return array<string,mixed>|null
     */
    private function quoteBlock($node, AttachmentRegistry $registry): ?array
    {
        $innerBlocks = [];
        foreach ($this->childNodes($node) as $child) {
            $innerBlocks = array_merge($innerBlocks, $this->nodeToBlocks($child, $registry));
        }

        if (empty($innerBlocks)) {
            return null;
        }

        $openTag      = '<blockquote class="wp-block-quote">';
        $innerContent = [$openTag];
        foreach ($innerBlocks as $ignored) {
            $innerContent[] = null;
        }
        $innerContent[] = '</blockquote>';

        return [
            'blockName'    => 'core/quote',
            'attrs'        => [],
            'innerBlocks'  => $innerBlocks,
            'innerHTML'    => $openTag . '</blockquote>',
            'innerContent' => $innerContent,
        ];
    }

    /**
     * Maps a <ul>/<ol> to core/list, with each <li> as a core/list-item
     * inner block (matching current Gutenberg's actual block structure -
     * list items are their own blocks, not just markup inside core/list).
     * A nested <ul>/<ol> inside an <li> becomes a nested core/list inner
     * block of that list-item, recursively.
     *
     * @return array<string,mixed>|null
     */
    private function listBlock($node, bool $ordered): ?array
    {
        $items = [];
        foreach ($this->childNodes($node) as $child) {
            if (!isset($child->nodetype) || $child->nodetype !== HDOM_TYPE_ELEMENT
                || strtolower((string) $child->tag) !== 'li') {
                continue;
            }
            $item = $this->listItemBlock($child);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if (empty($items)) {
            return null;
        }

        $tagName = $ordered ? 'ol' : 'ul';
        $attrs   = $ordered ? ['ordered' => true] : [];

        // Preserve a non-default start number Parsedown emits for e.g. a
        // Markdown list that begins "3. item".
        $start = (int) ($node->attr['start'] ?? 0);
        if ($ordered && $start > 1) {
            $attrs['start'] = $start;
        }

        $openTag = '<' . $tagName . ' class="wp-block-list"'
            . (isset($attrs['start']) ? ' start="' . $attrs['start'] . '"' : '')
            . '>';

        $innerContent = [$openTag];
        foreach ($items as $item) {
            $innerContent[] = null;
        }
        $innerContent[] = '</' . $tagName . '>';

        return [
            'blockName'    => 'core/list',
            'attrs'        => $attrs,
            'innerBlocks'  => $items,
            'innerHTML'    => $openTag . '</' . $tagName . '>',
            'innerContent' => $innerContent,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function listItemBlock($node): ?array
    {
        $nestedListBlock = null;
        $textParts        = [];

        foreach ($this->childNodes($node) as $child) {
            if (isset($child->nodetype) && $child->nodetype === HDOM_TYPE_ELEMENT) {
                $childTag = strtolower((string) $child->tag);
                if ($childTag === 'ul' || $childTag === 'ol') {
                    $nestedListBlock = $this->listBlock($child, $childTag === 'ol');
                    continue;
                }
                if ($childTag === 'p') {
                    // Loose-list rendering: Parsedown wraps an <li>'s content
                    // in <p> when the source Markdown list had blank lines
                    // between items. Gutenberg's own list-item stores rich
                    // text directly inside <li>, not wrapped in <p> - unwrap.
                    $textParts[] = (string) $child->innertext;
                    continue;
                }
            }
            $textParts[] = (string) $child->outertext;
        }

        $text = trim(implode('', $textParts));
        if ($text === '' && $nestedListBlock === null) {
            return null;
        }

        $innerBlocks  = $nestedListBlock !== null ? [$nestedListBlock] : [];
        $innerContent = $nestedListBlock !== null
            ? ['<li>' . $text, null, '</li>']
            : ['<li>' . $text . '</li>'];

        return [
            'blockName'    => 'core/list-item',
            'attrs'        => [],
            'innerBlocks'  => $innerBlocks,
            'innerHTML'    => '<li>' . $text . '</li>',
            'innerContent' => $innerContent,
        ];
    }

    /**
     * Walks a <p>'s direct children and splits out any embedded image (bare
     * <img>, an <a> wrapping nothing but a single <img>, or a no-op <span>
     * wrapper - e.g. Word's own <span style="mso-no-proof:yes"> - around
     * one) into its own core/image block, flushing accumulated inline
     * text/formatting around it into core/paragraph blocks. A paragraph
     * containing only an image (no surrounding text) yields just the image
     * block, no empty paragraph.
     *
     * $align (if any) is the wrapping <p>'s own detected alignment -
     * propagated to every block split out of it (any paragraph fragments,
     * and the image, if any), since a sender who center-aligned the whole
     * paragraph almost always means everything in it, and there's no
     * unambiguous way to apply alignment to only part of a split paragraph.
     *
     * @return array<int,array<string,mixed>>
     */
    private function splitParagraphContent($node, AttachmentRegistry $registry, ?string $align = null): array
    {
        $blocks = [];
        $buffer = '';

        foreach ($this->childNodes($node) as $child) {
            if (!isset($child->nodetype) || $child->nodetype !== HDOM_TYPE_ELEMENT) {
                $buffer .= (string) $child->outertext;
                continue;
            }

            $childTag   = strtolower((string) $child->tag);
            $imgNode    = $this->soleImageDescendant($child);
            $anchorNode = ($childTag === 'a' && $imgNode !== null) ? $child : null;

            if ($imgNode === null) {
                $buffer .= (string) $child->outertext;
                continue;
            }

            $pending = trim($buffer);
            if ($pending !== '') {
                $blocks[] = $this->paragraphBlock($pending, $align);
            }
            $buffer = '';

            $imageBlock = $this->imageBlock($imgNode, $anchorNode, $registry, $align);
            if ($imageBlock) {
                $blocks[] = $imageBlock;
            }
        }

        $pending = trim($buffer);
        if ($pending !== '') {
            $blocks[] = $this->paragraphBlock($pending, $align);
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

    /**
     * True for a text node that is nothing but a single bare tag, e.g.
     * "</p>" or "<br>" - never real content. simple_html_dom falls back to
     * emitting a closing tag as a literal text node when it can't match it
     * to an open element on its stack; this happens routinely with Word's
     * HTML export, where an outer wrapper <p> gets implicitly closed early
     * by a nested <p class="MsoNormal">, leaving the wrapper's own closing
     * </p> tag - further down the source - with nothing left to close. A
     * sender who genuinely wanted to show literal tag syntax as visible
     * text would have it HTML-entity-escaped (&lt;p&gt;) in the source,
     * which decodes to plain characters and never round-trips as an actual
     * unmatched tag - so this is safe to drop rather than emit as a junk
     * paragraph.
     */
    private function isOrphanedTagFragment(string $text): bool
    {
        return (bool) preg_match('/^<\/?[a-zA-Z][a-zA-Z0-9]*(?:\s[^<>]*)?\/?>$/', $text);
    }

    /**
     * Reads left/center/right alignment off $node's own "style" (a
     * "text-align:" declaration) or, failing that, its legacy "align"
     * attribute - both are how mail clients (Word/Outlook HTML export in
     * particular) express alignment, since Markdown itself has no
     * alignment concept and this only ever applies to genuinely-HTML
     * content. "style" wins when both are present, matching normal CSS
     * cascade. Anything other than left/center/right (notably "justify",
     * which core/paragraph and core/heading don't support) is treated as
     * no alignment rather than passed through as an unsupported value.
     */
    private function detectAlign($node): ?string
    {
        if (!isset($node->attr) || !is_array($node->attr)) {
            return null;
        }

        $style = (string) ($node->attr['style'] ?? '');
        if (preg_match('/text-align\s*:\s*(left|center|right)/i', $style, $m)) {
            return strtolower($m[1]);
        }

        $align = strtolower(trim((string) ($node->attr['align'] ?? '')));
        return in_array($align, ['left', 'center', 'right'], true) ? $align : null;
    }

    /**
     * Finds the single <img> that $node either is, or wraps through one or
     * more no-op <span>/<a> layers with no text content of their own -
     * e.g. Word's <span style="mso-no-proof:yes"><img/></span>, or that
     * span additionally wrapped in a link. Returns null if $node contains
     * any real text, more than one element child, or isn't an img/span/a at
     * all - i.e. if unwrapping it would lose or misrepresent real content.
     *
     * @return object|null
     */
    private function soleImageDescendant($node)
    {
        if (!isset($node->nodetype) || $node->nodetype !== HDOM_TYPE_ELEMENT) {
            return null;
        }

        $tag = strtolower((string) $node->tag);
        if ($tag === 'img') {
            return $node;
        }
        if ($tag !== 'span' && $tag !== 'a') {
            return null;
        }

        $elementChildren = [];
        foreach ($this->childNodes($node) as $child) {
            if (isset($child->nodetype) && $child->nodetype === HDOM_TYPE_TEXT) {
                if (trim((string) $child->outertext) !== '') {
                    return null;
                }
                continue;
            }
            $elementChildren[] = $child;
        }

        if (count($elementChildren) !== 1) {
            return null;
        }

        return $this->soleImageDescendant($elementChildren[0]);
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
    private function paragraphBlock(string $innerHtml, ?string $align = null): array
    {
        $class = $align !== null ? ' class="has-text-align-' . $align . '"' : '';
        $html  = '<p' . $class . '>' . $innerHtml . '</p>';
        return [
            'blockName'    => 'core/paragraph',
            'attrs'        => $this->textAlignAttrs($align),
            'innerBlocks'  => [],
            'innerHTML'    => $html,
            'innerContent' => [$html],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function headingBlock(string $innerHtml, int $level, ?string $align = null): array
    {
        $level = max(1, min(6, $level));
        $attrs = $level !== 2 ? ['level' => $level] : [];
        $attrs = array_merge($attrs, $this->textAlignAttrs($align));

        // "wp-block-heading" is present on every real Gutenberg heading
        // regardless of alignment - confirmed live (an unaligned heading
        // still saved with this class) - unlike core/paragraph, which gets
        // no base class of its own. Missing this class means the stored
        // markup wouldn't match what core/heading's own save() would
        // currently produce, which is exactly what makes the editor show
        // "this block contains unexpected or invalid content".
        $classes = ['wp-block-heading'];
        if ($align !== null) {
            $classes[] = 'has-text-align-' . $align;
        }
        $class = ' class="' . implode(' ', $classes) . '"';
        $html  = "<h{$level}{$class}>{$innerHtml}</h{$level}>";
        return [
            'blockName'    => 'core/heading',
            'attrs'        => $attrs,
            'innerBlocks'  => [],
            'innerHTML'    => $html,
            'innerContent' => [$html],
        ];
    }

    /**
     * Gutenberg's text blocks (paragraph, heading, list, quote) store
     * left/center/right text alignment under the nested style-engine attrs
     * shape - confirmed live against a real WP install by centering a
     * paragraph in the block editor and reading back the exact attrs it
     * saved: {"style":{"typography":{"textAlign":"center"}}}, not a
     * top-level "align" key (that shape is core/image's, a different,
     * older convention - see imageBlock()).
     *
     * @return array<string,mixed>
     */
    private function textAlignAttrs(?string $align): array
    {
        return $align !== null ? ['style' => ['typography' => ['textAlign' => $align]]] : [];
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
    private function imageBlock($imgNode, $anchorNode, AttachmentRegistry $registry, ?string $align = null): ?array
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

        // core/image alignment - confirmed live against a real WP install
        // by centering an image in the block editor: a top-level "align"
        // attribute plus a bare "align{value}" class on the <figure> (no
        // "has-text-align-" prefix - that's the paragraph/heading
        // convention, a different mechanism - see textAlignAttrs()). Falls
        // back to the <img> tag's own align/style if the caller (e.g. a
        // standalone top-level <img> with no wrapping <p>/<a>/<span>) had
        // no alignment context to pass in.
        $align = $align ?? $this->detectAlign($imgNode);
        if ($align !== null) {
            $blockAttrs['align'] = $align;
            $figureClasses[]     = 'align' . $align;
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

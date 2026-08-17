=== Postie Markdown Blocks ===
Contributors: James Harvey
Tags: postie, email, markdown, gutenberg, blocks
Requires at least: 6.2
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Converts Postie's emailed-in post content (Markdown plain-text or rich HTML) into native Gutenberg blocks instead of raw HTML.

== Description ==

This is an addon for the Postie plugin (email-to-post). It does nothing on its own - Postie must be installed and active.

By default, Postie saves emailed content as a flat HTML/text blob, so a post created from an email opens in the block editor as one big Classic/HTML block rather than proper paragraph/heading/image blocks. Postie Markdown Blocks hooks Postie's own `postie_post_pre` and `postie_post_before` filters (and `postie_file_added`) to convert that content into real Gutenberg blocks before the post is saved:

* Plain-text emails written with Markdown syntax (`#`/`##` headings, blank-line paragraphs, `**bold**`, `[links](url)`, `*`/`-` lists) are parsed into native blocks.
* Rich HTML emails (Outlook/Gmail-style formatted mail) are normalized into equivalent blocks.
* Emailed photo attachments become `core/image` blocks referencing the real WordPress attachment Postie already created, not bare `<img>` tags.
* Bullet and numbered lists become real `core/list` blocks (with each item as its own `core/list-item`, including nested sub-lists), not plain text.
* Anything not yet natively mapped (quotes, code, tables - deferred past v1) falls back to a `core/html` block, so content is never silently dropped.

== Installation ==

1. Install and activate Postie first.
2. Install and activate this plugin.
3. Optionally visit Postie's settings > Markdown Blocks to toggle Markdown parsing for plain-text emails (on by default). HTML-email normalization is always on.

== Frequently Asked Questions ==

= My email had a "---" line and everything after it disappeared =

This is Postie's own behavior, not this plugin's. Postie's default settings treat a line containing only `---` (or `--`) as the start of an email signature and strip everything from that point onward, before this plugin ever sees the content (Postie's `sig_pattern_list` setting, under Postie's own settings screen, includes `---` by default). Markdown commonly uses `---` on its own line as a thematic break (horizontal rule), so a Markdown email using that syntax will have everything after it silently removed by Postie itself.

Workarounds:
* Use `***` or `___` instead of `---` for a horizontal rule in emailed Markdown - neither is in Postie's default signature-pattern list.
* Or, if you don't rely on Postie's automatic signature stripping, remove `---` (and `--`) from Postie's "Signature Patterns" setting.

== Changelog ==

= 0.1.2 =
* Fix: Markdown was never parsed for real mail-client HTML - Thunderbird wraps each paragraph in its own <p>, which made every paragraph individually opaque to Parsedown's raw-HTML-passthrough rule
* Fix: a bare URL auto-linked by the mail client inside Markdown image/link syntax (e.g. Thunderbird's freetext links) broke image/link recognition - now unwrapped before parsing
* New: bullet and numbered lists now convert to real core/list blocks (with nested sub-list support) instead of falling back to core/html

= 0.1.1 =
* Fix: inline images within paragraph text now become their own core/image block instead of being embedded in the paragraph's HTML
* Fix: plain-text paragraphs and text preceding an inline image were silently dropped due to a simple_html_dom quirk (children() excludes text nodes)
* Fix: Markdown detection now based on content patterns rather than which MIME part the email populated, so HTML-wrapped plain-text emails (Gmail/Outlook) no longer collapse to one block
* Fix: horizontal rules (---) now become a core/separator block instead of being silently dropped

= 0.1.0 =
* Initial release: Markdown/HTML -> Gutenberg blocks conversion for paragraphs, headings, and images.

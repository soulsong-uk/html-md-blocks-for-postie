=== Postie Markdown Blocks ===
Contributors: James Harvey
Tags: postie, email, markdown, gutenberg, blocks
Requires at least: 6.2
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Converts Postie's emailed-in post content (Markdown plain-text or rich HTML) into native Gutenberg blocks instead of raw HTML.

== Description ==

This is an addon for the Postie plugin (email-to-post). It does nothing on its own - Postie must be installed and active.

By default, Postie saves emailed content as a flat HTML/text blob, so a post created from an email opens in the block editor as one big Classic/HTML block rather than proper paragraph/heading/image blocks. Postie Markdown Blocks hooks Postie's own `postie_post_pre` and `postie_post_before` filters (and `postie_file_added`) to convert that content into real Gutenberg blocks before the post is saved:

* Plain-text emails written with Markdown syntax (`#`/`##` headings, blank-line paragraphs, `**bold**`, `[links](url)`) are parsed into native blocks.
* Rich HTML emails (Outlook/Gmail-style formatted mail) are normalized into equivalent blocks.
* Emailed photo attachments become `core/image` blocks referencing the real WordPress attachment Postie already created, not bare `<img>` tags.
* Anything not yet natively mapped (lists, quotes, code, tables - deferred past v1) falls back to a `core/html` block, so content is never silently dropped.

== Installation ==

1. Install and activate Postie first.
2. Install and activate this plugin.
3. Optionally visit Postie's settings > Markdown Blocks to toggle Markdown parsing for plain-text emails (on by default). HTML-email normalization is always on.

== Changelog ==

= 0.1.1 =
* Fix: inline images within paragraph text now become their own core/image block instead of being embedded in the paragraph's HTML
* Fix: plain-text paragraphs and text preceding an inline image were silently dropped due to a simple_html_dom quirk (children() excludes text nodes)
* Fix: Markdown detection now based on content patterns rather than which MIME part the email populated, so HTML-wrapped plain-text emails (Gmail/Outlook) no longer collapse to one block
* Fix: horizontal rules (---) now become a core/separator block instead of being silently dropped

= 0.1.0 =
* Initial release: Markdown/HTML -> Gutenberg blocks conversion for paragraphs, headings, and images.

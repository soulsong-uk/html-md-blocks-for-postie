=== Postie Blocks Addon ===
Contributors: James Harvey
Tags: postie, email, markdown, gutenberg, blocks
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Converts Postie's emailed-in post content (Markdown plain-text or rich HTML) into native Gutenberg blocks instead of raw HTML.

== Description ==

This is an addon for the Postie plugin (email-to-post). It does nothing on its own - Postie must be installed and active.

By default, Postie saves emailed content as a flat HTML/text blob, so a post created from an email opens in the block editor as one big Classic/HTML block rather than proper paragraph/heading/image blocks - and a big blob is unpleasant to edit afterward. Postie Blocks Addon hooks Postie's own `postie_post_pre` and `postie_post_before` filters (and `postie_file_added`) to convert that content into real, individually-editable Gutenberg blocks before the post is saved:

* Plain-text emails written with Markdown syntax (`#`/`##` headings, blank-line paragraphs, `**bold**`, `[links](url)`, `*`/`-` lists) are parsed into native blocks.
* Rich HTML emails (Outlook/Gmail-style formatted mail) are normalized into equivalent blocks.
* Emailed photo attachments become `core/image` blocks referencing the real WordPress attachment Postie already created, not bare `<img>` tags.
* Bullet and numbered lists become real `core/list` blocks (with each item as its own `core/list-item`, including nested sub-lists), not plain text.
* Blockquotes (`>`) become real `core/quote` blocks, with their contents as native inner blocks.
* Anything not yet natively mapped (code, tables - deferred past v1) falls back to a `core/html` block, so content is never silently dropped.
* Wrap your Markdown in `<md>...</md>` for guaranteed-reliable conversion - shields it from several of Postie's own content-processing conventions that can otherwise collide with Markdown syntax (lists, "---" rules, a leading "#" heading). See the FAQ below.

== Installation ==

1. Install and activate Postie first.
2. Install and activate this plugin.
3. Optionally visit Postie's settings > Blocks to toggle Markdown parsing and/or Gutenberg block conversion independently (both on by default).

== Frequently Asked Questions ==

= Recommended: always wrap your Markdown in <md>...</md> =

Postie has several of its own content-processing features - collapsing single line breaks, stripping anything after a "---" line as a signature, pulling an inline "#subject#" out of the body - that all run *before* this plugin ever sees your email, and each can silently damage Markdown syntax that happens to resemble one of Postie's own conventions (see the three entries below for specifics). Wrapping the Markdown portion of your email like this sidesteps all three at once:

`<md>`
`# My Heading`
`---`
`* A list item`
`* Another item`
`</md>`

Content inside `<md>...</md>` is shielded from Postie's own content processing entirely before this plugin runs, and converts using your email's exact original line breaks. This is the single most reliable way to guarantee any Markdown email - especially one with a list, a "---" rule, or a heading as the very first line - converts correctly. Each entry below also lists an alternative if you'd rather not use `<md>`.

= My email had a "---" line and everything after it disappeared =

Postie's own behavior, not this plugin's: its default settings treat a line containing only `---` (or `--`) as the start of an email signature and strip everything from that point onward, before this plugin ever sees the content (Postie's `sig_pattern_list` setting, under Postie's own settings screen, includes `---` by default). Markdown commonly uses `---` on its own line as a thematic break (horizontal rule), so a Markdown email using that syntax loses everything after it.

Fix: wrap your Markdown in `<md>...</md>` (see above). Alternatively:
* Use `***` or `___` instead of `---` for a horizontal rule - neither is in Postie's default signature-pattern list.
* Or remove `---` (and `--`) from Postie's "Signature Patterns" setting if you don't rely on automatic signature stripping.

= My post title/slug ended up containing half my email body =

Also Postie's own behavior, and also a Markdown collision. If Postie's "Allow Subject In Mail" setting (Message tab) is on and the email body starts with `#`, Postie treats everything up to the *next* `#` anywhere in the body as the subject and strips it out - intended for a deliberate `#subject#` marker on the first line, but a Markdown email starting with a `# Heading` (with a later `##`/`###` heading further down) gets its entire opening section swallowed into the post title instead.

Fix: wrap your Markdown in `<md>...</md>` (see above), with the `<md>` tag itself as the very first thing in the body - the body then no longer starts with a literal `#`. Alternatively:
* Turn off "Allow Subject In Mail" under Postie's Message settings tab if you don't use that feature.
* Or avoid starting the email body with a `#` heading as literally the first character.

= My Markdown list came through as a jumbled mess, or a heading swallowed way more text than expected =

Also Postie's own behavior. Postie's own newline-collapsing (its "Filter newlines" setting, on by default) preserves a *blank-line* paragraph break, but collapses a *single* line break down to just a space - and Markdown lists (and a heading immediately followed by its list, per standard convention) use single line breaks between items, not blank lines. That distinction is gone by the time this plugin ever sees the content, so a list written the normal way can come through merged into one run-on line, sometimes with stray `*` characters misread as italics.

Fix: wrap your Markdown in `<md>...</md>` (see above) - there's no alternative workaround for this one short of avoiding lists entirely, since it's caused by Postie's own default settings rather than one specific character sequence.

== Changelog ==

= 0.1.7 =
* Change: renamed from Postie Markdown Blocks to Postie Blocks Addon - block conversion is the primary feature, Markdown parsing is a secondary/optional one. Namespace, constants, option name (with automatic migration), settings slug, and CSS classes all updated to match; no functional changes

= 0.1.6 =
* New: blockquotes now convert to real core/quote blocks (with native inner blocks) instead of falling back to core/html
* Change: settings page and readme FAQ now lead with the <md>...</md> marker as the single recommended fix for all of Postie's own content-processing conflicts, not just the list one

= 0.1.5 =
* New: independent settings toggles for Markdown parsing and Gutenberg block conversion - block conversion can now be turned off entirely (previously always on)

= 0.1.4 =
* New: wrap Markdown content in <md>...</md> to shield it from Postie's own newline-collapsing and signature/subject-marker collisions - fixes lists and headings-followed-by-lists coming through merged or corrupted

= 0.1.3 =
* Fix: Markdown list items with mail-client whitespace padding right after the marker were becoming indented code blocks instead of plain list text
* Fix: literal backslashes in emailed content (e.g. Windows file paths) were being doubled when Postie saved the post - now preserved correctly

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

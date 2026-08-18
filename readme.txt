=== Postie Blocks Addon ===
Contributors: James Harvey
Tags: postie, email, markdown, gutenberg, blocks
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Converts Postie's emailed-in post content (Markdown plain-text or rich HTML) into native Gutenberg blocks instead of raw HTML.

== Description ==

This is an addon for the Postie plugin (email-to-post). It does nothing on its own - Postie must be installed and active.

By default, Postie saves emailed content as a flat HTML/text blob, so a post created from an email opens in the block editor as one big Classic/HTML block rather than proper paragraph/heading/image blocks - and a big blob is difficult to edit afterward. 

Postie Blocks Addon converts that emailed content into real, individually-editable Gutenberg blocks before the post is saved with the ability to further format using markdown syntax within `<md>...</md>`.

* Rich HTML emails (Outlook/Gmail-style formatted mail) are normalized into blocks.
* Markdown syntax (`#`/`##` headings, blank-line paragraphs, `**bold**`, `[links](url)`, `*`/`-` lists, `>` quotes) wrapped in an explicit `<md>...</md>` block is parsed into native blocks. See the FAQ below.
* Emailed photo attachments become `core/image` blocks referencing the real WordPress attachment Postie already created, not bare `<img>` tags.
* Bullet and numbered lists become real `core/list` blocks (with each item as its own `core/list-item`, including nested sub-lists), not plain text.
* Blockquotes (`>`) become real `core/quote` blocks, with their contents as native inner blocks.
* Anything not yet natively mapped (code, tables - deferred past v1) falls back to a `core/html` block, so content is never silently dropped.

== Installation ==

1. Install and activate Postie first.
2. Install and activate this plugin.
3. Optionally visit Postie's settings > Postie Blocks to toggle Markdown parsing and/or Gutenberg block conversion independently (both on by default).

== Frequently Asked Questions ==

= How do I get Markdown syntax converted? =

Wrap the part of the email you want parsed as Markdown in `<md>` and `</md>` tags, each on their own line:

`<md>`
`# My Heading`
`* A list item`
`* Another item`
`</md>`

This is deliberate, not automatic: this plugin never guesses whether some arbitrary content "looks like" Markdown - only content inside an explicit `<md>...</md>` block is ever parsed as Markdown. Content outside the wrapper (or the whole email, if you don't use one) is treated as plain content. As a side benefit, content inside `<md>...</md>` is also fully shielded from Postie's own content-processing (its newline-collapsing, `---`-line signature stripping, and `#subject#` inline-subject extraction all run before this plugin ever sees the content, and could otherwise silently damage unwrapped Markdown syntax that happens to resemble one of those conventions) - using `<md>` sidesteps all of that automatically, with no workaround needed.

== Changelog ==

= 0.1.8 =
* Change: Markdown is now only ever parsed inside an explicit <md>...</md> block - the previous heuristic that guessed whether arbitrary content looked like Markdown has been removed entirely
* Change: settings page rewritten in the positive (what each setting does, not what breaks when off), with the now-unnecessary Recommended/Known Conflict sections removed
* Change: Markdown toggle renamed to 'Convert Markdown to Gutenberg Blocks' with a worked <md> example shown directly on the settings page

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

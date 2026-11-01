# Rich Content Editor v2.1 — v38 (v39: sticky buttons reset on reload) changelog, test report, limitations

## Changelog (v37 → v38)

**New**
- Blog and page body editor is now a single-flow rich editor (no blocks): paragraphs, headings (H2–H4), bold / italic / underline / strike, lists, quote, code, links, images, galleries, tables, YouTube and Vimeo videos, dividers.
- Top strip: Undo / Redo, Visual / Code tabs.
- Floating format bar on text selection (Alt+F10 / Shift+F10 for keyboard users).
- Hover "+" controls on empty or current lines: image, table, video, divider.
- Link popover (Ctrl+K) with internal link suggestions from published posts and pages.
- Image upload: file picker, drag and drop, paste; several files at once become a 2- or 3-column gallery. Each image is re-encoded to WebP at 1600 px and 800 px.
- Alt text popover for every image; a warning is shown while alt text is empty.
- Table grid picker (up to 6×6), table options (row/column add/remove, header row, delete), Tab / Enter navigation between cells.
- Code tab shows the stored HTML; edits apply back to the visual editor.
- Client paste cleaner (Word / Google Docs / web HTML is reduced to the allowed set).
- Legacy Markdown and plain-text posts keep rendering unchanged; they are converted only when edited.
- Server-side allow-list sanitiser on save and on render (`app/richtext.php`).
- Secure upload endpoint `jebu-cp-3hr3f/rich-upload.php`: POST only, CSRF (header or field), per-user rate limit (30 per minute), magic-byte check through GD, EXIF orientation applied and metadata dropped by re-encoding, random file names.

**Changed**
- `app/entities.php`: blog body field uses the `rich` type.
- `jebu-cp-3hr3f/entity.php`: `rich` case uses `rich_store()`; size check; editor markup.
- `blog-single.php`: rich posts render through `rich_render()`, wrapped in `.cq-content`; FAQ pairs from question headings for rich posts.
- `assets/js/rich.js`: rewritten (about 55 KB source, about 14 KB gzipped).
- `assets/css/admin.css`: old editor rules replaced with the new editor block. Existing admin design unchanged.
- `assets/css/site.css`: `.cq-content` rules added (images, galleries, tables, 16:9 video). Existing rules unchanged.
- `app/upload.php`: `rich_store_image()` and `rich_scale_image()` added. `save_upload()` unchanged.

**Fixed during testing (found by the browser suite)**
- Text typed into an empty editor was not wrapped in a paragraph.
- Bold and italic were stored as inline style spans, which the sanitiser dropped. They are now `<b>` / `<i>`, and Ctrl+B / Ctrl+I / Ctrl+U are handled.
- The table options button was always disabled.
- Ctrl+B could apply to a stale saved range instead of the live selection.
- Alt+F10 opened the bar and it closed again when focus moved into it.
- A broken Code-tab edit made the form submit an empty body and wiped the visual content. Submit is now blocked with a message and the content is kept.

## Test report

Run in real Chromium (Playwright) against PHP 8.3.6 and MariaDB 10.11.14 in the build sandbox.

| Suite | Result |
|---|---|
| Browser e2e: editor behaviour (spec §8.2 items 1–18, plus mobile and keyboard) | 47 / 47 passed |
| Upload security (spec §8.3): PHP file renamed to .jpg, PHP in EXIF, CSRF missing, anonymous, rate limit | included in the 47 |
| Sanitiser unit checks (allow-list, links, images, iframes, style props) | 44 / 44 |
| Security and injection checks from the tracking work (regression) | 44 / 44 |
| Admin flow checks (regression, tracking pages) | 20 / 20 |
| `php -l` on every changed PHP file; `node --check` on `rich.js` | clean |

Public rendering checked on the test site: rich post (images with srcset, iframe, table, gallery, no marker leak), legacy Markdown posts, sitemap and feed respond 200.

## Limitations (stated honestly)

1. **Minified size not measured.** No minifier is available in the build sandbox. Source is about 55 KB, about 14 KB gzipped. The spec budget is 40 KB minified; that is not confirmed.
2. **English dictionary absent.** Only Bangla (`bn`) strings exist. The editor falls back to Bangla labels.
3. **Original image is not kept as a fallback.** Only WebP 1600 and 800 variants are stored, as the design chose. The spec asked for an original fallback; this is a deliberate deviation to review.
4. **No `sizes` attribute** on images; the sanitiser does not keep it.
5. **Tables:** no colspan or rowspan (as the spec states).
6. **Animated GIF** becomes a static image after re-encoding.
7. **Undo / redo** is snapshot-based. It covers typing, tables, uploads and video; it is not a per-keystroke history.
8. **Anonymous upload** receives a 302 redirect to login, not a JSON 401. No file is stored.
9. **Layout check used a shim.** The sandbox cannot reach the Tailwind CDN, so admin layout utilities were injected in tests only. The live site loads Tailwind normally. Admin layout should be looked at once on the live host.
10. **FAQ schema from rich content** is generated from question headings, but the test content had none, so this path was not exercised.
11. **Docs** (`docs/editor-user-guide.md`, `docs/editor-integration.md`) are not written in this version.
12. **Live host not verified.** Everything was tested on the local sandbox only.

## Live host checklist

1. Import `app/database/jebu_medical_full.sql` if it has not been imported yet (no schema change in v38 for the editor).
2. Upload this package; keep the existing live `app/config.php`.
3. Open a blog post in edit mode; type, select text, add a link, add an image, add a 3-image gallery, add a table, add a YouTube video.
4. Save; open the public post; confirm images load, the video plays, and the table scrolls on a phone.
5. Open an old Markdown post; confirm it still renders; save it once and confirm the content is kept.
6. Check the browser console on the editor page: no errors.
7. On a phone (390 px), confirm the editor bar wraps and the page does not scroll sideways.

## v39 addendum
- Sticky action buttons (AI, appointment, WhatsApp): a dragged position now lasts for the current page view only. Reload returns them to the default place. A stored position from earlier versions is removed on load. Double-click on the grip still resets. Checked in Chromium: drag moves, nothing is stored, reload restores the default, no page errors.

## v40 addendum
- Home hero: the three service cards now switch automatically every 6 seconds. Hover, focus or touch pauses it; leaving resumes it; it stops when the hero is off-screen, the tab is hidden, or reduced motion is set.
- Mega menu: each card can show a photo from `assets/img/mega/<slug>.jpg` (see the README.txt in that folder). Without a file the menu looks exactly as before. Hidden on small screens.

## v41 addendum
- Mega menu photos added: assets/img/mega/conditions.jpg (রোগসমূহ), treatments.jpg (চিকিৎসা), procedures.jpg (প্রসিডিওর). Converted from PNG to JPEG. Replace them any time with the same file names.

## v42 addendum
- Mega menu: slightly larger panel (up to 1120 px), more padding and spacing in the cards.
- PWA: manifest (standalone, icons 192/512, shortcuts to appointment and conditions), /sw.js service worker (caches static files only; pages, forms, admin and API are never cached), icons in assets/img/icons/.
- Install popup (assets/js/install.js): Android/desktop Chrome gets an install button; iPhone/iPad gets a Share > Add to Home Screen note. Shown after 6 seconds; hidden for 14 days after the visitor closes it; hidden once installed.
- Needs HTTPS on the live host (SSL) for the app and service worker to work.

## v43 addendum
- Mega menu panel width now equals the page content width (same .wrap rule: max 1180 px, 20 px side gutter), so its edges line up with the page content.

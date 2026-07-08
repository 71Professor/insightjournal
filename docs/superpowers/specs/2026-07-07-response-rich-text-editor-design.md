# Rich-text editor for the learner response field

- Branch: `editor-implement`
- Date: 2026-07-07
- Status: Approved for planning

## Context

The teacher-facing prompt field (`mod_form.php`) already uses Moodle's standard
rich-text editor via an `editor` form element. The learner-facing response
field (`templates/view.mustache`) is a plain `<textarea>`, saved via AJAX
through `mod_insightjournal_save_entry` and always hardcoded to
`FORMAT_PLAIN`, even though `insightjournal_entries.responseformat` already
exists in the schema and defaults to `FORMAT_HTML`.

This feature gives learners a rich-text editor for their response, matching
the prompt field, plus a view/edit toggle: a saved response displays as
rendered read-only HTML with an "Edit" button, and editing switches to the
editor with a "Save" button that returns to the read-only view.

## Goals

- Learners write and format responses (bold, italic, lists, headings, links)
  using Moodle's site-configured editor (Atto or TinyMCE), not a hardcoded one.
- A saved response is shown read-only by default; "Edit" switches to the
  editor; "Save" switches back to the read-only view.
- Autosave keeps working in the background while editing, without forcing the
  learner out of edit mode.
- minchars/maxchars and the `completionentries` completion rule keep counting
  what a learner would perceive as "characters typed" (visible text), not raw
  HTML markup.
- Every other place a response is displayed (personal summary, teacher
  report, CSV export) renders it correctly under the new format.

## Non-goals

- No image/file embedding in the response. The editor is attached with
  `maxfiles => 0` (same restriction already used for the prompt field), so
  Atto/TinyMCE's image/media toolbar buttons, if shown, have no working
  upload path. This is an accepted functional limitation, not a security
  boundary — we are not adding extra server-side stripping of stray `<img>`
  tags that might survive the standard HTML purifier (e.g. from a paste of
  externally-hosted HTML). Standard `PARAM_CLEANHTML` cleaning is considered
  sufficient for this release.
- No lazy/AJAX-loaded editor (no fragment API). The editor is always attached
  at page load for `canwrite` users; only its container's visibility toggles.
- No "Cancel" / discard-changes action. Only Save and Edit exist, per the
  brief. A learner who clicks Edit and then wants to abandon changes has no
  dedicated affordance for that in this iteration.
- No database schema changes. `responseformat` already exists; this feature
  starts actually using it as intended.

## Approach

Two alternatives were considered:

1. **Chosen: render both view and edit markup at page load, toggle
   visibility client-side.** `view.php` calls
   `editors_get_preferred_editor(FORMAT_HTML)->use_editor(...)` unconditionally
   for `canwrite` users, exactly as `mform`'s `editor` element does internally.
   Both the read-only container and the editor container exist in the DOM from
   first render; JS toggles a `d-none` class between them. Saving updates the
   view container's contents from the server's response rather than reloading
   the page.
2. **Rejected: lazy-load the editor via Moodle's fragment API**, mirroring
   `mod_forum`'s inline reply editor (`core/fragment::loadFragment()` calling a
   server-rendered-fragment callback). This avoids attaching an editor for
   users who never click Edit, but requires a new fragment renderer class, a
   new AJAX round trip just to enter edit mode, and more edge cases to get
   right. Not justified for a single response field on one page.

A third option — a custom `contenteditable`-based editor instead of Moodle's
editor subsystem — was ruled out earlier in brainstorming: it would ignore the
site/user's configured editor and duplicate functionality Moodle already
ships, which is a poor fit for a plugin aiming for Plugin Directory review.

## Component changes

### `locallib.php`

New helper:

```php
function insightjournal_html_to_text(string $html): string {
    return trim(html_to_text($html, 0, false));
}
```

Used everywhere a "visible character count" or "is this response actually
empty" check is needed. This matters because an empty Atto/TinyMCE editor
serializes to something like `<p></p>` or `<p><br></p>`, not `""` — so a raw
`trim($response) === ''` check (used today in `custom_completion.php`,
`coursereport.php`) would incorrectly treat an empty-looking response as
present once responses are HTML.

### `view.php`

- Before `$OUTPUT->header()`: attach the preferred editor to the response
  textarea's element id (`insightjournal-response-<cmid>`), with
  `['maxfiles' => 0, 'trusttext' => false, 'subdirs' => false, 'context' =>
  $context]`, mirroring the prompt field's editor options.
- Template context additions:
  - `responseraw` — the stored HTML, to prefill the editor textarea.
  - `responseformatted` — `format_text($entry->response, FORMAT_HTML,
    ['context' => $context])`, for the read-only view.
  - `haveentry` — bool, whether a saved (non-empty, via
    `insightjournal_html_to_text`) entry exists. Drives which container starts
    visible.
  - Existing `lastsaved` is now shown in both the view and edit containers.

### `templates/view.mustache`

Inside the existing `{{#canwrite}}` block, replace the single textarea with
two sibling containers:

- **View container** (`data-insightjournal-view`, hidden via `d-none` when
  `haveentry` is false): renders `{{{responseformatted}}}`, the "Last saved"
  line, and an Edit button (`data-insightjournal-edit`, reusing the core
  `edit` string — no new lang string needed).
- **Edit container** (`data-insightjournal-edit-panel`, hidden via `d-none`
  when `haveentry` is true): the textarea (`id="insightjournal-response-
  {{cmid}}"`, prefilled with `{{responseraw}}`, `data-insightjournal-response`)
  plus the existing Save button, live status region, char counter, and
  minchars note, unchanged in structure.

### `classes/external/save_entry.php`

- `response` parameter: `PARAM_TEXT` → `PARAM_RAW`.
- Clean with `clean_param($response, PARAM_CLEANHTML)` before any further use.
- maxchars validation uses `core_text::strlen(insightjournal_html_to_text($clean))`
  instead of raw string length.
- Store `responseformat = FORMAT_HTML` unconditionally (no format selector is
  exposed to the learner).
- `execute_returns()` gains `responsehtml` — the freshly cleaned response run
  through `format_text()` — so the client can update the read-only view
  without a second request or full page reload.

### `classes/completion/custom_completion.php`

`get_state()`'s emptiness check and minchars comparison both switch to
`insightjournal_html_to_text($entry->response)`.

### `amd/src/autosave.js`

- New element refs for the view container, edit container, and Edit button.
- `save(cmid, isManual)`: existing AJAX flow, but on success:
  - If `isManual` (Save button click): replace the view container's inner
    HTML with `result.responsehtml`, update the "last saved" text there, and
    switch to view mode.
  - If not manual (autosave timer fire): update only the live status text;
    stay in edit mode.
- Edit button click: switch to edit mode (no server round trip — the editor
  is already attached and prefilled).
- Char counting strips HTML before measuring, via
  `new DOMParser().parseFromString(html, 'text/html').body.textContent` (inert
  parsing — no script execution, no resource loading — applied to the
  learner's own in-progress input, not third-party content).
- Focus management: entering edit mode focuses the editor; returning to view
  mode moves focus to the Edit button, so screen reader users aren't stranded.

### Other display surfaces

- `templates/entry_card.mustache` (personal summary): response changes from
  escaped `{{response}}` to rendered `{{{response}}}`; `summary.php` now
  computes `format_text()` output and a `hasresponse` boolean (via
  `insightjournal_html_to_text`) instead of relying on Mustache's truthiness
  of the raw string.
- `templates/report.mustache` (teacher report): same change —
  `{{response}}` → `{{{response}}}`, `report.php` runs it through
  `format_text()`.
- CSV export (`report.php`, `coursereport.php`): the `response` column uses
  `insightjournal_html_to_text()` (plain text), not raw HTML markup — a CSV
  cell full of `<p>` tags is not useful in a spreadsheet. The existing
  CSV-injection prefixing (`insightjournal_csv_value`) still applies to the
  stripped text.
- `coursereport.php`'s "submitted" check (`trim((string)$entry->response) !==
  ''`) switches to the same helper for the empty-HTML-shell reason above.

### Privacy provider

No changes needed. It already exports `response` and `responseformat`
verbatim; exporting HTML content is standard Moodle practice (e.g. forum
posts).

### Database / upgrade

No schema change. `responseformat` already exists and defaults to
`FORMAT_HTML`. `version.php` gets a version bump per Moodle convention for a
functional change, and `CHANGELOG.md` gets an entry.

Entries saved before this feature ships are stored with `responseformat =
FORMAT_PLAIN`. No data migration is needed: every read path already renders
through `format_text($response, $responseformat, ...)`, which honours each
row's own stored format, so old plain-text entries keep rendering correctly
(escaped, with line breaks) alongside new HTML ones.

## Testing

- **PHPUnit**
  - `tests/external/save_entry_test.php`: disallowed tags (e.g. `<script>`)
    are stripped, allowed formatting tags (e.g. `<strong>`) survive,
    `responseformat` is stored as `FORMAT_HTML`, maxchars validation is based
    on visible-text length (a heavily-marked-up-but-short response passes; a
    long-plain-text response fails), `responsehtml` is present and correctly
    formatted in the return value.
  - `tests/custom_completion_test.php`: an empty editor shell (`<p></p>`)
    counts as incomplete; minchars is evaluated against visible text length,
    not raw HTML length.
  - New small unit test for `insightjournal_html_to_text()`.
- **Behat** (`tests/behat/insight_journal.feature`): extend the existing
  save/reload scenario to drive the actual rich-text field (Moodle core's
  Behat field manager has built-in support for setting Atto/TinyMCE field
  content through the standard "I set the field" step) and add a scenario
  covering Edit → change text → Save → response shows read-only rendered
  content.

## Risks / open questions carried into implementation

- Exact DOM/event behavior for reading "live" editor content differs slightly
  between Atto and TinyMCE (both keep the backing textarea's value continuously
  synced, but the specific events fired — `input` vs `change` — may differ).
  The autosave module should be tested against whichever editor the dev/test
  Moodle instance has configured by default, per
  `moodle-docker PHPUnit env` / `Moodle Docker test env` memory notes, and
  adjusted if the debounce/char-count listeners don't fire as expected.

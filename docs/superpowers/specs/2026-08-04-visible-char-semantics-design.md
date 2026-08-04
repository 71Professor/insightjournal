# R4-01 – Sichtbare Zeichen semantisch definieren

**Datum:** 04.08.2026
**Bezug:** `Fix.md`, R4-01 (P1 – Stable-Gate)
**Status:** Entwurf, zur Review

## Problem

`insightjournal_visible_char_count()` (PHP, `locallib.php`) und `stripHtml()`/`charCount()`
(JS, `amd/src/autosave.js`) sollen dieselbe Zeichenzahl liefern wie ein Browser für den
Live-Zähler zeigt. Beide extrahieren bereits DOM-`textContent`-äquivalent (keine
synthetischen Trenner zwischen Blockelementen, Codepoint-bewusste Länge) – das ist
bereits durch eine PHPUnit-Suite abgedeckt. Zwei Lücken bleiben:

1. **Leer-Grenze nicht Unicode-bewusst:** `custom_completion.php:70` prüft „hat der
   Eintrag überhaupt Inhalt" über `insightjournal_html_to_text() === ''`. PHPs `trim()`
   entfernt nur ASCII-Whitespace, kein NBSP (`U+00A0`) oder Zero-Width-Zeichen. Ein
   Eintrag, der ausschließlich aus solchen Zeichen besteht, gilt daher als „hat Inhalt"
   und kann – abhängig von `minchars` – sogar als vollständig markiert werden, obwohl er
   optisch leer ist.
2. **Parität nur behauptet, nicht bewiesen:** Es gibt keinen Test, der nachweist, dass
   PHP-`DOMDocument`-Extraktion und ein echter Browser-`DOMParser` auf denselben Eingaben
   dieselbe Zahl liefern. Zusätzlich fehlt `LIBXML_NONET` auf dem `DOMDocument`-Aufruf.

## Ziel / Scope

- Eine einzige, klar definierte Leer-Grenze, identisch in PHP und JS angewendet.
- Dieselbe Fixture-Tabelle (HTML → erwartete Zeichenzahl) treibt PHPUnit **und** einen
  echten Browser-Test (Behat/Chrome) – die Parität wird damit nachgewiesen, nicht nur
  kommentiert.
- `LIBXML_NONET` auf dem bestehenden `DOMDocument`-Aufruf.

**Nicht Teil dieser Änderung:**
- Die DOM-Extraktion selbst (Blockgrenzen-Verhalten, `<br>`-Behandlung) – bereits korrekt
  und getestet, bleibt unverändert.
- `insightjournal_html_to_text()` (CSV/Anzeige, `prompttext`-Pflichtfeldprüfung in
  `mod_form.php`) – unverändert, weiterhin eine bewusst andere Funktion mit anderer
  Formatierungssemantik.
- Zeicheninnenraum bei nicht-leerem Text (Leerzeichen/NBSP *zwischen* sichtbaren
  Zeichen) – zählt weiterhin roh mit, wie bisher. Nur die vollständig-leer-Grenze ändert
  sich.
- R4-08 (Editorvertrag Tiny/Atto/No-JS) – separater, späterer Punkt in `Fix.md`.
- PARAM_CLEANHTML/XSS-Absicherung – unverändert, das ist nicht die Aufgabe von
  `insightjournal_visible_char_count()`.

## Semantik: die neue Leer-Grenze

Die bestehende DOM-Extraktion bleibt unverändert. Neu ist ausschließlich, wie „ist das
Ergebnis leer?" entschieden wird:

```
text = DOM-textContent-Extraktion(html)        // unverändert, wie bisher
stripped = entferne INVISIBLE_CHARS aus text   // nur für die Leer-Prüfung
if trim(stripped) === '':
    return 0
return codepoint_length(text)                  // roh, wie bisher – unverändert für
                                                // jeden nicht-leeren Text
```

**`INVISIBLE_CHARS`** (nur für die Leer-Prüfung relevant): NBSP `U+00A0`, Zero Width
Space `U+200B`, Zero Width Non-Joiner `U+200C`, Zero Width Joiner `U+200D`, Word Joiner
`U+2060`, BOM/Zero Width No-Break Space `U+FEFF`.

- **PHP:** `trim()` kennt nur ASCII-Whitespace. Die o.g. Zeichen werden vor dem
  Leer-Check per Regex entfernt, danach normales `trim()`.
- **JS:** natives `.trim()` entfernt laut ECMAScript-Spec bereits NBSP und BOM als
  Whitespace. Nur ZWSP/ZWNJ/ZWJ/Word Joiner müssen zusätzlich per Regex entfernt werden,
  bevor `.trim()` aufgerufen wird. Unterschiedlicher Code, identisches Ergebnis.

**Client-sichtbare Konsequenz:** Der Live-Zähler in `autosave.js` zeigt für eine
Eingabe, die *ausschließlich* aus Whitespace/NBSP/Zero-Width-Zeichen besteht, neu `0`
statt der rohen Zeichenzahl (z. B. drei Leerzeichen → `0 / 200` statt `3 / 200`). Für
jeden Text mit mindestens einem sichtbaren Zeichen ändert sich nichts, auch nicht bei
NBSP/Whitespace zwischen Wörtern. Das ist eine bewusste, kleine, aber sichtbare
Verhaltensänderung – notwendig, damit PHP und JS auf der gemeinsamen Fixture-Tabelle
tatsächlich identische Werte liefern, nicht nur serverseitig.

## Code-Änderungen

1. **`locallib.php`** – `insightjournal_visible_char_count()`: neue Leer-Grenze wie
   oben; `LIBXML_NONET` zu den bestehenden `loadHTML()`-Flags (`LIBXML_NOERROR |
   LIBXML_NOWARNING`) hinzufügen.
2. **`classes/completion/custom_completion.php:70`** –
   `\insightjournal_html_to_text($entry->response) === ''` wird zu
   `\insightjournal_visible_char_count($entry->response) === 0`. Eine einzige Definition
   von „leer" für sowohl die „hat-Inhalt"- als auch die `minchars`-Prüfung, statt zwei
   getrennt gepflegter Codepfade mit potenziell abweichender Semantik.
3. **`amd/src/autosave.js`** – neue interne Funktion (Arbeitstitel `visibleCharCount(html)`),
   die `stripHtml()` + die neue Leer-Grenze kapselt. Ersetzt die drei bestehenden
   Aufrufstellen von `charCount(stripHtml(...))` (in `updateCounter()` und den beiden
   Stellen in `save()`). Wird zusätzlich am Modul-Rückgabeobjekt neben `init` exportiert
   – dieselbe Funktion, die intern genutzt wird, direkt aufrufbar für den
   Parität-Nachweis (siehe unten), kein Duplikat.

## Gemeinsame Fixture-Tabelle

**`tests/fixtures/visible_char_fixtures.json`** – Array von Objekten:

```json
{"id": "list-items-basic", "html": "<ul><li>One</li><li>Two</li></ul>", "expected": 6, "description": "..."}
```

**Konsolidierung statt Duplikat:** Die 10 bestehenden Einzeltests in
`tests/locallib_test.php` (aktuell Zeilen 226–315: plain text, Absatz-/
Listen-Konkatenation ohne Trenner, echte Editor-Serialisierung mit Zeilenumbrüchen als
echten Textknoten, `<br>` ohne eigenen Zeichenwert, leere Editor-Shell, Codepoint- statt
Byte-Zählung, NBSP zwischen Wörtern) wandern in die JSON-Fixture; ihr jeweiliger
Doc-Kommentar wird zur `description`. Sie werden durch **einen** Data-Provider-Test
ersetzt, der jede Fixture-Zeile gegen `insightjournal_visible_char_count()` prüft.

Neue Fixture-Kategorien:
- **Entities:** `&amp;`, `&lt;`, `&gt;`, `&quot;`, `&#39;`, numerische Entities.
- **Malformed HTML:** unclosed tag (`<p>Hello`), falsch verschachtelt
  (`<p><b>Hi</p></b>`), freistehendes `<` bzw. `&` ohne gültige Entity.
- **Whitespace-only:** nur Leerzeichen/Tabs/Zeilenumbrüche → `expected: 0`.
- **NBSP-only:** nur `&nbsp;` (ein oder mehrere) → `expected: 0`.
- **Zero-Width-only:** nur Zero-Width-Zeichen aus `INVISIBLE_CHARS` → `expected: 0`.
- **Gemischt unsichtbar:** Kombination aus Whitespace + NBSP + Zero-Width, kein
  sichtbares Zeichen → `expected: 0`.
- **Ein sichtbares Zeichen umgeben von viel Whitespace/NBSP:** zählt weiterhin roh
  (Beleg, dass nur die Vollständig-leer-Grenze sich ändert, nicht die Zählung
  nicht-leerer Inhalte).

## Parität-Nachweis (Behat)

Ein neues Behat-Szenario:
1. Legt eine Aktivität mit gesetztem `maxchars` an (damit der Zähler im DOM gerendert
   wird).
2. Liest dieselbe `visible_char_fixtures.json` serverseitig (PHP-Kontext).
3. Ruft für jede Fixture-Zeile per JS-Ausführung im echten Chrome
   `require(['mod_insightjournal/autosave'], ...).visibleCharCount(html)` auf und
   assertet Gleichheit mit `expected`.

Zusätzlich bleibt mindestens ein bestehendes Ende-zu-Ende-Szenario (Tippen im echten
Editor → 1s-Poll → sichtbarer Zähler im DOM, analog dem bestehenden
„19 / 200"-Szenario in `tests/behat/insight_journal.feature`) als Beleg, dass der reale
Poll-Pfad denselben Wert erzeugt wie die isoliert aufgerufene Funktion – nicht nur die
Funktion in Isolation.

## Testen / Abnahme

- `vendor/bin/phpunit` (`locallib_test.php`, `custom_completion_test.php`): neuer
  Data-Provider-Test über alle Fixture-Zeilen; bestehender
  `custom_completion_test.php` bekommt einen Fall für „Eintrag besteht nur aus NBSP" →
  `COMPLETION_INCOMPLETE` unabhängig von `minchars`.
- `moodle-plugin-ci behat --profile chrome`: neues Fixture-Parität-Szenario plus
  bestehendes Zähler-Szenario weiterhin grün.
- `moodle-plugin-ci phpcs` / `grunt` (ESLint): neue JS-Funktion und JSON-Fixture-Datei
  müssen den bestehenden Lint-Regeln genügen.
- Manuelle Stichprobe: Response-Feld mit nur Leerzeichen füllen, prüfen dass Zähler `0`
  zeigt und Aktivität bei `completionentries` nicht als abgeschlossen markiert wird.

## Offene technische Details (für den Implementierungsplan)

Diese Punkte sind bewusst nicht bis auf Codezeile festgelegt, da sie Implementierungsdetail
sind, nicht Design:
- Exakter Mink/Behat-Mechanismus, um einen synchronen Rückgabewert aus einem
  asynchronen AMD-`require()` im Browser zu erhalten (z. B. über
  `wait_for_pending_js` + Zwischenspeichern auf `window`).
- Exakte PHP-Regex für das `INVISIBLE_CHARS`-Stripping.

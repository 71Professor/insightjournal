# InsightJournal – Konsolidierte Fix-Priorisierung

**Plugin:** `mod_insightjournal` (71Professor/moodle-mod_insightjournal)
**Basis:** `main` · 0.8.0-beta · Moodle 4.5+
**Stand:** 04.08.2026

Diese Datei führt zwei Reviews zu einer einzigen Umsetzungsliste zusammen:

- **[FR]** – Technischer Folgereview vom 04.08.2026 (R4-01 bis R4-10, evidenzgestützt: CI, Release, Tests, lokaler Arbeitsbaum).
- **[CR]** – Vorheriger Code-Review von `main`. Enthält drei Befunde, die im Folgereview nicht vorkommen.

Es sind **keine offenen P0-Blocker** vorhanden. Eine weitere kleine Beta ist ohne zusätzlichen Fix vertretbar. Die Liste unten definiert den Weg zu **Stable**.

---

## Priorisierungslegende

| Prio | Bedeutung |
|------|-----------|
| **P1** | Stable-Gate. Vor einem Stable-Release abzuschließen. |
| **P2** | Qualität, Wartbarkeit, Lieferkette. Vor oder kurz nach Stable. |
| **P3** | Politur / optionale Erweiterung. Backlog. |

---

## P1 – Stable-Gate

### R4-01 · Sichtbare Zeichen semantisch definieren  `[FR]` — ✅ Erledigt (2026-08-04, `main` @ `81e2e6c`, noch nicht gepusht)
Vor jeder Längenprüfung HTML in eine **klar definierte** Textform überführen: Unicode-Whitespace normalisieren, trimmen und das Verhalten für `<br>`, Blockgrenzen, NBSP und Zero-Width-Zeichen festlegen. **Dieselbe Fixture-Tabelle in PHP und JS** verwenden; bei `DOMDocument` `LIBXML_NONET` setzen. Browser-Parität nicht pauschal behaupten, sondern nachweisen.
**Warum:** Die Parität von `insightjournal_visible_char_count` und dem JS-`stripHtml` ist bisher nur kommentiert, nicht getestet – PHP-`textContent` und JS-`DOMParser` können bei Randfällen divergieren. Whitespace-only kann als Inhalt zählen, obwohl die Anzeige ihn als leer behandelt.
**Abnahme:** Whitespace-only erfüllt weder `minchars` noch Completion. Gemeinsame PHP-/JS-Fixtures für `p/br/li`, Entities, Unicode, malformed HTML und Randfälle liefern identische Ergebnisse.

**Umsetzung:** `insightjournal_visible_char_count()`/neue `insightjournal_is_visually_empty()` in `locallib.php` liefern `0` für Antworten, die **ausschließlich** aus ASCII-Whitespace, NBSP, weiteren Unicode-Space-/Zeilentrenner-Zeichen (Em Space, Ideographic Space, U+2028 …) oder Zero-Width-Zeichen (ZWSP/ZWNJ/ZWJ/Word Joiner/BOM) bestehen – innerer Whitespace neben echtem Inhalt zählt weiterhin roh mit (bewusst enger Scope, per Rückfrage bestätigt). `LIBXML_NONET` ergänzt. Vereinheitlicht auf diese eine Funktion: `custom_completion.php`s Completion-Gate **und** (erst im finalen Review gefunden und mit aufgenommen) `insightjournal_coursereport_cell_state()`s „erledigt"-Spalte im Kursbericht, die sonst nach dem Completion-Fix eine neue, in sich widersprüchliche Abweichung gezeigt hätte. `view.php:59` (`$haveentry`, reine View/Edit-Panel-Weiche) bewusst **nicht** angefasst – reines UI-Detail, kein Autorisierungs-/Datenintegritätsproblem. Neue Fixture-Tabelle `tests/fixtures/visible_char_fixtures.json` (29 Zeilen) ist Single Source of Truth für einen PHPUnit-Data-Provider-Test **und** ein neues Behat-Szenario, das die echte, jetzt exportierte `visibleCharCount()` aus `autosave.js` in einem echten Browser aufruft (lokal Firefox, in CI Chrome – 20/20 grün) – Parität also nachgewiesen statt nur behauptet.

**Zu beachten / Stolpersteine:**
- Der finale Whole-Branch-Review (nicht die Task-Reviews) fand zwei echte Bugs, die isoliert unauffällig aussahen: (1) `#[DataProvider]`-Attribut läuft auf PHPUnit 9.6 (Moodle-4.5-CI-Leg) ins Leere → `ArgumentCountError`; behoben durch die klassische `@dataProvider`-Docblock-Annotation (funktioniert 9.6–11.5, so wie Moodle-Core es selbst durchgängig macht). (2) JS' natives `String.trim()` deckt mehr Unicode-Leerzeichen ab als die ursprünglich auf 6 Zeichen gepinnte PHP-Regex – PHP-Regex entsprechend erweitert (kein JS-Change nötig).
- **Nachträglicher CI-Fail nach dem Push** (Ursache: der Data-Provider war als `iterable` typisiert): `moodle-plugin-ci phpcs` läuft zusätzlich mit dem `moodle-extra`-Regelset (lokales `--standard=moodle` allein reicht nicht als Vorab-Check!) – dessen `TestCaseProvider`-Sniff verlangt case-sensitiv `array`/`Generator`/`Iterable`, was das idiomatische kleine `iterable` nie erfüllt. Fix: Rückgabetyp auf `\Generator` präzisiert (Methode nutzt ohnehin `yield`). Commit `81e2e6c`.
- Vollständig verifiziert: PHPUnit 205/205, phpcs sauber (inkl. `moodle-extra`), Behat 20/20 (324 Steps, auf echtem Chrome in CI).

### R4-02 · Kursweiten Legacy-Pfad entfernen  `[FR]`
Bei `insightjournal_current_user_groups()` und `insightjournal_current_user_group_userids()` den Parameter `$cm` **verpflichtend** machen. Den `?cm = null`-Zweig mit kursweiter Legacy-Sicht entfernen und alle Aufrufer/Tests auf den aktivitätsspezifischen Vertrag umstellen.
**Warum:** Ein optionaler Legacy-Pfad in einer autorisierungsrelevanten Primitive ist eine latente Leak-Fläche.
**Abnahme:** Kein produktiver oder testweiser Aufruf ohne `$cm`; statische Analyse findet keinen optionalen Legacy-Pfad. Zwei Groupings bleiben vollständig isoliert.

### R4-03 · Gruppenautorisierung speicherbegrenzt machen  `[FR]`
Erlaubte **Gruppen-IDs** statt aller Member-IDs repräsentieren. Activity-Report filtert über `groups_members`-Join/Subquery; Summary prüft die Zielperson per Existenzabfrage; Kursreport löst Mitgliedschaften nur für die aktuelle Seite bzw. den 500er-Exportchunk auf und cached je Gruppierung.
**Warum:** Die 500er-Chunks betreffen nur die Eintragsdaten. Die Autorisierungs-Maps (`insightjournal_current_user_group_userids` → `array_flip`) materialisieren weiterhin **alle** Mitglieder aller Gruppen kursweit – und zwar vor dem Paging. Realer Skalierungs-Hotspot.
**Abnahme:** Peak Memory und Parameterzahl wachsen mit Seite/Chunk, nicht mit (alle Gruppenmitglieder × Aktivitäten). Lasttest mit großen Gruppen bleibt unter dokumentiertem Budget.

### R4-04 · Kursreport-Service extrahieren  `[FR]`
Autorisierung, Paging, Progress-Zählung und Exportselektion aus `coursereport.php` in einen Data-Provider/Service verschieben. Bildschirmseite und CSV rufen exakt denselben geprüften Kern auf; Renderer und HTTP-Steuerung bleiben dünn.
**Abnahme:** Unit-Tests prüfen den real verwendeten Provider für gemischte Gruppenmodi, private Einträge, Seitenwechsel und CSV-Chunks – ohne dass duplizierte Testlogik den Algorithmus nur nachbildet.

### R4-05 · Lokalen Template-Test bereinigen  `[FR]`
`tests/report_template_test.php` entweder auf Shell-Verantwortung (Titel, Links, Container) reduzieren oder löschen. Datenschutz-/Zeilentests in `report_table_test.php` bzw. den neuen Kursreport-Provider verschieben und anschließend **bewusst committen** (aktuell untracked mit fachlich falschen Erwartungen).
**Abnahme:** Kein Test erwartet Datenzeilen aus `report.mustache`. Ein absichtlich übergebener Geheimtext beweist am tatsächlichen Renderer/Provider, dass er unautorisiert nie ausgegeben wird.

### CR-01 · Completion beim Kursreset zurücksetzen  `[CR]`
`insightjournal_reset_course_userdata` löscht die Einträge, setzt aber die zugehörigen Completion-States **nicht** zurück. Nach dem Reset stehen gelöschte Einträge weiterhin auf „abgeschlossen". Pro betroffener Instanz die Completion neu berechnen (z. B. `completion_info::reset_all_state($cm)` nach dem Löschen der Einträge).
**Warum:** Echte Dateninkonsistenz nach Kursreset – gehört vor Stable.
**Abnahme:** Nach „Alle Einträge löschen" im Kursreset zeigt keine zurückgesetzte Aktivität mehr einen Abschluss für die betroffenen Lernenden.
> Nur im Erstreview aufgetaucht, im Folgereview nicht abgedeckt.

---

## P2 – Qualität, Wartung, Lieferkette

### R4-06 · Release nach Checkout erneut verifizieren  `[FR]`
Direkt nach `actions/checkout` `git rev-parse HEAD` mit `workflow_run.head_sha` vergleichen. Drittanbieter-Actions im privilegierten Release-Job auf vollständige Commit-SHAs pinnen.
**Abnahme:** Ein bewegter Tag zwischen Vorprüfung und Checkout stoppt den Release; Release-Abhängigkeiten sind revisionsfest.

### R4-07 · PHPStan-Baseline auf null  `[FR]`
`responseformat` vor `format_text()` explizit auf `int` casten und das `standard_intro_elements`-Typing lokal sauber kapseln. Danach `tests/` zunächst in einem separaten, toleranten Analysejob aufnehmen.
**Abnahme:** `phpstan-baseline.neon` ist leer/entfernt; Produktionscode bleibt Level 5 grün; Testanalyse erzeugt keine unbegrenzte neue Baseline.

### R4-08 · Autosave-Editorvertrag klären  `[FR]`
Das Tiny-spezifische flush/sync in einen kleinen Adapter isolieren. Für andere Editoren dokumentieren/testen, wie der Backing-Textarea-Wert synchronisiert wird; No-JS bleibt voll funktionsfähig.
**Abnahme:** Tiny und mindestens ein zweiter Moodle-Editor speichern identischen Inhalt; unbekannte Editoren degradieren ohne Datenverlust.

### R4-09 · End-to-End-Export absichern  `[FR]`
Behat- oder geeigneten Integrationstest für Kurs-CSV ergänzen: zwei Groupings, private Einträge, `accessallgroups`, Sonderzeichen/Zeilenumbrüche und mehr als einen Exportchunk.
**Abnahme:** Heruntergeladene CSV enthält nur erlaubte Daten und bleibt über Chunkgrenzen vollständig und korrekt escaped.

### R4-10 · Doku- und Workflow-Hygiene  `[FR]`
CHANGELOG-`[Unreleased]` auf `v0.8.0-beta...HEAD` korrigieren, veralteten `reload()`-Kommentar anpassen und unnötige übersprungene Release-Läufe bzw. doppelte PR-Push-CI reduzieren.
**Abnahme:** Links und Kommentare entsprechen dem Code; normale main-/PR-Läufe erzeugen keine irreführende Release-Historie.

### CR-02 · `promptcolor`-Normalisierung härten  `[CR]`
`insightjournal_normalise_promptcolor` prependet nur `#` und lowercased, ohne zu validieren – die Gültigkeitsprüfung liegt allein in `mod_form::validation`. Dieselbe Regex wie in `insightjournal_prompt_style` in die Normalisierung ziehen; ungültige Werte auf `''` abbilden.
**Warum:** Bei programmatischem Aufruf/Restore könnte ungültiger Inhalt in das `CHAR(7)`-Feld gelangen (DB-Fehler/Truncation).
**Abnahme:** Ungültige Farbwerte landen nie in der Datenbank, unabhängig vom Aufrufpfad.
> Nur im Erstreview.

### CR-03 · `get_string`-Komponente vereinheitlichen  `[CR]`
Gemischte Nutzung von `'insightjournal'` (31×) und `'mod_insightjournal'` (28×) auf den vollen Frankenstyle-Namen `mod_insightjournal` vereinheitlichen.
**Abnahme:** Ein einziger Komponentenname im gesamten Code; Greps und spätere Reviews werden eindeutig.
> Nur im Erstreview.

### CR-04 · `minchars` als reines Completion-Gate dokumentieren  `[CR]`
Im Hilfetext/README klarstellen, dass `minchars` nur den Abschluss steuert, das Speichern aber nicht blockiert.
**Abnahme:** Trainer-Doku beschreibt das Verhalten explizit; keine offenen Support-Fragen dazu.
> Nur im Erstreview.

---

## P3 – Politur / Optional

| ID | Thema | Quelle | Änderung |
|----|-------|--------|----------|
| **CR-05** | Autosave-Poll-Cleanup | `[CR]` | `setInterval`-ID speichern und via `clearInterval` bei Konflikt/Teardown stoppen (aktuell läuft die 1-s-Schleife dauerhaft). |
| **CR-06** | Magic Numbers im JS | `[CR]` | 3000 ms Debounce und 1000 ms Poll-Intervall als benannte Konstanten am Modulkopf. |
| **CR-07** | Farbwahl-UX | `[CR]` | Vorschaufeld oder `type="color"`-Fallback neben dem Hex-Textfeld für Trainer. |
| **CR-08** | Wortzählung | `[CR]` | Optionale Wortzählung als Alternative zu Zeichen für Reflexionstexte. |

---

## Empfohlene Umsetzungsreihenfolge

Jede Änderung bleibt einzeln abnehmbar. Reihenfolge kombiniert die PR-Folge des Folgereviews mit den Erstreview-Befunden.

1. ~~**R4-01** – Zeichensemantik + PHP/JS-Fixtures~~ — ✅ erledigt (siehe oben)
2. **R4-02** – Legacy-Pfad entfernen
3. **CR-01** – Completion-Reset (klein, P1, Datenkonsistenz)
4. **R4-03** – Gruppenautorisierung speicherbegrenzt **+ Messung** (siehe Messplan unten)
5. **R4-04 / R4-05** – Kursreport-Service + Template-Test
6. **R4-07** – PHPStan-Baseline auf null
7. **R4-09** – E2E-Export-Test
8. **P2-Sammel-PR** – CR-02 (promptcolor), CR-03 (get_string), CR-04 (Doku)
9. **R4-06 / R4-08 / R4-10** – Release-Härtung, Editorvertrag, Doku/Workflow
10. **P3-Backlog** – CR-05 bis CR-08 nach Bedarf

---

## Mess-/Abnahme-Hinweis zu R4-03

Vor und nach dem Umbau mit exakt demselben Seed vergleichen: CSV-Laufzeit, Queryzahl und Peak Memory bei 50 / 500 / 5.000 Gruppenmitgliedschaften und mehreren Groupings, Bildschirm und CSV getrennt. Abnahmebudget: Peak Memory proportional zum 500er-Chunk statt zur Kursgröße. Xdebug aus, OPcache an, Volumes im Linux-Dateisystem.

*Hinweis zu lokalen Ladezeiten:* Für minutenlange Login-/Dashboard-Zeiten fand der Folgereview keinen globalen InsightJournal-Hook – das deutet primär auf WSL/Docker-I/O, DB, Cache oder `moodledata` hin, nicht auf das Plugin. Der Report-interne Hotspot (R4-03) betrifft nur gruppenreiche Reports.

---

## Zusammenfassung nach Herkunft

| Quelle | P1 | P2 | P3 |
|--------|----|----|----|
| **Folgereview [FR]** | R4-01, R4-02, R4-03, R4-04, R4-05 | R4-06, R4-07, R4-08, R4-09, R4-10 | – |
| **Erstreview [CR]** | CR-01 | CR-02, CR-03, CR-04 | CR-05, CR-06, CR-07, CR-08 |

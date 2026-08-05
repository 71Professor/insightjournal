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

### R4-01 · Sichtbare Zeichen semantisch definieren  `[FR]` — ✅ Erledigt (2026-08-04, `main` @ `81e2e6c`)
Vor jeder Längenprüfung HTML in eine **klar definierte** Textform überführen: Unicode-Whitespace normalisieren, trimmen und das Verhalten für `<br>`, Blockgrenzen, NBSP und Zero-Width-Zeichen festlegen. **Dieselbe Fixture-Tabelle in PHP und JS** verwenden; bei `DOMDocument` `LIBXML_NONET` setzen. Browser-Parität nicht pauschal behaupten, sondern nachweisen.
**Warum:** Die Parität von `insightjournal_visible_char_count` und dem JS-`stripHtml` ist bisher nur kommentiert, nicht getestet – PHP-`textContent` und JS-`DOMParser` können bei Randfällen divergieren. Whitespace-only kann als Inhalt zählen, obwohl die Anzeige ihn als leer behandelt.
**Abnahme:** Whitespace-only erfüllt weder `minchars` noch Completion. Gemeinsame PHP-/JS-Fixtures für `p/br/li`, Entities, Unicode, malformed HTML und Randfälle liefern identische Ergebnisse.

**Umsetzung:** `insightjournal_visible_char_count()`/neue `insightjournal_is_visually_empty()` in `locallib.php` liefern `0` für Antworten, die **ausschließlich** aus ASCII-Whitespace, NBSP, weiteren Unicode-Space-/Zeilentrenner-Zeichen (Em Space, Ideographic Space, U+2028 …) oder Zero-Width-Zeichen (ZWSP/ZWNJ/ZWJ/Word Joiner/BOM) bestehen – innerer Whitespace neben echtem Inhalt zählt weiterhin roh mit (bewusst enger Scope, per Rückfrage bestätigt). `LIBXML_NONET` ergänzt. Vereinheitlicht auf diese eine Funktion: `custom_completion.php`s Completion-Gate **und** (erst im finalen Review gefunden und mit aufgenommen) `insightjournal_coursereport_cell_state()`s „erledigt"-Spalte im Kursbericht, die sonst nach dem Completion-Fix eine neue, in sich widersprüchliche Abweichung gezeigt hätte. `view.php:59` (`$haveentry`, reine View/Edit-Panel-Weiche) bewusst **nicht** angefasst – reines UI-Detail, kein Autorisierungs-/Datenintegritätsproblem. Neue Fixture-Tabelle `tests/fixtures/visible_char_fixtures.json` (29 Zeilen) ist Single Source of Truth für einen PHPUnit-Data-Provider-Test **und** ein neues Behat-Szenario, das die echte, jetzt exportierte `visibleCharCount()` aus `autosave.js` in einem echten Browser aufruft (lokal Firefox, in CI Chrome – 20/20 grün) – Parität also nachgewiesen statt nur behauptet.

**Zu beachten / Stolpersteine:**
- Der finale Whole-Branch-Review (nicht die Task-Reviews) fand zwei echte Bugs, die isoliert unauffällig aussahen: (1) `#[DataProvider]`-Attribut läuft auf PHPUnit 9.6 (Moodle-4.5-CI-Leg) ins Leere → `ArgumentCountError`; behoben durch die klassische `@dataProvider`-Docblock-Annotation (funktioniert 9.6–11.5, so wie Moodle-Core es selbst durchgängig macht). (2) JS' natives `String.trim()` deckt mehr Unicode-Leerzeichen ab als die ursprünglich auf 6 Zeichen gepinnte PHP-Regex – PHP-Regex entsprechend erweitert (kein JS-Change nötig).
- **Nachträglicher CI-Fail nach dem Push** (Ursache: der Data-Provider war als `iterable` typisiert): `moodle-plugin-ci phpcs` läuft zusätzlich mit dem `moodle-extra`-Regelset (lokales `--standard=moodle` allein reicht nicht als Vorab-Check!) – dessen `TestCaseProvider`-Sniff verlangt case-sensitiv `array`/`Generator`/`Iterable`, was das idiomatische kleine `iterable` nie erfüllt. Fix: Rückgabetyp auf `\Generator` präzisiert (Methode nutzt ohnehin `yield`). Commit `81e2e6c`.
- Vollständig verifiziert: PHPUnit 205/205, phpcs sauber (inkl. `moodle-extra`), Behat 20/20 (324 Steps, auf echtem Chrome in CI).

### R4-02 · Kursweiten Legacy-Pfad entfernen  `[FR]` — ✅ Erledigt (2026-08-05, `main` @ `8d96804`)
Bei `insightjournal_current_user_groups()` und `insightjournal_current_user_group_userids()` den Parameter `$cm` **verpflichtend** machen. Den `?cm = null`-Zweig mit kursweiter Legacy-Sicht entfernen und alle Aufrufer/Tests auf den aktivitätsspezifischen Vertrag umstellen.
**Warum:** Ein optionaler Legacy-Pfad in einer autorisierungsrelevanten Primitive ist eine latente Leak-Fläche.
**Abnahme:** Kein produktiver oder testweiser Aufruf ohne `$cm`; statische Analyse findet keinen optionalen Legacy-Pfad. Zwei Groupings bleiben vollständig isoliert.

**Umsetzung:** Beide Funktionssignaturen in `locallib.php` auf `cm_info|stdClass $cm` (kein Default, kein `null` mehr) umgestellt; der interne `$cm !== null`-Ternary für `$groupingid`/`$participationonly` entfernt (`$participationonly` ist jetzt immer `true`). Docblocks entsprechend gekürzt (kein „oder kursweite Legacy-Sicht" mehr). Geprüft: **jeder** produktive Aufrufer (`report.php:46`, `coursereport.php:82` sowie die beiden internen Aufrufe in `locallib.php` selbst) übergab `$cm` bereits vor diesem Fix immer schon konkret — R3-01/R3-02 hatten den Legacy-Pfad faktisch schon totgelegt, ohne ihn zu entfernen. Einzig `tests/locallib_groups_test.php` rief noch testweise ohne `$cm` auf (vier Stellen um `$this->cm` ergänzt) und ein Test (`test_group_userids_ignores_grouping_when_cm_omitted`) prüfte exakt den jetzt entfernten Legacy-Zweig — gelöscht, inklusive seines veralteten Kommentars, der fälschlich noch von einem angeblich unveränderten `summary.php`-Aufruf sprach (der Aufruf dort läuft seit R3-02 längst über `insightjournal_visible_activities_for_user()`, nie direkt).
**Verifiziert:** PHPUnit 204/204 (ein Test weniger als vor diesem Fix, s.o.; sonst unverändert grün), phpcs sauber (`moodle`+`moodle-extra`, `--warning-severity=1`), PHPStan 0 Fehler, Behat 20/20 (324 Steps).

### R4-03 · Gruppenautorisierung speicherbegrenzt machen  `[FR]` — ✅ Erledigt (2026-08-05, `main` @ `393d4d1`)
Erlaubte **Gruppen-IDs** statt aller Member-IDs repräsentieren. Activity-Report filtert über `groups_members`-Join/Subquery; Summary prüft die Zielperson per Existenzabfrage; Kursreport löst Mitgliedschaften nur für die aktuelle Seite bzw. den 500er-Exportchunk auf und cached je Gruppierung.
**Warum:** Die 500er-Chunks betreffen nur die Eintragsdaten. Die Autorisierungs-Maps (`insightjournal_current_user_group_userids` → `array_flip`) materialisieren weiterhin **alle** Mitglieder aller Gruppen kursweit – und zwar vor dem Paging. Realer Skalierungs-Hotspot.
**Abnahme:** Peak Memory und Parameterzahl wachsen mit Seite/Chunk, nicht mit (alle Gruppenmitglieder × Aktivitäten). Lasttest mit großen Gruppen bleibt unter dokumentiertem Budget.

**Umsetzung:** In vier Teilschritten umgesetzt. **(1)** Drei neue Primitiven in `locallib.php`: `insightjournal_current_user_allowed_groupids()` (nur Gruppen-IDs des aktuellen Nutzers für eine Aktivität, `groups_get_all_groups()` mit `$withmembers = false`/`$fields = 'g.id'`, materialisiert also nie Mitgliederlisten), `insightjournal_groupids_contain_member()` (eine einzelne Existenzabfrage gegen `groups_members`, begrenzt durch die Gruppenzahl, nicht die Mitgliederzahl) und `insightjournal_groupids_members_among()` (eine Abfrage begrenzt durch Gruppen-ID-Anzahl × Kandidaten-Userid-Anzahl, für Seiten-/Chunk-begrenzte Kandidatenlisten). `insightjournal_activity_visible_to_viewer()` (Summary-Pfad) direkt darauf umgestellt. **(2)** Activity-Report (`report.php` + `classes/table/report_table.php`): der `u.id IN (...)`-Userid-Filter wurde durch ein `EXISTS (SELECT 1 FROM {groups_members} gm WHERE gm.userid = u.id AND gm.groupid IN (...))` ersetzt – der Konstruktor nimmt jetzt `$restrictgroupids` statt `$restrictuserids` entgegen. **(3)** Kursreport (`coursereport.php`): die bisherige einmalige, kursweite `$diaryallowedusers`-Vorabberechnung (eine volle Mitgliederliste pro Aktivität, vor dem Paging) wurde durch zwei neue Funktionen ersetzt – `insightjournal_coursereport_allowed_groupids_by_diary()` löst erlaubte Gruppen-IDs pro Aktivität auf, gecacht je Gruppierung (zwei Aktivitäten derselben Gruppierung teilen sich das Ergebnis), und `insightjournal_coursereport_diary_allowed_users()` löst die tatsächliche Mitgliedschaft erst innerhalb der Bildschirm- bzw. CSV-Chunk-Schleife auf, beschränkt auf genau die dort vorkommenden Userids. `insightjournal_coursereport_restrict_groupids()` (SQL-seitiger Teilnehmer-Vorfilter) läuft jetzt ebenfalls über die neue Gruppen-ID-Primitive statt über `array_keys(insightjournal_current_user_groups(...))`. **(4)** Die beiden alten, member-materialisierenden Funktionen `insightjournal_current_user_groups()`/`insightjournal_current_user_group_userids()` nach Bestätigung (Grep: keine verbleibenden Aufrufer außer den eigenen Definitionen) vollständig aus `locallib.php` entfernt, samt ihrer sechs dedizierten Tests in `tests/locallib_groups_test.php`. Ein echter Bug wurde in Teilschritt (3) per TDD gefangen: `insightjournal_coursereport_diary_allowed_users()` nutzte zunächst `array_flip(insightjournal_groupids_members_among(...))` – da `insightjournal_groupids_members_among()` ein einfaches 0-indiziertes `int[]` liefert, erzeugte `array_flip()` `userid => <ursprünglicher Index>` (z. B. `122000 => 0`) statt der dokumentierten und getesteten `userid => true`-Kontrakt; für die tatsächlichen Aufrufer (die nur `isset($allowedusers[$userid])` prüfen) folgenlos, aber ein Vertragsbruch für jeden künftigen Aufrufer, der den Wert selbst auswertet. Behoben durch `array_fill_keys(..., true)`.
**Verifiziert:** PHPUnit 217/217 grün (keine „undefined function"-Fehler nach dem Entfernen der beiden Alt-Funktionen), phpcs sauber (`moodle`+`moodle-extra`, `--warning-severity=1`), PHPStan 0 Fehler, Behat grün (`@mod_insightjournal`). Vorher/Nachher-Benchmark (identischer Seed: der Viewer gehört zu genau einer Gruppe, deren eigene Mitgliederzahl auf 50/500/5000 wächst, plus einer kleinen Fremdgruppe; je ein simulierter 20-Zeilen-Bildschirm-Page-Call und ein ≤500-Zeilen-CSV-Chunk-Call) zeigt: die Queryzahl blieb bei diesem Ein-Page-plus-Ein-Chunk-Zuschnitt in beiden Fällen bei 9 – bei einem mehrteiligen CSV-Export mit mehreren 500er-Chunks steigt die Queryzahl nach dem Fix mit der Chunkzahl (mehr, kleinere Round-Trips statt einem großen), das ist Teil desselben Trade-offs. Der eigentliche Gewinn liegt darin, dass die in PHP materialisierte Ergebnismenge pro Aufruf jetzt durch Seiten-/Chunkgröße (20 bzw. ≤500 Einträge) begrenzt ist statt 1:1 mit der Gruppengröße zu wachsen: vorher 51→501→5001 Einträge in der Lookup-Map bei 50/500/5000 Mitgliedern, nachher konstant ≤20 (Seite) bzw. ≤500 (Chunk), unabhängig von der Gruppengröße. Die Laufzeit bestätigt das: vorher 0,0052 s (50 Mitglieder) → 0,0042 s (500) → 0,0434 s (5000 Mitglieder, ca. Faktor 8 gegenüber dem 50er-Wert); nachher bei allen drei Größen konstant bei ca. 0,006 s. Belegt das Abnahmekriterium „Peak Memory proportional zum 500er-Chunk statt zur Kursgröße" über die Lookup-Map-Größe als Proxy – tatsächliche Byte-Peak-Memory-Werte wurden nicht separat gemessen (`memory_get_peak_usage()` als Delta über den gesamten Testlauf war durch das Seed-Setup selbst verzerrt und unbrauchbar; siehe Stolperstein unten).

**Zu beachten / Stolpersteine:**
- Der finale Whole-Branch-Review (nicht die Task-Reviews) fand drei veraltete Kommentare/Docblocks, die noch die entfernte Architektur beschrieben (`tests/behat/insight_journal.feature:398`, `tests/report_authorization_test.php:46`, `locallib.php:457-460`) – genau die Art Fund, für den der finale Review über die einzelnen Task-Reviews hinaus existiert. Als eigener, reiner Kommentar-Commit nachgezogen (`393d4d1`), unabhängig re-reviewed.
- Der Review verifizierte zusätzlich empirisch (nicht nur durch Code-Lesen), dass der Wegfall von `$withmembers = true` keine Autorisierungslücke bei nicht standardmäßig sichtbaren Gruppen (`GROUPS_VISIBILITY_MEMBERS`/`OWN`) öffnet – Moodle filtert solche Gruppen bereits über das `participation`-Flag heraus, bevor `$withmembers` überhaupt greift.
- Erste Benchmark-Iteration nutzte `memory_get_peak_usage(true)`-Deltas und viele gleich große Gruppen – beides ungeeignet: Peak-Memory ist ein monotoner Prozess-Höchststand, der vom 5000-Studierende-Seed-Setup selbst dominiert wurde (Delta durchgehend `0 bytes`), und bei gleich großen Gruppen blieb die tatsächlich relevante Gruppengröße des Viewers konstant bei ~50 (da `insightjournal_current_user_group_userids()` schon seit R3-01 korrekt auf die eigenen Gruppen des Viewers scoped ist, nicht kursweit). Korrigiert auf eine einzelne, wachsende Gruppe plus direkte Auszählung der Lookup-Map-Größe als Proxy-Metrik – seitdem klares, eindeutiges Signal.
- Zurückgestellt auf R4-04 (nicht auf diesem Branch behoben, da Minor/kein Korrektheitsproblem): `insightjournal_coursereport_diary_allowed_users()` fragt `groups_members` einmal pro restriktierter Aktivität pro Chunk ab, auch wenn mehrere Aktivitäten dieselbe Gruppierung (und damit identische Gruppen-IDs) teilen – bounded, aber redundant; und `coursereport.php` löst die erlaubten Gruppen-IDs zweimal pro Request auf (einmal über `insightjournal_coursereport_restrict_groupids()`, einmal über `insightjournal_coursereport_allowed_groupids_by_diary()`). Beides passt natürlich in R4-04s Service-Extraktion, statt zweimal separat gepatcht zu werden.

### R4-04 · Kursreport-Service extrahieren  `[FR]` — ✅ Erledigt (2026-08-05, `main` @ `aead5d6`)
Autorisierung, Paging, Progress-Zählung und Exportselektion aus `coursereport.php` in einen Data-Provider/Service verschieben. Bildschirmseite und CSV rufen exakt denselben geprüften Kern auf; Renderer und HTTP-Steuerung bleiben dünn.
**Abnahme:** Unit-Tests prüfen den real verwendeten Provider für gemischte Gruppenmodi, private Einträge, Seitenwechsel und CSV-Chunks – ohne dass duplizierte Testlogik den Algorithmus nur nachbildet.

**Umsetzung:** In drei Teilschritten. **(1)** Neue Klasse `classes/local/coursereport_provider.php` (gleiches Muster wie `entry_manager.php` aus R2-03): `total_participants()`/`participants()` kapseln `count_enrolled_users()`/`get_enrolled_users()`, `rows_for()` löst pro Teilnehmer:in × Aktivität einmal auf, ob die Zelle sichtbar ist (`visible`), den Eintrag, `completed` und `private` – bei `visible === false` trägt die Zelle ausschließlich diesen einen Schlüssel. Die drei bisherigen `insightjournal_coursereport_restrict_groupids()`/`allowed_groupids_by_diary()`/`diary_allowed_users()`-Locallib-Funktionen wandern als private Methoden in die Klasse; `restrictgroupids` wird dabei aus den bereits je Gruppierung gecachten Daten **abgeleitet** statt separat aufgelöst, und die Mitgliedschaftsabfrage dedupliziert über das (wertgleiche) Gruppen-ID-Array – damit sind die beiden im R4-03-Abschlussreview zurückgestellten Punkte mitgelöst. **(2)** `insightjournal_coursereport_csv_row()` nimmt `$private` jetzt als Parameter statt es selbst aus `$entry` zu berechnen – dieselbe Berechnung lief sonst zweimal (einmal im Provider, einmal hier). **(3)** `coursereport.php` ruft `$provider->participants()`/`rows_for()` aus beiden Pfaden (Bildschirmseite, CSV-Chunk-Schleife) auf; die eigentliche Autorisierungs-Doppelschleife existiert nur noch einmal. Die drei absorbierten Locallib-Funktionen entfernt (keine Aufrufer mehr außerhalb der Klasse), ihre Testabdeckung nach `tests/local/coursereport_provider_test.php` verschoben.
**Verifiziert:** PHPUnit 221/221, phpcs sauber (`moodle`+`moodle-extra`), PHPStan 0 Fehler, Behat 20/20 (324 Steps).

**Zu beachten / Stolpersteine:**
- Task 1 fand während der Implementierung eine echte Bug-Ursache in den eigenen (vom Autor dieses Plans mitgeschriebenen) Test-Erwartungen: mehrere Tests nahmen an, die Lehrperson (Betrachter:in) zähle als „Teilnehmer:in" mit – tatsächlich ist `mod/insightjournal:submit` (die Capability, nach der `count_enrolled_users()`/`get_enrolled_users()` filtern) laut `db/access.php` ausschließlich dem `student`-Archetyp zugewiesen. Der erste Lösungsversuch des Implementierers ging in die falsche Richtung: statt die Testerwartung zu korrigieren, wurde der Capability-Filter im Produktivcode auf `''` (= alle eingeschriebenen Nutzer:innen, unabhängig von der Rolle) geändert, um die – falsche – Testerwartung zu erfüllen. Das wäre eine echte Autorisierungs-/Anzeige-Regression gewesen (Lehrpersonen/Manager wären als Kursbericht-„Teilnehmer:innen" aufgetaucht). Im Task-Review gefangen, im Fix-Round korrekt umgekehrt: Capability-Filter zurück auf `'mod/insightjournal:submit'`, die fünf betroffenen Testerwartungen stattdessen korrigiert (vom Re-Reviewer unabhängig neu hergeleitet, nicht nur übernommen).
- Der finale Whole-Branch-Review fand einen echten, wenn auch kleinen Verhaltensunterschied: die CSV-Export-Schleife iterierte die aufgelösten Zellen in `$activities`-Einfügereihenfolge (Kurs-Anzeigereihenfolge) statt – wie der Bildschirm-Pfad es bereits tat – in der ID-Reihenfolge von `$diaries`. Bei umsortierten Aktivitäten hätte sich damit die Spaltengruppen-Reihenfolge im CSV-Export geändert, ein Verstoß gegen das explizite Spec-Ziel „keine Verhaltensänderung". Behoben durch Angleichung an den Bildschirm-Pfad (`foreach ($diaries as $diary)`), was zugleich einen ungesicherten Array-Zugriff (`TypeError`-Risiko, falls je ein `course_modules`-Datensatz seine `insightjournal`-Instanz überlebt) beseitigt.
- Während Task 3 fand der Implementierer beim vorgeschriebenen Grep-Check einen Plan-Lücke: `tests/locallib_groups_test.php` (ein R4-03-Überbleibsel) rief `insightjournal_coursereport_restrict_groupids()` noch in zwei Tests direkt auf, außerhalb des im Plan vorgesehenen Datei-Scopes. Korrekt gestoppt statt geraten; beide Szenarien waren bereits über die neue Provider-Testdatei bzw. den in Task 3 selbst umgeschriebenen Zwei-Ebenen-Test abgedeckt – Scope live erweitert, in derselben Aufgabe mit erledigt.

### R4-05 · Lokalen Template-Test bereinigen  `[FR]` — ✅ Erledigt (2026-08-05, keine Codeänderung – reine Verifikation)
`tests/report_template_test.php` entweder auf Shell-Verantwortung (Titel, Links, Container) reduzieren oder löschen. Datenschutz-/Zeilentests in `report_table_test.php` bzw. den neuen Kursreport-Provider verschieben und anschließend **bewusst committen** (aktuell untracked mit fachlich falschen Erwartungen).
**Abnahme:** Kein Test erwartet Datenzeilen aus `report.mustache`. Ein absichtlich übergebener Geheimtext beweist am tatsächlichen Renderer/Provider, dass er unautorisiert nie ausgegeben wird.

**Umsetzung:** Der oben genannte Dateiname `tests/report_template_test.php` existiert unter diesem Namen nirgends in der Git-Historie (`git log --all -- '*report_template_test.php'` liefert nur Treffer über Suffix-Matching von `coursereport_template_test.php`), und im Arbeitsbaum liegt keine untracked Datei mehr vor (`git status` sauber). Die zum Folgereview-Zeitpunkt beschriebene, ungetrackte Datei mit fachlich falschen Erwartungen wurde offenbar verworfen, bevor sie je committet wurde. Geprüft, ob die beiden Abnahmekriterien trotzdem – über bereits bestehende Testdateien – erfüllt sind:
- „Kein Test erwartet Datenzeilen aus `report.mustache`": zutreffend per Design, nicht nur zufällig. Laut eigenem Docblock (`templates/report.mustache:20-23`) ist die Datei explizit nur die Seiten-Hülle (Zurück-/Download-Buttons, Suchformular); die Tabelle selbst rendert `report_table.php` direkt, nie über dieses Template. Kein Test ruft `render_from_template('mod_insightjournal/report', ...)` mit erwarteten Teilnehmerdaten auf (einziger produktiver Aufrufer ist `report.php` selbst).
- „Geheimtext beweist am tatsächlichen Renderer/Provider, dass unautorisierter Inhalt nie ausgegeben wird": für den **Aktivitätsbericht** bereits durch `tests/table/report_table_test.php` abgedeckt – `test_private_row_shows_notice_and_hides_response()` und `test_csv_export_hides_private_response()` schreiben „Secret reflection." in eine echte DB-Entry und rendern über den echten `report_table` (Bildschirm und CSV); `test_restrict_to_groupids_filters_participants()` tut dasselbe für eine gruppenbasiert unautorisierte „Excluded entry.". Für den **Kursbericht** doppelt abgesichert: strukturell durch `tests/local/coursereport_provider_test.php::test_invisible_cell_carries_only_the_visible_key()` (eine maskierte Zelle ist per `assertSame(['visible' => false], $cell)` exakt gleich diesem einen Schlüssel – jede künftige Regression, die zusätzlich `entry`/`completed`/`private` befüllt, und sei es mit `null`, lässt den Test sofort rot werden, unabhängig vom Inhalt) sowie inhaltlich durch `tests/coursereport_csv_test.php::test_private_entry_uses_notice_and_blanks_timemodified()` („Secret reflection." über die echte `insightjournal_coursereport_csv_row()`; `assertSame` gegen die Notiztext-Konstante schließt jeden anderen Inhalt inklusive des Geheimtexts aus).
- Beide der Vorgabe zugrunde liegenden Zielzustände sind damit bereits erreicht, ohne dass an einer eigenen Datei etwas migriert werden musste – vermutlich Nebeneffekt der R4-04-Provider-Extraktion und der schon vorher bestehenden `report_table_test.php`-Abdeckung.

**Verifiziert:** Keine Codeänderung, daher kein neuer PHPUnit-/phpcs-/PHPStan-/Behat-Lauf nötig; alle oben zitierten Tests wurden im Quelltext gelesen und bestehen bereits in der aktuellen grünen Baseline (221/221 aus R4-04, seither keine Testdatei in diesem Bereich verändert).

**Zu beachten / Stolpersteine:**
- Die von R4-05 nicht abgedeckte Lücke – ein echter End-to-End-Test, der einen echten Geheimtext durch die volle `coursereport.php` → `coursereport_provider` → `coursereport.mustache`-Kette schickt (nicht nur Provider-Struktur- und isolierter Template-Test mit synthetischem Kontext getrennt) – ist bewusst nicht Teil dieser Aufgabe, sondern deckungsgleich mit **R4-09** (E2E-Export absichern), das genau das für den CSV-Export vorsieht und diesen Fall mit abdecken sollte.
- Die „noch nicht gepusht"-Vermerke bei R4-01–R4-04/CR-01 oben waren stale: `git fetch origin main` zeigt `origin/main` deckungsgleich mit lokal `main` (`29a1ea9`) – alles bereits gepusht. Entsprechend korrigiert.

### CR-01 · Completion beim Kursreset zurücksetzen  `[CR]` — ✅ Erledigt (2026-08-05, `main` @ `f96d4e0`)
`insightjournal_reset_course_userdata` löscht die Einträge, setzt aber die zugehörigen Completion-States **nicht** zurück. Nach dem Reset stehen gelöschte Einträge weiterhin auf „abgeschlossen". Pro betroffener Instanz die Completion neu berechnen (z. B. `completion_info::reset_all_state($cm)` nach dem Löschen der Einträge).
**Warum:** Echte Dateninkonsistenz nach Kursreset – gehört vor Stable.
**Abnahme:** Nach „Alle Einträge löschen" im Kursreset zeigt keine zurückgesetzte Aktivität mehr einen Abschluss für die betroffenen Lernenden.
> Nur im Erstreview aufgetaucht, im Folgereview nicht abgedeckt.

**Umsetzung:** Per TDD (vier neue Tests in `tests/lib_test.php`, zuerst rot verifiziert: die beiden Completion-Tests scheiterten exakt mit „1 matches expected 0", die beiden reinen Entries-Tests liefen schon vorher grün). `insightjournal_reset_course_userdata()` in `lib.php` baut jetzt einmalig `$course`/`completion_info` auf und ruft pro betroffener Instanz nach dem Löschen der Einträge `get_coursemodule_from_instance()` + `$completion->reset_all_state($cm)` (nur wenn `is_enabled($cm)`) — exakt dieselbe Methode, mit der Moodle-Core selbst Completion-Daten nach einer Regeländerung neu berechnet (`completion/classes/manager.php:369`). Kein Sonderfall für `COMPLETION_TRACKING_MANUAL` nötig (dieser Fall wird von `reset_all_state()` selbst korrekt behandelt). Kein Behat nötig – rein serverseitige Logik ohne neues JS-beobachtbares Verhalten, kein bestehendes Szenario deckt den Kursreset-Flow ab.
**Verifiziert:** PHPUnit 208/208 (4 neue Tests), phpcs sauber (`moodle`+`moodle-extra`), PHPStan 0 Fehler.

---

## P2 – Qualität, Wartung, Lieferkette

### R4-06 · Release nach Checkout erneut verifizieren  `[FR]`
Direkt nach `actions/checkout` `git rev-parse HEAD` mit `workflow_run.head_sha` vergleichen. Drittanbieter-Actions im privilegierten Release-Job auf vollständige Commit-SHAs pinnen.
**Abnahme:** Ein bewegter Tag zwischen Vorprüfung und Checkout stoppt den Release; Release-Abhängigkeiten sind revisionsfest.

### R4-07 · PHPStan-Baseline auf null  `[FR]` — ✅ Erledigt (2026-08-05, lokal committet, noch nicht gepusht)
`responseformat` vor `format_text()` explizit auf `int` casten und das `standard_intro_elements`-Typing lokal sauber kapseln. Danach `tests/` zunächst in einem separaten, toleranten Analysejob aufnehmen.
**Abnahme:** `phpstan-baseline.neon` ist leer/entfernt; Produktionscode bleibt Level 5 grün; Testanalyse erzeugt keine unbegrenzte neue Baseline.

**Umsetzung:** Beide Baseline-Einträge einzeln aufgelöst, nicht pauschal maskiert. **(1)** `classes/local/entry_manager.php`: die beiden `format_text()`-Aufrufe (im Erfolgspfad und in `build_conflict_response()`) casten das Format-Argument jetzt explizit `(int)`. Im Erfolgspfad zusätzlich inhaltlich bereinigt: statt des hart codierten `FORMAT_HTML`-Literals (PHPStan sieht dessen realen Typ als `string`, da Moodle-Core `FORMAT_HTML` seit jeher als `define('FORMAT_HTML', '1')`, also gequotet, definiert) liest die Stelle jetzt `(int) $entry->responseformat` – zu diesem Zeitpunkt in beiden Branches (Create/Update) bereits identisch auf `FORMAT_HTML` gesetzt, also inhaltlich gleichwertig, aber DRY (kein zweites Hardcoding derselben Tatsache in derselben Funktion) und passend zur Formulierung der Vorgabe. **(2)** `mod_form.php`s `standard_intro_elements()`-Aufruf: ein erster Versuch mit einem PHPStan-`stubFiles`-Eintrag (eigene, plugin-lokale Signaturkorrektur für die eine Methode, ohne Core anzufassen) schlug fehl – PHPStan ersetzt bei einem Teil-Stub offenbar die gesamte Klassenreflektion statt sie Member-für-Member zu mergen, wodurch zwei andere, echte `moodleform_mod`-Methoden (`standard_coursemodule_elements()`, `get_suffix()`), die `mod_form.php` an anderer Stelle nutzt, plötzlich als „undefined method" galten. Stub verworfen; stattdessen ein präzise auf genau diese eine Zeile begrenzter `@phpstan-ignore-next-line argument.type`-Kommentar mit Begründung (Core-Docblock von `standard_intro_elements()` sagt `@param null $customlabel`, obwohl die Methode – am Quelltext in `course/moodleform_mod.php` verifiziert – eine echte String-Übergabe voll unterstützt und Core selbst sie so nutzt; ein Docblock-Bug in Core, kein echter Typfehler). **(3)** `phpstan-baseline.neon` vollständig entfernt (Datei gelöscht, `includes:`-Referenz in `phpstan.neon` entfernt) – beide Fehler sind jetzt an ihrer jeweiligen Fundstelle sauber aufgelöst statt pauschal in einer separaten Datei unterdrückt. **(4)** Neue `phpstan-tests.neon` nimmt `tests/` erstmals in eine PHPStan-Analyse auf (Level 5, gleiche `phpstan-moodle`-Bootstrap-Bridge) – ein Probe-Lauf zeigt aktuell **308 Findings**, praktisch ausschließlich `method.notFound`/`class.notFound` gegen PHPUnit-/Moodle-Testframework-Magie (`advanced_testcase`, `testing_data_generator::create_and_enrol()` etc.), die außerhalb von PHPUnits eigenem Bootstrap nicht auflösbar ist – exakt der Grund, warum `tests/` bisher komplett ausgeschlossen war. Bewusst **kein** Baseline für diese Datei (das wäre die im Abnahmekriterium explizit ausgeschlossene „unbegrenzte neue Baseline"); stattdessen in `.github/workflows/ci.yml` ein zweiter, eigener „PHPStan (tests)"-Schritt direkt nach dem bestehenden PHPStan-Schritt, mit `continue-on-error: true` – läuft und berichtet für Sichtbarkeit, blockiert aber nichts.
**Verifiziert:** Produktionscode-PHPStan (`phpstan.neon`, ohne jede Baseline-Datei) `[OK] No errors` gegen den echten `~/moodle-dev`-Checkout (phpstan-moodle, Level 5). `phpstan-tests.neon` läuft durch (308 Findings, wie erwartet, nicht blockierend). phpcs `moodle`+`moodle-extra`, `--warning-severity=1`, auf beiden geänderten PHP-Dateien: 0 Fehler/Warnungen (ein erster Formulierungsversuch des Ignore-Kommentars scheiterte zunächst zweimal an phpcs' `InlineComment`-Sniff – „muss mit Großbuchstaben beginnen" bzw. „muss mit Satzzeichen enden" –, dritter Versuch sauber). PHPUnit 221/221 grün (unverändert), Behat 20/20 (324 Steps) grün (unverändert) – beides erneut voll durchlaufen, da `mod_form.php` (Aktivitätseinstellungsformular) mitgeändert wurde.

**Zu beachten / Stolpersteine:**
- Der PHPStan-`stubFiles`-Fehlversuch (siehe oben) ist der eigentliche Lernpunkt dieser Aufgabe: ein Teil-Stub für eine Vendor-Klasse ist **nicht** automatisch additiv/mergend gegenüber der echten Klassendeklaration – wer nur eine Methode korrigieren will, muss entweder die komplette reale Klasse im Stub nachbilden (aufwendig, divergiert leicht von Core) oder – wie hier – einen gezielten Inline-Ignore am Aufrufort setzen. Für zukünftige „Core hat einen falschen Docblock"-Fälle in diesem Projekt: Inline-Ignore ist der Standardweg, `stubFiles` nur für tatsächlich vollständig nachgebildete Klassen/Funktionen erwägen.
- `.gitattributes` `export-ignore`-Liste bereinigt: `phpstan-baseline.neon`-Zeile entfernt (Datei existiert nicht mehr), `phpstan-tests.neon` neu ergänzt (dieselbe Dev-only-Behandlung wie `phpstan.neon`).

### R4-08 · Autosave-Editorvertrag klären  `[FR]`
Das Tiny-spezifische flush/sync in einen kleinen Adapter isolieren. Für andere Editoren dokumentieren/testen, wie der Backing-Textarea-Wert synchronisiert wird; No-JS bleibt voll funktionsfähig.
**Abnahme:** Tiny und mindestens ein zweiter Moodle-Editor speichern identischen Inhalt; unbekannte Editoren degradieren ohne Datenverlust.

### R4-09 · End-to-End-Export absichern  `[FR]` — ✅ Erledigt (2026-08-05, lokal committet, noch nicht gepusht)
Behat- oder geeigneten Integrationstest für Kurs-CSV ergänzen: zwei Groupings, private Einträge, `accessallgroups`, Sonderzeichen/Zeilenumbrüche und mehr als einen Exportchunk.
**Abnahme:** Heruntergeladene CSV enthält nur erlaubte Daten und bleibt über Chunkgrenzen vollständig und korrekt escaped.

**Umsetzung:** PHPUnit-Integrationstest statt Behat gewählt (von der Vorgabe explizit als Alternative erlaubt) — ein echter Chunkgrenzen-Test bräuchte hunderte real angelegte Nutzer:innen, über eine echte Browser-Session unpraktikabel langsam/instabil; `report_table_test.php` hatte für CSV-Downloads bereits denselben Grund dokumentiert (`csv_export_writer::download_file()` ruft `exit()`, was einen Behat-artigen echten HTTP-Download-Test gegen die reale Datei ohnehin ausschließt). **(1)** Kleiner, verhaltensgleicher Refactor als Voraussetzung: die bisher direkt in `coursereport.php` inline liegende CSV-Chunk-Schleife (Teilnehmer:innen-Chunk holen → `rows_for()` → sichtbare Zellen in CSV-Zeilen wandeln → nächster Chunk) wandert als neue `coursereport_provider::csv_rows()`-Generator-Methode in die schon aus R4-04 bestehende Service-Klasse — dieselbe Fortsetzung des R4-04-Musters wie schon bei R4-03s Nachzüglern: `coursereport.php` selbst schrumpft auf eine Drei-Zeilen-Schleife, die nur noch `$writer->add_data($row)` aufruft. **(2)** Neue `tests/coursereport_csv_export_test.php` (4 Tests) treibt `csv_rows()` direkt gegen einen echten `csv_export_writer`, liest die tatsächlich geschriebenen Bytes über `print_csv_data(true)` zurück und parsed sie mit `fgetcsv()` (nicht naiv nach `\n` gesplittet — ein Feld darf einen echten eingebetteten Zeilenumbruch enthalten) erneut ein: `test_two_groupings_restricted_viewer_and_private_entry` (zwei unabhängige Groupings, ein auf eine Gruppe beschränkter Viewer sieht nur seine eigene Gruppierung, ein `private`-Eintrag darin zeigt den Datenschutzhinweis statt Klartext), `test_accessallgroups_viewer_sees_across_both_groupings` (ein Viewer mit `moodle/site:accessallgroups`, aber ohne jede eigene Gruppenmitgliedschaft, sieht beide Groupings vollständig), `test_special_characters_and_line_breaks_round_trip_through_real_writer` (Komma, eingebettetes Anführungszeichen und ein echter Absatzumbruch überstehen einen echten Schreib-dann-Lese-Zyklus byte-genau), `test_complete_across_multiple_chunks_no_drops_or_dupes` (5 Teilnehmer:innen, Chunkgröße 2 → 3 Chunks; alle 5 erscheinen exakt einmal).
**Verifiziert – inklusive Mutationsproben, nicht nur grüner Lauf:** Alle vier neuen Tests grün, PHPUnit gesamt 225/225, phpcs sauber (`moodle`+`moodle-extra`, `--warning-severity=1`), PHPStan weiterhin 0 Fehler (kein neuer Baseline-Bedarf durch den Refactor), Behat 20/20 (324 Steps, erneut vollständig gelaufen wegen der `coursereport.php`-Änderung). Zusätzlich zwei gezielte Mutationsproben direkt im `~/moodle-dev`-Container (nur dort, nie im echten Repo-Stand verändert, danach diff-verifiziert identisch zurückgesetzt): `insightjournal_coursereport_csv_row()`s `$private`-Zweig testweise auf `false` fest verdrahtet → `test_two_groupings_restricted_viewer_and_private_entry` schlägt exakt mit dem zu erwartenden Diff fehl (Klartext statt Datenschutzhinweis); `csv_rows()`s `$offset += $chunksize` testweise auf `$chunksize + 1` geändert (simuliert eine übersprungene Zeile an der Chunkgrenze) → `test_complete_across_multiple_chunks_no_drops_or_dupes` schlägt exakt mit „4 statt 5" fehl. Beide Proben bestätigen: die Tests erkennen die Bugs, die sie zu verhindern vorgeben, nicht nur tautologisch grün.

**Zu beachten / Stolpersteine:**
- Ein eigener Testfehler beim ersten Lauf war lehrreich, kein Produktionsbug: der `find_row()`-Testhelfer suchte anfangs nur nach `userid`, aber bei `accessallgroups` erscheint jede Person zu Recht **einmal pro sichtbarer Aktivität** (zwei Zeilen bei zwei Tagebüchern) — der Helfer griff die erste gefundene Zeile, die zufällig zur jeweils anderen (leeren) Aktivität gehörte. Behoben durch einen optionalen `cmid`-Parameter auf `find_row()`, der bei mehrdeutigen Fällen die richtige Zeile eindeutig auswählt.
- `insightjournal_coursereport_csv_row()`/`insightjournal_entries_by_diary_and_user()` selbst bleiben unverändert; nur der Aufrufort wandert. `coursereport_csv_test.php` (die bestehende, schmalere Unit-Testdatei für diese Funktionen isoliert) bleibt unangetastet und weiterhin komplementär, nicht redundant, zur neuen Datei.

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
2. ~~**R4-02** – Legacy-Pfad entfernen~~ — ✅ erledigt (siehe oben)
3. ~~**CR-01** – Completion-Reset (klein, P1, Datenkonsistenz)~~ — ✅ erledigt (siehe oben)
4. ~~**R4-03** – Gruppenautorisierung speicherbegrenzt **+ Messung** (siehe Messplan unten)~~ — ✅ erledigt (siehe oben)
5. ~~**R4-04** – Kursreport-Service~~ — ✅ erledigt (siehe oben) / ~~**R4-05** – Template-Test bereinigen~~ — ✅ erledigt (siehe oben, keine Codeänderung nötig)
6. ~~**R4-07** – PHPStan-Baseline auf null~~ — ✅ erledigt (siehe oben)
7. ~~**R4-09** – E2E-Export-Test~~ — ✅ erledigt (siehe oben)
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

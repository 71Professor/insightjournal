**InsightJournal – technischer Folgereview**

**Neubewertung nach den Änderungen seit dem Bericht vom 04.08.2026**

**Stand:** 06.08.2026   |   **Repository:** 71Professor/moodle-mod\_insightjournal   |   **Basis:** main 6300438c · ungetaggt · version.php 0.8.0-beta · Moodle 4.5+

**URTEIL · NO-GO FÜR EINEN NEUEN TAG**  Der Umbau ist architektonisch deutlich reifer, aber der aktuelle HEAD hat zwei klare Release-Blocker. Erstens umgehen die neuen direkten groups\_members-Abfragen Moodles Gruppen-Sichtbarkeit und können bei GROUPS\_VISIBILITY\_OWN fremde Mitgliedschaften sowie zugehörige Berichtsdaten freigeben. Zweitens ist CI \#79 rot: das manuell gerenderte Farbwähler-Markup verwendet die veraltete Klasse .form-group und lässt Behat auf Moodle 5.0, 5.1, 5.2 und main scheitern. Der veröffentlichte Tag v0.8.0-beta liegt vor diesen Änderungen und ist von diesen beiden neuen Regressionen nicht betroffen.

> ### ✅ Status-Update (06.08.2026, spätabends – nach `78c930e`)
>
> **Beide P0-Blocker (R5-01, R5-02) sind behoben, verifiziert und gepusht.** R5-02 (CI #79) wurde unabhängig vom Nutzer selbst als Erstes gefunden und mit Commit `ef8b9a3` behoben (`.form-group` → `.mb-3 row`, dem modernen Moodle-5.x-Wrapper), bevor dieses Re-Review überhaupt gelesen wurde – deckt sich exakt mit R5-02s Diagnose. R5-01 wurde daraufhin genau nach diesem Reviews Befund umgesetzt, mit einem wichtigen Korrekturzyklus unterwegs: der erste Fix-Versuch vergaß den `moodle/course:viewhiddengroups`-Bypass, den Moodle-Core selbst immer neben `sql_member_visibility_where()` einsetzt – das hätte gewöhnliche Lehrpersonen (die diese Capability standardmäßig haben) nach dem Fix weniger sehen lassen als vorher, ein Fail-Closed-Regressionsbug im Fix selbst. Zwei unabhängige Subagent-Codereviews (vor und nach dieser Korrektur) plus 24 committete Tests (davon 12 neu für R5-01) fingen das ab. Volle Details: `Fix.md`, Abschnitt „R5-01".
>
> **Verifiziert (Commit `78c930e`):** PHPUnit 239/239, phpcs (`moodle`+`moodle-extra`) sauber, PHPStan 0 Fehler, Behat 24/24 (365 Steps, inkl. `--scss-deprecations`). Committet und nach `origin/main` gepusht.
>
> **Zusätzlich aus R5-04 bereits erledigt** (siehe Abschnitt 4 unten für Details): README/CHANGELOG-Baseline-Referenz korrigiert, Behat-Szenarienzahl auf 24 aktualisiert, CSV-„E2E" auf „Integrationstest" präzisiert, `Fix.md`/`fix2.md` per `.gitattributes` `export-ignore` aus dem Release-Archiv ausgeschlossen (wirkt ab dem nächsten Commit/Tag), `ci.yml`s veralteter Baseline-Kommentar korrigiert, CHANGELOG-Zwischenzustand zu den entfernten `current_user_groups()`-Funktionen konsolidiert.
>
> **R5-03 geprüft, kein Handlungsbedarf**: `tests/report_template_test.php` existiert nicht (weder getrackt noch untracked) – `git status` ist sauber. Deckt sich mit R4-05s eigener Untersuchung (siehe `Fix.md`), die zum selben Ergebnis kam: die im ursprünglichen Erstreview beschriebene Datei wurde offenbar verworfen, bevor sie je committet wurde.
>
> **R5-05/R5-06 seit diesem Update ebenfalls ✅ erledigt** (per Rückfrage mit dem Nutzer entschieden): beide Male „aktuelles Verhalten beibehalten, nur explizit dokumentieren" gewählt statt der jeweils aufwendigeren Verhaltensänderungs-Alternative. Details in `Fix.md`, Abschnitte „R5-05"/„R5-06".
>
> **Noch offen:** R5-07 (Test-PHPStan-Rauschen), R5-08 (CSV-Test-Benennung), R5-09 (Provider-Härtung), R5-10 (ESM), R5-11 (CI-Pin-Nachschärfung). Alle P2/P3, keine P0/P1 mehr.

---

# **1  Aktueller Reifegrad**

| Bereich | Status | Aktueller Befund |
| :---- | ----- | :---- |
| **Autorisierung** | **ROT** | Separate Groups ist speicherbegrenzt, aber direkte Membership-SQLs beachten Group visibility OWN/MEMBERS/NONE nicht vollständig. |
| **Form-/UI-Integration** | **ROT** | Das editorneutrale Antwortformular ist gut; die optionale Farbauswahl führt jedoch eigenes Bootstrap-Layout ein und bricht CI auf Moodle 5.x. |
| **Architektur** | **GRÜN/GELB** | entry\_manager, report\_table und coursereport\_provider trennen Kernlogik sinnvoll. Einige Verträge bleiben zu breit oder unvalidiert. |
| **Tests & Analyse** | **GRÜN/GELB** | Alle 227 PHPUnit-Fälle laufen; Behat ist auf vier von fünf Matrix-Legs rot. Die Test-PHPStan-Stufe toleriert 348 Fehler. |
| **Release** | **GRÜN/GELB** | Das gehärtete Release-Gate verhindert korrekt eine Veröffentlichung nach roter CI. HEAD ist seit dem letzten Tag um 42 Commits weiterentwickelt. |
| **Dokumentation** | **ROT** | README, CHANGELOG, Fix.md und ein CI-Kommentar widersprechen dem aktuellen Code- und Teststand; Fix.md landet derzeit im Release-Archiv. |
| **Performance** | **GRÜN/GELB** | Reports sind nun seiten-/chunkbegrenzt. Für langsamen Login/Dashboard gibt es weiterhin keinen globalen Plugin-Hook als plausible Ursache. |

**Änderungsumfang.** Gegen e162f6c aus dem Review vom 04.08.: 41 Commits, 52 Dateien, \+6.812/−601 Zeilen. Der Arbeitsbaum bleibt bis auf die nutzereigene untracked Datei tests/report\_template\_test.php unverändert.

# **2  P0 — zuerst schließen**

| ID | Prio | Thema | Konkrete Änderung | Abnahme |
| ----- | ----- | :---- | :---- | :---- |
| **R5-01** ✅ | **P0** | **Group visibility in allen Membership-Abfragen erzwingen** | Die neuen EXISTS-/groups\_members-Pfade in locallib.php, report\_table und coursereport\_provider dürfen nicht allein nach groupid filtern. Mit core\_group\\visibility::can\_view\_all\_groups() und sql\_member\_visibility\_where() eine gemeinsame SQL-Policy einführen. Activity-Report, Summary, Kursreport-Paging/-Count, Screen-Zellen und CSV müssen denselben Prädikatkern verwenden. Der Kursreport benötigt dafür eine sichtbarkeitsfähige Teilnehmer-SQL statt eines unqualifizierten groupid-Filters in get\_enrolled\_users(). | Bei GROUPS\_VISIBILITY\_OWN sieht ein Viewer ohne moodle/course:viewhiddengroups nur die eigene Mitgliedschaft; MEMBERS erlaubt Mitglieder untereinander; NONE bleibt verborgen; mit viewhiddengroups bleiben alle berechtigten Pfade sichtbar. Bildschirm, Summary, CSV, total\_participants und Paging liefern konsistente Ergebnisse. |
| **R5-02** ✅ | **P0** | **Farbwähler-Eigenlösung aus dem Release-Gate entfernen** | Empfohlen: die optionale CR-07-Erweiterung vollständig zurücknehmen — mod\_form.php-Zeilen mit rohem Picker-Markup, promptcolor.js samt Build-Artefakten und das Picker-Behat-Szenario entfernen; das standardisierte Hex-Textfeld bleibt. Falls der Picker zwingend ist, als echtes MoodleQuickForm-Element bzw. addGroup ohne .form-group/col-md-\* und ohne Inline-Style neu bauen; Behat-Selektoren dürfen nicht die HTML-Architektur bestimmen. | CI mit \--scss-deprecations ist auf Moodle 4.5, 5.0, 5.1, 5.2 und main grün. Kein manuelles Bootstrap-Grid im PHP-String; Label, Tastaturbedienung und No-JS-Fallback bleiben geprüft. |

## **2.1  Warum R5-01 eine echte Regression ist**

| Pfad | Aktueller Code | Risiko |
| :---- | :---- | :---- |
| **Activity-Report** | EXISTS auf groups\_members mit erlaubten groupids, ohne groups.visibility-Prädikat. | Ein Eintrag eines fremden Mitglieds kann in einer OWN-Gruppe erscheinen. |
| **Summary** | insightjournal\_groupids\_contain\_member() prüft nur userid \+ groupid. | Die Zusammenfassung kann Aktivitäten eines fremden Mitglieds freigeben. |
| **Kursreport-Zellen/CSV** | insightjournal\_groupids\_members\_among() liefert alle Mitglieder der erlaubten IDs. | Private Membership-Sichtbarkeit wird für Status und Export umgangen. |
| **Kursreport-Paging** | get\_enrolled\_users()/count\_enrolled\_users() filtern nur nach der Gruppen-ID-Union. | Auch bei späterer Zellmaskierung können Anzahl und Seitenstruktur verborgene Mitglieder verraten. |

**REGRESSIONSNACHWEIS**  Der vorherige Stand materialisierte Mitglieder über groups\_get\_all\_groups(..., withmembers=true). Moodle wendet dabei OWN/MEMBERS/NONE an. R4-03 ersetzte diesen Pfad durch direkte, zwar speicherbegrenzte SQL-Abfragen, übernahm aber das Visibility-Prädikat nicht. Die Performance-Idee bleibt richtig; die fehlende Privacy-Bedingung muss in die neue SQL-Architektur zurück.

# **3  Status der Punkte aus dem Review vom 04.08.**

| Punkt | Status | Neubewertung |
| :---- | ----- | :---- |
| **R4-01** | **ERLEDIGT** | PHP/JS teilen 29 Fixtures, All-invisible-Inhalt ergibt 0 und LIBXML\_NONET ist gesetzt. Der bewusst enge Vertrag zählt unsichtbare Zeichen weiter, sobald ein sichtbares Zeichen vorhanden ist; siehe R5-05. |
| **R4-02** | **ERLEDIGT** | Der optionale kursweite Legacy-Pfad wurde erst verpflichtend gemacht und anschließend mit den member-materialisierenden Funktionen vollständig entfernt. |
| **R4-03** | **ERLEDIGT ✅** (war „WIEDER OFFEN") | Gruppen-IDs, EXISTS und Chunk-Maps begrenzen Speicher korrekt. Das direkte groups\_members-SQL übernahm Moodles Membership-Visibility zunächst nicht — behoben über R5-01 (siehe Status-Update oben). |
| **R4-04** | **TEILWEISE** | coursereport\_provider bündelt Paging, Zellen und CSV sauber. Seine Teilnehmer- und Membership-Abfragen müssen mit R5-01 privacy-konform werden; chunksize sollte defensiv validiert werden. |
| **R4-05** | **OFFEN** | tests/report\_template\_test.php ist weiterhin untracked. Drei Tests erwarten Teilnehmerzeilen aus report.mustache, obwohl dieses Template laut eigenem Vertrag nur die Seitenschale rendert; die Datei wäre nicht CI-grün. |
| **R4-06** | **ERLEDIGT** | Der privilegierte Release-Job pinnt Drittanbieter-Actions auf vollständige SHAs und vergleicht den ausgecheckten HEAD erneut mit dem CI-SHA. |
| **R4-07** | **TEILWEISE** | Produktions-PHPStan Level 5 ist ohne Baseline grün. Der neue Testlauf ist nicht verwertbar: 348 tolerierte Fehler, darunter vier nicht bloß frameworkbedingte Typbefunde. |
| **R4-08** | **ERLEDIGT** | Tiny-spezifischer Zugriff ist im TinyAdapter isoliert; Tiny, Atto und Plain Textarea sind in Behat abgedeckt. Das Formular selbst bleibt editorneutral. |
| **R4-09** | **TEILWEISE** | Der Provider→csv\_export\_writer-Test ist ein guter Integrationstest mit Gruppen, Privacy, Sonderzeichen und Chunkgrenze. Er durchläuft aber weder HTTP-Controller noch Download-Header; „real E2E“ ist zu stark formuliert. |
| **R4-10** | **TEILWEISE** | CI-Doppeltrigger und Unreleased-Link wurden verbessert. README, CHANGELOG, Fix.md und Workflow-Kommentare enthalten dennoch mehrere stale Aussagen; main erzeugt weiterhin kosmetische skipped Release-Läufe. |

## **3.1  Weitere CR-Punkte**

**Positiv.** CR-01 (Completion beim Kursreset), CR-02 (Farbnormalisierung), CR-03/04 (String-/Hilfetext-Konsistenz), CR-05/06 (Konflikt-Timer und Konstanten) sowie CR-08 (Wortzähler mit Blockgrenzen) sind im Code und in Tests nachvollziehbar.

**Negativ (behoben, siehe Status-Update oben).** CR-07 war die Quelle des CI-Fehlers `.form-group` — behoben mit `ef8b9a3`, seither lokal auch mit `--scss-deprecations` reproduziert/verifiziert statt nur auf CI vertraut (siehe [[moodle-codechecker-toolchain]]-Lehre in `Fix.md`).

# **4  P1 — Stable-Gate und Release-Wahrheit**

| ID | Prio | Thema | Konkrete Änderung | Abnahme |
| ----- | ----- | :---- | :---- | :---- |
| **R5-03** ✅ | **P1** | **Untracked Template-Test bewusst auflösen** | Geprüft statt gelöscht: die Datei existiert nicht (weder getrackt noch untracked), `git status` sauber. Deckt sich mit R4-05s eigener, unabhängiger Untersuchung. Kein Handlungsbedarf. | git status ist sauber; kein Test erwartet Datenzeilen aus dem Shell-Template. Ein echter Geheimtext wird nur dort geprüft, wo report\_table oder coursereport\_provider ihn tatsächlich verarbeitet. |
| **R5-04** ✅ | **P1** | **Dokumentation und Release-Paket synchronisieren** | Erledigt: README-Baseline-Verweis entfernt, 24 Behat-Szenarien + CSV-Integrationstest-Wortwahl aktualisiert, CHANGELOG-Zwischenzustand zu den entfernten current\_user\_groups()-Funktionen konsolidiert, `Fix.md`+`fix2.md` per `.gitattributes` `export-ignore` ausgeschlossen (wirkt ab dem nächsten Commit/Tag), `ci.yml`s veralteter Baseline-Kommentar korrigiert. | git archive HEAD enthält keine internen Arbeitsnotizen. README, CHANGELOG, version.php und CI-Beschreibung nennen denselben Test-/Release-Stand; ein Archiv-Inventartest bestätigt die Paketgrenzen. |
| **R5-05** ✅ | **P1** | **Zeichensemantik als Produktentscheidung festziehen** | Entweder den aktuellen Vertrag ehrlich benennen („DOM-Textlänge; nur vollständig unsichtbar \= 0“) oder für echte sichtbare Zeichen Zero-Width-Zeichen überall entfernen, Unicode-Whitespace normalisieren/trimmen und danach zählen. Für pädagogische minchars-Regeln ist die zweite Variante robuster, weil ein sichtbares Zeichen plus viele unsichtbare Zeichen aktuell das Minimum erfüllen kann. | Explizite Fixtures für sichtbares Zeichen \+ viele Zero-Width-Zeichen, führende/trailing NBSP, gemischte Unicode-Spaces und maxchars. Completion, Servervalidierung und JS-Zähler stimmen fachlich überein. |
| **R5-06** ✅ | **P1** | **Teilnehmervertrag des Kursreports definieren** | Der Provider filtert Teilnehmer aktuell einmal im Kurskontext nach mod/insightjournal:submit. Entscheiden und dokumentieren, ob die Matrix alle Kursteilnehmenden oder die Union der Personen mit submit je sichtbarer Aktivität zeigen soll. Modulbezogene Capability-Overrides anschließend in count, paging, rows und CSV konsistent berücksichtigen. | Tests mit submit-CAP\_PREVENT/CAP\_ALLOW auf Modulebene; total\_participants, Seitengröße und exportierte Zeilen entsprechen dem gewählten Vertrag, ohne leere Phantomzeilen. |

**VOR DEM NÄCHSTEN TAG**  version.php muss eine neue, monotone $plugin-\>version und passende $plugin-\>release erhalten; der Tag muss dazu passen. Der unveränderte Wert 2026080300 / 0.8.0-beta ist auf ungetaggtem main noch erklärbar, darf aber nicht für das nächste Paket wiederverwendet werden.

# **5  P2/P3 — Testsignal und unnötige Eigenlösungen**

| ID | Prio | Thema | Konkrete Änderung | Abnahme |
| ----- | ----- | :---- | :---- | :---- |
| **R5-07** | **P2** | **Test-PHPStan von Dauerrauschen zu Signal umbauen** | Zuerst die vier klaren Nicht-Magic-Befunde beheben: unqualifiziertes stdClass im locallib\_test-Docblock sowie die zwei Privacy-Listen-Typen prüfen/korrigieren. Danach entweder einen Moodle-Testframework-Stub bereitstellen oder den Pilot auf eigene pure Test-Utilities/Generatoren begrenzen. Einen dauerhaft mit 348 Fehlern weiterlaufenden Schritt nicht als Qualitätsnachweis führen. | Der Pilot läuft ohne Fehler und wird dann gating, oder er ist bewusst auf einen null-fehlerfähigen Teilbereich begrenzt. Neue echte Typfehler machen CI rot; keine unbeschränkte Baseline. |
| **R5-08** | **P2** | **CSV-Test korrekt benennen und optional HTTP-Smoke ergänzen** | Bestehenden Test als Integrationstest dokumentieren. Wenn Downloads im Behat-Setup zuverlässig geprüft werden können, einen kleinen Controller-Smoke für Capability, sesskey, Header/BOM und Dateiname ergänzen; die tiefen Gruppen-/Privacy-Fälle bleiben im schnellen PHPUnit-Test. | Dokumentation verspricht exakt die geprüfte Ebene. Optionaler Browser-Smoke lädt eine CSV; die Provider-Integration deckt weiterhin zwei Groupings, Privacy, Sonderzeichen und mehrere Chunks ab. |
| **R5-09** | **P2** | **Provider-Verträge defensiv machen** | csv\_rows() soll chunksize \<= 0 mit coding\_exception/invalid\_parameter\_exception ablehnen oder auf mindestens 1 normalisieren. Zusätzlich Array-Keys/Diary-IDs vor dem Zugriff validieren und die Erwartung an diaries/activities in Tests fixieren. | Kein Endlos-/Nullfortschrittsrisiko bei ungültigem chunksize; fehlende Diary-Zuordnung erzeugt eine klare kontrollierte Ausnahme statt Notice/TypeError. |
| **R5-10** | **P3** | **Neues JavaScript schrittweise auf ESM umstellen** | Moodle unterstützt AMD weiterhin, empfiehlt für neuen Community-Code aber ESM. Bei der nächsten fachlichen Änderung autosave.js/summary.js in import/export-Syntax migrieren. Dadurch entfallen Teile der define()-Boilerplate und die lokale PHPCS-Ausnahme für function(). | Grunt erzeugt dieselben Build-Dateien; Tiny/Atto/Plain-Textarea-Behat bleibt grün; keine neue globale Sniff-Ausnahme. |
| **R5-11** | **P3** | **CI-Reproduzierbarkeit nachschärfen** | Auch read-only-CI-Actions optional auf SHAs pinnen und moodle-plugin-ci statt ^4 kontrolliert aktualisieren. Die fünf PHPUnit-Deprecations unter PHPUnit 11 inventarisieren; soweit versionsübergreifend möglich auf Attribute umstellen oder als bekannte Moodle-4.5-Kompatibilität dokumentieren. | Abhängigkeiten ändern sich nur in bewussten Update-PRs; keine unbekannten PHPUnit-Deprecations. Release-Job bleibt vollständig SHA-gepinnt. |

## **5.1  Eigenlösungen: behalten, zurückbauen, beobachten**

| Baustein | Bewertung | Empfehlung |
| :---- | :---- | :---- |
| **Moodle editor \+ TinyAdapter** | Angemessene kleine Adaptergrenze; kein manuelles Editor-Bootstrapping mehr. | Behalten. Editorvertrag mit Tiny, Atto und Plain Textarea weiter testen. |
| **Prompt-Farbwähler** | Optionales Feature mit rohem Bootstrap-Markup, Inline-CSS, eigenem AMD und eigenem Behat-Test; verursacht aktuell mehr Wartung als Nutzen. | Für den nächsten Tag zurückbauen. Später nur über Forms API/plugin-eigene Klassen neu einführen. |
| **Wortzähler** | Kleine, rein clientseitige Funktion; eigene HTML→Text-Semantik ist begründet und für Blockgrenzen getestet. | Behalten, aber nicht mit Completion-/Validierungsregeln vermischen. |
| **Test-PHPStan tolerant** | Der Schritt läuft absichtlich fehlerhaft und erzeugt wenig verwertbares Signal. | Verkleinern oder korrekt bootstrappen; danach gating machen. |
| **Fix.md im Paket** | Interne Arbeits-/Subagent-Notizen sind kein Laufzeitbestandteil und teilweise stale. | Nach docs verschieben oder export-ignore; nicht ausliefern. |

# **6  Architektur- und Moodle-Konformitätsprüfung**

| Bereich | Stärken | Rest-/Änderungsbedarf |
| :---- | :---- | :---- |
| **Schreiben** | entry\_manager ist die gemeinsame Save-Grenze für AJAX und No-JS; Lock, Revision, Konflikt, Completion und Events sind zentral. | Keine neue kritische Lücke gefunden. Sichtbare-Zeichen-Vertrag aus R5-05 festziehen. |
| **Formulare/Editortausch** | entry\_form nutzt Moodles editor-Element; keine Dateianhänge; No-JS ist funktional; Tiny-Sonderwissen ist isoliert. | Prompt-Farbwähler verletzt die Forms-/Theme-Grenze. ESM ist für neuen Code vorzuziehen. |
| **Activity-Report** | table\_sql übernimmt Paging/Download; Identitätsfelder und private Inhalte werden capability-/privacybewusst behandelt. | Group visibility in EXISTS ergänzen; OWN/MEMBERS/NONE testen. |
| **Kursreport** | coursereport\_provider reduziert den Controller und streamt CSV in 500er-Chunks über denselben row-Kern. | Participant-SQL und per-cell membership visibility korrigieren; Capability-Vertrag und chunksize härten. |
| **Privacy/Backup/Reset** | Privacy Provider, Backup/Restore, Course reset und Completion-Reset sind vorhanden und getestet. | Keine neue P0-Lücke. Test-PHPStan-Typbefunde in Privacy-Tests bereinigen. |
| **Release/Supply Chain** | Tag→CI-SHA→Checkout-SHA wird doppelt geprüft; privilegierte Actions sind fest gepinnt; rotes CI verhindert Release. | Paketinhalt und Doku bereinigen; read-only CI-Pins optional nachziehen. |
| **Moodle-Gruppen-API** | Grouping, Separate Groups, accessallgroups und participation-only sind berücksichtigt. | Direkte Membership-SQL muss zusätzlich core\_group\\visibility verwenden; genau diese Core-Regel ist derzeit verloren gegangen. |

## **6.1  Sicherheits- und Datenschutzfazit**

**FAIL CLOSED WIEDERHERSTELLEN**  Die Eintrags-Privacy (Learner entscheidet private/visible) bleibt sauber. Das neue Problem liegt eine Ebene davor: Moodle kann schon die Zugehörigkeit zu einer Gruppe verbergen. Ein Report darf diese Membership nicht über eine direkte DB-Abfrage rekonstruieren und daraus Zugriff auf Name, Status oder Inhalt ableiten. R5-01 muss deshalb alle Reportflächen, nicht nur den sichtbaren Text einer Zelle, einschließen.

**Unverändert positiv.** CSRF-Schutz für CSV, getrennte export-Capability, PARAM\_CLEANHTML, format\_text(), per-entry Privacy-Maskierung, Moodle Lock API und optimistische Revisionen bleiben angemessen umgesetzt.

# **7  Reihenfolge für PHPUnit, PHPStan, Behat und Code Checker**

| Werkzeug | Stand am HEAD | Nächste Änderung | Abnahme |
| :---- | :---- | :---- | :---- |
| **1 · PHPUnit** | Alle fünf Matrix-Legs grün. Im 5.0-Job: 227 Fälle, 432 Assertions, 5 PHPUnit-Deprecations. 199 versionierte test\_-Methoden; 4 weitere nur untracked. | R5-01 zuerst rot/grün entwickeln: OWN, MEMBERS, NONE und viewhiddengroups für Helper, report\_table, Provider, Summary, count/paging und CSV. Danach R5-03, R5-05, R5-06 und R5-09. | Gezielte Klassen im PR, danach komplette Matrix. Geheimtexte und verborgene Mitgliedschaften werden am realen Produktionspfad geprüft. |
| **2 · PHPStan** | Produktion Level 5 auf Moodle 5.0 grün; baseline entfernt. Testlauf meldet 348 Fehler und ist continue-on-error. | Nach R5-01-Signatur-/SQL-Änderungen Produktionslauf. Danach vier klare Testbefunde korrigieren und Pilot auf einen null-fehlerfähigen Scope bringen. | Produktionscode 0 Fehler. Test-Pilot 0 Fehler und gating; keine wachsende Toleranzliste. |
| **3 · Code Checker** | Auf PHP 8.2–8.4 in vier Matrix-Legs grün; 4.5/PHP 8.1 wird durch die Workflow-Bedingung übersprungen. PHPDoc, Mustache und Grunt ebenfalls grün. | Nach jedem kleinen PR laufen lassen. Bei R5-02 zusätzlich Grunt und SCSS-Deprecation aktiv lassen; kein neues raw Bootstrap-Markup und keine globale Sniff-Ausnahme. | 0 Warnungen/Fehler in unterstützter Checker-Matrix; Build-Artefakte stimmen mit src überein. |
| **4 · Behat** | 24 Szenarien. Moodle 4.5 grün; Moodle 5.0/5.1/5.2/main jeweils 23 grün, 1 rot — identisch am Prompt-Picker wegen .form-group. | R5-02 zuerst matrixweit grün machen. Danach ein browsernahes OWN-Visibility-Szenario über Activity-Report, Kursreport und Summary; CSV-Smoke nur wenn Download stabil testbar ist. | 24/24 oder mehr auf allen fünf Legs mit \--scss-deprecations; keine rerun-persistente Abweichung. |

## **7.1  Was die grüne PHPUnit-Suite nicht beweist**

| Lücke | Warum bisher grün | Neue Probe |
| :---- | :---- | :---- |
| **Group visibility** | Tests variieren Groupings und Separate Groups, aber keine GROUPS\_VISIBILITY\_\*-Werte. | Gleiche Gruppe, Viewer ohne viewhiddengroups, visibility OWN: fremder Lernender muss überall fehlen. |
| **Prompt-Markup** | PHPUnit prüft Werte/HTML nicht gegen Moodles Style-Deprecation-Liste. | Behat mit \--scss-deprecations auf jeder unterstützten Moodle-Version. |
| **Untracked Test** | GitHub kennt die Datei nicht; drei ihrer vier fachlichen Tests richten Erwartungen an das falsche Template. | Datei löschen oder Shell-Vertrag testen und committen. |
| **Test-PHPStan** | continue-on-error wandelt 348 Befunde in einen erfolgreichen Jobschritt um; Check-Annotations bleiben laut. | Null-fehlerfähigen Pilot wählen und anschließend gating. |

# **8  Lange lokale Moodle-Ladezeiten**

**BEFUND**  Auch im neuen Stand gibt es keinen Hook, der Login oder Dashboard global mit InsightJournal-Abfragen belastet. R4-03/R4-04 betreffen ausschließlich Reports. Der Autosave-Poll läuft nur auf der Journal-Seite, sendet ohne Änderung keinen Request und existierte schon zuvor; der neue Wortzähler rechnet nur bei Wertänderung. Der Farbwähler wird nur auf der Aktivitätseinstellungsseite geladen. Minutenlange Login-/Dashboard-Zeiten bleiben daher primär ein lokales Plattformproblem, bis eine Messung das Gegenteil zeigt.

## **8.1  Pfade getrennt bewerten**

| Pfad | Pluginbezug | Interpretation |
| :---- | :---- | :---- |
| **Login / Dashboard** | Kein InsightJournal-Laufzeithook oder seitenweiter Querypfad gefunden. | Zuerst WSL/Docker-I/O, PHP-Start, Xdebug, OPcache, DB, Session und MUC messen. |
| **Kursseite** | get\_coursemodule\_info() liest pro Instanz die üblichen Modulfelder und wird von Moodles Modinfo-Cache getragen. | Nach Cache-Purge einmalige Kälte von dauerhaft langsamen Requests unterscheiden. |
| **Journal-View** | Editor, ein 1-s-DOM-Poll, einmaliger String-Load; Netzwerk nur beim Speichern. | Nur bei isoliert langsamer View Browser-Wasserfall und Server-TTFB trennen. |
| **Activity-/Kursreport** | Neue SQLs, Paging und 500er-Chunks; hier liegen die echten Plugin-Hotspots. | Nach R5-01 Queryzahl, Laufzeit und Peak Memory mit großen sichtbaren/verborgenen Gruppen erneut messen. |

## **8.2  Konkreter Messplan**

| Schritt | Messung | Werkzeug/Setup | Entscheidung |
| :---- | :---- | :---- | :---- |
| **1 · TTFB trennen** | Je 5 warme Requests für Login, Dashboard, leeren Kurs, Journal-View und beide Reports; Median statt Einzelwert. | Browser Network oder curl time\_starttransfer/time\_total; Moodle Performance info. | Nur TTFB hoch: PHP/DB/Session. Nur Assets hoch: Browser/Proxy/Volumes. |
| **2 · Umgebung** | PHP CLI/FPM-Start, Xdebug, OPcache, CPU/RAM, DB-Latenz, moodledata- und Code-Volume-Pfad. | Xdebug aus; OPcache an; docker stats; DB slow log; Linux-Dateisystem statt /mnt/c-Bind-Mount. | Alles langsam: Plattform optimieren, nicht Plugin refactoren. |
| **3 · Cache** | Erster Request nach purge\_caches gegen fünf nachfolgende Requests. | Gleiche URL/Session; keine parallelen Install-/Grunt-Prozesse. | Nur erster langsam: erwartete Cache-Warmup-Kosten. |
| **4 · Reportlast** | 50/500/5.000 Mitglieder; visibility ALL/MEMBERS/OWN; mehrere Groupings; Screen und CSV getrennt. | DB-Queries, Peak Memory, TTFB, Exportdauer; identischer Seed vor/nach R5-01. | Abnahme: Memory bleibt seiten-/chunkproportional, Visibility korrekt, keine leeren Paging-Seiten. |
| **5 · A/B** | Plugin deaktiviert/aktiv bei identischen Login-/Dashboard-Requests; danach nur Journalpfade vergleichen. | Gleicher Container, gleicher Cachezustand, gleiche Session. | Keine Differenz global: Umgebung bestätigt. Differenz nur Reports: SQL profilieren. |

# **9  Empfohlene Umsetzungsreihenfolge**

| PR | Inhalt | Primäre Prüfung | Gate |
| :---- | :---- | :---- | :---- |
| **1** ✅ | R5-01 Group visibility: gemeinsame SQL-Policy, Provider-Paging/Count, alle Reportflächen. | PHPUnit zuerst; Produktion-PHPStan; Behat OWN-Szenario; volle Matrix. | Keine verborgene Membership oder Berichtsdaten; Performance bleibt bounded. |
| **2** ✅ | R5-02 CR-07 zurückbauen oder Forms-API-konform neu bauen. | Behat \--scss-deprecations, Grunt, Code Checker, No-JS/A11y. | CI \#79-Regression vollständig geschlossen. |
| **3** ✅ | R5-03 untracked Template-Test löschen oder als Shell-Test committen. | Gezieltes PHPUnit plus git status. | Sauberer Arbeitsbaum; keine falsche Testverantwortung. |
| **4** ✅ | R5-04 README/CHANGELOG/Fix/Workflow-Kommentare/Paketinventar korrigieren. | git archive-Inventar, Links, diff-check. | Release-Paket enthält nur beabsichtigte Dateien; Doku ist widerspruchsfrei. |
| **5** | R5-07 Test-PHPStan auf echten Nullfehler-Pilot bringen. | PHPStan JSON/Standardausgabe, neuer gating Schritt. | Kein toleriertes Dauerrauschen; echte Fehler blockieren. |
| **6** ✅ | R5-05 Zeichensemantik entscheiden und Fixtures erweitern. | PHPUnit-Fixtures, Browser-Parität, Completion-/maxchars-Behat. | Per Rückfrage entschieden: Vertrag dokumentiert statt geändert (keine Fixture-Erweiterung nötig, da kein Verhalten geändert wurde). |
| **7** (R5-06 ✅, R5-08/R5-09 offen) | R5-06/R5-08/R5-09: Teilnehmervertrag, CSV-Smoke, Provider-Härtung. | PHPUnit, optional Behat-Download, PHPStan. | R5-06: per Rückfrage dokumentiert statt geändert. R5-08/R5-09 weiterhin offen. |
| **8** | R5-10/R5-11 Wartung: ESM, Pins, PHPUnit-Deprecations. | Grunt, Code Checker, volle CI. | Kein Funktionsumbau; reproduzierbar und wartbar. |

**FREIGABELOGIK**  Nach PR 1 und 2 sowie vollständig grüner Fünfermatrix ist wieder eine Beta-Kandidatur vertretbar. Vor Stable zusätzlich PR 3 bis 6, dokumentierte Lastmessung, neue Versionsnummer/Release-Bezeichnung und die bereits offenen Plugin-Directory-Unterlagen (Screenshots; Moderations-Capability-Entscheidung) abschließen.

# **10  Verifizierte Evidenz**

| Nachweis | Ergebnis |
| :---- | :---- |
| **Repository** | origin/main \= lokal 6300438c026722ec49752654411ea6e4dc97fbe7 vom 06.08.2026; git describe: v0.8.0-beta-42-g6300438. |
| **Diff** | Seit e162f6c: 41 Commits, 52 Dateien, \+6.812/−601 Zeilen. git diff \--check unauffällig; AMD-Quellen syntaktisch mit Node geprüft. |
| **CI \#79** | Gesamt: failure. Moodle 4.5/PHP 8.1/PostgreSQL grün; Moodle 5.0, 5.1, 5.2 und main scheitern jeweils im selben Picker-Szenario an deprecated .form-group. |
| **PHPUnit** | Alle fünf Jobs erfolgreich. Moodle-5.0-Leg: 227 Tests, 432 Assertions, 5 PHPUnit-Deprecations. 199 committed test\_-Methoden im Quellbaum. |
| **PHPStan** | Produktion Level 5 ohne Baseline erfolgreich. Testanalyse: 348 tolerierte Fehler — 344 method.notFound, 1 class.notFound, 1 return.phpDocType, 2 argument.type. |
| **Behat** | 24 Szenarien / 365 Laufzeitschritte im 5.0-Job: 23 bestanden, 1 fehlgeschlagen; zweimaliger Rerun scheitert identisch. 4.5 besteht 24/24. |
| **Code Checker** | Moodle Code Checker auf PHP 8.2–8.4 in vier Legs erfolgreich; 4.5/PHP 8.1 workflowbedingt übersprungen. PHPDoc, Mustache, Grunt und PHP lint erfolgreich. |
| **Arbeitsbaum** | Nur tests/report\_template\_test.php ist untracked. Die vier Methoden sind nicht Teil der CI; drei erwarten Zeileninhalt aus einem Template, das laut Vertrag keine Zeilen rendert. |
| **Archiv** | git archive HEAD enthält 104 Einträge, darunter Fix.md; /docs ist korrekt ausgeschlossen. Letzter veröffentlichter Release bleibt v0.8.0-beta vom 03.08.2026. |
| **Lokale Grenzen** | PHP, Composer, Docker und gh sind in dieser Review-Umgebung nicht verfügbar; Moodle-Tests wurden deshalb über die aktuelle GitHub-Matrix und deren vollständige Joblogs verifiziert. |

# **11  Freigabeentscheidung**

> **Status 06.08.2026 spätabends:** Die beiden hier beschriebenen P0-Blocker (R5-01, R5-02) sind seit `78c930e` behoben, verifiziert und gepusht — siehe Status-Update ganz oben. Das folgende „NO-GO" bezieht sich auf den damaligen Stand `6300438c`; ob nach `78c930e` bereits eine neue Beta-Kandidatur vertretbar ist (siehe „FREIGABELOGIK" unten: „Nach PR 1 und 2 sowie vollständig grüner Fünfermatrix"), hängt vom CI-Lauf auf dem neuen HEAD ab. Zum Zeitpunkt dieses Updates: „Moodle Plugin CI" für `78c930e` steht auf GitHub noch in der Warteschlange (`queued`), noch kein Ergebnis. Vor einem neuen Tag den tatsächlichen Ausgang dieses Laufs prüfen, nicht von lokal-grün auf CI-grün schließen (siehe [[moodle-codechecker-toolchain]]-Lehre in `Fix.md`).

**AKTUELLER HEAD · NO-GO**  Keinen neuen Beta- oder Stable-Tag aus 6300438c erstellen. Die Gruppen-Sichtbarkeit ist ein Autorisierungs-/Privacy-Gate; die rote CI ist ein unabhängiges Liefer-Gate. Der Release-Workflow reagiert korrekt und veröffentlicht diesen Stand nicht.

**LETZTER RELEASE · UNVERÄNDERT**  v0.8.0-beta bleibt der zuletzt veröffentlichte Stand. Die beiden hier beschriebenen Regressionen wurden erst danach auf main eingeführt. Für eine erneute Freigabe sind jedoch neue Matrixläufe nach R5-01/R5-02 erforderlich; ein früherer grüner Lauf ersetzt sie nicht.

**STABLE · NO-GO**  Nach den P0-Fixes zusätzlich R5-03 bis R5-06 und die dokumentierte Performance-A/B-Messung abschließen. Danach neue Versionsnummer, Changelog-/README-Abgleich, sauberes Archiv und vollständige grüne CI als gemeinsames Stable-Gate verwenden.

# **12  Quellen**

**Repository / geprüfter Commit:** [Quelle öffnen](https://github.com/71Professor/moodle-mod_insightjournal/tree/6300438c026722ec49752654411ea6e4dc97fbe7)

**Aktueller CI-Lauf \#79:** [Quelle öffnen](https://github.com/71Professor/moodle-mod_insightjournal/actions/runs/31111502465)

**Letzter grüner Lauf vor CR-07/08 \#78:** [Quelle öffnen](https://github.com/71Professor/moodle-mod_insightjournal/actions/runs/31105499757)

**Release v0.8.0-beta:** [Quelle öffnen](https://github.com/71Professor/moodle-mod_insightjournal/releases/tag/v0.8.0-beta)

**Moodle Groups API:** [Quelle öffnen](https://moodledev.io/docs/5.0/apis/subsystems/group)

**Moodle Core Group visibility (4.5):** [Quelle öffnen](https://github.com/moodle/moodle/blob/MOODLE_405_STABLE/group/classes/visibility.php)

**Moodle Forms API:** [Quelle öffnen](https://moodledev.io/docs/5.2/apis/subsystems/form)

**Moodle JavaScript Modules / ESM:** [Quelle öffnen](https://moodledev.io/docs/5.0/guides/javascript/modules)

**GitHub Actions Security Hardening:** [Quelle öffnen](https://docs.github.com/en/actions/security-for-github-actions/security-guides/security-hardening-for-github-actions)

**Quellenhinweis.** Verwendet wurden das Projekt selbst, aktuelle GitHub-API-/Actions-Nachweise sowie offizielle Moodle- und GitHub-Dokumentation. Keine Springer-Quellen.
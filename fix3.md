# InsightJournal – begrenztes Abschlussreview

**Stand:** 07.08.2026  
**Repository:** `71Professor/moodle-mod_insightjournal`  
**Geprüfter Stand:** `5442266479fba18c458b8cc4dc39d4e22e708a17`  
**Tag/Release:** `v0.9.0-beta`  
**Vergleichsbasis:** Review vom 06.08.2026, Commit `6300438c026722ec49752654411ea6e4dc97fbe7`

> **Nachtrag 07.08.2026: R5-01b ist kein Bug — Review-Annahme widerlegt.** Vor der Umsetzung von R5-01b (Abschnitt 3) wurden vier gezielte PHPUnit-Regressionstests gegen den *unveränderten* Code aus diesem Review geschrieben (OWN ohne viewhiddengroups, MEMBERS ohne viewhiddengroups, OWN mit viewhiddengroups, Mehrseiten-Paging mit verstecktem Mitglied — exakt die vier hier in §3 "Verbindliche Tests" geforderten Fälle). Alle vier liefen **sofort grün**, ohne jede Codeänderung. Ursache: die Kernannahme dieses Abschnitts — `count_enrolled_users()`/`get_enrolled_users()` filtern "nur nach der rohen Gruppenzugehörigkeit" — trifft für den tatsächlichen Moodle-Core nicht zu. `get_enrolled_with_capabilities_join()` (`lib/enrollib.php`) reicht einen nicht-leeren `$groupid` an `groups_get_members_join()` (`lib/grouplib.php`) durch, das intern bereits `core_group\visibility::sql_member_visibility_where()` anwendet, sobald der Betrachter kein `moodle/course:viewhiddengroups` hat — verifiziert im echten Core-Checkout für Moodle 4.5.12+ und 5.0.8+, also über die gesamte von der Plugin-CI getestete Versionsspanne. `coursereport_provider.php:117-150` bleibt deshalb unverändert; die vier Tests wurden als dauerhafte Regressionsabsicherung übernommen (`tests/local/coursereport_provider_test.php`, `test_total_participants_excludes_hidden_own_visibility_member` und Geschwister). **Für ein erneutes Review: R5-01b nicht erneut als offenen Punkt vorschlagen, ohne zuvor `groups_get_members_join()` im tatsächlichen Core-Checkout der Zielversion nachzuschlagen.**

## 1. Urteil

Der neue Stand ist deutlich weiter und die Veröffentlichung von `v0.9.0-beta` wurde technisch korrekt ausgeführt. Die vollständige Moodle-CI-Matrix ist grün; PHPUnit, Produktions-PHPStan, der begrenzte Test-PHPStan-Lauf, Moodle Code Checker, Grunt und Behat bestehen. Der frühere Farbwähler-CI-Blocker ist geschlossen.

Es wurde **kein neuer Release-Blocker** gefunden. Ein Teil des bereits vereinbarten R5-01-Gates ist jedoch noch nicht umgesetzt: Im Kursbericht berücksichtigen die Teilnehmeranzahl und die SQL-Paginierung weiterhin nur Gruppen-IDs, nicht Moodles Membership-Visibility. Die eigentlichen Berichtszellen, der Aktivitätsbericht und die Summary sind dagegen korrigiert.

Der veröffentlichte Beta-Tag muss deshalb nicht zurückgezogen werden. Vor dem **nächsten** Tag beziehungsweise vor einer breiteren Verteilung sollte der verbleibende Paging-/Count-Pfad in einem kleinen, isolierten Patch geschlossen werden. Danach endet diese Review-Schleife: Weitere optionale Architektur- oder Wartungsideen sind Backlog und kein Release-Gate.

## 2. Abnahme der vereinbarten Ziellinie

| Gate | Ergebnis | Bewertung |
|---|---|---|
| R5-01 Group visibility | Aktivitätsreport, Summary und Kursreport-Zellen verwenden nun Moodles `core_group\visibility`-Prädikat. `total_participants()` und `participants()` tun dies noch nicht. | **Teilweise offen – bekannter Restpunkt** |
| R5-02 Farbwähler/Behat | `.form-group` wurde entfernt. Behat besteht auf Moodle 4.5, 5.0, 5.1, 5.2 und `main`. | **Erledigt** |
| Vollständige CI | Lauf `#31149088662`: alle fünf Matrix-Jobs erfolgreich. | **Erledigt** |
| Dokumentation/Version | `version.php`, README, Dokumentation, Changelog, Tag und Release nennen `0.9.0-beta`. | **Erledigt** |
| Release-Paket | Release-Workflow erfolgreich; ZIP hat den korrekten Root-Ordner `insightjournal/`. `Fix.md`, `fix2.md`, `/docs`, PHPStan-Konfiguration und GitHub-Workflowdateien sind ausgeschlossen. | **Erledigt** |

## 3. Einziger verbleibender Pflichtpunkt

### R5-01b – Membership-Visibility bereits bei Count und Paging anwenden

**Priorität:** P0 für den nächsten Tag, weil es sich um den bereits bekannten Autorisierungs-/Privacy-Pfad handelt.  
**Umfang:** ein eng begrenzter Patch im Kursreport-Provider, keine weitere Architekturüberarbeitung.

In `classes/local/coursereport_provider.php` verwenden:

- `total_participants()` in den Zeilen 117–126 weiterhin `count_enrolled_users(..., $restrictgroupids)`;
- `participants()` in den Zeilen 137–150 weiterhin `get_enrolled_users(..., $restrictgroupids)`.

Diese Moodle-Funktionen filtern hier nach der rohen Gruppenzugehörigkeit. Erst später prüft `rows_for()` mit `insightjournal_groupids_members_among(..., $courseid)` die tatsächliche Membership-Visibility. `coursereport.php` verwirft anschließend zwar Zeilen mit `visiblecount === 0`, berechnet die Seitennavigation aber mit der zuvor ungefilterten Anzahl.

Dadurch kann bei dem bereits beschriebenen Restore-Sonderfall – `GROUPS_VISIBILITY_OWN` oder `NONE` zusammen mit einer inkonsistenten `participation=1` – Folgendes passieren:

1. verborgene Nutzer belegen Plätze in der SQL-Seite;
2. die sichtbare Seite enthält zu wenige oder gar keine Zeilen;
3. die Seitennavigation verrät mindestens näherungsweise die Zahl verborgener Mitgliedschaften;
4. berechtigte sichtbare Nutzer können auf spätere Seiten verschoben werden.

Das ist **kein neu entdecktes Problem**, sondern genau der im Review vom 06.08. bereits genannte Teil „Provider-Paging/Count“ von R5-01.

### Konkrete Änderung

`total_participants()` und `participants()` sollten denselben gemeinsamen, visibility-fähigen Teilnehmer-SQL-Kern verwenden:

1. Enrolment und `mod/insightjournal:submit` über Moodles Enrolment-SQL bestimmen.
2. Bei uneingeschränkten Aktivitäten keine Gruppenbedingung hinzufügen.
3. Bei ausschließlich eingeschränkten Aktivitäten auf die Union der erlaubten Gruppen begrenzen.
4. Wenn `core_group\visibility::can_view_all_groups($courseid)` falsch ist, zusätzlich `core_group\visibility::sql_member_visibility_where()` anwenden.
5. Count und Datenselektion müssen exakt denselben `FROM`-/`WHERE`-/Parameterkern verwenden.
6. Sortierung und `LIMIT/OFFSET` erst nach der vollständigen Autorisierungsbedingung anwenden.

Die bestehende, speicherbegrenzte Verarbeitung kann dabei erhalten bleiben. Es ist nicht nötig, wieder vollständige Mitgliederlisten zu materialisieren.

### Verbindliche Tests

- `OWN`, ohne `moodle/course:viewhiddengroups`: `total_participants() === 0` und `participants() === []` für fremde Mitglieder.
- `MEMBERS`, ohne `viewhiddengroups`: andere Gruppenmitglieder bleiben in Count und Seite enthalten.
- `OWN`, mit `viewhiddengroups`: andere Mitglieder bleiben enthalten.
- Paging-Test mit mehr als einer Seite: verborgene Mitglieder erzeugen weder falsche Gesamtzahlen noch leere Seiten.
- Bestehende CSV-, Zell- und Grouping-Tests bleiben grün.

## 4. Test- und CI-Nachweise

Der erfolgreiche CI-Lauf `#31149088662` prüfte:

| Moodle/PHP/Datenbank | Ergebnis |
|---|---|
| Moodle `main`, PHP 8.4, PostgreSQL | Grün |
| Moodle 5.2, PHP 8.3, MariaDB | Grün |
| Moodle 5.1, PHP 8.2, PostgreSQL | Grün |
| Moodle 5.0, PHP 8.2, MariaDB | Grün |
| Moodle 4.5, PHP 8.1, PostgreSQL | Grün |

Im Moodle-5.0-Job:

- PHPUnit: **242 Tests, 447 Assertions, 1 dokumentierte Deprecation**;
- Behat: **24/24 Szenarien, 365/365 Schritte**;
- Produktions-PHPStan Level 5: **0 Fehler**;
- begrenzter, nun blockierender Test-PHPStan-Lauf: **0 Fehler**;
- Moodle Code Checker, PHPDoc, Mustache, Grunt, PHP-Lint und Validierung: erfolgreich.

Die lokale Review-Umgebung enthält kein PHP/Composer/Moodle. Deshalb wurden die Moodle-Laufzeittests über die vollständigen GitHub-Joblogs verifiziert. Zusätzlich waren `git diff --check`, die Syntaxprüfung aller drei AMD-Quellen mit Node und die XML-Validierung von `db/install.xml` lokal unauffällig.

## 5. Nicht blockierende Hinweise

Diese Punkte begründen **keine weitere Review-Schleife**:

- Der Farbwähler bleibt manuell gerendertes HTML mit Bootstrap-Spalten und Inline-CSS. Das ist wartungsintensiver als ein echtes Forms-API-Element, erzeugt aktuell aber keinen CI-, Sicherheits- oder Funktionsfehler. Daher Backlog, kein Gate.
- Im Abschnitt `0.9.0-beta` des Changelogs steht zunächst noch, der Test-PHPStan-Lauf sei nicht blockierend; weiter unten wird korrekt beschrieben, dass er inzwischen blockiert. Diese historische Formulierung kann bei der nächsten normalen Dokuänderung bereinigt werden.
- Die ältere lokale Datei `tests/report_template_test.php` ist weiterhin nur in unserem ursprünglichen Review-Arbeitsbaum ungetrackt. Sie ist nicht im Tag oder Release-ZIP enthalten und damit kein Releaseproblem.
- Für die langen lokalen Login-/Dashboard-Ladezeiten ist im neuen Diff weiterhin kein globaler Plugin-Hook erkennbar. Die geänderten Gruppenabfragen laufen nur auf Reportpfaden. Eine lokale A/B-Messung bleibt sinnvoll für Stable, ist aber kein Beta-Blocker.

## 6. Endgültige Abschlussregel

Für den nächsten Tag sind nur noch folgende Schritte erforderlich:

1. R5-01b für Count und Paging implementieren.
2. Die vier oben genannten Sichtbarkeits-/Paging-Tests ergänzen.
3. Die vollständige Fünfermatrix erneut grün laufen lassen.
4. Eine neue monotone Plugin-Version und einen neuen Release-Namen verwenden, beispielsweise `2026080700` / `0.9.1-beta`.

Wenn diese vier Punkte erfüllt sind, gilt die Beta-Review als **abgeschlossen**. Danach dürfen nur ein neu nachgewiesenes Sicherheits-/Autorisierungsproblem, Datenverlust oder eine reproduzierbare CI-/Laufzeitregression einen Release stoppen. Wartbarkeit, ESM-Migration, zusätzliche Browser-Smokes oder Dokumentationskosmetik bleiben normale Backlog-Aufgaben.

## 7. Quellen

- [Geprüfter Commit](https://github.com/71Professor/moodle-mod_insightjournal/tree/5442266479fba18c458b8cc4dc39d4e22e708a17)
- [Erfolgreicher Moodle-CI-Lauf #31149088662](https://github.com/71Professor/moodle-mod_insightjournal/actions/runs/31149088662)
- [Erfolgreicher Release-Lauf #31150033969](https://github.com/71Professor/moodle-mod_insightjournal/actions/runs/31150033969)
- [Release v0.9.0-beta](https://github.com/71Professor/moodle-mod_insightjournal/releases/tag/v0.9.0-beta)
- [Moodle Groups API](https://moodledev.io/docs/5.0/apis/subsystems/group)
- [Moodle-Core: Group visibility](https://github.com/moodle/moodle/blob/MOODLE_500_STABLE/group/classes/visibility.php)


# R4-03 – Gruppenautorisierung speicherbegrenzt machen

**Datum:** 05.08.2026
**Bezug:** `Fix.md`, R4-03 (P1 – Stable-Gate)
**Status:** Entwurf, zur Review

## Problem

Drei Oberflächen (Activity-Report `report.php`, Kursreport `coursereport.php`, Summary
`summary.php`) prüfen Gruppenautorisierung über `insightjournal_current_user_groups()`
bzw. `insightjournal_current_user_group_userids()` in `locallib.php`. Beide rufen
`groups_get_all_groups(..., $withmembers = true)` auf – das holt für **jede** erlaubte
Gruppe die volle Mitgliederliste, auch wenn der Aufrufer am Ende nur die Gruppen-IDs
braucht oder nur wissen will, ob ein einzelner User dabei ist.

Konkrete Symptome:

1. **report.php:46** löst alle Mitglieder aller erlaubten Gruppen auf und übergibt sie
   als `$restrictuserids` an `report_table`, die daraus `u.id IN (...)` mit einem
   Parameter pro Mitglied baut – unabhängig von der Bildschirmseite (`table_sql`
   paginiert nur die *Ergebniszeilen*, nicht die vorgelagerte Autorisierungsarbeit).
2. **coursereport.php:82** löst dieselbe Mitgliederliste **pro Aktivität, einmal für den
   ganzen Kurs, vor dem Paging** auf (`$diaryallowedusers`) und hält sie für die gesamte
   Dauer des Requests im Speicher – unabhängig davon, ob am Ende nur 20 Zeilen einer
   Bildschirmseite oder ein 500er-CSV-Chunk gerendert werden.
3. **summary.php** (über `insightjournal_activity_visible_to_viewer()`) löst für eine
   einzige Ja/Nein-Frage ("ist Zielperson X sichtbar?") ebenfalls alle Mitglieder auf und
   prüft per `in_array()`.

Alle drei skalieren mit **Kursgröße × Gruppenmitgliedern**, nicht mit der tatsächlich
angeforderten Datenmenge (Seite/Chunk/Einzelperson).

## Ziel / Scope

- Autorisierung wird durchgehend über **Gruppen-IDs** ausgedrückt, nie über materialisierte
  Mitglieder-ID-Listen.
- Peak Memory und Parameterzahl wachsen mit Seite/Chunk bzw. mit der Zahl erlaubter
  Gruppen (klein, durch Grouping begrenzt), nicht mit Kursgröße.
- Bestehende Autorisierungssemantik bleibt exakt erhalten (Grouping-Scoping,
  Participation-Flag, "leer heißt niemand passt", Separate-Groups-Regeln) – reines
  Performance-Refactoring, kein Verhaltenswechsel für Endnutzer.

**Nicht Teil dieser Änderung:**
- R4-04/R4-05 (Kursreport-Service-Extraktion, Template-Test-Bereinigung) – separate,
  spätere Punkte in `Fix.md`. Dieser Umbau ändert nur die Autorisierungs-Primitiven und
  ihre unmittelbaren Aufrufstellen, nicht die Struktur von `coursereport.php` als Ganzes.
- Der SQL-Vorfilter-Mechanismus von `get_enrolled_users()`/`count_enrolled_users()`
  selbst (`$restrictgroupids` in coursereport.php) – der ist bereits Gruppen-ID-basiert
  und nicht Teil des Problems; `insightjournal_coursereport_restrict_groupids()` wird nur
  intern auf die neue Primitive umgestellt.
- Formale Query-Zahl-Assertions in PHPUnit – bewusst nicht Teil des Testkonzepts (siehe
  Abnahme unten), da umgebungsabhängig/brüchig.

## Neue Primitiven (`locallib.php`)

Ersetzen `insightjournal_current_user_groups()` und
`insightjournal_current_user_group_userids()` vollständig – beide haben nach diesem
Umbau keinen Aufrufer mehr und werden entfernt (dieselbe Begründung wie R4-02: eine
ungenutzte autorisierungsrelevante Funktion ist eine latente Angriffsfläche).

```php
/**
 * Group ids belonging to the current user for a specific activity, per
 * Moodle's Separate Groups rules. Never materialises member lists -
 * groups_get_all_groups() is called with $withmembers = false.
 */
function insightjournal_current_user_allowed_groupids(stdClass $course, cm_info|stdClass $cm): array

/**
 * Whether $userid is a member of any group in $groupids - a single
 * existence query, bounded by count($groupids), never by group size.
 */
function insightjournal_groupids_contain_member(array $groupids, int $userid): bool

/**
 * The subset of $userids that are members of any group in $groupids -
 * one groups_members query bounded by count($groupids) x count($userids),
 * never by full course/group size. For coursereport.php's per-page/chunk masking.
 */
function insightjournal_groupids_members_among(array $groupids, array $userids): array
```

Alle drei behalten die bestehende Sicherheitsgarantie von
`insightjournal_current_user_groups()` (falsy `$USER->id` → `[]`, nie "jede Gruppe").

## Änderungen pro Oberfläche

### Activity-Report (`report.php` + `classes/table/report_table.php`)

- `report.php:46`: `insightjournal_current_user_allowed_groupids($course, $cm)` statt
  `insightjournal_current_user_group_userids(...)`.
- `report_table`-Konstruktor: Parameter `?array $restrictuserids` →
  `?array $restrictgroupids`. Statt `u.id IN (...)`:
  ```sql
  AND EXISTS (
      SELECT 1 FROM {groups_members} gm
       WHERE gm.userid = u.id AND gm.groupid IN (...)
  )
  ```
  Parameterzahl skaliert mit erlaubten Gruppen, nicht Mitgliedern. **Wichtig:** der
  bestehende Code ruft `get_in_or_equal($restrictuserids, SQL_PARAMS_NAMED, 'grp', true,
  -1)` auf – der letzte Parameter (`$onemptyitems = -1`) sorgt dafür, dass ein leeres
  Array ein "passt garantiert auf niemanden"-Fragment erzeugt statt eine
  `coding_exception` zu werfen (Moodles Default bei leerem IN()). Dieses `-1` muss beim
  neuen, Gruppen-ID-basierten `get_in_or_equal($restrictgroupids, ...)`-Aufruf exakt so
  erhalten bleiben – sonst wirft eine Aktivität, bei der die Gruppenrestriktion aktiv ist
  aber der Betrachter zu null erlaubten Gruppen gehört, eine Exception statt eine leere
  Tabelle zu zeigen.

### Kursreport (`coursereport.php`)

Größte strukturelle Änderung: Die pro-Aktivität-Maske `$diaryallowedusers[$diary->id]`
wird von "einmal, vor dem Paging, kursweit" zu "pro Bildschirmseite bzw. pro
CSV-Chunk, nur für die dort tatsächlich vorliegenden User-IDs" verschoben:

```php
// einmal pro Aktivität, GEcached nach groupingid (nicht nach Aktivität):
$allowedgroupidsbygroupingid = [];
$diaryallowedgroupids = []; // diary->id => int[]|null (null = unrestricted)
foreach ($diaries as $diary) {
    $cm = $activities[$diary->id];
    $context = context_module::instance($cm->id);
    if (!insightjournal_activity_group_restricted($context, $course, $cm)) {
        $diaryallowedgroupids[$diary->id] = null;
        continue;
    }
    $groupingid = (int) $cm->groupingid;
    $allowedgroupidsbygroupingid[$groupingid] ??= insightjournal_current_user_allowed_groupids($course, $cm);
    $diaryallowedgroupids[$diary->id] = $allowedgroupidsbygroupingid[$groupingid];
}

// innerhalb JEDER Bildschirm-/CSV-Chunk-Schleife, neu für die jeweiligen User-IDs:
$diaryallowedusers = [];
foreach ($diaries as $diary) {
    $allowedgroupids = $diaryallowedgroupids[$diary->id];
    $diaryallowedusers[$diary->id] = $allowedgroupids === null
        ? null
        : array_flip(insightjournal_groupids_members_among($allowedgroupids, array_keys($chunkoderparticipants)));
}
```

`insightjournal_coursereport_restrict_groupids()` (SQL-Vorfilter für
`get_enrolled_users()`) wird intern auf `insightjournal_current_user_allowed_groupids()`
umgestellt (statt `array_keys(insightjournal_current_user_groups(...))`) – funktional
identisch, aber ohne die bisher unnötig mitgeladenen Mitgliederlisten.

### Summary (`summary.php` über `insightjournal_activity_visible_to_viewer()`)

```php
function insightjournal_activity_visible_to_viewer(...): bool {
    if (!insightjournal_activity_group_restricted($context, $course, $cm)) {
        return true;
    }
    return insightjournal_groupids_contain_member(
        insightjournal_current_user_allowed_groupids($course, $cm),
        $targetuserid
    );
}
```
`summary.php` selbst und `insightjournal_visible_activities_for_user()` bleiben
unverändert – sie rufen nur diese eine Funktion pro Aktivität auf.

## Testen

- Bestehende Verhaltenstests (`report_authorization_test.php`,
  `coursereport_authorization_test.php`, `coursereport_csv_test.php`,
  `summary_authorization_test.php`) prüfen beobachtbares Autorisierungsverhalten, nicht
  die interne Repräsentation – müssen nach dem Umbau **unverändert grün** bleiben und
  dienen als Verhaltens-Absicherung des Refactors.
- `locallib_groups_test.php` wird für die drei neuen Primitiven neu geschrieben, inkl.
  aller bestehenden Randfälle (Grouping-Scoping, Participation-Flag, falsy `$USER->id`,
  zwei Gruppierungen dürfen nicht leaken, leeres Ergebnis ≠ "unrestricted").
- Neuer, gezielter Test: zwei Aktivitäten in derselben Gruppierung im Kursreport liefern
  identische `$diaryallowedusers`-Ergebnisse über den Cache hinweg (Ergebnis-, keine
  Query-Zahl-Assertion).

## Abnahme / Messplan

Kein committeter Performance-Test. Stattdessen ein **einmaliges Benchmark-Skript** in
`~/moodle-dev` (nicht Teil des Repos):

- Seed-Daten: Kurs mit 50 / 500 / 5.000 Gruppenmitgliedschaften über mehrere Groupings.
- Vergleich `main` (vor dem Umbau) vs. Fix-Branch (danach), identischer Seed: CSV-Laufzeit,
  Queryzahl (`$DB->perf_get_reads()`), Peak Memory (`memory_get_peak_usage(true)`) für
  Bildschirm und CSV getrennt.
- Xdebug aus, OPcache an, Volumes im Linux-Dateisystem (nicht `/mnt/c/...`), wie im
  Fix.md-Messhinweis vorgegeben.
- Ergebnis wird im Fix.md-Umsetzungs-Log dokumentiert (wie bei R4-01/R4-02/CR-01), nicht
  als Repo-Artefakt committet.
- Abnahmebudget: Peak Memory proportional zum 500er-Chunk statt zur Kursgröße.

## Offene technische Details (für den Implementierungsplan)

- Exakte SQL-Dialekt-Details für `EXISTS`/`get_in_or_equal()` in `report_table.php`
  (Postgres vs. MySQL/MariaDB – beide von der CI-Matrix abgedeckt, sollte sich aus
  bestehenden Mustern im Codebase ergeben, keine neue Technik).
- Ob `insightjournal_groupids_members_among()` `get_fieldset_select()` oder
  `get_records_select()` + `array_keys()` nutzt – Implementierungsdetail, funktional
  äquivalent.
- Genaue Aufteilung in Subagent-Tasks (Primitiven+Tests / report.php+report_table.php /
  coursereport.php) folgt im Plan.

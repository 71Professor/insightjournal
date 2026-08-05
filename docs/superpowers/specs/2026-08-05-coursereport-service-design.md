# R4-04 – Kursreport-Service extrahieren

**Datum:** 05.08.2026
**Bezug:** `Fix.md`, R4-04 (P1 – Stable-Gate)
**Status:** Entwurf, zur Review

## Problem

`coursereport.php` implementiert zwei fast identische Doppelschleifen (Teilnehmer ×
Aktivität) – einmal für den CSV-Export (pro 500er-Chunk), einmal für die Bildschirmseite
(pro Page). Beide lösen pro Durchlauf dieselbe Sequenz auf: Teilnehmer holen
(`get_enrolled_users()`), deren Einträge holen (`insightjournal_entries_by_diary_and_user()`),
die Gruppenautorisierungsmaske für genau diese Userids auflösen
(`insightjournal_coursereport_diary_allowed_users()`), dann pro (Teilnehmer, Aktivität)
entscheiden, ob die Zelle sichtbar ist und was sie zeigt. Nur das Ergebnis unterscheidet
sich: CSV lässt eine nicht-autorisierte Zeile ganz weg, der Bildschirm maskiert sie und
zählt trotzdem den Progress (`done`/`visiblecount`) mit.

Zusätzlich hat der finale R4-03-Review zwei kleine, verwandte Ineffizienzen zurückgestellt
und empfohlen, sie hier mitzulösen statt separat zu patchen:
1. `insightjournal_coursereport_diary_allowed_users()` fragt `groups_members` einmal pro
   restriktierter Aktivität pro Chunk ab, auch wenn mehrere Aktivitäten dieselbe
   Gruppierung (und damit identische Gruppen-IDs) teilen.
2. `coursereport.php` löst die erlaubten Gruppen-IDs zweimal pro Request auf – einmal über
   `insightjournal_coursereport_restrict_groupids()` (SQL-Vorfilter), einmal über
   `insightjournal_coursereport_allowed_groupids_by_diary()` (Zellmaskierung) – obwohl das
   Ergebnis der zweiten Funktion die erste vollständig ableiten könnte.

## Ziel / Scope

- Eine neue Service-Klasse `classes/local/coursereport_provider.php` (Namens-/Strukturmuster
  wie `classes/local/entry_manager.php`, R2-03) übernimmt Autorisierung, Paging,
  Progress-Zählung und Exportselektion. Bildschirmseite und CSV rufen exakt denselben
  geprüften Kern auf; `coursereport.php` bleibt dünner Renderer/HTTP-Controller.
- Die beiden zurückgestellten R4-03-Punkte werden durch die Extraktion selbst gelöst
  (einmalige, abgeleitete Gruppen-ID-Auflösung statt zweimal getrennt; deduplizierte
  Mitgliedschaftsabfrage pro eindeutigem Gruppen-Set statt pro Aktivität).
- **Keine Verhaltensänderung** für Endnutzer – wer heute welche Zeile/Zelle sieht, sieht sie
  identisch danach. Reines Struktur-Refactoring mit Nebeneffekt-Performance-Gewinn.

**Nicht Teil dieser Änderung:**
- R4-05 (Template-Test-Bereinigung) – betrifft `tests/report_template_test.php`
  (Activity-Report), eine andere Datei, kein Bezug zu `coursereport.php`.
- `report.php`/`report_table.php`/`summary.php` – bereits durch R4-01–R4-03 behandelt,
  nicht Teil dieses Umbaus.
- `insightjournal_coursereport_csv_row()`s eigentliches CSV-Spaltenformat/-Layout – bleibt
  unverändert, nur der `$private`-Parameter kommt hinzu (siehe unten).
- Eine allgemeine `table_sql`-artige Abstraktion für den Kursreport (der Bildschirm-Teil ist
  bewusst kein `table_sql` wie `report.php`, das ist außerhalb des Scopes hier).

## Neue Klasse: `coursereport_provider`

```php
namespace mod_insightjournal\local;

final class coursereport_provider {
    public function __construct(\stdClass $course, array $activities) { ... }

    /** Gesamtzahl passender Teilnehmer:innen, 0 wenn keine SQL-sichere Einschränkung möglich ist. */
    public function total_participants(): int { ... }

    /** Ein Ausschnitt eingeschriebener Teilnehmer:innen (Bildschirm-Page oder CSV-Chunk). */
    public function participants(int $offset, int $limit): array { ... }

    /**
     * Voll aufgelöste Zeilendaten für genau die übergebenen Teilnehmer:innen.
     *
     * @param array $participants Von participants() zurückgegebene User-Records.
     * @return array<int, array{
     *   user: \stdClass,
     *   cells: array<int, array{visible: bool, entry: ?\stdClass, completed: bool, private: bool}>,
     *   done: int,
     *   visiblecount: int,
     * }> userid => Zeilendaten, cells keyed by insightjournal-Instanz-id.
     */
    public function rows_for(array $participants): array { ... }
}
```

**Konstruktion:** löst `$diaryallowedgroupids` (instance id => allowed group ids, gecacht
je Gruppierung) über die bestehende `insightjournal_coursereport_allowed_groupids_by_diary()`
**einmal** auf. `$restrictgroupids` (der SQL-Vorfilter für `get_enrolled_users()`) wird daraus
**abgeleitet**: `null` sobald irgendeine Aktivität unrestricted ist (ein Wert ist `null`),
sonst die Vereinigung aller Nicht-null-Arrays – funktional identisch zum bisherigen
`insightjournal_coursereport_restrict_groupids()`, aber ohne eigenen zweiten Durchlauf durch
`insightjournal_current_user_allowed_groupids()`.

**`rows_for()`:** holt `insightjournal_entries_by_diary_and_user()` für die übergebenen
Userids, dann pro (Teilnehmer, Aktivität): `visible` aus der (jetzt deduplizierten)
Mitgliedschaftsauflösung. **Nur wenn `visible === true`** werden `entry`/`completed`/`private`
über das bestehende `insightjournal_coursereport_cell_state()` berechnet und gesetzt; bei
`visible === false` bleibt die Zelle bei `['visible' => false]` – `entry`/`completed`/`private`
werden nicht berechnet (kein Bedarf, da kein Renderer sie für eine nicht-autorisierte Zelle
verwendet) und dürfen von keinem Aufrufer gelesen werden. `done`/`visiblecount` werden dabei mitgezählt
(Summe der `completed`/nicht-`visible`-Zellen pro Teilnehmer) – das ist die bisher nur im
Bildschirm-Pfad vorhandene Progress-Zählung, jetzt zentral im Service. Die drei
`insightjournal_coursereport_restrict_groupids()`/`insightjournal_coursereport_allowed_groupids_by_diary()`/
`insightjournal_coursereport_diary_allowed_users()`-Locallib-Funktionen werden zu privaten
Methoden der Klasse – sie haben nach der Extraktion keinen Aufrufer mehr außerhalb.
`diary_allowed_users`'s Nachfolgermethode dedupliziert intern über das (serialisierte,
sortierte) Gruppen-ID-Array als Cache-Schlüssel: zwei Aktivitäten mit identischem Array
lösen die Mitgliedschaft nur einmal pro Chunk auf.

**Randfall, bewusst festgelegt:** `rows_for([])` (leerer Teilnehmer-Ausschnitt, z. B. letzter
Chunk exakt an der Chunkgrenze) liefert `[]`, keine Exception – matcht den bestehenden
`if (empty($chunk)) { break; }`-Guard in `coursereport.php`.

## `insightjournal_coursereport_csv_row()`: `$private` als Parameter

```php
function insightjournal_coursereport_csv_row(
    stdClass $course, int $cmid, stdClass $diary, stdClass $user,
    ?stdClass $entry, bool $private, bool $showemail
): array
```

Statt `$private = $entry && !insightjournal_entry_visible_to_teacher($entry)` intern selbst
zu berechnen, bekommt die Funktion das von `rows_for()` bereits berechnete `private`-Flag
übergeben – dieselbe Berechnung lief sonst zweimal pro Zelle (einmal in
`insightjournal_coursereport_cell_state()` innerhalb des Providers, einmal hier). Rein
mechanische Signaturänderung, Verhalten identisch.

## `coursereport.php` danach

Beide Pfade (CSV-Chunk-Schleife, Bildschirm-Page) rufen `$provider->participants(...)` und
`$provider->rows_for(...)` auf. Die eigentliche Teilnehmer-×-Aktivität-Doppelschleife mit
Autorisierungsentscheidung existiert nur noch **einmal** (in `rows_for()`) statt zweimal
fast wortgleich in `coursereport.php`. Jeder Renderer entscheidet nur noch selbst, was er
mit `cells[$diaryid]['visible'] === false` tut: CSV lässt die Zeile weg (`continue`),
Bildschirm maskiert die Zelle (`['private' => true]`) und zählt den Teilnehmer trotzdem,
solange `visiblecount > 0`. Kein `$blockallparticipants`-Sonderfall mehr im Skript selbst –
wandert in `total_participants()`/`participants()`, die dann `0`/`[]` liefern.

## Tests

- Neue `tests/local/coursereport_provider_test.php` (Namensmuster wie
  `tests/local/entry_manager_test.php`, R2-03) – PHPUnit-Tests gegen die echte Klasse:
  gemischte Gruppenmodi (Separate Groups + unrestricted im selben Kurs), private Einträge,
  Seitenwechsel (`participants()` mit verschiedenen Offsets), CSV-Chunk-Grenzen
  (Chunk-Größe kleiner als Teilnehmerzahl, exakt an der Grenze), zwei Aktivitäten mit
  geteilter Gruppierung (jetzt direkt an `rows_for()`s Ergebnis beobachtbar, nicht nur an
  der internen Cache-Struktur).
- `tests/coursereport_authorization_test.php`: die Tests, die bisher
  `insightjournal_coursereport_restrict_groupids()` direkt aufrufen, werden auf die neue
  Provider-Klasse umgestellt (die Locallib-Funktion wird privat und ist von außen nicht
  mehr aufrufbar).
- `tests/coursereport_csv_test.php`: Tests für `insightjournal_coursereport_csv_row()`
  bekommen den neuen `$private`-Parameter; Tests für `insightjournal_coursereport_cell_state()`
  bleiben unverändert (die Funktion selbst ändert sich nicht).
- Kein neuer Behat-Test – die beiden bestehenden Separate-Groups-Kursreport-Szenarien
  bleiben die Ende-zu-Ende-Absicherung, dass die Extraktion das gerenderte Ergebnis nicht
  verändert (reiner Verhaltens-Erhalt).

Deckt alle vier Fix.md-Abnahmepunkte direkt am echten Provider ab (gemischte Gruppenmodi,
private Einträge, Seitenwechsel, CSV-Chunks), nicht an duplizierter Testlogik, die den
Algorithmus nur nachbildet.

## Offene technische Details (für den Implementierungsplan)

- Exakte interne Cache-Schlüssel-Bildung für die deduplizierte Mitgliedschaftsauflösung
  (z. B. `implode(',', $groupids)` nach `sort()` vs. ein anderes stabiles Serialisierungsformat)
  – Implementierungsdetail, funktional äquivalent.
- Ob `total_participants()`/`participants()` das `$restrictgroupids`-Zwischenergebnis als
  Konstruktor-Zustand cachen oder bei jedem Aufruf neu ableiten (letzteres ist bereits
  günstig, da nur ein Array-Merge über bereits im Konstruktor aufgelöste Daten, keine neue
  DB-Abfrage) – Implementierungsdetail.
- Genaue Aufteilung in Subagent-Tasks (Provider-Klasse + Tests / `coursereport.php`-Umbau /
  Aufräumen der jetzt privaten Locallib-Funktionen und ihrer alten Tests) folgt im Plan.

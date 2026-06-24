# Prüfbericht: mod_insightjournal

**Datum:** 2026-06-24  
**Prüfer:** Senior Moodle-Entwickler (KI-gestützte Analyse)  
**Plugin:** `mod_insightjournal` v0.2.0-beta (Version 2026061703)  
**Moodle-Zielversion:** 4.5+ (requires 2024100700)  
**Umfang:** Vollständige Repository-Prüfung (Sicherheit, Korrektheit, Moodle-Konventionen, Qualität)

---

## Zusammenfassung

Das Plugin ist strukturell solide und zeigt gutes Moodle-Know-how: korrekte External-API, funktionierendes Privacy-API, CSRF-Schutz bei CSV-Downloads, saubere Completion-Logik via `custom_completion`. Es gibt jedoch **einen kritischen Bug** im Privacy-Provider, **zwei mittelschwere Korrektheitsfehler** und mehrere kleinere Mängel, die vor einer Einreichung im Plugin-Verzeichnis behoben werden sollten.

---

## Befunde

### F-01 — KRITISCH: Null-Pointer in `privacy/provider.php:101`

**Datei:** `classes/privacy/provider.php`, Zeile 101  
**Kategorie:** Korrektheit / Absturzrisiko

**Beschreibung:**  
In `export_user_data()` wird `$DB->get_record()` ohne `MUST_EXIST` aufgerufen. Wenn das `insightjournal`-Datenbankdatensatz fehlt (z. B. race condition bei Löschung), gibt `get_record()` `false` zurück. Der direkte Zugriff auf `$diary->id` in Zeile 101 führt dann in PHP 8.x zu einem `Attempt to read property "id" on false`-Fatal-Error.

```php
// Zeile 100-101 – problematisch
$diary = $DB->get_record('insightjournal', ['id' => $cm->instance]);
$entry = $DB->get_record('insightjournal_entries', ['insightjournalid' => $diary->id, ...]);
```

**Fehlerszenario:**  
Eine DSGVO-Exportanforderung tritt kurz nach dem Löschen einer Journal-Instanz ein → PHP Fatal Error → der gesamte Privacy-Export schlägt fehl.

**Fix:**
```php
$diary = $DB->get_record('insightjournal', ['id' => $cm->instance]);
if (!$diary) {
    continue;
}
$entry = $DB->get_record('insightjournal_entries', ['insightjournalid' => $diary->id, 'userid' => $userid]);
```

---

### F-02 — HOCH: Inkonsistente Zeichenzählung (JS vs. PHP)

**Dateien:** `amd/src/autosave.js:44`, `classes/external/save_entry.php:68`  
**Kategorie:** Korrektheit / UX-Fehler

**Beschreibung:**  
PHP zählt Zeichen über `core_text::strlen()` (= `mb_strlen`, Unicode-Codepunkte). JavaScript zählt mit `textarea.value.length` (UTF-16 code units). Für Zeichen außerhalb der Basic Multilingual Plane — Emoji, manche CJK-Zeichen — liefert JS doppelt so viele Einheiten wie PHP.

**Fehlerszenario:**  
`maxchars = 100`, Schüler tippt 51 Emoji → JS-Counter zeigt 102/100 und sperrt den Speichern-Button; PHP würde den Text als 51 Zeichen (< 100) akzeptieren. Der Schüler kann nicht speichern, obwohl sein Text serverseitig gültig wäre.

**Fix:**  
Verwendung der `Intl.Segmenter`-API (Moodle 4.3+ Browserziel unterstützt dies) oder `[...str].length` (Array-Spread zählt Codepunkte):

```js
var current = [...textarea.value].length;  // Unicode-Codepunkte, identisch mit mb_strlen
```

---

### F-03 — HOCH: Kursübergreifender Bericht zeigt falsche Abschluss-Logik

**Datei:** `coursereport.php:113`  
**Kategorie:** Korrektheit / semantischer Fehler

**Beschreibung:**  
Der Kurs-Bericht ermittelt den Abschlussstatus mit:

```php
$completed = $entry && trim((string)$entry->response) !== '';
```

Er prüft nur, ob *irgendeine* Antwort existiert. Ist jedoch `minchars` (Mindestzeichenzahl) konfiguriert, kann der Moodle-Completion-Status `COMPLETION_INCOMPLETE` sein, obwohl der Bericht `"Completed"` (via `get_string('completed', 'completion')`) zeigt.

**Fehlerszenario:**  
`minchars = 200`, Schüler speichert 30 Zeichen. `custom_completion::get_state()` → INCOMPLETE. Kurs-Bericht → "Abgeschlossen". Trainer trifft Entscheidungen auf falscher Datengrundlage.

**Fix (Option A – direkter Completion-Check):**
```php
$cmobj = $activities[$diary->id];
$completioninfo = new \completion_info($course);
$state = $completioninfo->get_data($cmobj, false, $user->id)->completionstate;
$completed = ($state == COMPLETION_COMPLETE);
```

**Fix (Option B – eigene Logik beibehalten, aber Sprachstring anpassen):**  
Eigene Strings `'submitted'`/`'notsubmitted'` statt `'completed'`/`'notcompleted'` verwenden, um die Spalte inhaltlich korrekt als "Antwort abgegeben" zu kennzeichnen.

---

### F-04 — MITTEL: `delete_instance()` ist nicht transaktional

**Datei:** `lib.php:91-99`  
**Kategorie:** Datenintegrität

**Beschreibung:**  
`insightjournal_delete_instance()` löscht zuerst alle Einträge, dann den Hauptdatensatz. Wenn das zweite DELETE fehlschlägt (z. B. Datenbankfehler), ist die Journal-Instanz noch vorhanden, aber alle Schülerantworten sind unwiederbringlich gelöscht.

```php
$DB->delete_records('insightjournal_entries', ['insightjournalid' => $diary->id]);  // erst
$DB->delete_records('insightjournal', ['id' => $diary->id]);                        // dann
```

**Fix:**
```php
function insightjournal_delete_instance($id) {
    global $DB;
    if (!$diary = $DB->get_record('insightjournal', ['id' => $id])) {
        return false;
    }
    $transaction = $DB->start_delegated_transaction();
    $DB->delete_records('insightjournal_entries', ['insightjournalid' => $diary->id]);
    $DB->delete_records('insightjournal', ['id' => $diary->id]);
    $transaction->allow_commit();
    return true;
}
```

---

### F-05 — MITTEL: Falsche Fehlermeldung bei `minchars > maxchars`

**Datei:** `mod_form.php:95`  
**Kategorie:** UX / Konventionen

**Beschreibung:**  
Wenn `minchars > maxchars` konfiguriert wird, zeigt das Formular `get_string('err_numeric', 'form')` — "Dieser Wert ist keine Zahl". Der tatsächliche Fehler ist jedoch ein Wertebereichsproblem. Die generische Zahlenmeldung ist für Trainer irreführend.

**Fix:**  
Einen eigenen Fehlerstring hinzufügen:

```php
// lang/en/insightjournal.php
$string['err_mingtmax'] = 'Minimum characters cannot exceed maximum characters.';

// mod_form.php
if ($maxchars > 0 && $minchars > $maxchars) {
    $errors['minchars'] = get_string('err_mingtmax', 'insightjournal');
}
```

---

### F-06 — MITTEL: `fullname()` im Report inkonsistent (Anzeige vs. CSV)

**Datei:** `report.php:98-119`  
**Kategorie:** Korrektheit / inkonsistentes Verhalten

**Beschreibung:**  
In der HTML-Tabellenansicht werden phonetische Namen und Alternativnamen absichtlich auf leere Strings gesetzt, sodass `fullname()` sie ignoriert. Im CSV-Export werden dieselben Felder korrekt aus der Datenbankabfrage übernommen. Bei Moodle-Installationen mit aktiviertem `fullnamedisplay`-Format (z. B. `"firstnamephonetic lastname"`) differieren Anzeigename und CSV-Name für betroffene Nutzer.

```php
// Zeile 98-104 – Anzeige: phonetische Felder leer
$user = (object)[
    'firstnamephonetic' => '',  // bewusst leer
    'lastnamephonetic'  => '',
    ...
];

// Zeile 70-74 – CSV: phonetische Felder korrekt befüllt
$user = (object)['firstnamephonetic' => $entry->firstnamephonetic, ...];
```

**Fix:**  
Dieselbe `$user`-Objekt-Konstruktion für Anzeige und CSV verwenden (phonetische Felder immer aus `$entry` befüllen).

---

### F-07 — NIEDRIG: Skalierbarkeit der Suche in `report.php`

**Datei:** `report.php:50-61`  
**Kategorie:** Performance

**Beschreibung:**  
Die Teilnehmersuche lädt **alle** Einträge eines Journals in den PHP-Speicher und filtert dann in PHP. Bei großen Kursen (> 1000 Teilnehmer) führt dies zu hohem Speicherverbrauch.

**Fix:**  
`LIKE`-Filter direkt in der SQL-Abfrage ergänzen (mit `$DB->sql_like()` für Datenbankportabilität).

---

### F-08 — NIEDRIG: Fehlender Sprachstring in Deutsch

**Datei:** `lang/de/insightjournal.php`  
**Kategorie:** i18n

**Beschreibung:**  
Der String `pluginadministration` ist in der englischen Sprachdatei vorhanden (`'Insight Journal administration'`), fehlt aber in `lang/de/insightjournal.php`. Moodle fällt automatisch auf Englisch zurück, aber die Übersetzung ist unvollständig.

**Fix:**
```php
$string['pluginadministration'] = 'Insight-Journal-Administration';
```

---

### F-09 — NIEDRIG: PHPDoc fehlt bei `validation()`

**Datei:** `mod_form.php:83`  
**Kategorie:** Moodle-Konventionen / Code-Checker

**Beschreibung:**  
Die überschriebene `validation()`-Methode hat keinen vollständigen PHPDoc-Block mit `@param` und `@return`. Der Moodle Code Checker (PHPUnit) meldet dies als Fehler, was eine Plugin-Directory-Einreichung blockieren kann.

**Fix:**
```php
/**
 * Validates the form data.
 *
 * @param array $data Submitted form data.
 * @param array $files Submitted files.
 * @return array Validation errors, keyed by field name.
 */
public function validation($data, $files) {
```

---

### F-10 — INFO: AMD-Legacy-Muster statt ES-Module

**Datei:** `amd/src/autosave.js`, `amd/src/summary.js`  
**Kategorie:** Zukunftssicherheit

**Beschreibung:**  
Beide AMD-Dateien verwenden `define(['core/ajax', ...], function(...) { ... })`. Ab Moodle 4.3 bevorzugt das Framework ES-Module (`export default { ... }`). Das Legacy-AMD-Muster wird noch unterstützt, aber neue Plugins sollten das ES-Modul-Format verwenden, um build-unabhängig zu arbeiten (kein Grunt-Build der `amd/build/`-Dateien erforderlich).

---

## Positive Aspekte

| Bereich | Bewertung |
|---------|-----------|
| External-API-Sicherheit | Gut: `validate_parameters()`, `validate_context()`, `require_login()`, `require_capability()` in korrekter Reihenfolge |
| CSRF-Schutz | Gut: CSV-Download-Links enthalten `sesskey`, serverseitig `confirm_sesskey()` |
| XSS-Schutz | Gut: `{{response}}` in Mustache-Templates (doppelte Klammern = HTML-Escape), `{{{prompt}}}` nur nach `format_text()` |
| Privacy-API | Vollständig: alle vier Löschmethoden, `get_users_in_context`, `get_contexts_for_userid` |
| Completion-Logik | Korrekt: `COMPLETION_UNKNOWN` statt hardkodiertem `COMPLETION_COMPLETE`, `minchars` wird in `custom_completion.php` respektiert |
| Backup/Restore | Vollständig und korrekt implementiert inkl. `userinfo`-Einstellung |
| DB-Schema | Sauber: UNIQUE-Index auf `(insightjournalid, userid)` verhindert Duplikate |
| CSV-Injection | Korrekt abgesichert mit `insightjournal_csv_value()` |
| Testsuite | Vorhanden mit sinnvollen Regressionstests für Completion-Logik |
| Sprachstrings | Vollständig für EN und DE (bis auf F-08) |

---

## Priorisierte Aufgabenliste für Plugin-Directory-Einreichung

| Priorität | Fund | Datei | Aufwand |
|-----------|------|-------|---------|
| 1 | F-01: Null-Pointer Privacy-Provider | `classes/privacy/provider.php:100` | Klein |
| 2 | F-02: Zeichenzählung JS vs. PHP | `amd/src/autosave.js:44` | Klein |
| 3 | F-03: Falsche Abschluss-Logik Kurs-Bericht | `coursereport.php:113` | Mittel |
| 4 | F-04: Nicht-transaktionales delete_instance | `lib.php:91` | Klein |
| 5 | F-05: Falscher Fehlermeldung-String | `mod_form.php:95` | Klein |
| 6 | F-06: fullname()-Inkonsistenz Report/CSV | `report.php:98` | Klein |
| 7 | F-08: Fehlender DE-String | `lang/de/insightjournal.php` | Trivial |
| 8 | F-09: PHPDoc validation() | `mod_form.php:83` | Trivial |
| 9 | F-07: Suchskalierung | `report.php:50` | Mittel |
| 10 | F-10: AMD-Muster modernisieren | `amd/src/*.js` | Größer |

---

*Bericht erstellt mit Claude Code (claude-sonnet-4-6) am 2026-06-24.*

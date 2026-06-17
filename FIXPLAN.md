# Fixplan: InsightJournal – Community Release

---

## 1. Privilege Escalation: canviewall-Logik in summary.php

**Problem**
Die `OR`-Verkettung über alle Journal-Instanzen des Kurses führt dazu, dass eine Lehrperson,
die in Journal A `viewall` besitzt, automatisch auch alle Einträge aus Journal B lesen kann –
unabhängig davon, ob sie dort berechtigt ist.

**Ort**
`summary.php`, Zeile 26
```php
$canviewall = $canviewall || has_capability('mod/insightjournal:viewall', $modulecontext);
```

**Lösung**
`canviewall` darf kein globaler Boolean sein. Stattdessen die Capability **pro Instanz** prüfen,
bevor deren Einträge in die Query aufgenommen werden. Der `$cms`-Array enthält nur Instanzen,
für die der aktuelle Nutzer tatsächlich `viewall` besitzt:

```php
$viewableInstances = [];
foreach ($modinfo->get_instances_of('insightjournal') as $cm) {
    if (!$cm->uservisible) continue;
    $modulecontext = context_module::instance($cm->id);
    if (!has_capability('mod/insightjournal:view', $modulecontext)) continue;
    if ($userid != $USER->id && !has_capability('mod/insightjournal:viewall', $modulecontext)) continue;
    $viewableInstances[$cm->instance] = $cm;
}
```

Damit schrumpft der `$diaryids`-Array auf die tatsächlich erlaubten Instanzen,
bevor die SQL-Query ausgeführt wird.

---

## 2. Stored XSS Root Cause: PARAM_RAW in der externen API

**Problem**
`save_entry.php` deklariert `response` als `PARAM_RAW` im `external_value`. Das Web-Service-Framework
lässt damit beliebiges HTML durch. `clean_param(..., PARAM_TEXTAREA)` auf Zeile 31 korrigiert nur
die UTF-8-Kodierung, entfernt aber keine Tags. HTML-Payloads landen verbatim in der Datenbank.

**Ort**
`classes/external/save_entry.php`, Zeile 12 und 31
```php
'response' => new \external_value(PARAM_RAW, 'Learner response'),
// ...
$response = clean_param($params['response'], PARAM_TEXTAREA);
```

**Lösung**
Den Typ auf `PARAM_TEXT` ändern (entfernt alle HTML-Tags). Falls Rich-Text gewünscht ist,
`PARAM_CLEANHTML` plus explizites `clean_text()` verwenden:

```php
// In execute_parameters():
'response' => new \external_value(PARAM_TEXT, 'Learner response'),

// In execute():
$response = clean_param($params['response'], PARAM_TEXT);
```

Wenn Rich-Text später benötigt wird: `PARAM_CLEANHTML` + `format_text()` beim Lesen –
aber niemals `PARAM_RAW` speichern.

---

## 3. CSRF: CSV-Export in report.php ohne sesskey

**Problem**
Der CSV-Export (`?download=csv`) wird per einfachem GET ausgelöst. Jeder Link oder `<img>`-Tag
kann einen authentifizierten Lehrer zwingen, die CSV aller Schülereinträge herunterzuladen –
ohne Interaktion des Nutzers.

**Ort**
`report.php`, Zeile 35
```php
if ($download === 'csv') {
    require_capability('mod/insightjournal:export', $context);
```

**Lösung**
Vor dem Export `confirm_sesskey()` aufrufen und den Download-Link im Template mit `sesskey()` versehen:

```php
// report.php
if ($download === 'csv') {
    require_capability('mod/insightjournal:export', $context);
    confirm_sesskey();   // <-- hinzufügen
    // ... restlicher Export-Code
}

// Beim Aufbau der Download-URL:
'downloadurl' => (new moodle_url('/mod/insightjournal/report.php', [
    'id'       => $cm->id,
    'search'   => $search,
    'download' => 'csv',
    'sesskey'  => sesskey(),   // <-- hinzufügen
]))->out(false),
```

---

## 4. CSRF: CSV-Export in coursereport.php ohne sesskey

**Problem**
Identisches Problem wie #3, aber für den kursweiten Export über alle Journal-Instanzen.
Größere Datenmenge, gleiche Angriffsfläche.

**Ort**
`coursereport.php`, Download-Block

**Lösung**
Gleiche Lösung wie #3: `confirm_sesskey()` im Download-Pfad, `sesskey()` im Download-Link.

---

## 5. XSS: Triple-Braces in Mustache-Templates

**Problem**
`view.mustache` und `entry_card.mustache` geben den Prompt mit `{{{prompt}}}` aus. Das deaktiviert
Mustaches HTML-Escaping vollständig. Die einzige Absicherung ist `format_text()` auf der PHP-Seite –
fällt der Moodle HTML-Purifier aus oder wird umgangen, ist XSS möglich.

**Ort**
`templates/view.mustache`, Zeile 5
`templates/entry_card.mustache`, Zeile 4

**Lösung**
`format_text()` sanitiert den Prompt bereits auf der PHP-Seite zu sicherem HTML.
Das Template muss dieses bereinigte HTML unescaped ausgeben – Triple-Braces sind hier
**technisch korrekt**. Wichtig ist, dass:

1. `format_text()` **immer** aufgerufen wird, bevor der Prompt ins Template-Kontext-Array gelangt.
2. Die Schüler-`response` **immer** mit `{{response}}` (Double-Braces) ausgegeben wird – niemals Triple-Braces, da sie reiner Text ist.

Kommentare in beiden Templates zur Absicherung für zukünftige Entwickler:

```mustache
{{! prompt is pre-sanitised via format_text() in PHP – triple braces intentional }}
{{{prompt}}}
{{! response is raw user text – always use double braces, never triple }}
{{response}}
```

---

## 6. Installationsfehler auf MySQL/MariaDB: Doppelter Index-Name in install.xml

**Problem**
Die Tabelle `insightjournal` deklariert gleichzeitig einen `FOREIGN KEY` namens `course` und
einen `INDEX` namens `course`. MySQL erstellt für FK automatisch einen Index mit demselben Namen.
XMLDB versucht dann einen zweiten Index anzulegen → `Duplicate key name`-Fehler →
Plugin lässt sich auf MySQL/MariaDB **nicht installieren**.

**Ort**
`db/install.xml`, Zeilen 21–24
```xml
<KEY NAME="course" TYPE="foreign" FIELDS="course" REFTABLE="course" REFFIELDS="id"/>
...
<INDEX NAME="course" UNIQUE="false" FIELDS="course"/>
```

**Lösung**
Den redundanten `<INDEX NAME="course">` entfernen. Der FK-Constraint erzeugt auf MySQL bereits
einen Index; auf PostgreSQL legt XMLDB ihn separat an:

```xml
<KEYS>
    <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
    <KEY NAME="course" TYPE="foreign" FIELDS="course" REFTABLE="course" REFFIELDS="id"/>
</KEYS>
<INDEXES>
    <!-- Index "course" entfernt – wird durch den FK-Constraint auf MySQL automatisch erzeugt -->
</INDEXES>
```

---

## 7. Restore-Fehler: Fehlendes unset($data->id) und fehlende insightjournalid-Prüfung

**Problem**
In `process_insightjournal_entry` trägt `$data->id` noch den Wert aus dem Backup, wenn
`insert_record()` aufgerufen wird. Außerdem wird `$data->insightjournalid` auf `0` gesetzt,
wenn das Parent-Mapping nicht verfügbar ist – ohne Prüfung dagegen. Beides verletzt die
Moodle-Restore-Konvention und kann zu Datenbankfehlern führen.

**Ort**
`backup/moodle2/restore_insightjournal_stepslib.php`, Zeilen 27–30
```php
$data->insightjournalid = $this->get_new_parentid('insightjournal');
$data->userid = $this->get_mappingid('user', $data->userid);
if ($data->userid) {
    $DB->insert_record('insightjournal_entries', $data);
```

**Lösung**
```php
protected function process_insightjournal_entry($data) {
    global $DB;
    $data = (object)$data;
    unset($data->id);  // <-- hinzufügen: alte Backup-ID entfernen
    $data->insightjournalid = $this->get_new_parentid('insightjournal');
    $data->userid = $this->get_mappingid('user', $data->userid);
    if ($data->userid && $data->insightjournalid) {  // <-- insightjournalid prüfen
        $DB->insert_record('insightjournal_entries', $data);
    }
}
```

---

## 8. Veraltete Completion-API: kein custom_completion.php für Moodle 4.3+

**Problem**
`insightjournal_get_completion_state()` in `lib.php` ist die veraltete Pre-4.3-Completion-API.
Moodle 4.3+ erwartet eine `classes/completion/custom_completion.php`-Klasse. Fehlende Klasse →
Deprecation-Notices bei jeder Completion-Auswertung. Zusätzlich fehlt
`insightjournal_get_completion_active_rule_descriptions()`, weshalb Schüler keinen Hinweistext sehen.

**Ort**
`lib.php`, Zeile 74 (alter Callback)
Fehlende Datei: `classes/completion/custom_completion.php`

**Lösung**
Neue Klasse anlegen unter `classes/completion/custom_completion.php`:

```php
<?php
namespace mod_insightjournal\completion;

use core_completion\activity_custom_completion;

class custom_completion extends activity_custom_completion {
    public function get_state(string $rule): int {
        global $DB;
        $this->validate_rule($rule);
        $diary = $DB->get_record('insightjournal', ['id' => $this->cm->instance],
            'id,minchars,completionentries', MUST_EXIST);
        if (empty($diary->completionentries)) {
            return COMPLETION_INCOMPLETE;
        }
        $entry = $DB->get_record('insightjournal_entries',
            ['insightjournalid' => $diary->id, 'userid' => $this->userid], 'response');
        if (!$entry || trim((string)$entry->response) === '') {
            return COMPLETION_INCOMPLETE;
        }
        return \core_text::strlen(trim($entry->response)) >= (int)$diary->minchars
            ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    public static function get_defined_custom_rules(): array {
        return ['completionentries'];
    }
}
```

In `lib.php` ergänzen:

```php
function insightjournal_get_completion_active_rule_descriptions($cm) {
    if (!empty($cm->customdata['customcompletionrules']['completionentries'])) {
        return [get_string('completionentries', 'insightjournal')];
    }
    return [];
}
```

Den alten Callback `insightjournal_get_completion_state()` mit `@deprecated since Moodle 4.3`
annotieren oder entfernen, sobald die Mindestanforderung auf 4.3 angehoben wird.

---

## 9. Fehlende Kurs-Reset-Funktion

**Problem**
Moodle sucht beim Kurs-Reset nach `insightjournal_reset_course_userdata()`. Da sie fehlt,
überspringt Moodle das Plugin kommentarlos. Einträge des Vorjahrgangs bleiben erhalten –
Completion-Zustand ist falsch, DSGVO-Pflichten bleiben offen.

**Ort**
`lib.php` – Funktion existiert nicht

**Lösung**
Folgende Funktionen in `lib.php` ergänzen:

```php
function insightjournal_reset_userdata_form_definition(&$mform) {
    $mform->addElement('checkbox', 'reset_insightjournal_entries',
        get_string('deleteallentries', 'insightjournal'));
}

function insightjournal_reset_course_userdata($data) {
    global $DB;
    $status = [];
    if (!empty($data->reset_insightjournal_entries)) {
        $instances = $DB->get_records('insightjournal', ['course' => $data->courseid], '', 'id');
        foreach ($instances as $instance) {
            $DB->delete_records('insightjournal_entries', ['insightjournalid' => $instance->id]);
        }
        $status[] = [
            'component' => get_string('modulename', 'insightjournal'),
            'item'      => get_string('deleteallentries', 'insightjournal'),
            'error'     => false,
        ];
    }
    return $status;
}
```

Lang-String `deleteallentries` in `lang/en/insightjournal.php` und `lang/de/insightjournal.php` ergänzen.

---

## 10. DSGVO-Lücke: export_user_data() exportiert responseformat nicht

**Problem**
`get_metadata()` deklariert `responseformat` als personenbezogenes Datum.
`export_user_data()` exportiert dieses Feld aber nicht. Das DSGVO-Auskunftsexport ist damit
vertragswidrig bezüglich des eigenen Metadaten-Schemas.

**Ort**
`classes/privacy/provider.php`, `export_user_data()`-Methode

**Lösung**
Das Feld `responseformat` zum exportierten Objekt hinzufügen:

```php
$data = (object)[
    'activity'       => format_string($record->activityname),
    'response'       => $record->response,
    'responseformat' => $record->responseformat,  // <-- hinzufügen
    'timecreated'    => transform::datetime($record->timecreated),
    'timemodified'   => transform::datetime($record->timemodified),
];
```

---

## Weitere Pflichtpunkte für die Plugin-Directory-Einreichung

Diese Punkte sind kein Sicherheitsproblem, **blockieren aber die Einreichung** beim Moodle Plugin Directory:

| # | Problem | Ort | Lösung |
|---|---------|-----|--------|
| A | Fehlender vollständiger GPL-3.0-Header | Alle `.php`-Dateien | Standard-Moodle-GPL-Block in jede Datei einfügen (`// This file is part of Moodle - https://moodle.org/ // Moodle is free software: ...`) |
| B | Fehlende PHPDoc-Blöcke auf public functions | `lib.php`, `locallib.php`, alle `classes/` | `@param`, `@return` und kurze Beschreibung ergänzen – `phpcs --standard=moodle` muss sauber durchlaufen |
| C | `manageentries`-Capability deklariert aber nie geprüft | `db/access.php`, Zeile 41 | Entweder Capability entfernen oder konsequent per `require_capability('mod/insightjournal:manageentries', ...)` durchsetzen |

---

## Prioritätenübersicht

| Priorität | # | Datei | Kategorie |
|-----------|---|-------|-----------|
| Kritisch | 1 | `summary.php:26` | Sicherheit – Privilege Escalation |
| Kritisch | 2 | `save_entry.php:12` | Sicherheit – Stored XSS Root Cause |
| Hoch | 3 | `report.php:35` | Sicherheit – CSRF |
| Hoch | 4 | `coursereport.php` | Sicherheit – CSRF |
| Hoch | 6 | `db/install.xml:21` | Korrektheit – Installationsfehler MySQL |
| Hoch | 7 | `restore_insightjournal_stepslib.php:29` | Korrektheit – Restore-Fehler |
| Mittel | 5 | `templates/view.mustache:5` | Sicherheit – XSS-Risiko |
| Mittel | 8 | `lib.php:74` | Kompatibilität – Moodle 4.3+ |
| Mittel | 9 | `lib.php` | Funktionalität – Kurs-Reset |
| Mittel | 10 | `privacy/provider.php:56` | DSGVO – unvollständiger Export |
| Pflicht | A | Alle `.php`-Dateien | Plugin Directory – GPL-Header |
| Pflicht | B | `lib.php`, `classes/` | Plugin Directory – PHPDoc |
| Pflicht | C | `db/access.php:41` | Aufräumen – tote Capability |

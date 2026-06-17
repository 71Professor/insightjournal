# Prüfprotokoll – mod_insightjournal

**Datum:** 2026-06-17
**Zielplattform:** Moodle 4.5 oder höher
**Umfang:** Vollständige statische Code-Prüfung aller PHP-, Template-, AMD-, DB- und Sprachdateien.
**Hinweis:** Keine PHP-CLI in der Umgebung verfügbar → keine automatische Syntax-/`phpcs`-Prüfung möglich; alle Befunde stammen aus manueller Code-Analyse.

---

## A. Status der bereits dokumentierten Punkte (FIXPLAN.md)

Alle 10 Sicherheits-/Korrektheitspunkte und die Pflichtpunkte A/B/C aus `FIXPLAN.md` wurden im Code **verifiziert und korrekt umgesetzt**:

| # | Thema | Datei | Status |
|---|-------|-------|--------|
| 1 | Privilege Escalation `canviewall` | `summary.php` | ✅ pro-Instanz-Prüfung (`$viewallcms`), beim Fremdzugriff nur `$viewallcms` |
| 2 | Stored XSS / `PARAM_RAW` | `save_entry.php` | ✅ `PARAM_TEXT` in Parametern und `clean_param` |
| 3 | CSRF CSV-Export | `report.php` | ✅ `confirm_sesskey()` + `sesskey()` im Link |
| 4 | CSRF CSV-Export (kursweit) | `coursereport.php` | ✅ `confirm_sesskey()` + `sesskey()` im Link |
| 5 | Triple-Braces XSS | `view.mustache`, `entry_card.mustache` | ✅ `{{{prompt}}}` mit erklärendem Kommentar, `{{response}}` doppelt |
| 6 | Doppelter Index `course` | `db/install.xml` | ✅ redundanter Index entfernt |
| 7 | Restore `unset($data->id)` | `restore_..._stepslib.php` | ✅ `unset` + `insightjournalid`-Prüfung |
| 8 | Completion-API 4.3+ | `classes/completion/custom_completion.php` | ✅ Klasse vorhanden, Callback-Beschreibung in `lib.php` |
| 9 | Kurs-Reset | `lib.php` | ✅ `*_reset_userdata_form_definition` + `*_reset_course_userdata` |
| 10 | DSGVO `responseformat`-Export | `privacy/provider.php` | ✅ Feld wird exportiert |
| A | GPL-Header | alle `.php` | ✅ vorhanden |
| B | PHPDoc | `lib.php`, `classes/` | ✅ weitgehend vorhanden |
| C | tote `manageentries`-Capability | `db/access.php` | ✅ entfernt (nicht mehr deklariert) |

---

## B. Neue Befunde aus dieser Prüfung

### 🔴 B1 – KRITISCH: Web-Service `save_entry` bricht zur Laufzeit ab (`$CFG` außerhalb des Scopes)

**Ort:** `classes/external/save_entry.php`, Zeile 29

```php
require_once($CFG->libdir . '/externallib.php');
```

**Problem**
Diese Datei wird über den Moodle-Klassen-Autoloader (`core_component::classloader`) geladen.
Autoloader-Includes laufen **im Methoden-Scope, nicht im globalen Scope** – dort existiert `$CFG`
**nicht**. `$CFG->libdir` ist also `null`, der Ausdruck wird zu `require_once('/externallib.php')`,
was zu einem **Fatal Error** führt, sobald der Web-Service `mod_insightjournal_save_entry` aufgerufen wird.
Damit ist die zentrale Funktion des Plugins – das **Speichern/Autosave von Einträgen** – defekt.

Zusätzlich verwendet die Klasse die **veralteten globalen** Klassen `\external_api`,
`\external_function_parameters`, `\external_value`, `\external_single_structure`. Diese wurden
in Moodle 4.2 in den Namespace `core_external\` verschoben und sind im globalen Namespace nur
noch über `lib/externallib.php` als Deprecated-Aliase verfügbar.

**Lösung (empfohlen, 4.5-konform)** – `core_external\`-Klassen verwenden und den `require_once` entfernen:

```php
namespace mod_insightjournal\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;

class save_entry extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'response' => new external_value(PARAM_TEXT, 'Learner response'),
        ]);
    }
    // ... external_value/external_single_structure analog ohne führenden Backslash
}
```

Die `require_once($CFG->libdir . '/externallib.php');`-Zeile ersatzlos streichen; die
`core_external\`-Klassen werden automatisch geladen. (Notfall-Minimalfix: `global $CFG;`
vor der Zeile – behebt nur den Fatal Error, bleibt aber auf veralteten Klassen.)

---

### 🟠 B2 – MITTEL: Completion-Regeln ohne `get_suffix()` (Moodle 4.4+/4.5)

**Ort:** `mod_form.php`, `add_completion_rules()` (Z. 65) und `completion_rule_enabled()` (Z. 74)

**Problem**
Seit Moodle 4.4 müssen Completion-Regel-Formularelemente das Suffix aus `$this->get_suffix()`
tragen, damit die Seite **„Standardmäßige Aktivitätsabschlüsse“** (Bulk-Bearbeitung mehrerer
Module) ohne doppelte Element-IDs funktioniert. Ohne Suffix erscheinen auf 4.5 `debugging`-Meldungen,
und die Bulk-Default-Completion kann fehlerhaft sein.

**Lösung**
```php
public function add_completion_rules() {
    $mform = $this->_form;
    $suffix = $this->get_suffix();
    $name = 'completionentries' . $suffix;
    $mform->addElement('checkbox', $name, get_string('completionentriesgroup', 'insightjournal'),
        get_string('completionentries', 'insightjournal'));
    $mform->setDefault($name, 1);
    return [$name];
}

public function completion_rule_enabled($data) {
    $suffix = $this->get_suffix();
    return !empty($data['completionentries' . $suffix]);
}
```
(Die DB-/`custom_completion`-Seite bleibt unverändert – das Suffix betrifft nur das Formular.)

---

### 🟠 B3 – MITTEL: `version.php` fordert Moodle 4.1, Ziel ist aber 4.5

**Ort:** `version.php`, Zeile 28

```php
$plugin->requires = 2022112800; // Moodle 4.1+.
```

**Problem**
Das Plugin nutzt die Completion-API von Moodle 4.3+ (`classes/completion/custom_completion.php`)
und soll laut Vorgabe auf **Moodle 4.5+** laufen. Die Mindestversion `2022112800` (4.1) ist
damit inkonsistent: Auf 4.1/4.2 würde das Plugin installierbar sein, dort aber teils auf veraltete
Pfade zurückfallen.

**Lösung** – Mindestversion auf Moodle 4.5 anheben:
```php
$plugin->requires = 2024100700; // Moodle 4.5.
```

---

### 🟡 B4 – GERING: Veralteter Completion-Callback noch vorhanden

**Ort:** `lib.php`, Zeile 157 – `insightjournal_get_completion_state()`

**Problem**
Mit vorhandener `custom_completion`-Klasse und `FEATURE_COMPLETION_HAS_RULES` nutzt Moodle 4.3+
ausschließlich die neue Klasse. Die Alt-Funktion ist toter Code und kann je nach Version eine
Deprecation-`debugging`-Meldung auslösen. Da die Mindestversion ohnehin auf 4.5 angehoben wird
(B3), kann die Funktion **entfernt** werden.

---

### 🟡 B5 – GERING: Kein Aktivitäts-Icon (`pix/monologo.svg`)

**Ort:** fehlendes Verzeichnis `pix/`

**Problem**
Es existiert kein `pix/monologo.svg` (bzw. `pix/icon.svg`). Moodle 4.x zeigt dann ein generisches
Standard-Icon. Für ein konsistentes Erscheinungsbild und für die Einreichung im Plugin-Directory
sollte ein `pix/monologo.svg` ergänzt werden.

---

### 🟡 B6 – GERING: `FEATURE_MOD_PURPOSE` nicht deklariert

**Ort:** `lib.php`, `insightjournal_supports()` (Z. 33)

**Problem**
Ohne `case FEATURE_MOD_PURPOSE: return MOD_PURPOSE_...;` wird die Aktivität im Aktivitätsauswahl-
Dialog von Moodle 4.x nicht kategorisiert/eingefärbt. Kein Fehler, aber empfohlen
(z. B. `MOD_PURPOSE_COLLABORATION` oder `MOD_PURPOSE_ASSESSMENT`).

---

## C. Geprüft und unauffällig

- **`report.php` / `coursereport.php`:** CSV-Export mit `confirm_sesskey()`, CSV-Injection-Schutz über `insightjournal_csv_value()`, Capability-Prüfung pro Instanz – korrekt.
- **`db/install.xml`:** Schlüssel/Indizes konsistent, Unique-Index `insight_user` sinnvoll, keine doppelten Namen mehr.
- **`db/upgrade.php`:** `field_exists`-Guard vor `add_field`, korrekter Savepoint.
- **`privacy/provider.php`:** Implementiert `metadata`, `plugin\provider`, `core_userlist_provider` vollständig inkl. Lösch-/Exportpfaden.
- **Backup/Restore:** Struktur, `annotate_ids('user',...)`, `annotate_files`, `unset($data->id)`, Parent-Mapping – korrekt.
- **Templates:** `{{{prompt}}}` bewusst (PHP-seitig `format_text()`), `{{response}}` escaped; Such-Form ist GET/read-only (kein CSRF-Bedarf).
- **AMD:** `amd/src/*` und passende `amd/build/*.min.js` vorhanden.
- **Sprachdateien (en/de):** Alle in Code/Templates referenzierten String-Keys sind in **beiden** Sprachen definiert; keine fehlenden Keys gefunden.

---

## D. Priorisierte Zusammenfassung

| Priorität | ID | Datei | Kategorie |
|-----------|----|-------|-----------|
| 🔴 Kritisch | B1 | `classes/external/save_entry.php:29` | Laufzeitfehler – Speichern/Autosave defekt |
| 🟠 Mittel | B2 | `mod_form.php:65/74` | Kompatibilität – Completion-Suffix (4.4+) |
| 🟠 Mittel | B3 | `version.php:28` | Kompatibilität – Mindestversion vs. Ziel 4.5 |
| 🟡 Gering | B4 | `lib.php:157` | Aufräumen – veralteter Completion-Callback |
| 🟡 Gering | B5 | `pix/` | Fehlendes Aktivitäts-Icon |
| 🟡 Gering | B6 | `lib.php:33` | `FEATURE_MOD_PURPOSE` nicht gesetzt |

**Fazit:** Die in `FIXPLAN.md` beschriebenen Sicherheits- und Korrektheitsmängel sind behoben.
Vor dem Produktivbetrieb auf Moodle 4.5+ ist jedoch **B1 zwingend** zu fixen (sonst funktioniert
das Speichern von Einträgen nicht), gefolgt von **B2** und **B3** für saubere 4.5-Kompatibilität.

---

## E. Behebungsstatus (2026-06-17)

Alle sechs Befunde wurden umgesetzt:

| ID | Fix |
|----|-----|
| ✅ B1 | `save_entry.php` auf `core_external\`-Namespace umgestellt (`use`-Imports), defekte `require_once($CFG->libdir...)`-Zeile und `MOODLE_INTERNAL`-Check entfernt; PHPDoc ergänzt. |
| ✅ B2 | `mod_form.php`: `add_completion_rules()` und `completion_rule_enabled()` nutzen jetzt `$this->get_suffix()`. |
| ✅ B3 | `version.php`: `$plugin->requires = 2024100700;` (Moodle 4.5). |
| ✅ B4 | `lib.php`: veraltete `insightjournal_get_completion_state()` entfernt. |
| ✅ B5 | `pix/monologo.svg` angelegt. |
| ✅ B6 | `lib.php`: `FEATURE_MOD_PURPOSE => MOD_PURPOSE_COLLABORATION` in `insightjournal_supports()`. |

**Verifikation:** Keine verwaisten Referenzen auf die entfernte Funktion und keine
`externallib`-/global-`external_*`-Verwendung mehr im Code (per `grep` bestätigt).
Eine PHP-Syntaxprüfung (`php -l`) war mangels PHP-CLI in der Umgebung nicht möglich –
empfohlen vor dem Deployment auf einer Moodle-Instanz.

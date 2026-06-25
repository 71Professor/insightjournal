# mod_insightjournal – Insight Journal für Moodle

**Moodle Activity Module · Version 0.2.0-beta · Juni 2026**

> **Zweck:** Trainer/innen legen pro Kursabschnitt eine Insight-Journal-Aktivität mit einem gezielten Impuls an. Lernende schreiben ihre Antwort direkt in Moodle, können sie jederzeit überarbeiten und am Kursende eine persönliche Gesamtübersicht drucken. Trainer/innen sehen alle Einträge und können sie als CSV exportieren.

---

## 1  Was unterscheidet Insight Journal von Forum oder Tagebuch?

| Merkmal | Insight Journal | Moodle-Forum | Moodle-Tagebuch |
|---|---|---|---|
| Einträge pro Aktivität | **1 (ein Impuls, eine Antwort)** | beliebig viele Beiträge | beliebig viele Einträge |
| Fokus | ein konkreter Reflexionsimpuls | Diskussion | freies Schreiben |
| Antworten für andere sichtbar? | Nein | Ja (standardmäßig) | Nein |
| Kursweite Gesamtübersicht | **Ja – druckbar/PDF** | Nein | Nein |
| Kursfortschrittsbericht (alle Aktivitäten) | **Ja** | Nein | Nein |
| Mindestzeichenzahl als Abschlussbedingung | **Ja** | Nein | begrenzt |

Insight Journal eignet sich besonders für begleitetes Lernen, Kompetenzreflexionen und Portfolio-Ansätze, bei denen jede Lerneinheit einen eigenen Reflexionspunkt bekommt.

---

## 2  Funktionsübersicht

**Aktivitätsansicht (`view.php`)** – Lernende sehen den Impuls und ihr persönliches Eingabefeld. Manuelles Speichern oder optionales Autosave nach einer Tippause.

**Aktivitätsbericht (`report.php`)** – Trainer/innen sehen alle Antworten der Kursteilnehmenden für einen Impuls; Volltextsuche nach Teilnehmenden; CSV-Export.

**Kursgesamtbericht (`coursereport.php`)** – Übersicht über alle Insight-Journal-Aktivitäten im Kurs mit Fortschrittsanzeige je Teilnehmende/r.

**Persönliche Zusammenfassung (`summary.php`)** – Lernende sehen alle ihre Antworten auf einer druckbaren Seite. Trainer/innen können die Zusammenfassung eines bestimmten Teilnehmenden aufrufen.

**Abschlussregel** – Optional: Aktivität gilt erst als abgeschlossen, wenn die Antwort eine bestimmte Mindestzeichenzahl erreicht.

---

## 3  Installation

### 3.1  Plugin-Dateien einrichten

1. Den Ordner `insightjournal` (bzw. die entpackte ZIP-Datei) in das Verzeichnis `mod/` der Moodle-Installation kopieren – also nach `mod/insightjournal/`.
2. Im Moodle-Adminbereich: **Website-Administration → Benachrichtigungen** aufrufen.
3. Moodle erkennt das neue Plugin und führt die Datenbankinstallation automatisch durch.
4. Nach Änderungen an Sprachstrings, Templates oder JavaScript: **Cache bereinigen** (Website-Administration → Entwicklung → Cache löschen).

> **Hinweis zu JavaScript:** Für Produktionsumgebungen sollte der AMD-Build-Prozess von Moodle ausgeführt werden:
> ```bash
> npx grunt amd
> ```
> In Entwicklungsumgebungen mit `$CFG->cachejs = false` ist das nicht notwendig.

### 3.2  Systemanforderungen

| | |
|---|---|
| Plugin-Typ | Moodle Activity Module (`mod`) |
| Plugin-Name | `mod_insightjournal` |
| Moodle-Kompatibilität | Moodle 4.5+ (`requires = 2024100700`) |
| PHP-Anforderung | PHP 7.4+ |
| Externe Abhängigkeiten | Keine (kein Composer, kein Node.js zur Laufzeit) |
| Reifegrad | Beta (`MATURITY_BETA`) |

### 3.3  Rechte prüfen

Die Capabilities werden bei der Installation automatisch angelegt. Zur Kontrolle unter **Website-Administration → Nutzer/innen → Rechte → Rollen** prüfen:

| Capability | Standardmäßig vergeben an |
|---|---|
| `mod/insightjournal:view` | Lernende, Trainer/in |
| `mod/insightjournal:submit` | Lernende |
| `mod/insightjournal:viewown` | Lernende |
| `mod/insightjournal:viewall` | Trainer/in, Editing Trainer/in, Manager/in |
| `mod/insightjournal:export` | Trainer/in, Editing Trainer/in, Manager/in |
| `mod/insightjournal:addinstance` | Editing Trainer/in, Manager/in |

---

## 4  Trainer/innen-Workflow

1. Im Kurs auf **Aktivität oder Material anlegen** klicken und **Insight Journal** wählen.
2. **Name** der Aktivität eingeben (erscheint in der Kursnavigation).
3. **Insight-Impuls** formulieren – das ist die Reflexionsfrage oder -aufgabe für die Lernenden.
4. Optional: **Automatisches Speichern** aktivieren (Antwort wird nach einer Tippause gespeichert, ohne dass Lernende auf „Speichern" klicken).
5. Optional: **Mindestzeichenzahl für Abschluss** festlegen – die Aktivität gilt erst als abgeschlossen, wenn die Antwort diese Zeichenzahl erreicht.
6. In den **Aktivitätsabschluss-Einstellungen** sicherstellen, dass „Lernende/r muss eine Insight-Journal-Antwort gespeichert haben" aktiviert ist (sofern Abschluss gewünscht).
7. Nach dem Kurs: **Aktivitätsbericht** öffnen, um alle Antworten zu sehen. **Kursbericht** für eine kursweite Fortschrittsübersicht.

---

## 5  Lernenden-Workflow

1. Insight-Journal-Aktivität im Kurs öffnen.
2. Impuls lesen, Antwort im Textfeld eingeben.
3. Auf **Speichern** klicken – oder bei aktiviertem Autosave einfach einige Sekunden aufhören zu tippen.
4. Aktivität kann jederzeit wieder geöffnet und die Antwort überarbeitet werden.
5. Am Kursende: **Persönliche Zusammenfassung** öffnen – alle Antworten auf einer Seite, geeignet für den Browser-Druckdialog (inkl. PDF-Export über den Browser).

---

## 6  Berichte & Auswertung

### 6.1  Aktivitätsbericht

Aufruf: Innerhalb der Aktivität auf **Bericht** klicken (nur für Trainer/innen sichtbar).

- Zeigt alle Einträge der Kursteilnehmenden für diesen Impuls
- Volltextsuche nach Teilnehmernamen
- CSV-Export (erfordert Capability `mod/insightjournal:export`)

### 6.2  Kursgesamtbericht

Aufruf: **Website-Administration** (im Kurs) → **Berichte** → **Insight Journal Kursbericht** (oder direkt via `coursereport.php?id=KURS-ID`).

- Zeigt alle Insight-Journal-Aktivitäten des Kurses
- Fortschritt je Teilnehmende/r (Anzahl beantworteter Impulse)

### 6.3  Persönliche Zusammenfassung

Aufruf: In der Aktivität auf **Meine Zusammenfassung** klicken.

- Lernende sehen alle eigenen Antworten im Kurs
- Trainer/innen können eine Teilnehmerin/einen Teilnehmenden auswählen und deren/dessen Zusammenfassung einsehen
- Für den Ausdruck geeignet (Browserdruckdialog → als PDF speichern)

---

## 7  Datenschutz (DSGVO)

Das Plugin implementiert die Moodle Privacy API vollständig:

- **Datenbeschreibung:** Alle gespeicherten Felder sind in `get_metadata()` dokumentiert.
- **Datenexport:** Moodle kann auf Anfrage alle Einträge einer/eines Nutzers/in exportieren.
- **Datenlöschung:** Einträge können über die Moodle-Datenschutzverwaltung für einzelne Nutzende oder für einen gesamten Modulkontext gelöscht werden.

Die Capability `mod/insightjournal:viewall` ist mit `RISK_PERSONAL` markiert, da Trainer/innen persönliche Reflexionen anderer Nutzender einsehen können.

CSV-Exporte werden durch die Capability `mod/insightjournal:export` abgesichert. Tabellenformeln in Antworten werden automatisch mit einem Präfix versehen, um CSV-Injection-Risiken zu reduzieren.

---

## 8  Backup & Wiederherstellen

- Moodle-Backups enthalten die Aktivitätskonfiguration.
- Einträge der Lernenden werden nur gesichert, wenn im Backup „Nutzerdaten einschließen" aktiviert ist.
- Beim Wiederherstellen werden Nutzer-IDs automatisch auf die Zielsystem-IDs gemappt. Einträge für Nutzende, die auf dem Zielsystem nicht verfügbar sind, werden übersprungen.

---

## 9  Bekannte Einschränkungen (Beta)

- **Keine native Moodle-App-Unterstützung:** Es gibt kein `db/mobile.php`. Die Aktivität ist in der Moodle-App über die responsive Webansicht nutzbar; eine native App-Integration ist für eine spätere Version geplant.
- **Kein Server-seitiger PDF-Export:** Die Druckfunktion nutzt den Browserdruckdialog. Ein direkter PDF-Download ist für eine spätere Version geplant.
- **PHPStan:** Noch nicht in einem vollständigen Moodle-Checkout ausgeführt.
- **Behat-Tests:** Noch nicht vorhanden (PHPUnit-Tests sind enthalten).

---

## 10  Feedback & Kontakt

Diese Version wird an ausgewählte Personen aus dem Bildungsbereich und der Moodle-Community zur Rückmeldung verteilt. Jedes Feedback ist willkommen – ob als Entwickler/in oder als Lehrende/r.

**Was besonders interessiert:**
- Ist der Trainer-Workflow in Moodle intuitiv genug?
- Fehlen wichtige Funktionen für den realen Einsatz?
- Verhalten sich Autosave und Abschlussbedingung erwartungsgemäß?
- Gibt es Probleme mit Moodle-Versionen, Themes oder Rollen?
- Code-Review-Anmerkungen: Gibt es Verstöße gegen Moodle-Coding-Standards, die übersehen wurden?

**Kontakt:** Michael Kohl – michaelkohl71@gmail.com

**GitHub:** https://github.com/71Professor/insightjournal

---

*Erstellt: Juni 2026 · Plugin: mod_insightjournal v0.2.0-beta*

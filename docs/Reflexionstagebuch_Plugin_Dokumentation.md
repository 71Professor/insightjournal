# mod_insightjournal – Insight Journal für Moodle

## **1 Moodle Activity Module · Version 0.6.0-beta · Juli 2026**

> **Zweck:** Trainer/innen legen pro Kursabschnitt eine Insight-Journal-Aktivität mit einer gezielten Aufgabe oder Frage an. Lernende schreiben ihre Antwort direkt in Moodle, können sie jederzeit überarbeiten und am Kursende eine persönliche Gesamtübersicht drucken. Jede/r Lernende entscheidet selbst, pro Eintrag, ob Trainer/innen ihn sehen dürfen. Trainer/innen behalten den kursweiten Fortschritt im Blick und können exportieren, sehen dabei aber nur die Einträge, die die Verfassenden freigegeben haben.

---

Insight Journal eignet sich besonders für begleitetes Lernen, Kompetenzreflexionen und Portfolio-Ansätze, bei denen jede Lerneinheit einen eigenen Reflexionspunkt bekommt.

---

## 2  Funktionsübersicht

**Aktivitätsansicht (`view.php`)** – Lernende sehen die Aufgabe/Frage und ihr persönliches Eingabefeld. Manuelles Speichern oder optionales Autosave nach einer Tippause.

**Aktivitätsbericht (`report.php`)** – Trainer/innen sehen alle Antworten der Kursteilnehmenden für eine Aufgabe/Frage; Volltextsuche nach Teilnehmenden; CSV-Export.

**Kursgesamtbericht (`coursereport.php`)** – Übersicht über alle Insight-Journal-Aktivitäten im Kurs mit Fortschrittsanzeige je Teilnehmende/r.

**Persönliche Zusammenfassung (`summary.php`)** – Lernende sehen alle ihre Antworten auf einer druckbaren Seite. Trainer/innen können die Zusammenfassung eines bestimmten Teilnehmenden aufrufen.

**Abschlussregel** – Optional: Aktivität gilt erst als abgeschlossen, wenn die Antwort eine bestimmte Mindestzeichenzahl erreicht.

---

## 3  Installation

### 3.1  Plugin-Dateien einrichten

**Empfohlener Weg – Release-ZIP:**

1. Die Datei `mod_insightjournal-v….zip` vom neuesten [GitHub-Release](https://github.com/71Professor/insightjournal/releases) herunterladen. Ihr Wurzelordner heißt bereits `insightjournal`, wie es der Moodle-Installer verlangt.
2. In Moodle unter **Website-Administration → Plugins → Plugins installieren** die ZIP-Datei hochladen und dem Installer folgen – die Datenbankinstallation läuft automatisch.

**Alternativ – manuell kopieren:**

1. Den Ordner `insightjournal` (aus dem entpackten Release-ZIP) in das Verzeichnis `mod/` der Moodle-Installation kopieren – also nach `mod/insightjournal/`.
2. Im Moodle-Adminbereich: **Website-Administration → Benachrichtigungen** aufrufen.
3. Moodle erkennt das neue Plugin und führt die Datenbankinstallation automatisch durch.

> **Hinweis:** Der GitHub-Button *Code → Download ZIP* verpackt das Plugin in einen Ordner namens `insightjournal-main`. Wer dieses ZIP statt eines Release-ZIPs nutzt, muss den entpackten Ordner zuerst in `insightjournal` umbenennen – sonst lehnt der Moodle-Installer es ab.

Nach Änderungen an Sprachstrings, Templates oder JavaScript: **Cache bereinigen** (Website-Administration → Entwicklung → Cache löschen).

> **Hinweis zu JavaScript:** Für Produktionsumgebungen sollte der AMD-Build-Prozess von Moodle ausgeführt werden:
> 
> ```bash
> npx grunt amd
> ```
> 
> In Entwicklungsumgebungen mit `$CFG->cachejs = false` ist das nicht notwendig.

### 3.2  Systemanforderungen

|                        |                                                  |
| ---------------------- | ------------------------------------------------ |
| Plugin-Typ             | Moodle Activity Module (`mod`)                   |
| Plugin-Name            | `mod_insightjournal`                             |
| Moodle-Kompatibilität  | Moodle 4.5+ (`requires = 2024100700`)            |
| PHP-Anforderung        | PHP 7.4+                                         |
| Externe Abhängigkeiten | Keine (kein Composer, kein Node.js zur Laufzeit) |
| Reifegrad              | Beta (`MATURITY_BETA`)                           |

### 3.3  Rechte prüfen

Die Capabilities werden bei der Installation automatisch angelegt. Zur Kontrolle unter **Website-Administration → Nutzer/innen → Rechte → Rollen** prüfen:

| Capability                       | Standardmäßig vergeben an                  |
| -------------------------------- | ------------------------------------------ |
| `mod/insightjournal:view`        | Lernende, Trainer/in                       |
| `mod/insightjournal:submit`      | Lernende                                   |
| `mod/insightjournal:viewown`     | Lernende                                   |
| `mod/insightjournal:viewall`     | Trainer/in, Editing Trainer/in, Manager/in |
| `mod/insightjournal:export`      | Trainer/in, Editing Trainer/in, Manager/in |
| `mod/insightjournal:addinstance` | Editing Trainer/in, Manager/in             |

---

## 4  Trainer/innen-Workflow

1. Im Kurs auf **Aktivität oder Material anlegen** klicken und **Insight Journal** wählen.
2. **Name** der Aktivität eingeben (erscheint in der Kursnavigation).
3. **Aufgabe / Frage** formulieren – das ist die Reflexionsfrage oder -aufgabe für die Lernenden.
4. Optional: **Hintergrundfarbe für Aufgabe / Frage** als Hex-Code festlegen (z. B. `#ffcc00`), um Aufgabe/Frage überall dort, wo sie angezeigt wird, optisch von der Antwort der/des Lernenden abzuheben.
5. Optional: **Automatisches Speichern** aktivieren (Antwort wird nach einer Tippause gespeichert, ohne dass Lernende auf „Speichern" klicken).
6. Optional: **Mindestzeichenzahl für Abschluss** festlegen – die Aktivität gilt erst als abgeschlossen, wenn die Antwort diese Zeichenzahl erreicht – und/oder eine **maximale Zeichenzahl**, die während der Eingabe mit einem Live-Zähler durchgesetzt wird.
7. In den **Aktivitätsabschluss-Einstellungen** sicherstellen, dass „Lernende/r muss eine Insight-Journal-Antwort gespeichert haben" aktiviert ist (sofern Abschluss gewünscht).
8. Nach dem Kurs: **Aktivitätsbericht** öffnen, um die freigegebenen Antworten zu sehen. **Kursbericht** für eine kursweite Fortschrittsübersicht. Jede/r Lernende entscheidet selbst für den eigenen Eintrag, ob Trainer/innen ihn sehen dürfen – siehe Abschnitt 7 „Datenschutz".

---

## 5  Lernenden-Workflow

1. Insight-Journal-Aktivität im Kurs öffnen.
2. Aufgabe/Frage lesen, Antwort im Rich-Text-Editor von Moodle eingeben.
3. Auf **Speichern** klicken – oder bei aktiviertem Autosave einfach einige Sekunden aufhören zu tippen.
4. Aktivität kann jederzeit wieder geöffnet und die Antwort überarbeitet werden.
   Neben der Antwort befindet sich das Kästchen **„Diesen Eintrag privat
   halten (nur für dich sichtbar)"**, standardmäßig nicht angehakt – Trainer/innen
   können den Eintrag also lesen, sofern die/der Lernende nicht widerspricht.
   Das Umschalten speichert sofort und kann jederzeit wieder geändert werden –
   siehe Abschnitt 7 „Datenschutz". Jedes Speichern prüft, ob zwischenzeitlich
   nicht bereits an anderer Stelle (z. B. in einem weiteren Tab) eine neuere
   Version gespeichert wurde; ist das der Fall, wird das Speichern mit einem
   Hinweis abgelehnt, statt die neuere Version stillschweigend zu überschreiben.
5. Am Kursende: **Persönliche Zusammenfassung** öffnen – alle Antworten auf einer Seite, geeignet für den Browser-Druckdialog (inkl. PDF-Export über den Browser).

---

## 6  Berichte & Auswertung

### 6.1  Aktivitätsbericht

Aufruf: Innerhalb der Aktivität auf **Bericht** klicken (nur für Trainer/innen sichtbar).

- Zeigt alle Einträge der Kursteilnehmenden für diese Aufgabe/Frage
- Detailansicht bei Kiick auf Teilnehmernamen
- CSV-Export (erfordert Capability `mod/insightjournal:export`)

### 6.2  Persönliche Zusammenfassung

Aufruf: In der Aktivität auf **Meine Zusammenfassung** klicken.

- Lernende sehen alle eigenen Antworten im Kurs
- Jeder eigene, noch bearbeitbare Eintrag zeigt einen **Gehe zum Eintrag**-Link,
  der direkt zur betreffenden Aktivität springt
- Trainer/innen können einen Teilnehmenden auswählen und deren/dessen Zusammenfassung einsehen
- Für den Ausdruck geeignet (Browserdruckdialog → als PDF speichern)

---

## 7  Datenschutz (DSGVO)

Das Plugin implementiert die Moodle Privacy API vollständig:

- **Datenbeschreibung:** Alle gespeicherten Felder sind in `get_metadata()` dokumentiert.
- **Datenexport:** Moodle kann auf Anfrage alle Einträge einer/eines Nutzers/in exportieren.
- **Datenlöschung:** Einträge können über die Moodle-Datenschutzverwaltung für einzelne Nutzende oder für einen gesamten Modulkontext gelöscht werden.

Die Capability `mod/insightjournal:viewall` ist mit `RISK_PERSONAL` markiert, da Trainer/innen persönliche Reflexionen anderer Nutzender einsehen können.

CSV-Exporte werden durch die Capability `mod/insightjournal:export` abgesichert. Tabellenformeln in Antworten werden automatisch mit einem Präfix versehen, um CSV-Injection-Risiken zu reduzieren.

Jeder Eintrag hat eine eigene Sichtbarkeits-Entscheidung, die **ausschließlich
die verfassende Person** trifft – über das Kästchen **„Diesen Eintrag privat
halten (nur für dich sichtbar)"** direkt beim Schreiben der Antwort.
Standardmäßig ist ein Eintrag „Für Trainer/innen sichtbar"; die Person kann
ihn jederzeit auf privat umstellen und die Entscheidung auch wieder
rückgängig machen. Bei „Privat" bleibt der Eintrag nur für die verfassende
Person sichtbar: Aktivitätsbericht, Kursbericht und persönliche
Zusammenfassung bleiben für alle mit `mod/insightjournal:viewall` erreichbar,
zeigen für diesen einen Eintrag dann aber einen Hinweistext statt des
Inhalts; der CSV-Export ersetzt nur diese eine Zeile durch den Hinweistext,
statt den ganzen Export zu sperren. Das gilt einheitlich für alle Rollen,
auch Manager/innen und Site-Admins – es gibt keine Ausnahme, und **Trainer/innen
können diese Entscheidung nicht überschreiben**: Anders als bei einer früheren,
inzwischen entfernten Aktivitätseinstellung liegt die Kontrolle vollständig bei
der verfassenden Person. Innerhalb einer Aktivität können verschiedene
Lernende unterschiedlich eingestellt sein; Aktivitätsbericht, Kursbericht und
persönliche Zusammenfassung berücksichtigen das pro Eintrag, statt die ganze
Seite oder Spalte auszublenden.

---

## 8  Backup & Wiederherstellen

- Moodle-Backups enthalten die Aktivitätskonfiguration.
- Einträge der Lernenden werden nur gesichert, wenn im Backup „Nutzerdaten einschließen" aktiviert ist.
- Beim Wiederherstellen werden Nutzer-IDs automatisch auf die Zielsystem-IDs gemappt. Einträge für Nutzende, die auf dem Zielsystem nicht verfügbar sind, werden übersprungen.

---

## 9  Bekannte Einschränkungen (Beta)

- **Keine native Moodle-App-Unterstützung:** Es gibt kein `db/mobile.php`. Die Aktivität ist in der Moodle-App über die responsive Webansicht nutzbar; eine native App-Integration ist für eine spätere Version geplant.
- **Kein Server-seitiger PDF-Export:** Die Druckfunktion nutzt den Browserdruckdialog. Ein direkter PDF-Download ist für eine spätere Version geplant.
- **Behat-Testabdeckung ist begrenzt:** Acht Szenarien decken den Speicher-/Neuladen-Ablauf, das Bearbeiten einer gespeicherten Antwort, Autosave, die Mindestzeichenzahl-Abschlussregel, den Erfolgsstatus beim Speichern, das private Markieren eines einzelnen Eintrags durch die/den Lernende/n, den Atto-Editor sowie unterschiedliche Sichtbarkeits-Entscheidungen über mehrere Aktivitäten hinweg ab. Umfassendere Abdeckung (CSV-Export) ist noch nicht automatisiert.

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

*Erstellt: Juli 2026 · Plugin: mod_insightjournal v0.6.0-beta*

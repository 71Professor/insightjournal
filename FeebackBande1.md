# Einschätzung: Feedback „Moodle Bande“ vom 16.07.2026

Bewertung der vier Feedback-Punkte aus `MoodleBande1.md` hinsichtlich Machbarkeit, Aufwand und Empfehlung — bezogen auf den aktuellen Stand von `mod_insightjournal`.

## Ausgangslage (relevanter Ist-Zustand)

Für die Bewertung sind diese Eigenschaften des Plugins entscheidend:

- **Ein Eintrag pro Person und Aktivität**: `insightjournal_entries` hat einen Unique-Index auf `(insightjournalid, userid)`. Es gibt also kein „Tagebuch mit vielen Einträgen“, sondern pro Aktivität genau eine (fortlaufend editierbare) Antwort pro Lernendem.
- **Sichtbarkeit ist ein Aktivitäts-Setting mit zwei Stufen**: `entriesvisibility` (sichtbar für Trainer / privat) wird vom *Trainer* beim Anlegen der Aktivität festgelegt — nicht vom Lernenden pro Eintrag.
- **„My Insight Journal“ (summary.php)** ist eine reine Lese-Ansicht auf Kursebene; editiert wird nur in `view.php`.
- **Keine Dateien/Anhänge**: Der Antwort-Editor läuft mit `maxfiles = 0`, gespeichert wird via eigenem Webservice `mod_insightjournal_save_entry` (nimmt nur `cmid` + HTML entgegen, `PARAM_CLEANHTML`). Es gibt keine Filearea, kein `pluginfile.php`-Serving.
- Autosave läuft über ein eigenes AMD-Modul (`amd/src/autosave.js`) gegen diesen Webservice.

---

## 1. Sichtbarkeit der Einträge

### 1.a Vier Stufen: „nur ich“, „ich + Trainer“, „alle“, „nur bestimmte Mitglieder“

**Ist:** Zwei Stufen existieren („privat“ / „für Trainer sichtbar“), aber als *Trainer*-Entscheidung auf Aktivitätsebene. Das Feedback verlangt implizit eine *Lernenden*-Entscheidung pro Eintrag.

**Einschätzung nach Stufen:**

| Stufe | Machbarkeit | Kommentar |
|---|---|---|
| nur ich | ✅ vorhanden (als Aktivitäts-Setting) | Müsste zur Wahl des Lernenden werden |
| ich + Trainer | ✅ vorhanden (Default) | dito |
| alle | ⚠️ machbar, aber konzeptioneller Sprung | Macht aus dem Reflexionstagebuch ein halböffentliches Format |
| bestimmte Mitglieder | ❌ abraten (in dieser Form) | Hoher Aufwand, fragwürdiger Nutzen |

**Umsetzung „Lernende wählen pro Eintrag“:** Neues Feld `visibility` in `insightjournal_entries`, Auswahl-Element auf der `view.php` neben dem Editor, Erweiterung des `save_entry`-Webservice um den Parameter, Filterung in `report.php`, `coursereport.php` und `summary.php`. Das ist sauber machbar (mittlerer Aufwand), wirft aber eine Designfrage auf: **Wie verhält sich die Lernenden-Wahl zum bestehenden Trainer-Setting `entriesvisibility`?** Sinnvolles Modell: Das Aktivitäts-Setting definiert die *maximal erlaubten* Stufen (z. B. „Lernende dürfen zwischen privat und Trainer-sichtbar wählen“), der Eintrag speichert die konkrete Wahl. Damit bleibt Abwärtskompatibilität erhalten.

**Zu „alle“:** Technisch kein Hexenwerk (zusätzliche Stufe + eine Peer-Lese-Ansicht + Capability-Prüfung), aber didaktisch und datenschutzrechtlich ein anderes Produkt: Sobald Lernende die Einträge anderer sehen, braucht es eine eigene Ansicht (wer sieht was, Gruppenmodus?), eine neue Capability (z. B. `viewpeers`), Anpassung des Privacy-Providers und eine klare UX, damit niemand versehentlich „alle“ wählt. Empfehlung: als eigene Ausbaustufe planen, nicht mit der Grundfunktion vermischen. Falls gewünscht, zuerst auf **Moodle-Gruppen** beschränken („sichtbar für meine Gruppe“) — das nutzt vorhandene Moodle-Infrastruktur und ist realistischer als freie Personenwahl.

**Zu „bestimmte Mitglieder“:** Erfordert eine Zuordnungstabelle Eintrag→Personen, einen Nutzer-Picker im Lernenden-UI, Pflege bei Ab-/Anmeldungen im Kurs, Backup/Restore der Zuordnungen mit User-Mapping. Das ist der mit Abstand teuerste Teilpunkt bei gleichzeitig geringstem erwartbarem Nutzen. **Empfehlung: nicht umsetzen**; „sichtbar für meine Gruppe“ deckt den realistischen Anwendungsfall (Peer-Feedback in Kleingruppen) deutlich billiger ab.

### 1.b Kursübersicht für Lernende: wer sieht welche Einträge, mit Editiermöglichkeit

**Ist:** `summary.php` („My Insight Journal“) listet bereits alle Journals des Kurses mit den eigenen Antworten — read-only und ohne Sichtbarkeits-Info.

**Einschätzung:** Gut machbar und ein natürlicher Ausbau der bestehenden Seite. Zwei Teilschritte:

1. **Sichtbarkeits-Badge pro Eintrag** („privat“ / „für Trainer sichtbar“ / …): trivial, sobald 1.a existiert; auch ohne 1.a könnte man das Aktivitäts-Setting anzeigen (geringer Aufwand, sofort umsetzbar).
2. **Editieren direkt auf der Übersichtsseite:** deckt sich mit Punkt 2 (siehe unten). Technisch: pro Karte denselben TinyMCE + Autosave initialisieren wie auf `view.php`. Der Webservice ist bereits pro `cmid` parametrisiert, die Editor-IDs sind pro Kursmodul eindeutig — mehrere Editoren auf einer Seite sind also kein strukturelles Problem. Aufwand: mittel (JS-Init pro Karte, Zeichenlimits pro Aktivität, Completion-Updates funktionieren über den Webservice bereits).

**Empfehlung: umsetzen.** Das ist der Punkt mit dem besten Nutzen/Aufwand-Verhältnis im ganzen Feedback, weil er drei Wünsche gleichzeitig bedient (1.b, 2 und teilweise 3).

### 1.c Sichtbarkeit auf Website-/Kurs-/Aktivitätsebene — wer überschreibt was?

**Einschätzung:** Hier lohnt ein Blick auf das, was Moodle standardmäßig hergibt:

- **Website-Ebene (Admin):** Standard-Moodle-Muster „Admin-Defaults für Aktivitätseinstellungen“ (`settings.php` mit `admin_setting_configselect`, optional mit *advanced/locked*-Mechanik wie bei `mod_assign`). Der Admin definiert den Default und kann das Setting sperren → dann kann die Aktivität es nicht mehr ändern. **Gut machbar, geringer Aufwand, etabliertes Muster.**
- **Kursebene:** Dafür gibt es in Moodle **keinen Standardmechanismus** für Aktivitäts-Plugins. Kursweite Plugin-Settings müssten über eine eigene Kurs-Settings-Seite o. Ä. gebaut werden — unüblich, wartungsintensiv, und für die Aufnahme ins Plugin-Directory eher ein Malus. **Empfehlung: weglassen.**
- **Aktivitätsebene:** existiert bereits.

**Empfohlene Hierarchie (Moodle-konform):** Website-Default (Admin, optional gesperrt) → Aktivitäts-Setting (überschreibt den Default, sofern nicht gesperrt) → ggf. Lernenden-Wahl pro Eintrag innerhalb der von der Aktivität erlaubten Stufen (aus 1.a). Das beantwortet „wer überschreibt was“ mit der in Moodle üblichen Logik: *die speziellere Ebene gewinnt, außer die höhere Ebene sperrt.*

---

## 2. Übersichtsseite als Kurselement einfügbar + editierbar

**Wunsch:** „My Insight Journal“ soll ohne Umweg über eine Aktivität erreichbar sein (z. B. letzter Kursabschnitt) und Einträge sollen dort editierbar sein.

**Einschätzung — drei Optionen:**

1. **Block-Plugin `block_insightjournal` (empfohlen):** Ein Begleit-Block, der die Übersicht (oder einen Link darauf) rendert und im Kurs platziert werden kann. Deckt gleichzeitig Punkt 3 ab (siehe unten) — *ein* neues Plugin löst zwei Feedback-Punkte. Nachteil: separates Plugin (eigener Frankenstyle, eigene Releases im Plugin-Directory, Abhängigkeit deklarieren via `dependencies` in `version.php`).
2. **Direkter Link im Kurs:** Trainer legt schlicht eine URL-Ressource auf `summary.php?courseid=X` in den letzten Abschnitt. **Null Entwicklungsaufwand** — das funktioniert heute schon und sollte in der Doku als Rezept beschrieben werden. Erfüllt den Wunsch zu 80 %.
3. **„Inline-Aktivität“ à la `mod_label`** (Anzeige direkt auf der Kursseite via `FEATURE_NO_VIEW_LINK` / `cm_info_view`): technisch möglich, aber die Übersicht ist zu groß für die Kursseite und das Muster passt schlecht zu einer Aktivität, die es pro Kurs nur einmal geben dürfte. **Abraten.**

**Editierbarkeit auf der Summary-Seite:** unabhängig von der Einbindungsfrage sinnvoll und machbar (siehe 1.b). Empfehlung: **Editieren auf `summary.php` einbauen (mittlerer Aufwand)** + kurzfristig Doku-Rezept „URL-Ressource auf die Summary-Seite“ + mittelfristig der Block aus Punkt 3.

---

## 3. Paralleles Arbeiten (Video schauen + Notizen machen)

**Wunsch:** Journal per „Add a block“ neben einer anderen Aktivität offen haben.

**Einschätzung:** Der Vorschlag aus dem Feedback ist genau richtig — das ist in Moodle die Aufgabe eines **Block-Plugins**. Ein Aktivitätsmodul kann architektonisch nicht „neben“ einer anderen Aktivität laufen; ein Block im Block-Drawer (Boost) kann das, wenn er mit „Display throughout the course“ konfiguriert wird und damit auch innerhalb anderer Aktivitätsseiten sichtbar ist.

**Umsetzung `block_insightjournal`:**

- Block zeigt ein kompaktes Editor-Feld für ein wählbares (oder das per Block-Konfiguration festgelegte) Insight Journal des Kurses.
- **Die Speicher-Infrastruktur existiert bereits vollständig:** `mod_insightjournal_save_entry` nimmt `cmid` + HTML, prüft Capability/Login/maxchars und aktualisiert Completion. Der Block braucht im Kern nur UI + Aufruf dieses Service; das Autosave-AMD-Modul lässt sich weitgehend wiederverwenden.
- Aufwand: **mittel** (neues Plugin-Skelett, Block-Konfiguration, schmale Editor-Variante — im Block eher ein einfaches Textarea/Mini-TinyMCE als der volle Editor).
- Einschränkungen ehrlich benennen: Auf schmalen Bildschirmen liegt der Block-Drawer *über* dem Inhalt (echtes Nebeneinander nur auf Desktop), und bei eingebetteten Videos scrollt man ggf. trotzdem. Als Sofort-Workaround ohne Code: Aktivität in zweitem Browser-Tab/-Fenster öffnen — sollte ebenfalls in die Doku.

**Empfehlung: umsetzen**, als separates Begleit-Plugin, nach den Kernpunkten 1.a/1.b/2. Es ist der wertvollste „neue“ Baustein, weil er das Kern-Nutzungsszenario (reflektieren *während* des Lernens) erst wirklich ermöglicht.

---

## 4. Multimediales Arbeiten (Audio, Bilder)

**Wunsch:** Audioaufnahme optional zulassen (per Aktivitäts-Setting); Bildupload entweder ermöglichen oder sauber unterbinden (Editor-Menüs ausblenden bzw. Fehlermeldung).

### Audio/Bilder zulassen

**Einschätzung:** Machbar und in Moodle gut vorgezeichnet, aber der **aufwendigste Punkt des gesamten Feedbacks**, weil er das Speichermodell erweitert:

- Der Moodle-Editor (TinyMCE) bringt Audio-/Videoaufnahme (RecordRTC) und Bildupload von Haus aus mit — sie sind derzeit nur wirkungslos, weil `maxfiles = 0` gesetzt ist und es keine Filearea gibt. „Audio erlauben“ heißt also nicht „Recorder einbauen“, sondern „**Dateianhänge korrekt unterstützen**“.
- Dafür nötig: Filearea `response` inkl. Draft-Area-Handling (`file_prepare_draft_area` / `file_save_draft_area_files`), `insightjournal_pluginfile()` zum Ausliefern mit Capability-Prüfung, Backup/Restore der Dateien, Export im Privacy-Provider, Berücksichtigung beim CSV-Export und in allen Anzeige-Seiten (`format_text` mit `pluginfile`-Rewrite).
- **Kniffligster Teil:** Der eigene Autosave-Webservice speichert rohes HTML per `PARAM_CLEANHTML` ohne Draft-Item-Handling. Eingebettete Dateien brauchen eine Draft-Itemid pro Session und das Umschreiben der `@@PLUGINFILE@@`-URLs — der Service und das JS müssen dafür erweitert werden. Das ist lösbar (Standardmuster), aber sorgfältige Arbeit inkl. Tests.
- Als **Aktivitäts-Setting** (z. B. „Anhänge erlauben: nein / Audio / Audio+Bilder“) gut abbildbar; bei `maxfiles = 0` bleibt alles wie heute. `accepted_types` erlaubt die Beschränkung auf Audioformate.

**Aufwand: hoch** (größter Einzelposten), **Empfehlung: umsetzen, aber als eigenes Release** nach den Sichtbarkeits-/Übersichts-Themen, da es Schema, Webservice, Backup, Privacy und Tests gleichzeitig berührt.

### Bilder unterbinden (falls Upload nicht gewollt)

- **Editor-Menüs/Icons pro Aktivität ausblenden:** In Moodle 4.x/5.x ist die TinyMCE-Toolbar praktisch nur **site-weit** (Admin: Tiny-Subplugins deaktivieren) konfigurierbar, nicht sauber pro Instanz. Davon abraten — fragil und themeabhängig.
- **Robuste Alternative (empfohlen):** serverseitig beim Speichern filtern. Der `save_entry`-Service entfernt `<img>`/Media-Tags (bzw. lehnt mit verständlicher Meldung ab), das Frontend zeigt einen Hinweis. Das ist wenige Zeilen, wasserdicht (gilt auch für Copy-Paste von Bildern) und unabhängig vom Editor. Heute können Bilder ohnehin nur als externe URLs oder Base64 eingebettet werden — genau das würde damit sauber abgefangen.

---

## Priorisierungsvorschlag

| Prio | Maßnahme | Feedback-Punkt | Aufwand | Bemerkung |
|---|---|---|---|---|
| 1 | Editieren + Sichtbarkeits-Anzeige auf `summary.php` | 1.b, 2 | mittel | Bestes Nutzen/Aufwand-Verhältnis |
| 1 | Doku-Rezepte: URL-Ressource auf Summary-Seite; zweiter Browser-Tab | 2, 3 | minimal | Sofort lieferbar |
| 2 | Lernenden-Wahl der Sichtbarkeit pro Eintrag (privat / Trainer), Aktivitäts-Setting definiert erlaubte Stufen | 1.a | mittel | DB-Feld + Webservice + Filterung |
| 2 | Site-Admin-Defaults inkl. Sperr-Option (`settings.php`) | 1.c | gering | Standardmuster; Kursebene bewusst weglassen |
| 3 | `block_insightjournal` (Notizblock im Block-Drawer) | 3, 2 | mittel | Separates Begleit-Plugin; Webservice wiederverwendbar |
| 4 | Anhänge/Audio als Opt-in (Filearea, pluginfile, Backup, Privacy) | 4 | hoch | Eigenes Release; Bild-Filter serverseitig als Vorstufe |
| — | „Sichtbar für alle“ → wenn, dann als „sichtbar für meine Gruppe“ | 1.a | mittel–hoch | Eigene Ausbaustufe, Datenschutz beachten |
| — | „Bestimmte Mitglieder“ | 1.a | hoch | **Nicht empfohlen** |

## Querschnittsthemen (gelten für fast alle Punkte)

Jede Schema-Änderung (Sichtbarkeitsfeld, Anhänge-Setting) zieht den kompletten Moodle-Pflichtkanon nach sich: `db/upgrade.php` + `version.php`-Bump, `install.xml`, Backup/Restore-Steps, Privacy-Provider, PHPUnit-/Behat-Tests, Sprachpakete (en/de) sowie README/Doku/CHANGELOG. Das ist bei diesem Plugin bereits gut eingespielt, sollte aber in die Aufwandsschätzung je Punkt eingepreist werden (grob +30–50 % auf die reine Feature-Arbeit). Mit Blick auf die geplante Plugin-Directory-Einreichung spricht das dafür, die Punkte gestaffelt in kleinen Releases zu liefern statt als ein großes.

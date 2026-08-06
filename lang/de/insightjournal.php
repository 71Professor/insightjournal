<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * German language strings for mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['autosave'] = 'Automatisches Speichern aktivieren';
$string['autosave_help'] = 'Wenn aktiviert, wird die Antwort einer/eines Teilnehmenden kurz nach Ende der Eingabe automatisch gespeichert – zusätzlich zur Schaltfläche „Speichern".';
$string['backtoactivity'] = 'Zurück zur Aktivität';
$string['backtocourse'] = 'Zurück zum Kurs';
$string['backtolist'] = 'Zurück zur Liste';
$string['backtosection'] = 'Zurück';
$string['completionentries'] = 'Teilnehmende müssen eine Insight-Journal-Antwort speichern';
$string['completionentriesgroup'] = 'Gespeicherte Antwort erforderlich';
$string['conflictreload'] = 'Seite neu laden';
$string['coursereport'] = 'Kurs-Insight-Journal-Bericht';
$string['deleteallentries'] = 'Alle Insight-Journal-Einträge löschen';
$string['downloadcsv'] = 'CSV herunterladen';
$string['entriesprivatenotice'] = 'Die Insight-Journal-Einträge sind derzeit privat. Nur die Person, die einen Eintrag verfasst hat, kann ihn einsehen.';
$string['entryprivate'] = 'Diesen Eintrag privat halten (nur für dich sichtbar)';
$string['entryprivate_help'] = 'Standardmäßig ist dein Eintrag für Trainer/innen mit der Fähigkeit „Alle Einträge ansehen" sichtbar. Aktiviere dieses Kästchen, um ihn nur für dich sichtbar zu halten – Trainer/innen sehen dann einen Hinweistext statt deiner Antwort. Du kannst dies jederzeit ändern.';
$string['err_emptyprompt'] = 'Gib eine Aufgabe oder Frage ein - die Aufgabe/Frage darf nicht leer sein.';
$string['err_invalidcolor'] = 'Gib einen gültigen Hex-Farbcode ein (z. B. #ffcc00) oder lasse das Feld leer.';
$string['err_mingtmax'] = 'Die Mindestzeichenzahl darf die Höchstzeichenzahl nicht überschreiten.';
$string['evententrycreated'] = 'Insight-Journal-Eintrag erstellt';
$string['evententryupdated'] = 'Insight-Journal-Eintrag aktualisiert';
$string['gotoentry'] = 'Gehe zum Eintrag';
$string['insightjournal:addinstance'] = 'Neue Insight-Journal-Aktivität hinzufügen';
$string['insightjournal:export'] = 'Insight-Journal-Einträge exportieren';
$string['insightjournal:submit'] = 'Eigenen Insight-Journal-Eintrag speichern';
$string['insightjournal:view'] = 'Insight Journal anzeigen';
$string['insightjournal:viewall'] = 'Alle Insight-Journal-Einträge anzeigen';
$string['insightjournal:viewown'] = 'Eigene Insight-Journal-Einträge anzeigen';
$string['intro'] = 'Beschreibung';
$string['lastsaved'] = 'Zuletzt gespeichert: {$a}';
$string['maxchars'] = 'Maximale Zeichenzahl';
$string['maxchars_help'] = 'Die maximale Anzahl an Zeichen, die eine/ein Teilnehmende/r eingeben darf. Während der Eingabe wird ein Live-Zähler angezeigt. Der Wert 0 bedeutet kein Limit.';
$string['maxcharserror'] = 'Die Antwort überschreitet die maximal erlaubte Länge von {$a} Zeichen.';
$string['maxcharsnote'] = '{$a->current} / {$a->max} Zeichen';
$string['minchars'] = 'Mindestzeichenzahl für Abschluss';
$string['minchars_help'] = 'Die Mindestanzahl an Zeichen, die eine Antwort enthalten muss, bevor die Aktivität als abgeschlossen gilt. Der Wert 0 bedeutet keine Mindestlänge. Dies betrifft ausschließlich den Abschlussstatus – Lernende können jederzeit eine kürzere Antwort speichern, sie zählt lediglich noch nicht als abgeschlossen.';
$string['mincharsnote'] = 'Mindestlänge für den Abschluss: {$a} Zeichen.';
$string['modulename'] = 'Insight Journal';
$string['modulename_help'] = 'Mit der Aktivität Insight Journal schreiben Teilnehmende Antworten auf eine Aufgabe oder Frage. Trainer/innen können Einträge anzeigen und exportieren.';
$string['modulenameplural'] = 'Insight Journals';
$string['mysummary'] = 'Mein Insight Journal';
$string['mysummaryfor'] = 'Insight Journal: {$a}';
$string['noentries'] = 'Noch keine Einträge vorhanden.';
$string['noreflectionsincourse'] = 'In diesem Kurs gibt es noch keine Insight-Journal-Aktivitäten.';
$string['noresponse'] = 'Keine Antwort eingetragen.';
$string['notsubmitted'] = 'Nicht eingereicht';
$string['participant'] = 'Teilnehmer/in';
$string['pluginadministration'] = 'Insight-Journal-Administration';
$string['pluginname'] = 'Insight Journal';
$string['print'] = 'Drucken / als PDF speichern';
$string['privacy:metadata:insightjournal_entries'] = 'Speichert Insight-Journal-Antworten von Nutzer/innen.';
$string['privacy:metadata:insightjournal_entries:insightjournalid'] = 'Die Aktivitätsinstanz, zu der die Antwort gehört.';
$string['privacy:metadata:insightjournal_entries:response'] = 'Der Antworttext.';
$string['privacy:metadata:insightjournal_entries:responseformat'] = 'Das Antwortformat.';
$string['privacy:metadata:insightjournal_entries:revision'] = 'Ein bei jeder Speicherung erhöhter Zähler, der genutzt wird, um gleichzeitige, einander widersprechende Änderungen zu erkennen.';
$string['privacy:metadata:insightjournal_entries:timecreated'] = 'Zeitpunkt der Erstellung.';
$string['privacy:metadata:insightjournal_entries:timemodified'] = 'Zeitpunkt der letzten Änderung.';
$string['privacy:metadata:insightjournal_entries:userid'] = 'Die Nutzerin oder der Nutzer, die/der die Antwort geschrieben hat.';
$string['privacy:metadata:insightjournal_entries:visibility'] = 'Ob der Eintrag privat ist (nur für die verfassende Person sichtbar) oder für Trainer/innen sichtbar ist.';
$string['private'] = 'Privat';
$string['progress'] = 'Fortschritt';
$string['promptcolor'] = 'Hintergrundfarbe für Aufgabe / Frage';
$string['promptcolor_help'] = 'Ein optionaler Hex-Farbcode (z. B. #ffcc00) für den Hintergrund von Aufgabe oder Frage, überall dort, wo diese angezeigt wird. Dies betrifft nur die Aufgabe oder Frage, niemals die Antwort einer/eines Teilnehmenden. Leer lassen, um die Standarddarstellung zu verwenden.';
$string['prompttext'] = 'Aufgabe / Frage';
$string['prompttext_help'] = 'Die Aufgabe oder Frage, die den Teilnehmenden angezeigt wird. Jede Insight-Journal-Aktivität enthält genau eine Aufgabe oder Frage, auf die die Teilnehmenden antworten.';
$string['readonlyteacher'] = 'Du kannst diese Aktivität anzeigen. Schreiben können nur Nutzer/innen mit Abgaberecht.';
$string['report'] = 'Insight-Journal-Bericht';
$string['reportfor'] = 'Insight-Journal-Bericht: {$a}';
$string['response'] = 'Antwort';
$string['responseplaceholder'] = 'Schreibe deine Insight-Journal-Antwort hier...';
$string['save'] = 'Speichern';
$string['saveconflict'] = 'Nicht gespeichert: An anderer Stelle (z. B. einem weiteren Tab) wurde bereits eine neuere Version gespeichert. Laden Sie die Seite neu, um sie zu sehen.';
$string['savedat'] = 'Gespeichert am {$a}';
$string['saveerror'] = 'Die Antwort konnte nicht gespeichert werden.';
$string['savelockerror'] = 'Die Antwort konnte nicht gespeichert werden: Der Server ist gerade ausgelastet. Bitte versuchen Sie es in Kürze erneut.';
$string['saving'] = 'Wird gespeichert...';
$string['searchparticipants'] = 'Teilnehmende suchen';
$string['submitted'] = 'Eingereicht';
$string['timemodified'] = 'Zuletzt geändert';

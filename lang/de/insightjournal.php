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

$string['pluginname'] = 'Insight Journal';
$string['pluginadministration'] = 'Insight-Journal-Administration';
$string['modulename'] = 'Insight Journal';
$string['modulenameplural'] = 'Insight Journals';
$string['modulename_help'] = 'Mit der Aktivität Insight Journal schreiben Teilnehmende Antworten auf eine Aufgabe oder Frage. Trainer/innen können Einträge anzeigen und exportieren.';
$string['insightjournal:addinstance'] = 'Neue Insight-Journal-Aktivität hinzufügen';
$string['insightjournal:view'] = 'Insight Journal anzeigen';
$string['insightjournal:submit'] = 'Eigenen Insight-Journal-Eintrag speichern';
$string['insightjournal:viewown'] = 'Eigene Insight-Journal-Einträge anzeigen';
$string['insightjournal:viewall'] = 'Alle Insight-Journal-Einträge anzeigen';
$string['insightjournal:export'] = 'Insight-Journal-Einträge exportieren';
$string['deleteallentries'] = 'Alle Insight-Journal-Einträge löschen';
$string['intro'] = 'Beschreibung';
$string['prompttext'] = 'Aufgabe / Frage';
$string['prompttext_help'] = 'Die Aufgabe oder Frage, die den Teilnehmenden angezeigt wird. Jede Insight-Journal-Aktivität enthält genau eine Aufgabe oder Frage, auf die die Teilnehmenden antworten.';
$string['promptcolor'] = 'Hintergrundfarbe für Aufgabe / Frage';
$string['promptcolor_help'] = 'Ein optionaler Hex-Farbcode (z. B. #ffcc00) für den Hintergrund von Aufgabe oder Frage, überall dort, wo diese angezeigt wird. Dies betrifft nur die Aufgabe oder Frage, niemals die Antwort einer/eines Teilnehmenden. Leer lassen, um die Standarddarstellung zu verwenden.';
$string['autosave'] = 'Automatisches Speichern aktivieren';
$string['autosave_help'] = 'Wenn aktiviert, wird die Antwort einer/eines Teilnehmenden kurz nach Ende der Eingabe automatisch gespeichert – zusätzlich zur Schaltfläche „Speichern".';
$string['minchars'] = 'Mindestzeichenzahl für Abschluss';
$string['minchars_help'] = 'Die Mindestanzahl an Zeichen, die eine Antwort enthalten muss, bevor die Aktivität als abgeschlossen gilt. Der Wert 0 bedeutet keine Mindestlänge.';
$string['mincharsnote'] = 'Mindestlänge für den Abschluss: {$a} Zeichen.';
$string['maxchars'] = 'Maximale Zeichenzahl';
$string['maxchars_help'] = 'Die maximale Anzahl an Zeichen, die eine/ein Teilnehmende/r eingeben darf. Während der Eingabe wird ein Live-Zähler angezeigt. Der Wert 0 bedeutet kein Limit.';
$string['maxcharsnote'] = '{$a->current} / {$a->max} Zeichen';
$string['maxcharserror'] = 'Die Antwort überschreitet die maximal erlaubte Länge von {$a} Zeichen.';
$string['completionentries'] = 'Teilnehmende müssen eine Insight-Journal-Antwort speichern';
$string['completionentriesgroup'] = 'Gespeicherte Antwort erforderlich';
$string['response'] = 'Antwort';
$string['responseplaceholder'] = 'Schreibe deine Insight-Journal-Antwort hier...';
$string['save'] = 'Speichern';
$string['saving'] = 'Wird gespeichert...';
$string['savedat'] = 'Gespeichert am {$a}';
$string['lastsaved'] = 'Zuletzt gespeichert: {$a}';
$string['saveerror'] = 'Die Antwort konnte nicht gespeichert werden.';
$string['readonlyteacher'] = 'Du kannst diese Aktivität anzeigen. Schreiben können nur Nutzer/innen mit Abgaberecht.';
$string['report'] = 'Insight-Journal-Bericht';
$string['reportfor'] = 'Insight-Journal-Bericht: {$a}';
$string['downloadcsv'] = 'CSV herunterladen';
$string['searchparticipants'] = 'Teilnehmende suchen';
$string['participant'] = 'Teilnehmer/in';
$string['timemodified'] = 'Zuletzt geändert';
$string['noentries'] = 'Noch keine Einträge vorhanden.';
$string['noresponse'] = 'Keine Antwort eingetragen.';
$string['mysummary'] = 'Mein Insight Journal';
$string['mysummaryfor'] = 'Insight Journal: {$a}';
$string['noreflectionsincourse'] = 'In diesem Kurs gibt es noch keine Insight-Journal-Aktivitäten.';
$string['backtocourse'] = 'Zurück zum Kurs';
$string['backtolist'] = 'Zurück zur Liste';
$string['backtoactivity'] = 'Zurück zur Aktivität';
$string['backtosection'] = 'Zurück';
$string['print'] = 'Drucken / als PDF speichern';
$string['privacy:metadata:insightjournal_entries'] = 'Speichert Insight-Journal-Antworten von Nutzer/innen.';
$string['privacy:metadata:insightjournal_entries:insightjournalid'] = 'Die Aktivitätsinstanz, zu der die Antwort gehört.';
$string['privacy:metadata:insightjournal_entries:userid'] = 'Die Nutzerin oder der Nutzer, die/der die Antwort geschrieben hat.';
$string['privacy:metadata:insightjournal_entries:response'] = 'Der Antworttext.';
$string['privacy:metadata:insightjournal_entries:responseformat'] = 'Das Antwortformat.';
$string['privacy:metadata:insightjournal_entries:timecreated'] = 'Zeitpunkt der Erstellung.';
$string['privacy:metadata:insightjournal_entries:timemodified'] = 'Zeitpunkt der letzten Änderung.';
$string['err_mingtmax'] = 'Die Mindestzeichenzahl darf die Höchstzeichenzahl nicht überschreiten.';
$string['err_invalidcolor'] = 'Gib einen gültigen Hex-Farbcode ein (z. B. #ffcc00) oder lasse das Feld leer.';
$string['submitted'] = 'Eingereicht';
$string['notsubmitted'] = 'Nicht eingereicht';
$string['coursereport'] = 'Kurs-Insight-Journal-Bericht';
$string['progress'] = 'Fortschritt';
$string['entriesvisibletoteacher'] = 'Einträge für Trainer/innen sichtbar';
$string['entriesvisibletoteacher_desc'] = 'Wenn aktiviert (Standard), können Trainer/innen mit der Fähigkeit „Alle Einträge ansehen" die Insight-Journal-Einträge der Teilnehmenden im Bericht, im Kursbericht und in der persönlichen Übersicht sehen. Wenn deaktiviert, sind die Einträge privat: Nur die Person, die einen Eintrag verfasst hat, kann ihn sehen; Trainer/innen sehen stattdessen einen Hinweistext. Kurstrainer/innen können dies für einzelne Aktivitäten in den jeweiligen Aktivitätseinstellungen überschreiben.';
$string['entriesprivatenotice'] = 'Die Insight-Journal-Einträge sind derzeit privat. Nur die Person, die einen Eintrag verfasst hat, kann ihn einsehen.';
$string['private'] = 'Privat';
$string['entriesvisibility'] = 'Sichtbarkeit der Einträge für Trainer/innen';
$string['entriesvisibility_sitedefault'] = 'Website-Standard verwenden';
$string['entriesvisibility_visible'] = 'Für Trainer/innen sichtbar';
$string['entriesvisibility_private'] = 'Privat (nur für Lernende sichtbar)';
$string['entriesvisibility_help'] = 'Legt fest, ob Trainer/innen mit der Fähigkeit „Alle Einträge ansehen" die Einträge dieser Aktivität sehen können. „Website-Standard verwenden" folgt der globalen Insight-Journal-Einstellung (Website-Administration → Plugins → Aktivitäten → Insight Journal), einschließlich späterer Änderungen durch die Website-Administration. Mit „Für Trainer/innen sichtbar" oder „Privat" wird dieser Standard nur für diese Aktivität überschrieben.';

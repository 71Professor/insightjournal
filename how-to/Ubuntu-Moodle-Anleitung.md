# **Ubuntu-Moodle.md**

# **Moodle-Entwicklungsumgebung unter Ubuntu (WSL2)**

## **Warum Ubuntu statt C:?**

Die bisherige Installation unter

C:\\Users\\Profe\\moodle-dev

funktioniert zwar, ist aber wegen der langsamen Windows-Dateizugriffe deutlich träger.

Die neue Entwicklungsumgebung liegt vollständig im Linux-Dateisystem von Ubuntu und bietet:

* deutlich schnellere Seitenaufrufe  
* schnelleren Login  
* bessere Docker-Performance  
* ideale Voraussetzungen für PHPUnit, PHPStan, Behat und den Moodle Code Checker

---

# **Alte Installation entfernen**

## **Container stoppen**

In PowerShell:

cd C:\\Users\\Profe\\moodle-dev\\moodle-docker  
bin\\moodle-docker-compose down

Kontrolle:

docker ps

Es sollten keine Moodle-Container mehr laufen.

---

## **Verzeichnisse löschen**

Falls keine Daten mehr benötigt werden:

C:\\Users\\Profe\\moodle-dev

komplett löschen:

Remove-Item C:\\Users\\Profe\\moodle-dev \-Recurse \-Force

Docker Desktop und WSL2 bleiben installiert.

---

# **Ubuntu öffnen**

Im Startmenü:

Ubuntu 24.04

Ab jetzt werden alle Befehle ausschließlich in Ubuntu ausgeführt.

---

# **Arbeitsverzeichnis anlegen**

mkdir \-p \~/moodle-dev  
cd \~/moodle-dev

---

# **Moodle herunterladen**

git clone \-b MOODLE\_500\_STABLE https://github.com/moodle/moodle.git

---

# **moodle-docker herunterladen**

git clone https://github.com/moodlehq/moodle-docker.git

Kontrolle:

ls

Es sollten vorhanden sein:

moodle  
moodle-docker

---

# **Umgebungsvariablen setzen**

export MOODLE\_DOCKLE\_WWWROOT=\~/moodle-dev/moodle  
export MOODLE\_DOCKER\_DB=mariadb  
export MOODLE\_DOCKER\_PHP\_VERSION=8.3

---

# **Variablen dauerhaft speichern**

echo 'export MOODLE\_DOCKER\_WWWROOT=\~/moodle-dev/moodle' \>\> \~/.bashrc  
echo 'export MOODLE\_DOCKER\_DB=mariadb' \>\> \~/.bashrc  
echo 'export MOODLE\_DOCKER\_PHP\_VERSION=8.3' \>\> \~/.bashrc

source \~/.bashrc

---

# **config.php erzeugen**

cd \~/moodle-dev/moodle-docker

cp config.docker-template.php \\  
"$MOODLE\_DOCKER\_WWWROOT/config.php"

---

# **Container starten**

bin/moodle-docker-compose up \-d

---

# **Moodle installieren**

bin/moodle-docker-compose exec webserver php admin/cli/install\_database.php \\  
 \--agree-license \\  
 \--fullname="Moodle Dev" \\  
 \--shortname="moodledev" \\  
 \--summary="Lokale Entwicklungsumgebung" \\  
 \--adminuser=admin \\  
 \--adminpass=Admin123\! \\  
 \--adminemail=admin@example.com

---

# **Moodle öffnen**

Browser:

http://localhost:8000

Login:

Benutzername:

admin

Passwort:

Admin123\!

---

# **PHPUnit initialisieren**

bin/moodle-docker-compose exec webserver \\  
php admin/tool/phpunit/cli/init.php

---

# **PHPUnit testen**

Version:

bin/moodle-docker-compose exec webserver \\  
vendor/bin/phpunit \--version

Plugin:

bin/moodle-docker-compose exec webserver \\  
vendor/bin/phpunit mod/reflectiondiary/tests/

---

# **Täglicher Ablauf**

## **Entwicklungsumgebung starten**

cd \~/moodle-dev/moodle-docker

bin/moodle-docker-compose up \-d

---

## **Moodle öffnen**

http://localhost:8000

---

## **Plugin bearbeiten**

Pfad:

\~/moodle-dev/moodle/mod/reflectiondiary

---

## **Tests ausführen**

bin/moodle-docker-compose exec webserver \\  
vendor/bin/phpunit mod/reflectiondiary/tests/

---

## **Entwicklungsumgebung beenden**

bin/moodle-docker-compose down

---

# **Qualitätssicherung**

## **PHPUnit**

Prüft die Funktionalität des Codes.

Frage:

Tut mein Code das Richtige?

---

## **PHPStan**

Statische Codeanalyse.

Frage:

Könnte mein Code Fehler enthalten?

---

## **Behat**

Automatisierte Browsertests.

Frage:

Funktioniert das Plugin aus Sicht des Benutzers?

---

## **Moodle Code Checker**

Prüft die Einhaltung der Moodle Coding Guidelines.

Frage:

Ist mein Code Moodle-konform?

---

# **Empfohlene Reihenfolge**

1. PHPUnit  
2. Moodle Code Checker  
3. PHPStan  
4. Behat

Mit diesen vier Werkzeugen erreicht man ein Qualitätsniveau, das dem vieler Plugins im offiziellen Moodle Plugin Directory entspricht.


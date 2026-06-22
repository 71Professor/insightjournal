# InsightJournal Plugin – Sync zu lokalem Moodle (WSL2 + moodle-docker)

## Voraussetzungen

| Was | Wo |
|---|---|
| Plugin-Repo (Windows) | `C:\Git\insightjournal` |
| Moodle-Verzeichnis (WSL2) | `~/moodle-dev/moodle/` |
| moodle-docker | `~/moodle-dev/moodle-docker/` |
| Plugin-Typ | `mod_insightjournal` → gehört nach `mod/insightjournal/` |

---

## Einmalig einrichten

In WSL2 ausführen (alles auf einmal kopieren und einfügen):

```bash
mkdir -p ~/moodle-dev/moodle/mod/insightjournal

cat > ~/sync-insightjournal.sh << 'EOF'
#!/bin/bash
rsync -av --delete \
  /mnt/c/Git/insightjournal/ \
  ~/moodle-dev/moodle/mod/insightjournal/
EOF

chmod +x ~/sync-insightjournal.sh
echo "alias syncij='~/sync-insightjournal.sh'" >> ~/.bashrc
source ~/.bashrc
```

---

## Erster Sync + Moodle-Installation

```bash
# 1. Plugin sync
syncij

# 2. Im Browser: Moodle Admin aufrufen
# http://localhost/moodle/admin
# → Moodle erkennt das neue Plugin automatisch
# → Installationsassistent durchklicken
```

---

## Normaler Entwicklungs-Workflow

```
1. In Windows entwickeln (VS Code + GitHub Desktop)
2. In WSL2: syncij
3. Im Browser testen
```

Bei Änderungen an `version.php` (Versionsnummer erhöht):
→ Nach dem Sync kurz `http://localhost/moodle/admin` aufrufen, Upgrade bestätigen.

---

## Nützliche Befehle

```bash
# Plugin syncen
syncij

# Cron manuell ausführen (aus moodle-docker Ordner)
cd ~/moodle-dev/moodle-docker
bin/moodle-docker-compose exec webserver php admin/cli/cron.php

# Befehl im Container ausführen (allgemeines Schema)
bin/moodle-docker-compose exec webserver <befehl>
```

---

## Hinweise

- Der `--delete` Flag in rsync sorgt dafür, dass gelöschte Dateien aus Windows auch in Moodle entfernt werden.
- Symlinks funktionieren bei moodle-docker **nicht** – deshalb rsync statt `ln -s`.
- Das Git-Repo bleibt in Windows (`C:\Git\insightjournal`), Commits und Pushes wie gewohnt über GitHub Desktop.
- Der WSL2-Pfad zu Windows ist immer `/mnt/c/...` (entspricht `C:\...`).

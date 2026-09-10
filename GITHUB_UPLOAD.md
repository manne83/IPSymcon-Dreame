# Upload zu GitHub und Installation in IP-Symcon

## 1. Repository erstellen

1. Im Browser `https://github.com/new` aufrufen.
2. Als Repository-Namen `IPSymcon-Dreame` eintragen.
3. **Public** auswählen, damit die SymBox das Modul ohne GitHub-Zugangsdaten
   laden kann.
4. Keine README, `.gitignore` oder Lizenz von GitHub erzeugen lassen; diese
   Dateien sind bereits im Modul enthalten.
5. **Create repository** anklicken.

## 2. Dateien hochladen

1. Auf der leeren Repository-Seite **uploading an existing file** anklicken.
2. Den Inhalt des lokalen Ordners `Dreame-Symcon` in das Upload-Feld ziehen.
   `library.json`, `README.md`, `DreameRobot` und `libs` müssen direkt auf der
   obersten Ebene des Repositorys liegen. Es darf keine zusätzliche
   Verzeichnisebene `Dreame-Symcon` geben.
3. Als Beschreibung `Initial Dreame X60 test version` eintragen.
4. **Commit changes** anklicken.

## 3. In IP-Symcon installieren

1. In der Verwaltungskonsole **Kerninstanzen → Modulverwaltung** öffnen.
2. **Modul hinzufügen** auswählen.
3. Die Repository-URL eintragen:
   `https://github.com/manne83/IPSymcon-Dreame`
4. Anschließend eine neue Instanz vom Hersteller **Dreame** und Typ
   **Dreame Saugwischroboter** erstellen.
5. Zugangsdaten eintragen und zunächst **Anmeldung testen und Geräte suchen**
   verwenden.
6. Erst nach erfolgreichem Test **Aktiv** einschalten und übernehmen.

Zugangsdaten oder vollständige Debug-Ausgaben niemals in GitHub hochladen.

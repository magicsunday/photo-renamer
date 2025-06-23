[![Latest version](https://img.shields.io/github/v/release/magicsunday/photo-renamer?sort=semver)](https://github.com/magicsunday/photo-renamer/releases/latest)
[![License](https://img.shields.io/github/license/magicsunday/photo-renamer)](https://github.com/magicsunday/photo-renamer/blob/main/LICENSE)
[![CI](https://github.com/magicsunday/photo-renamer/actions/workflows/ci.yml/badge.svg)](https://github.com/magicsunday/photo-renamer/actions/workflows/ci.yml)


# Use at your own risk! Always try "--dry-run" first.


This tool is written in PHP and relies on a statically linked php binary which is built and provided by the project itself. This will make a local installation of PHP unnecessary. This makes it possible to provide the whole tool as a single binary which contains all dependencies.

# Development Guidelines for Photo Renamer

This document provides guidelines and instructions for developing and maintaining the Photo Renamer project.

## Build/Configuration Instructions

### Setting Up the Development Environment

There are two ways to initialize the build environment:

#### 1. Using Docker (Recommended)

```bash
make init-with-docker
```

This command uses Docker to set up the build environment, which is the recommended approach as it ensures a consistent environment across different development machines.

#### 2. Without Docker

```bash
make init
```

This command sets up the build environment directly on your machine.

### Installing Dependencies

After initializing the build environment, install the PHP dependencies:

```bash
bin/composer install
```

**Note:** The initialization process downloads, builds, and compiles a PHP binary, which:
- Requires approximately 1.5 GB of disk space
- Requires approximately 4 GB of RAM
- Takes about 10 minutes to complete
- Uses all available CPU resources

### Building the Tool

To build a new version of the renamer binary:

```bash
make build
```

This creates a new binary named `renamer` in the project root.

## Run the tool
```bash
./renamer
```

[//]: # (# Installation)

[//]: # ()
[//]: # (## 1 - Install mediainfo)

[//]: # (You should install [mediainfo]&#40;http://manpages.ubuntu.com/manpages/gutsy/man1/mediainfo.1.html&#41;:)

[//]: # ()
[//]: # (### On linux:)

[//]: # (```bash)

[//]: # ($ sudo apt-get install mediainfo)

[//]: # (```)

[//]: # ()
[//]: # (### On Mac:)

[//]: # (```bash)

[//]: # ($ brew install mediainfo)

[//]: # (```)


# Usage
These are some command-line commands I use to consistently name the photos in my photo collection.

This also applies to Apple Live Photos (consisting of an image and a video file with the same name).
These tools help me consistently name the files by date and time and mark duplicates based on the EXIF data.

Ideally, these tools should be used before sorting and grouping the images into individual subfolders, as otherwise, duplicate detection may result in duplicates in different folders, which may then have to be laboriously moved or deleted manually.

For example, I personally perform the following steps to organize the jumble of images.

## Alle Dateinamen in Kleinbuchstaben umbenennen

```bash
./renamer rename:lower sourceDirectory/
```


## Dateiendung angleichen
Alle Dateien mit Endung "jpeg" suchen und nach "jpg" umbenennen. Dazu verwende ich den "pattern" Befehl.

```bash
./renamer rename:pattern --dry-run --pattern "/^(.+)(jpeg)$/" --replacement "\$1jpg" sourceDirectory/
```

- Das Suchen und Ersetzen erfolgt hierbei per regulärem Ausdruck.
  Siehe auch https://www.php.net/manual/en/function.preg-replace.php

- Eine Ersetzung erfolgt für alle eingeklammerten Suchmuster, im Beispiel "(.+)" und "(jpeg)"

- $1 ist eine Rückreferenzierungen (Escaped für die Kommandozeile). Jede dieser Referenzen wird mit dem Text ersetzt,
  der vom n-ten eingeklammerten Suchmuster erfasst wurde. $0 bezieht sich auf den Text, der auf das komplette Suchmuster passt.

- Der Parameter "replacement" gibt an, wie alle Suchmuster durch diese Zeichenkette ersetzt werden.

- Das Suchen/Ersetzen erfolgt zwar rekursiv durch den Verzeichnisbaum, es werdem aber nur jeweils die Dateien in einem 
  Verzeichnis auf Dupliakte hin überprüft und entspr. im Dateinamen markiert.
 

## Zweistellige Jahreszahlen im Dateinamen in vierstellige Jahreszahlen umwandeln
Alle Dateien nach Datumsformat filtern, z.B. 18-12-31 22-15-00.jpg oder 18-12-31-22-15-00-blah.jpg und in das angegebene
Datumsformat umwandeln: 2018-12-31_22-15-00.jpg bzw. 2018-12-31_22-15-00-blah.jpg

```bash
./renamer rename:date-pattern --pattern "/^{y}-{m}-{d}.{H}-{i}-{s}(.+)$/" --replacement "{Y}-{m}-{d}_{H}-{i}-{s}" sourceDirectory/
```

Die Platzhalter im Pattern entsprechen den Formatierungszeichen der Datumsformatfunktion von PHP.
Siehe auch https://www.php.net/manual/de/datetime.format.php

Unterstützt bisher:

| Formatierungszeichen | Beschreibung                                                                  |
|----------------------|-------------------------------------------------------------------------------|
| Y                    | Darstellung einer Jahreszahl; zwei Ziffern                                    |
| y                    | Vollständige numerische Darstellung einer Jahreszahl; mindestens vier Ziffern |
| m                    | Numerische Darstellung eines Monats; mit vorangestellter Null                 |
| d                    | Tag des Monats; zwei Ziffern mit vorangestellter Null                         |
| H                    | Stunde im 24-Stunden-Format; mit vorangestellter Null                         |
| i                    | Minuten; mit vorangestellter Null                                             |
| s                    | Sekunden; mit vorangestellter Null                                            |


## Duplikate anhand des Hashes einer Datei erkennen
Ermittelt für jede Datei den Hash des Dateiinhaltes und gruppiert Dateien mit gleichem Hash zusammen.
Dieser Befehl ermittelt reskursiv in allen Verzeichnissen des jeweiligen Hash und findet somit auch Duplikate von 
Dateien die in anderen Verzeichnissen liegen.

Gerade bei sehr großen Verzeichnissen mit vielen Dateien läuft dieser Befehl unter Umständen sehr lange, da die 
Hash-Berechnung hier die meiste Zeit in Anspruch nimmt.

Bei Verwendung von `--skip-duplicates` verbleiben etwaige auftretende Duplikate im Quellverzeichnis, ansonsten werden 
sie mit einer fortlaufenden Nummer versehen.

```bash
./renamer rename:hash --skip-duplicates sourceDirectory/ targetDirectory/
```


## Live-Fotos umbenennen
Verwendet die EXIF-Daten (genauer das Aufnahmedatum) aus den Bildern, um alle Fotos und Videos mit gleichen Namen 
(ohne Berücksichtigung der Dateierweiterung) nach dem vorgegebenen Muster zu benennen. Über den optionalen 
Parameter `target-filename-pattern` kann das Muster für die Dateien angegeben werden. Dies ist standardmässig 
`Y-m-d_H-i-s-v` (Datum, Zeit inkl. Millisekunden) und resultiert z.B. in `2024-01-20_12-10-05-555`. Das Format für 
das Muster wird in der Form erwartet, wie es der PHP-Methode [format](https://www.php.net/manual/en/datetime.format.php) 
des [DateTime](https://www.php.net/manual/en/book.datetime.php)-Objektes entspricht.

Achtung hier kann es aufgrund unvollständiger EXIF-Daten (z.B. Millisekunden nicht verfügbar) dazu kommen, dass 
unterschiedliche Bilder unter dem gleichen Namen abgelegt und dann als Duplikate markiert werden.

Es werden nur Bilder mit einem gültigen Aufnahmedatum und deren zugehörige Videodatei verarbeitet.
Alle anderen Dateien verbleiben unberührt im Verzeichnis.

```bash
./renamer rename:exifdate sourceDirectory/ 
```

## Testing
```bash
bin/composer update
bin/composer ci:cgl
bin/composer ci:test
bin/composer ci:test:php:phplint
bin/composer ci:test:php:phpstan
bin/composer ci:test:php:rector
```

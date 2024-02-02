
!!! WIP !!!


# Usage
Dies sind ein paar Kommandozeilenbefehle, die ich benutze, um die Fotos in meiner Fotosammlung einheitlich zu benennen.
Auch im Hinblick auf die Apple Live-Fotos (bestehend aus einer Bild- und Video-Datei mit gleichem Namen). Die Tools
helfen mir die Dateien einheitlich nach Datum und Zeit zu benennen und über die EXIF-Daten Dupliakte zu markieren.


Folgende Schritte führe ich z.B. für mich persönlich aus, um Ordnung in den Bilderwust zu bekommen.


## Alle Dateinamen in Kleinbuchstaben umbenennen

```bash
bin/console rename:lower sourceDirectory/
```


## Zweistellige Jahreszahlen im Dateinamen in vierstellige Jahreszahlen umwandeln
Alle Dateien nach Datumsformat filtern, z.B. 18-12-31 22-15-00.jpg oder 18-12-31-22-15-00-blah.jpg und in das angegebene
Datumsformat umwandeln: 2018-12-31_22-15-00.jpg bzw 2018-12-31_22-15-00-blah.jpg

```bash
bin/console rename:date-pattern \
  --pattern "/^{y}-{m}-{d}[.]{H}-{i}-{s}(.+)$/" \ 
  --replacement="{Y}-{m}-{d}_{H}-{i}-{s}" sourceDirectory/
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


## Live-Fotos umbenennen
Verwendet die EXIF-Daten aus den Bildern, um alle Fotos und Videos mit gleichen Namen (ohne Berücksichtigung 
der Dateierweiterung) nach dem vorgegebenen Zieldateinamen zu benennen. 
Etwaige auftretende Duplikate werden, wenn `--skip-duplicates` nicht verwendet wird, mit einer fortlaufenden Nummer versehen.

```bash
bin/console rename:live-photos \
  --skip-duplicates sourceDirectory/ targetDirectory/
```


## Dateiendung angleichen
Alle Dateien mit Endung "jpeg" suchen und nach "jpg" umbenennen. Dazu verwende ich den "pattern" Befehl.
    
```bash
bin/console rename:pattern \
  --pattern "/^(.+)(jpeg)$/" \ 
  --replacement "\$1jpg" sourceDirectory/
```

- Das Suchen und Ersetzen erfolgt hierbei per regulärem Ausdruck. 
  Siehe auch https://www.php.net/manual/en/function.preg-replace.php

- Eine Ersetzung erfolgt für alle eingeklammerten Suchmuster, im Beispiel "(.+)" und "(jpeg)"

- $1 ist eine Rückreferenzierungen (Escaped für die Kommandozeile). Jede dieser Referenzen wird mit dem Text ersetzt, 
  der vom n-ten eingeklammerten Suchmuster erfasst wurde. $0 bezieht sich auf den Text, der auf das komplette Suchmuster passt.

- Der Parameter "replacement" gibt an, wie alle Suchmuster durch diese Zeichenkette ersetzt werden.

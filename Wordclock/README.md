# Wordclock – IP-Symcon Modul

Dieses Modul integriert eine **Wordclock LED-Uhr** über **MQTT** in IP-Symcon.

Es wertet den Status der Uhr aus, stellt komfortable Steuervariablen bereit (Helligkeit, Farbe, Effekte) und sendet Änderungen automatisch über MQTT zurück an die Uhr.

## Funktionen

- Empfängt Wordclock-Status über MQTT (`<Topic>/status`)
- Stellt Steuervariablen in IP-Symcon bereit
- Sendet Änderungen automatisch per MQTT (`<Topic>/cmd`)
- Farbauswahl über HexColor + automatische Umrechnung in Hue/Saturation
- Einstellbarer Effekt über Variablenprofil `Wordclock.Effect`
- Frei definierbarer Lauftext. Die Laufzeit des Textes kann im Modul vorgegeben werden.. Die Geschwindigkeit muss jedoch in der Konfiguration der Wordclock definiert werden.
- Debug-Ausgaben für alle MQTT-Ein-/Ausgänge

## Installation

1. Repository als Modul in IP-Symcon einbinden
2. Instanz **Wordclock** anlegen
3. Beim Anlegen der Instanz wird ebenfalls eine MQTT-Server Instanz angelegt, wenn noch keine besteht
4. MQTT-Server und Server-Socket mit der Wordclock-Instanz verbinden und konfigurieren
5. Basis-Topic im Konfigurationsformular konfigurieren
6. Die Wordclock muss danach allenfalls neu gestartet werden

## Konfigurationsformular

| Option | Beschreibung |
|--------|--------------|
| **Basis-Topic** | MQTT-Basis-Topic aus der Konfgiguration - Smart Home der Wordclock |

## Erzeugte Variablen

| Ident | Name | Typ | Profil | Beschreibung |
|-------|------|------|---------|--------------|
| `Color` | Farbe | Integer | `~HexColor` | Farbe |
| `Brightness` | Helligkeit | Float | `~Intensity.100` | 0–100% |
| `Hue` | Farbton | Integer | `Wordclock.Hue` | 0–360° |
| `Saturation` | Sättigung | Integer | `~Intensity.100` | 0–100% |
| `Effect` | Effekt | Integer | `Wordclock.Effect` | Effektliste |
| `ScrollingText` | Lauftext| String | `~Textbox` | Eingabefeld |
| `ScrollingDuration` | Lauftext Dauer | Integer | `Wordclock.ScrollDuration` | Dauer des Lauftextes |

## Variablenprofile

### Wordclock.Hue
- Integer 0–360  
- Icon: Bulb

### Wordclock.Effect
| Wert | Name |
|------|-------|
| 0 | Wordclock |
| 1 | Seconds |
| 2 | Digitalclock |
| 3 | Scrollingtext |
| 4 | Rainbowcycle |
| 5 | Rainbow |
| 6 | Color |
| 7 | Symbol |

### Wordclock.ScrollDuration
- Integer 0–600  
- Icon: Clock

## MQTT Kommunikation

### Empfang: `<Topic>/status`
```json
{
  "state": "ON",
  "brightness": 180,
  "color": { "h": 210, "s": 80 }
}
```

### Senden: `<Topic>/cmd`
```json
{
  "state": "ON",
  "effect": "Scrollingtext",
  "color": { "h": 210, "s": 80 },
  "brightness": 180,
  "scrolling_text": " Hallo Symcon"
}
```
Andere Parameter sendet oder empfängt die Wordclock nicht.

## PHP-Befehlsreferenz

| Wert | Name |
|------|-------|
| WCLOCK_ShowScrollingText(12345, "Mein Lauftext!", 20, "#FFFFFF"); | Lauftext für 20 Sekunden in Weiss anzeigen |
| WCLOCK_ShowScrollingText(12345, "Mein Lauftext!", 30, "#FF0000"); | Lauftext für 30 Sekunden in Rot anzeigen |
| WCLOCK_ShowScrollingText(12345, "Mein Lauftext!", 40, ""); | Lauftext für 40 Sekunden ohne Farbänderung anzeigen |


| Farbe | Hex |
| Rot | #FF0000 |
| Grün | #00FF00 |
| Blau | #0000FF |
| Gelb | #FFFF00 |
| Weiss | #FFFFFF |
| Pink | #FF00FF |

Die Farbe wird im Hex-Format eingegeben. Weitere Farben: https://htmlcolorcodes.com/

## Debug Log

Geloggte Infos:

- Eingehende MQTT-Daten
- Ausgehende Kommandos
- WebFront-Aktionen
- RGB/HSV Berechnungen

## Versionen

### Version 1.2 (23.11.2025)
- Lauftext jetzt mit Farbangabe in Hex.

### Version 1.1 (16.11.2025)
- Standard Topic auf ESPWordclock geändert, was dem Standard der Uhr entspricht.
- Helligkeit wird nun in Prozent dargestellt.
- Debug überarbeitet.
- Nach Möglichkeit Symcon eigene Profile verwendet.
- Lauftext mit Laufzeit.

### Version 1.0 (15.11.2025)
- Initiale Version.

## Lizenz

MIT License  
(c) 2025 Stefan Künzli

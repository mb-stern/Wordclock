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

## MQTT Kommunikation

### Empfang: `<Topic>/status`
```json
{
  "state": "ON",
  "brightness": 150,
  "color": { "h": 210, "s": 80 }
}
```

### Senden: `<Topic>/cmd`
```json
{
  "state": "ON",
  "color": { "h": 210, "s": 80 },
  "brightness": 150,
  "effect": "Rainbow"
}
```

## Befehle senden

### Lauftext über Automation senden`

Beispiel eines Lauftextes welcher 20 Sekunden angezeigt werden soll
```
WCLOCK_ShowScrollingText(INSTANZ-ID, "Mein Lauftext!", 20);
```

## Debug Log

Geloggte Infos:

- Eingehende MQTT-Daten
- Ausgehende Kommandos
- WebFront-Aktionen
- RGB/HSV Berechnungen

## Versionen

### Version 1.1 (16.11.2025)
- Standard Topic auf ESPWordclock geändert, was dem Standard der Uhr entspricht.
- Helligkeit wird nun in Prozent dargestellt.
- Debug überarbeitet.
- Nach Möglichkeit Symcon eigene Profile verwendet.
- Zusätzlich Variable zur Eingabe des Lauftextes.

### Version 1.0 (15.11.2025)
- Initiale Version.

## Lizenz

MIT License  
(c) 2025 Stefan Künzli

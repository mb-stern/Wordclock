# Wordclock – IP-Symcon Modul

Dieses Modul integriert eine **Wordclock LED-Uhr** über **MQTT** in IP-Symcon.

Es wertet den Status der Uhr aus, stellt komfortable Steuervariablen bereit (Helligkeit, Farbe, Effekte) und sendet Änderungen automatisch über MQTT zurück an die Uhr.

## Funktionen

- Empfängt Wordclock-Status über MQTT (`<Topic>/status`)
- Stellt Steuervariablen in IP-Symcon bereit
- Sendet Änderungen automatisch per MQTT (`<Topic>/cmd`)
- Farbauswahl über HexColor + automatische Umrechnung in Hue/Saturation
- Einstellbarer Effekt über Variablenprofil `Wordclock.Effect`
- Debug-Ausgaben für alle MQTT-Ein-/Ausgänge
- Keine Timer oder Events notwendig

## Installation

1. Repository als Modul in IP-Symcon einbinden
2. Instanz **Wordclock** anlegen
3. Basis-Topic konfigurieren
4. MQTT-Server/Client als Parent verbinden

## Konfigurationsformular

| Option | Beschreibung |
|--------|--------------|
| **Basis-Topic** | MQTT-Basis-Topic ohne `/status` oder `/cmd` |

## Erzeugte Variablen

| Ident | Name | Typ | Profil | Beschreibung |
|-------|------|------|---------|--------------|
| `Color` | Farbe | Integer | `~HexColor` | Farbe |
| `Brightness` | Helligkeit | Float | `Wordclock.Brightness` | 0–255 |
| `Hue` | Farbton | Integer | `Wordclock.Hue` | 0–360° |
| `Saturation` | Sättigung | Integer | `Wordclock.Saturation` | 0–100% |
| `Effect` | Effekt | Integer | `Wordclock.Effect` | Effektliste |

## Variablenprofile

### Wordclock.Brightness
- Float 0–255  
- Icon: Intensity

### Wordclock.Hue
- Integer 0–360  
- Icon: Bulb

### Wordclock.Saturation
- Integer 0–100  
- Icon: Intensity

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

## Debug Log

Geloggte Infos:

- Eingehende MQTT-Daten
- Ausgehende Kommandos
- WebFront-Aktionen
- RGB/HSV Berechnungen

## Lizenz

MIT License  
(c) 2025 Stefan Künzli

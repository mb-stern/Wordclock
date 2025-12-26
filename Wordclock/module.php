<?php

class Wordclock extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        // Eigenschaften
        $this->RegisterPropertyString('Topic', 'ESPWordclock');

        // Interner Timestamp für Throttle
        $this->RegisterAttributeInteger('LastParseTS', 0);

        // Ursprünglicher Effekt für Lauftext-Rückkehr
        $this->RegisterAttributeInteger('PreviousEffect', -1);

        // Ursprüngliche Farbe für Lauftext-Rückkehr
        $this->RegisterAttributeInteger('PreviousColor', -1);

        // Timer für Lauftext-Rückkehr (ms)
        $this->RegisterTimer('ScrollingReset', 0, 'WCLOCK_ScrollingReset($_IPS[\'TARGET\']);');
    }
    
    public function GetCompatibleParents(): string
    {
        $json = json_encode([
            'type'      => 'require',
            'moduleIDs' => [
                // MQTT-Server (Splitter)
                '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}'
            ]
        ]);

        return ($json !== false) ? $json : '[]';
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Profile anlegen
        $this->EnsureProfiles();

        // Variablen anlegen

        // Farbe (HexColor)
        $this->RegisterVariableInteger(
            'Color',
            'Farbe',
            '~HexColor',
            5
        );
        $this->EnableAction('Color');

        // Helligkeit 0–100% (Integer mit Standardprofil)
        $this->RegisterVariableInteger(
            'Brightness',
            'Helligkeit',
            '~Intensity.100',
            10
        );
        $this->EnableAction('Brightness');

        // Effekt (Integer mit Profil)
        $this->RegisterVariableInteger(
            'Effect',
            'Effekt',
            'Wordclock.Effect',
            15
        );
        $this->EnableAction('Effect');

        // Hue 0–360° (eigenes Profil)
        $this->RegisterVariableInteger(
            'Hue',
            'Farbton',
            'Wordclock.Hue',
            20
        );
        $this->EnableAction('Hue');

        // Saturation 0–100% (Standardprofil)
        $this->RegisterVariableInteger(
            'Saturation',
            'Sättigung',
            '~Intensity.100',
            25
        );
        $this->EnableAction('Saturation');

        // Scrolling-Text
        $this->RegisterVariableString(
            'ScrollingText',
            'Lauftext',
            '~TextBox',
            30
        );
        $this->EnableAction('ScrollingText');

        // Lauftext-Dauer in Sekunden (0 = unendlich)
        $this->RegisterVariableInteger(
            'ScrollingDuration',
            'Lauftext Dauer',
            'Wordclock.ScrollDuration',
            35
        );
        $this->EnableAction('ScrollingDuration');
    }

    public function GetConfigurationForm(): string
    {
        $form = [
            'elements' => [
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'Topic',
                    'caption' => 'Basis-Topic',
                    'width'   => '400px'
                ]
            ]
        ];

        $json = json_encode($form);
        return ($json !== false) ? $json : '{}';
    }

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) {
            return '';
        }

        $this->SendDebug('ReceiveData', 'DataID=' . ($data['DataID'] ?? 'n/a'), 0);

        // Basis-Topic holen und /status anhängen
        $baseTopic = rtrim($this->ReadPropertyString('Topic'), '/');
        if ($baseTopic === '') {
            return '';
        }
        $statusTopic = $baseTopic . '/status';

        // Nur Status-Topic der Wordclock verarbeiten
        if (!isset($data['Topic']) || $data['Topic'] !== $statusTopic) {
            return '';
        }

        // Throttle: max. 1x/s
        $now  = time();
        $last = $this->ReadAttributeInteger('LastParseTS');
        if (($now - $last) < 1) {
            return '';
        }
        $this->WriteAttributeInteger('LastParseTS', $now);

        if (!isset($data['Payload'])) {
            return '';
        }

        // --- Payload lesen (HEX aus Datenfluss) ---
        $payloadHex = (string)$data['Payload'];
        $payloadHexTrim = trim($payloadHex);
        if ($payloadHexTrim === '') {
            return '';
        }

        // HEX -> BIN (decoded JSON-String)
        $payloadBin = (ctype_xdigit($payloadHexTrim) && (strlen($payloadHexTrim) % 2 === 0)) ? hex2bin($payloadHexTrim) : false;
        $payloadJson = ($payloadBin === false) ? $payloadHexTrim : $payloadBin;

        // Debug: Roh-HEX + decoded JSON (eine Zeile)
        $this->SendDebug('ReceiveData', 'Topic=' . $data['Topic'] . ', Payload(HEX)=' . $payloadHexTrim, 0);
        $this->SendDebug('ReceiveData', 'Payload(decoded)=' . $payloadJson, 0);

        // Debug: optional pretty (nur wenn JSON gültig)
        $state = json_decode($payloadJson, true);
        if (!is_array($state)) {
            $this->SendDebug('ReceiveData', 'JSON decode failed', 0);
            return '';
        }
        $pretty = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($pretty !== false) {
            $this->SendDebug('ReceiveData', "Payload(pretty)\n" . $pretty, 0);
        }

        $h    = null;
        $s    = null;
        $v255 = null;

        // brightness (0–255 von der Uhr) -> 0–100% in Symcon
        if (isset($state['brightness'])) {
            $v255 = (float)$state['brightness'];

            $percent = (int)round(($v255 / 255.0) * 100.0);
            if ($percent < 0) {
                $percent = 0;
            } elseif ($percent > 100) {
                $percent = 100;
            }

            $this->SetValueIntegerIfChanged('Brightness', $percent);
        }

        // color.h (0–360)
        if (isset($state['color']['h'])) {
            $h = (int)$state['color']['h'];
            $this->SetValueIntegerIfChanged('Hue', $h);
        }

        // color.s (0–100)
        if (isset($state['color']['s'])) {
            $s = (int)$state['color']['s'];
            $this->SetValueIntegerIfChanged('Saturation', $s);
        }

        // RGB-Wert für ~HexColor berechnen, wenn alle drei Werte da sind
        if ($h !== null && $s !== null && $v255 !== null) {
            $rgb = $this->HSVtoRGB((float)$h, (float)$s, (float)$v255);  // h:0–360, s:0–100, v:0–255
            $colorInt = ($rgb['r'] << 16) | ($rgb['g'] << 8) | $rgb['b'];
            $this->SetValueIntegerIfChanged('Color', $colorInt);
        }

        // Optionaler Return an Parent (meist leer)
        return '';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        // Änderung aus dem Modul (eine Zeile)
        $this->SendDebug('RequestAction', $Ident . '=' . json_encode($Value), 0);

        $includeEffect   = false;
        $scrollingTextTx = '';

        switch ($Ident) {
            case 'Brightness': // 0–100 %
                $val = (int)$Value;
                if ($val < 0) {
                    $val = 0;
                } elseif ($val > 100) {
                    $val = 100;
                }
                $this->SetValue('Brightness', $val);
                break;

            case 'Hue':
                $this->SetValue('Hue', (int)$Value);
                break;

            case 'Saturation':
                $this->SetValue('Saturation', (int)$Value);
                break;

            case 'Color':
                // Farbe setzen
                $colorInt = (int)$Value;
                $this->SetValue('Color', $colorInt);

                // RGB -> HSV umrechnen und Hue/Sat/Brightness aktualisieren
                $r = ($colorInt >> 16) & 0xFF;
                $g = ($colorInt >> 8) & 0xFF;
                $b = $colorInt & 0xFF;

                $hsv = $this->RGBtoHSV($r, $g, $b); // h:0–360, s:0–100, v:0–255

                $this->SetValue('Hue', (int)round($hsv['h']));
                $this->SetValue('Saturation', (int)round($hsv['s']));

                $percent = (int)round(($hsv['v'] / 255.0) * 100.0);
                if ($percent < 0) {
                    $percent = 0;
                } elseif ($percent > 100) {
                    $percent = 100;
                }
                $this->SetValue('Brightness', $percent);
                break;

            case 'Effect':
                $this->SetValue('Effect', (int)$Value);
                $includeEffect = true;

                // Manueller Effektwechsel: Auto-Rückkehr deaktivieren
                $this->SetTimerInterval('ScrollingReset', 0);
                $this->WriteAttributeInteger('PreviousEffect', -1);
                break;

            case 'ScrollingText':
                $newText = (string)$Value;

                // Originaltext mit Umlauten in der Variable speichern
                $this->SetValue('ScrollingText', $newText);

                // Text für die Uhr normalisieren (Umlaute + führendes Leerzeichen)
                $normalized      = $this->NormalizeScrollingText($newText);
                $sendText        = ' ' . $normalized; // 1 führendes Leerzeichen als Vorlauf
                $scrollingTextTx = $sendText;

                // aktuellen Effekt & PreviousEffect prüfen
                $currentEffectIdx  = (int)$this->GetValue('Effect');
                $currentEffectName = $this->EffectIndexToName($currentEffectIdx);

                $prev = $this->ReadAttributeInteger('PreviousEffect');

                // Ursprünglichen Effekt nur dann merken, wenn noch keiner gespeichert ist
                // und wir nicht schon im Scrollingtext sind
                if ($prev < 0 && $currentEffectName !== 'Scrollingtext') {
                    $this->WriteAttributeInteger('PreviousEffect', $currentEffectIdx);
                    $this->SendDebug('ScrollingText', 'PreviousEffect gesetzt auf ' . $currentEffectIdx, 0);
                }

                // auf Scrollingtext schalten, falls noch nicht aktiv
                if ($currentEffectName !== 'Scrollingtext') {
                    $effects = $this->GetEffectList();
                    $idx     = array_search('Scrollingtext', $effects, true);
                    if ($idx !== false) {
                        $this->SetValue('Effect', (int)$idx);
                        $includeEffect = true;
                    }
                }

                // Laufzeit in Sekunden holen
                $duration = 0;
                if (@$this->GetIDForIdent('ScrollingDuration')) {
                    $duration = (int)$this->GetValue('ScrollingDuration');
                }
                if ($duration < 0) {
                    $duration = 0;
                }

                $this->SendDebug(
                    'ScrollingText',
                    'Duration=' . $duration . 's, StoredPreviousEffect=' . $this->ReadAttributeInteger('PreviousEffect'),
                    0
                );

                if ($duration === 0) {
                    // unendlich: Timer aus
                    $this->SetTimerInterval('ScrollingReset', 0);
                } else {
                    // Timer in ms
                    $this->SetTimerInterval('ScrollingReset', $duration * 1000);
                }
                break;

            case 'ScrollingDuration':
                $val = (int)$Value;
                if ($val < 0) {
                    $val = 0;
                }
                $this->SetValue('ScrollingDuration', $val);

                // Wenn gerade Scrollingtext aktiv ist, Timer entsprechend anpassen
                $currentEffectName = $this->EffectIndexToName((int)$this->GetValue('Effect'));
                if ($currentEffectName === 'Scrollingtext') {
                    if ($val === 0) {
                        // unendlich: Timer aus
                        $this->SendDebug('ScrollingDuration', 'Timer aus (unendlich)', 0);
                        $this->SetTimerInterval('ScrollingReset', 0);
                    } else {
                        $this->SendDebug('ScrollingDuration', 'Timer auf ' . ($val * 1000) . ' ms gesetzt', 0);
                        $this->SetTimerInterval('ScrollingReset', $val * 1000);
                    }
                }
                break;

            default:
                throw new Exception('Invalid Ident');
        }

        // Für alle Änderungen normalen State senden, ggf. inkl. Text
        $this->SendStateToWordclock($includeEffect, $scrollingTextTx);
    }

    public function ShowScrollingText(string $text, int $duration = 0, string $hexColor = ''): void
    {
        if ($duration < 0) {
            $duration = 0;
        }

        // Dauer im Modul setzen
        if (@$this->GetIDForIdent('ScrollingDuration')) {
            $this->SetValue('ScrollingDuration', $duration);
        }

        // Farbe nur ändern, wenn hexColor nicht leer ist
        if ($hexColor !== '' && @$this->GetIDForIdent('Color')) {

            // Falls mit "#" angegeben wurde, entfernen
            $hexColor = ltrim($hexColor, '#');

            // Hex in Integer umwandeln
            $colorInt = (int)hexdec($hexColor);

            // Ursprungsfarbe nur speichern, wenn noch nicht gespeichert
            $prevColor = $this->ReadAttributeInteger('PreviousColor');
            if ($prevColor < 0) {
                $currentColor = (int)$this->GetValue('Color');
                $this->WriteAttributeInteger('PreviousColor', $currentColor);
                $this->SendDebug('ShowScrollingText', 'PreviousColor gesetzt auf ' . $currentColor, 0);
            }

            // Neue Farbe setzen (über bestehende Logik)
            $this->RequestAction('Color', $colorInt);
        }

        // Text setzen
        $this->RequestAction('ScrollingText', $text);
    }

    public function ScrollingReset(): void
    {
        $this->SendDebug('ScrollingReset', 'Timer ausgelöst', 0);

        // Timer stoppen
        $this->SetTimerInterval('ScrollingReset', 0);

        $prevEffect = $this->ReadAttributeInteger('PreviousEffect');
        $prevColor  = $this->ReadAttributeInteger('PreviousColor');

        $includeEffect = false;

        // Ursprünglichen Effekt wiederherstellen (falls vorhanden)
        if ($prevEffect >= 0) {
            $this->SetValue('Effect', $prevEffect);
            $this->WriteAttributeInteger('PreviousEffect', -1);
            $includeEffect = true;
            $this->SendDebug('ScrollingReset', 'Wechsele zurück zu EffektIdx=' . $prevEffect, 0);
        } else {
            $this->SendDebug('ScrollingReset', 'Kein PreviousEffect gesetzt', 0);
        }

        // Ursprüngliche Farbe wiederherstellen (falls vorhanden)
        if ($prevColor >= 0 && @$this->GetIDForIdent('Color')) {
            $this->SendDebug('ScrollingReset', 'Stelle PreviousColor wieder her: ' . $prevColor, 0);

            $colorInt = $prevColor;
            $this->SetValue('Color', $colorInt);

            // Hue/Saturation/Brightness passend zur ursprünglichen Farbe setzen
            $r = ($colorInt >> 16) & 0xFF;
            $g = ($colorInt >> 8) & 0xFF;
            $b = $colorInt & 0xFF;

            $hsv = $this->RGBtoHSV($r, $g, $b); // h:0–360, s:0–100, v:0–255

            $this->SetValue('Hue', (int)round($hsv['h']));
            $this->SetValue('Saturation', (int)round($hsv['s']));

            $percent = (int)round(($hsv['v'] / 255.0) * 100.0);
            if ($percent < 0) {
                $percent = 0;
            } elseif ($percent > 100) {
                $percent = 100;
            }
            $this->SetValue('Brightness', $percent);

            $this->WriteAttributeInteger('PreviousColor', -1);
        } else {
            $this->SendDebug('ScrollingReset', 'Keine PreviousColor gesetzt', 0);
        }

        // State mit ggf. neuem Effekt und wiederhergestellter Farbe senden (ohne scrolling_text)
        $this->SendStateToWordclock($includeEffect, '');
    }

    private function SendStateToWordclock(bool $includeEffect, string $scrollingText = ''): void
    {
        $baseTopic = rtrim($this->ReadPropertyString('Topic'), '/');
        if ($baseTopic === '') {
            return;
        }
        $commandTopic = $baseTopic . '/cmd';

        // Helligkeit in % (0–100) -> 0–255 für die Uhr
        $brightnessPercent = (int)$this->GetValue('Brightness');
        if ($brightnessPercent < 0) {
            $brightnessPercent = 0;
        } elseif ($brightnessPercent > 100) {
            $brightnessPercent = 100;
        }
        $brightness255 = (int)round(($brightnessPercent / 100.0) * 255.0);

        $h          = (int)$this->GetValue('Hue');
        $s          = (int)$this->GetValue('Saturation');
        $effectIdx  = (int)$this->GetValue('Effect');
        $effectName = $this->EffectIndexToName($effectIdx);

        // TX: Werte der gesendeten Variablen (inkl. Text)
        $valuesLog = sprintf(
            'Values: Brightness=%d%% (%d), Hue=%d, Saturation=%d, EffectIdx=%d, EffectName=%s, ScrollingText="%s"',
            $brightnessPercent,
            $brightness255,
            $h,
            $s,
            $effectIdx,
            $effectName ?? 'null',
            $scrollingText
        );
        $this->SendDebug('SendState', $valuesLog, 0);

        $payload = [
            'state'      => 'ON',
            'color'      => ['h' => $h, 's' => $s],
            'brightness' => $brightness255
        ];

        if ($includeEffect && $effectName !== null) {
            $payload['effect'] = $effectName;
        }

        if ($scrollingText !== '') {
            $payload['scrolling_text'] = $scrollingText;
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false) {
            $this->SendDebug('SendState', 'json_encode fehlgeschlagen', 0);
            return;
        }

        // TX: Topic + Payload
        $this->SendDebug('SendState', 'Topic=' . $commandTopic . ', Payload=' . $jsonPayload, 0);

        $mqttPacket = [
            'DataID'           => '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}', // TX
            'PacketType'       => 3,
            'QualityOfService' => 0,
            'Retain'           => false,
            'Topic'            => $commandTopic,
            'Payload'          => bin2hex($jsonPayload)
        ];

        $packet = json_encode($mqttPacket);
        if ($packet === false) {
            $this->SendDebug('SendState', 'json_encode mqttPacket fehlgeschlagen', 0);
            return;
        }

        $this->SendDataToParent($packet);
    }

    private function NormalizeScrollingText(string $text): string
    {
        // deutsche Umlaute ersetzen
        $map = [
            'ä' => 'ae',
            'Ä' => 'Ae',
            'ö' => 'oe',
            'Ö' => 'Oe',
            'ü' => 'ue',
            'Ü' => 'Ue',
            'ß' => 'ss'
        ];
        $text = strtr($text, $map);

        // nicht druckbare / nicht ASCII Zeichen entfernen
        $text = preg_replace('/[^\x20-\x7E]/', '', $text);

        return $text;
    }

    private function SetValueIntegerIfChanged(string $Ident, int $new): void
    {
        $old = $this->GetValue($Ident);
        if ($old !== $new) {
            $this->SetValue($Ident, $new);
        }
    }

    private function SetValueFloatIfChanged(string $Ident, float $new): void
    {
        $old = (float)$this->GetValue($Ident);
        if (abs($old - $new) > 0.0001) {
            $this->SetValue($Ident, $new);
        }
    }

    private function EnsureProfiles(): void
    {
        $ensureProfile = function (string $name, int $type, ?callable $init = null): void {
            if (IPS_VariableProfileExists($name)) {
                $p = IPS_GetVariableProfile($name);
                if ($p['ProfileType'] != $type) {
                    IPS_DeleteVariableProfile($name);
                } else {
                    return;
                }
            }
            IPS_CreateVariableProfile($name, $type);
            if ($init) {
                $init($name);
            }
        };

        // Hue: 0–360° (eigenes Profil)
        $ensureProfile('Wordclock.Hue', VARIABLETYPE_INTEGER, function (string $name): void {
            IPS_SetVariableProfileValues($name, 0, 360, 1);
            IPS_SetVariableProfileText($name, '', '°');
            IPS_SetVariableProfileIcon($name, 'Bulb');
        });

        // Effektprofil
        $ensureProfile('Wordclock.Effect', VARIABLETYPE_INTEGER, function (string $name): void {
            IPS_SetVariableProfileValues($name, 0, 7, 1);
            IPS_SetVariableProfileIcon($name, 'Script');

            $effects = $this->GetEffectList();
            foreach ($effects as $idx => $effName) {
                IPS_SetVariableProfileAssociation($name, (int)$idx, (string)$effName, '', -1);
            }
        });

        // Lauftext-Dauer: 0–600s, Schritt 5
        $ensureProfile('Wordclock.ScrollDuration', VARIABLETYPE_INTEGER, function (string $name): void {
            IPS_SetVariableProfileValues($name, 0, 600, 5);
            IPS_SetVariableProfileText($name, '', ' s');
            IPS_SetVariableProfileIcon($name, 'Clock');
        });
    }

    private function GetEffectList(): array
    {
        return [
            0 => 'Wordclock',
            1 => 'Seconds',
            2 => 'Digitalclock',
            3 => 'Scrollingtext',
            4 => 'Rainbowcycle',
            5 => 'Rainbow',
            6 => 'Color',
            7 => 'Symbol'
        ];
    }

    private function EffectIndexToName(int $idx): ?string
    {
        $effects = $this->GetEffectList();
        return $effects[$idx] ?? null;
    }

    private function RGBtoHSV(int $r, int $g, int $b): array
    {
        $r_f = $r / 255.0;
        $g_f = $g / 255.0;
        $b_f = $b / 255.0;

        $max   = max($r_f, $g_f, $b_f);
        $min   = min($r_f, $g_f, $b_f);
        $delta = $max - $min;

        $h = 0.0;
        if ($delta == 0) {
            $h = 0;
        } elseif ($max == $r_f) {
            $h = 60 * fmod((($g_f - $b_f) / $delta), 6);
        } elseif ($max == $g_f) {
            $h = 60 * ((($b_f - $r_f) / $delta) + 2);
        } else {
            $h = 60 * ((($r_f - $g_f) / $delta) + 4);
        }
        if ($h < 0) {
            $h += 360;
        }

        $s = ($max == 0) ? 0 : ($delta / $max) * 100;
        $v = $max * 255;

        return ['h' => $h, 's' => $s, 'v' => $v];
    }

    private function HSVtoRGB(float $h, float $s, float $v): array
    {
        $s /= 100.0;
        $v /= 255.0;

        $c = $v * $s;
        $x = $c * (1 - abs(fmod($h / 60.0, 2) - 1));
        $m = $v - $c;

        if ($h < 60) {
            $r1 = $c;
            $g1 = $x;
            $b1 = 0;
        } elseif ($h < 120) {
            $r1 = $x;
            $g1 = $c;
            $b1 = 0;
        } elseif ($h < 180) {
            $r1 = 0;
            $g1 = $c;
            $b1 = $x;
        } elseif ($h < 240) {
            $r1 = 0;
            $g1 = $x;
            $b1 = $c;
        } elseif ($h < 300) {
            $r1 = $x;
            $g1 = 0;
            $b1 = $c;
        } else {
            $r1 = $c;
            $g1 = 0;
            $b1 = $x;
        }

        $r = (int)round(($r1 + $m) * 255);
        $g = (int)round(($g1 + $m) * 255);
        $b = (int)round(($b1 + $m) * 255);

        return ['r' => $r, 'g' => $g, 'b' => $b];
    }
}

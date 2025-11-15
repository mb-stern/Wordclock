<?php

declare(strict_types=1);

class Wordclock extends IPSModule
{
  public function Create()
    {
        parent::Create();

        // MQTT-Parent verbinden (Struktur wie bei deinem Goodwe-Modul)
        $this->ConnectParent('{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}');

        // Eigenschaften
        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('StatusTopic', 'Wordclock/status');
        $this->RegisterPropertyString('CommandTopic', 'Wordclock/cmd');

        // Interner Timestamp für Throttle (statt eigener Variable)
        $this->RegisterAttributeInteger('LastParseTS', 0);

        // Profile einmalig anlegen
        $this->EnsureProfiles();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Wenn deaktiviert, keine Variablen etc.
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }

        // --- Variablen: mit RegisterVariable..., nicht Maintain... ---

        // Farbe (HexColor)
        $this->RegisterVariableInteger(
            'Color',
            'Farbe',
            '~HexColor',
            5
        );
        $this->EnableAction('Color');

        // Helligkeit 0–255 (Float)
        $this->RegisterVariableFloat(
            'Brightness',
            'Helligkeit',
            'Wordclock.Brightness',
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

        // Hue 0–360°
        $this->RegisterVariableInteger(
            'Hue',
            'Farbton',
            'Wordclock.Hue',
            20
        );
        $this->EnableAction('Hue');

        // Saturation 0–100%
        $this->RegisterVariableInteger(
            'Saturation',
            'Sättigung',
            'Wordclock.Saturation',
            25
        );
        $this->EnableAction('Saturation');
    }

    public function GetConfigurationForm()
    {
        $form = [
            'elements' => [
                [
                    'type'    => 'CheckBox',
                    'name'    => 'Active',
                    'caption' => 'Aktiv'
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'StatusTopic',
                    'caption' => 'Status-Topic (von Wordclock)',
                    'width'   => '400px'
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'CommandTopic',
                    'caption' => 'Command-Topic (zur Wordclock)',
                    'width'   => '400px'
                ]
            ]
        ];

        return json_encode($form);
    }

    /**
     * ReceiveData – wird vom MQTT-Parent aufgerufen
     */
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) {
            return;
        }

        // RX-DataID des MQTT-Splitters prüfen (optional, aber sauber)
        if (!isset($data['DataID']) || $data['DataID'] !== '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}') {
            return;
        }

        $statusTopic = $this->ReadPropertyString('StatusTopic');
        if ($statusTopic === '') {
            return;
        }

        // Nur das gewünschte Status-Topic verarbeiten
        if (!isset($data['Topic']) || $data['Topic'] !== $statusTopic) {
            return;
        }

        // Throttle: max. 1x/s (wie dein InternalTS im Script)
        $now  = time();
        $last = $this->ReadAttributeInteger('LastParseTS');
        if (($now - $last) < 1) {
            return;
        }
        $this->WriteAttributeInteger('LastParseTS', $now);

        if (!isset($data['Payload'])) {
            return;
        }

        $json = (string)$data['Payload'];
        if (trim($json) === '') {
            return;
        }

        $state = json_decode($json, true);
        if (!is_array($state)) {
            return;
        }

        // ---- Logik aus deinem ursprünglichen Script ----

        $h = null;
        $s = null;
        $v = null;

        // brightness (0–255)
        if (isset($state['brightness'])) {
            $v = (float)$state['brightness'];
            $this->SetValueFloatIfChanged('Brightness', $v);
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

        // Effekt wird bewusst NICHT aus dem JSON übernommen

        // Color (Hex) aus HSV, wenn alles vorhanden
        if ($h !== null && $s !== null && $v !== null) {
            $rgb = $this->HSVtoRGB($h, $s, $v);  // h:0–360, s:0–100, v:0–255
            $colorInt = ($rgb['r'] << 16) | ($rgb['g'] << 8) | $rgb['b'];
            $this->SetValueIntegerIfChanged('Color', $colorInt);
        }
    }

    /**
     * RequestAction – reagiert auf WebFront/Skript-Änderungen
     */
    public function RequestAction($Ident, $Value)
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }

        $includeEffect = false;

        switch ($Ident) {
            case 'Brightness':
                $this->SetValue('Brightness', (float)$Value);
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

                // RGB -> HSV umrechnen und Hue/Sat aktualisieren
                $r = ($colorInt >> 16) & 0xFF;
                $g = ($colorInt >> 8) & 0xFF;
                $b = $colorInt & 0xFF;

                $hsv = $this->RGBtoHSV($r, $g, $b); // h:0–360, s:0–100, v:0–255
                $this->SetValue('Hue', (int)round($hsv['h']));
                $this->SetValue('Saturation', (int)round($hsv['s']));
                // $hsv['v'] ignorieren, da Helligkeit separat geführt wird
                break;

            case 'Effect':
                $this->SetValue('Effect', (int)$Value);
                $includeEffect = true;
                break;

            default:
                throw new Exception('Invalid Ident');
        }

        // Danach Zustand zur Wordclock senden
        $this->SendStateToWordclock($includeEffect);
    }

    /**
     * Zustand als JSON an das Command-Topic publizieren
     */
    private function SendStateToWordclock(bool $includeEffect): void
    {
        $commandTopic = $this->ReadPropertyString('CommandTopic');
        if ($commandTopic === '') {
            return;
        }

        $brightness = $this->GetValue('Brightness');
        $h          = $this->GetValue('Hue');
        $s          = $this->GetValue('Saturation');
        $effectIdx  = $this->GetValue('Effect');

        $effectName = $this->EffectIndexToName($effectIdx);

        $payload = [
            'state'      => 'ON',
            'color'      => ['h' => $h, 's' => $s],
            'brightness' => $brightness
        ];

        if ($includeEffect && $effectName !== null) {
            $payload['effect'] = $effectName;
        }

        $mqttPacket = [
            'DataID'           => '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}', // TX
            'PacketType'       => 3, // PUBLISH
            'QualityOfService' => 0,
            'Retain'           => false,
            'Topic'            => $commandTopic,
            'Payload'          => $json
        ];

        $this->SendDataToParent(json_encode($mqttPacket));
    }

    // -----------------------------------------------------------------
    // Helper: SetValue only if changed
    // -----------------------------------------------------------------

    private function SetValueIntegerIfChanged(string $Ident, int $new): void
    {
        if ($this->GetValue($Ident) !== $new) {
            $this->SetValue($Ident, $new);
        }
    }

    private function SetValueFloatIfChanged(string $Ident, float $new): void
    {
        if (abs((float)$this->GetValue($Ident) - $new) > 0.0001) {
            $this->SetValue($Ident, $new);
        }
    }

    // -----------------------------------------------------------------
    // Profile & Effekte
    // -----------------------------------------------------------------

    private function EnsureProfiles(): void
    {
        $ensureProfile = function (string $name, int $type, callable $init = null) {
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

        // Brightness: 0–255 (Float)
        $ensureProfile('Wordclock.Brightness', VARIABLETYPE_FLOAT, function (string $name) {
            IPS_SetVariableProfileValues($name, 0, 255, 1);
            IPS_SetVariableProfileText($name, '', '');
            IPS_SetVariableProfileIcon($name, 'Intensity');
        });

        // Hue: 0–360° (Integer)
        $ensureProfile('Wordclock.Hue', VARIABLETYPE_INTEGER, function (string $name) {
            IPS_SetVariableProfileValues($name, 0, 360, 1);
            IPS_SetVariableProfileText($name, '', '°');
            IPS_SetVariableProfileIcon($name, 'Bulb');
        });

        // Saturation: 0–100% (Integer)
        $ensureProfile('Wordclock.Saturation', VARIABLETYPE_INTEGER, function (string $name) {
            IPS_SetVariableProfileValues($name, 0, 100, 1);
            IPS_SetVariableProfileText($name, '', '%');
            IPS_SetVariableProfileIcon($name, 'Intensity');
        });

        // Effektprofil
        $ensureProfile('Wordclock.Effect', VARIABLETYPE_INTEGER, function (string $name) {
            IPS_SetVariableProfileValues($name, 0, 7, 1);
            IPS_SetVariableProfileIcon($name, 'Script');

            // alte Assoziationen leeren
            $prof = IPS_GetVariableProfile($name);
            foreach ($prof['Associations'] as $assoc) {
                IPS_SetVariableProfileAssociation($name, $assoc['Value'], '', '', -1);
            }

            $effects = $this->GetEffectList();
            foreach ($effects as $idx => $effName) {
                IPS_SetVariableProfileAssociation($name, $idx, $effName, '', -1);
            }
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

    // -----------------------------------------------------------------
    // RGB <-> HSV wie in deinem Script
    // -----------------------------------------------------------------

    /**
     * RGB (0–255) → HSV (h:0–360, s:0–100, v:0–255)
     */
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

    /**
     * HSV (h:0–360, s:0–100, v:0–255) → RGB (0–255)
     */
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

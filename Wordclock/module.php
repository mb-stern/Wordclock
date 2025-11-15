<?php

declare(strict_types=1);

class Wordclock extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // MQTT-Parent verbinden
        $this->ConnectParent('{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}');
        $this->SendDebug('Create', 'MQTT-Parent verbunden', 0);

        // Eigenschaften
        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('Topic', 'Wordclock');

        // Interner Timestamp für Throttle (statt eigener Variable)
        $this->RegisterAttributeInteger('LastParseTS', 0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $active = $this->ReadPropertyBoolean('Active');
        $topic  = $this->ReadPropertyString('Topic');
        $this->SendDebug('ApplyChanges', 'Active=' . ($active ? 'true' : 'false') . ', Topic=' . $topic, 0);

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

        // Profile anlegen
        $this->EnsureProfiles();
    }

    public function GetConfigurationForm()
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

        return json_encode($form);
    }

    public function ReceiveData($JSONString)
    {
        $this->SendDebug('ReceiveData', 'Raw=' . $JSONString, 0);

        $data = json_decode($JSONString, true);
        if (!is_array($data)) {
            $this->SendDebug('ReceiveData', 'JSON decode fehlgeschlagen', 0);
            return;
        }

        // RX-DataID des MQTT-Splitters prüfen
        if (!isset($data['DataID']) || $data['DataID'] !== '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}') {
            $this->SendDebug('ReceiveData', 'Andere DataID: ' . ($data['DataID'] ?? 'null'), 0);
            return;
        }

        // Basis-Topic holen und /status anhängen
        $baseTopic   = rtrim($this->ReadPropertyString('Topic'), '/');
        if ($baseTopic === '') {
            $this->SendDebug('ReceiveData', 'Kein Basis-Topic gesetzt', 0);
            return;
        }
        $statusTopic = $baseTopic . '/status';

        // Nur Status-Topic der Wordclock verarbeiten
        if (!isset($data['Topic']) || $data['Topic'] !== $statusTopic) {
            $this->SendDebug('ReceiveData', 'Ignoriere Topic=' . ($data['Topic'] ?? 'null') . ', erwartet=' . $statusTopic, 0);
            return;
        }

        $this->SendDebug('ReceiveData', 'Topic=' . $data['Topic'], 0);

        // Throttle: max. 1x/s
        $now  = time();
        $last = $this->ReadAttributeInteger('LastParseTS');
        if (($now - $last) < 1) {
            $this->SendDebug('ReceiveData', 'Throttle aktiv, letztes Parse vor ' . ($now - $last) . 's', 0);
            return;
        }
        $this->WriteAttributeInteger('LastParseTS', $now);

        if (!isset($data['Payload'])) {
            $this->SendDebug('ReceiveData', 'Kein Payload vorhanden', 0);
            return;
        }

        $json = (string)$data['Payload'];
        if (trim($json) === '') {
            $this->SendDebug('ReceiveData', 'Payload ist leer', 0);
            return;
        }

        $this->SendDebug('ReceiveData', 'Payload=' . $json, 0);

        $state = json_decode($json, true);
        if (!is_array($state)) {
            $this->SendDebug('ReceiveData', 'Payload JSON decode fehlgeschlagen', 0);
            return;
        }

        $this->SendDebug('ReceiveData', 'Decoded=' . print_r($state, true), 0);

        $h = null;
        $s = null;
        $v = null;

        // brightness (0–255)
        if (isset($state['brightness'])) {
            $v = (float)$state['brightness'];
            $this->SendDebug('ReceiveData', 'brightness=' . $v, 0);
            $this->SetValueFloatIfChanged('Brightness', $v);
        }

        // color.h (0–360)
        if (isset($state['color']['h'])) {
            $h = (int)$state['color']['h'];
            $this->SendDebug('ReceiveData', 'color.h=' . $h, 0);
            $this->SetValueIntegerIfChanged('Hue', $h);
        }

        // color.s (0–100)
        if (isset($state['color']['s'])) {
            $s = (int)$state['color']['s'];
            $this->SendDebug('ReceiveData', 'color.s=' . $s, 0);
            $this->SetValueIntegerIfChanged('Saturation', $s);
        }

        if ($h !== null && $s !== null && $v !== null) {
            $rgb = $this->HSVtoRGB($h, $s, $v);  // h:0–360, s:0–100, v:0–255
            $colorInt = ($rgb['r'] << 16) | ($rgb['g'] << 8) | $rgb['b'];
            $this->SendDebug('ReceiveData', 'RGB=' . json_encode($rgb) . ', ColorInt=' . $colorInt, 0);
            $this->SetValueIntegerIfChanged('Color', $colorInt);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        $this->SendDebug('RequestAction', 'Ident=' . $Ident . ', Value=' . json_encode($Value), 0);

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SendDebug('RequestAction', 'Instanz inaktiv, Abbruch', 0);
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
                $this->SendDebug('RequestAction', 'RGBtoHSV=' . json_encode($hsv), 0);

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

        $this->SendStateToWordclock($includeEffect);
    }

    private function SendStateToWordclock(bool $includeEffect): void
    {
        $baseTopic    = rtrim($this->ReadPropertyString('Topic'), '/');
        if ($baseTopic === '') {
            $this->SendDebug('SendState', 'Kein Basis-Topic gesetzt, Abbruch', 0);
            return;
        }
        $commandTopic = $baseTopic . '/cmd';

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

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $this->SendDebug('SendState', 'Topic=' . $commandTopic . ', Payload=' . $jsonPayload, 0);

        $mqttPacket = [
            'DataID'           => '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}', // TX
            'PacketType'       => 3,
            'QualityOfService' => 0,
            'Retain'           => false,
            'Topic'            => $commandTopic,
            'Payload'          => $jsonPayload
        ];

        $this->SendDataToParent(json_encode($mqttPacket));
    }

    private function SetValueIntegerIfChanged(string $Ident, int $new): void
    {
        $old = $this->GetValue($Ident);
        if ($old !== $new) {
            $this->SendDebug('SetValueIntegerIfChanged', $Ident . ': ' . $old . ' -> ' . $new, 0);
            $this->SetValue($Ident, $new);
        }
    }

    private function SetValueFloatIfChanged(string $Ident, float $new): void
    {
        $old = (float)$this->GetValue($Ident);
        if (abs($old - $new) > 0.0001) {
            $this->SendDebug('SetValueFloatIfChanged', $Ident . ': ' . $old . ' -> ' . $new, 0);
            $this->SetValue($Ident, $new);
        }
    }

    private function EnsureProfiles(): void
    {
        $this->SendDebug('EnsureProfiles', 'Profile prüfen/anlegen', 0);

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
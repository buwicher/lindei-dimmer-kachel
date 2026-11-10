<?php

declare(strict_types=1);

// Lindei Dimmer-Kachel: runde Dimmer-Kachel für die Kachel-Visualisierung (HTML-SDK, Symcon >= 7.1)
class LindeiDimmerKachel extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('SwitchVariable', 0);
        $this->RegisterPropertyInteger('DimVariable', 0);
        $this->RegisterPropertyInteger('DayNightVariable', 0);
        $this->RegisterPropertyString('DayStart', '06:30');
        $this->RegisterPropertyString('DayEnd', '20:30');
        $this->RegisterPropertyInteger('DayValue', 75);
        $this->RegisterPropertyInteger('NightValue', 15);
        $this->RegisterPropertyBoolean('ShowTitle', false);

        // HTML-SDK-Kachel aktivieren
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Alte Registrierungen entfernen
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }
        foreach ($this->GetReferenceList() as $ref) {
            $this->UnregisterReference($ref);
        }

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        foreach (['SwitchVariable', 'DimVariable', 'DayNightVariable'] as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0 && IPS_VariableExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
                $this->RegisterReference($id);
            }
        }

        $dim = $this->ReadPropertyInteger('DimVariable');
        if ($dim > 0 && IPS_VariableExists($dim)) {
            $this->SetStatus(102);
        } else {
            $this->SetStatus(104);
        }

        $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $this->ApplyChanges();
            return;
        }
        if ($Message == VM_UPDATE) {
            $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
        }
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        $init = '<script>handleMessage(' . json_encode($this->GetFullUpdateMessage()) . ');</script>';
        return $html . $init;
    }

    public function RequestAction($Ident, $Value)
    {
        $switch = $this->ReadPropertyInteger('SwitchVariable');
        $dim = $this->ReadPropertyInteger('DimVariable');
        $hasSwitch = $switch > 0 && IPS_VariableExists($switch);
        if ($dim <= 0 || !IPS_VariableExists($dim)) {
            return;
        }

        switch ($Ident) {
            case 'toggle':
                if ($this->IsOn()) {
                    // Aus: Schalt-GA aus (z. B. Melder beenden) UND Dimmwert 0 hinterher,
                    // damit auch Leuchten ausgehen, die über andere GAs eingeschaltet wurden.
                    if ($hasSwitch) {
                        RequestAction($switch, false);
                        IPS_Sleep(300);
                    }
                    RequestAction($dim, $this->ToRaw($dim, 0));
                } else {
                    if ($hasSwitch) {
                        RequestAction($switch, true);
                    } else {
                        $pct = $this->IsDay() ? $this->ReadPropertyInteger('DayValue') : $this->ReadPropertyInteger('NightValue');
                        RequestAction($dim, $this->ToRaw($dim, $pct));
                    }
                }
                break;

            case 'dim':
                $pct = max(0, min(100, (int) $Value));
                // Ist das Licht aus und gibt es eine Schalt-Variable, erst darüber einschalten
                // (z. B. Küche alles: Melder-Halbautomat startet seinen Nachlauf), dann dimmen.
                if ($pct > 0 && $hasSwitch && !$this->IsOn()) {
                    RequestAction($switch, true);
                    IPS_Sleep(800);
                }
                RequestAction($dim, $this->ToRaw($dim, $pct));
                break;

            default:
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
    }

    private function GetFullUpdateMessage()
    {
        $dim = $this->ReadPropertyInteger('DimVariable');
        $pct = 0;
        if ($dim > 0 && IPS_VariableExists($dim)) {
            $pct = $this->ToPercent($dim, GetValue($dim));
        }
        return json_encode([
            'name'      => IPS_GetName($this->InstanceID),
            'showTitle' => $this->ReadPropertyBoolean('ShowTitle'),
            'on'        => $this->IsOn(),
            'pct'       => $pct
        ]);
    }

    private function IsOn()
    {
        $switch = $this->ReadPropertyInteger('SwitchVariable');
        // An, wenn Schalt-Status an ODER Dimmwert > 0
        if ($switch > 0 && IPS_VariableExists($switch) && (bool) GetValue($switch)) {
            return true;
        }
        $dim = $this->ReadPropertyInteger('DimVariable');
        if ($dim > 0 && IPS_VariableExists($dim)) {
            return $this->ToPercent($dim, GetValue($dim)) > 0;
        }
        return false;
    }

    private function IsDay()
    {
        $dn = $this->ReadPropertyInteger('DayNightVariable');
        if ($dn > 0 && IPS_VariableExists($dn)) {
            return (bool) GetValue($dn);
        }
        $now = date('H:i');
        $start = $this->ReadPropertyString('DayStart');
        $end = $this->ReadPropertyString('DayEnd');
        return ($now >= $start && $now < $end);
    }

    // Wertebereich der Variable aus dem Profil (Standard 0..100)
    private function GetRange($id)
    {
        $v = IPS_GetVariable($id);
        $profile = $v['VariableCustomProfile'] != '' ? $v['VariableCustomProfile'] : $v['VariableProfile'];
        if ($profile != '' && IPS_VariableProfileExists($profile)) {
            $p = IPS_GetVariableProfile($profile);
            if ($p['MaxValue'] > $p['MinValue']) {
                return [$p['MinValue'], $p['MaxValue'], $v['VariableType']];
            }
        }
        return [0, 100, $v['VariableType']];
    }

    private function ToPercent($id, $raw)
    {
        [$min, $max] = $this->GetRange($id);
        return (int) round(((float) $raw - $min) / ($max - $min) * 100);
    }

    private function ToRaw($id, $pct)
    {
        [$min, $max, $type] = $this->GetRange($id);
        $raw = $min + $pct / 100 * ($max - $min);
        return $type == 1 ? (int) round($raw) : (float) $raw;
    }
}

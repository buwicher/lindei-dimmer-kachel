<?php

declare(strict_types=1);

// Lindei Alles-aus-Kachel: runder Schalter für die Kachel-Visualisierung (HTML-SDK, Symcon >= 7.1)
// Leuchtet orange, solange die Status-Variable "Licht an" meldet. Lange drücken = Zentral aus.
class LindeiAllesAusKachel extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('StatusVariable', 0);
        $this->RegisterPropertyInteger('SwitchVariable', 0);
        $this->RegisterPropertyInteger('HoldTime', 1000);
        $this->RegisterPropertyString('TextOn', 'Licht an');
        $this->RegisterPropertyString('TextOff', 'alles aus');
        $this->RegisterPropertyBoolean('ShowTitle', false);

        // HTML-SDK-Kachel aktivieren
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }
        foreach ($this->GetReferenceList() as $ref) {
            $this->UnregisterReference($ref);
        }

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        foreach (['StatusVariable', 'SwitchVariable'] as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0 && IPS_VariableExists($id)) {
                $this->RegisterReference($id);
            }
        }
        $status = $this->ReadPropertyInteger('StatusVariable');
        if ($status > 0 && IPS_VariableExists($status)) {
            $this->RegisterMessage($status, VM_UPDATE);
        }

        $switch = $this->ReadPropertyInteger('SwitchVariable');
        if ($status > 0 && IPS_VariableExists($status) && $switch > 0 && IPS_VariableExists($switch)) {
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
        switch ($Ident) {
            case 'allOff':
                $switch = $this->ReadPropertyInteger('SwitchVariable');
                if ($switch <= 0 || !IPS_VariableExists($switch)) {
                    return;
                }
                RequestAction($switch, false);
                IPS_LogMessage('Alles aus', IPS_GetName($this->InstanceID) . ': Zentral aus gesendet');
                break;

            default:
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
    }

    private function GetFullUpdateMessage()
    {
        $status = $this->ReadPropertyInteger('StatusVariable');
        $on = $status > 0 && IPS_VariableExists($status) && (bool) GetValue($status);
        return json_encode([
            'name'      => IPS_GetName($this->InstanceID),
            'showTitle' => $this->ReadPropertyBoolean('ShowTitle'),
            'on'        => $on,
            'hold'      => $this->ReadPropertyInteger('HoldTime'),
            'textOn'    => $this->ReadPropertyString('TextOn'),
            'textOff'   => $this->ReadPropertyString('TextOff')
        ]);
    }
}

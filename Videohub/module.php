<?php

/**
 * Blackmagic Videohub – IP-Symcon Modul
 *
 * Spricht das "Blackmagic Videohub Ethernet Protocol" (TCP 9990) über einen
 * Client Socket. Das Gerät sendet nach dem Verbindungsaufbau von sich aus einen
 * kompletten Statusdump und danach bei jeder Änderung ein Delta-Update –
 * es wird also nicht gepollt, sondern nur zugehört.
 *
 * Ports sind im Protokoll 0-basiert, nach außen (Variablen, Profile, öffentliche
 * Funktionen) arbeitet das Modul 1-basiert, passend zur Beschriftung am Gerät.
 */
class BlackmagicVideohub extends IPSModule
{
    // Client Socket
    const IO_MODULE = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    const IO_TX     = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
    const IO_RX     = '{018EF6B5-AB94-40C6-AA53-46943E824ACF}';

    const DEFAULT_PORT = 9990;
    const MAX_RX_BUFFER = 262144;

    // Lock-Zustände (Integer-Profil)
    const LOCK_FREE   = 0;  // "U" – frei
    const LOCK_OWN    = 1;  // "O" – von diesem Client gesperrt
    const LOCK_FOREIGN = 2; // "L" – von einem anderen Client gesperrt

    public function Create()
    {
        parent::Create();

        $this->RequireParent(self::IO_MODULE);

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', self::DEFAULT_PORT);
        $this->RegisterPropertyBoolean('CreateLockVariables', false);
        $this->RegisterPropertyBoolean('SyncOutputNames', true);
        $this->RegisterPropertyInteger('WatchdogInterval', 30);

        // Topologie und Labels überleben Neustart und Modul-Update
        $this->RegisterAttributeString('Topology', '');

        $this->RegisterVariableBoolean('Online', 'Online', '~Switch', 10);
        $this->RegisterVariableString('DeviceInfo', 'Geräteinfo', '~TextBox', 20);
        $this->RegisterVariableString('LastError', 'Letzter Fehler', '~TextBox', 900);

        $this->RegisterTimer('Watchdog', 0, 'BMVH_Watchdog($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->ResetParser();

        // Nachrichten des Sockets neu abonnieren
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        $connID = $this->GetConnectionID();
        if ($connID > 0) {
            $this->RegisterMessage($connID, IM_CHANGESTATUS);
        }

        $host = trim($this->ReadPropertyString('Host'));

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->ConfigureParent();
        }

        // Profile und Variablen aus der gemerkten Topologie wiederherstellen.
        // Wichtig, weil Destroy() bei jedem Modul-Update die Instanzprofile löscht
        // und das Gerät zu diesem Zeitpunkt offline sein kann.
        $this->RestoreFromTopology();

        if ($host === '') {
            $this->SetTimerInterval('Watchdog', 0);
            $this->SetValueIfChanged('Online', false);
            $this->SetStatus(104);
            return;
        }

        $interval = max(10, (int)$this->ReadPropertyInteger('WatchdogInterval'));
        $this->SetTimerInterval('Watchdog', $interval * 1000);

        // Ohne Client Socket läuft nichts. Der Kernel lässt eine Instanz sich nicht
        // aus dem eigenen ApplyChanges heraus verbinden – über die Konsole erledigt
        // das RequireParent(), per Skript angelegte Instanzen brauchen ein
        // IPS_ConnectInstance() von außen. Hier wird das Fehlen nur sichtbar gemacht.
        if ($this->GetConnectionID() == 0) {
            $this->SetValueIfChanged('Online', false);
            $this->SetStatus(201);
            return;
        }

        $this->SetStatus(102);
    }

    public function Destroy()
    {
        $prefix = 'BMVH.' . $this->InstanceID . '.';
        foreach (IPS_GetVariableProfileList() as $profile) {
            if (strpos($profile, $prefix) === 0) {
                @IPS_DeleteVariableProfile($profile);
            }
        }

        parent::Destroy();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message != IM_CHANGESTATUS) {
            return;
        }

        // Socket-Status hat gewechselt: halbe Blöcke verwerfen, damit ein
        // frischer Dump nicht auf Bruchstücke der alten Verbindung trifft.
        $this->ResetParser();

        $status = isset($Data[0]) ? (int)$Data[0] : 0;
        if ($status != 102) {
            $this->SetValueIfChanged('Online', false);
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $topology = $this->GetTopology();
        if (isset($topology['outputs']) && (int)$topology['outputs'] > 0) {
            $info = sprintf(
                'Erkannt: %s – %d Eingänge / %d Ausgänge, Protokoll %s',
                $topology['model'] !== '' ? $topology['model'] : 'Videohub',
                (int)$topology['inputs'],
                (int)$topology['outputs'],
                $topology['protocol'] !== '' ? $topology['protocol'] : '?'
            );
        } else {
            $info = 'Noch keine Verbindung – Topologie wird beim ersten Verbindungsaufbau automatisch übernommen.';
        }

        foreach ($form['actions'] as $index => $element) {
            if (isset($element['name']) && $element['name'] === 'TopologyInfo') {
                $form['actions'][$index]['caption'] = $info;
            }
        }

        $this->FillLabelLists($form, $topology);

        return json_encode($form);
    }

    /**
     * Füllt die beiden Beschriftungslisten mit dem, was aktuell im Videohub steht.
     */
    private function FillLabelLists(array &$Form, array $Topology)
    {
        $lists = [
            'InputLabels'  => ['count' => (int)$Topology['inputs'],  'labels' => $Topology['inputLabels'],  'fallback' => 'Eingang '],
            'OutputLabels' => ['count' => (int)$Topology['outputs'], 'labels' => $Topology['outputLabels'], 'fallback' => 'Ausgang ']
        ];

        foreach ($Form['actions'] as &$action) {
            if (!isset($action['items'])) {
                continue;
            }
            foreach ($action['items'] as &$item) {
                $rows = isset($item['items']) ? $item['items'] : [$item];
                foreach ($rows as &$candidate) {
                    if (!isset($candidate['name']) || !isset($lists[$candidate['name']])) {
                        continue;
                    }
                    $spec = $lists[$candidate['name']];
                    $values = [];
                    for ($port = 1; $port <= $spec['count']; $port++) {
                        $label = isset($spec['labels'][$port - 1]) ? (string)$spec['labels'][$port - 1] : '';
                        if ($label === '') {
                            $label = $spec['fallback'] . $port;
                        }
                        $values[] = ['Port' => $port, 'Label' => $label];
                    }
                    $candidate['values'] = $values;
                    $candidate['rowCount'] = max(2, min(16, $spec['count']));
                }
                unset($candidate);
                if (isset($item['items'])) {
                    $item['items'] = $rows;
                }
            }
            unset($item);
        }
        unset($action);
    }

    public function RequestAction($Ident, $Value)
    {
        if (preg_match('/^Output(\d+)$/', $Ident, $matches)) {
            $this->SetRoute((int)$matches[1], (int)$Value);
            return;
        }

        if (preg_match('/^Lock(\d+)$/', $Ident, $matches)) {
            $this->SetOutputLock((int)$matches[1], (int)$Value);
            return;
        }

        throw new Exception('Unbekannte Aktion: ' . $Ident);
    }

    // ---------------------------------------------------------------- öffentlich

    /**
     * Legt einen Eingang auf einen Ausgang. Beide Nummern 1-basiert wie am Gerät.
     */
    public function SetRoute(int $Output, int $Input)
    {
        $topology = $this->GetTopology();
        $outputs = (int)$topology['outputs'];
        $inputs = (int)$topology['inputs'];

        if ($outputs > 0 && ($Output < 1 || $Output > $outputs)) {
            throw new Exception('Ausgang ' . $Output . ' liegt außerhalb von 1..' . $outputs);
        }
        if ($inputs > 0 && ($Input < 1 || $Input > $inputs)) {
            throw new Exception('Eingang ' . $Input . ' liegt außerhalb von 1..' . $inputs);
        }

        $this->SendBlock('VIDEO OUTPUT ROUTING', [($Output - 1) . ' ' . ($Input - 1)]);
    }

    /**
     * Sperrt oder entsperrt einen Ausgang.
     * 0 = frei, 1 = sperren. Ein von einem anderen Client gesetztes Schloss
     * wird beim Entsperren per "F" (force) gebrochen.
     */
    public function SetOutputLock(int $Output, int $State)
    {
        $topology = $this->GetTopology();
        $outputs = (int)$topology['outputs'];
        if ($outputs > 0 && ($Output < 1 || $Output > $outputs)) {
            throw new Exception('Ausgang ' . $Output . ' liegt außerhalb von 1..' . $outputs);
        }

        if ($State == self::LOCK_FREE) {
            $current = self::LOCK_FREE;
            $varID = @$this->GetIDForIdent('Lock' . $Output);
            if ($varID > 0) {
                $current = (int)GetValue($varID);
            }
            $code = ($current == self::LOCK_FOREIGN) ? 'F' : 'U';
        } else {
            $code = 'O';
        }

        $this->SendBlock('VIDEO OUTPUT LOCKS', [($Output - 1) . ' ' . $code]);
    }

    /**
     * Überträgt die im Formular bearbeitete Beschriftung. Erwartet
     * {"inputs":[{"Port":1,"Label":"..."}],"outputs":[...]} und schickt nur,
     * was sich gegenüber dem Gerätestand geändert hat.
     */
    public function ApplyLabels(string $Labels)
    {
        $this->SendDebug('ApplyLabels', $Labels, 0);

        $data = $this->DecodeRows($Labels);
        if (count($data) === 0) {
            echo 'Beschriftung konnte nicht gelesen werden.';
            return;
        }

        $topology = $this->GetTopology();
        $sent = 0;
        $seen = 0;

        $blocks = [
            'inputs'  => ['header' => 'INPUT LABELS',  'key' => 'inputLabels',  'max' => (int)$topology['inputs']],
            'outputs' => ['header' => 'OUTPUT LABELS', 'key' => 'outputLabels', 'max' => (int)$topology['outputs']]
        ];

        foreach ($blocks as $name => $spec) {
            if (!isset($data[$name])) {
                continue;
            }

            // Symcon reicht Listenwerte im onClick als JSON-String durch, nicht als Array
            $rows = $this->DecodeRows($data[$name]);

            $lines = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $seen++;
                if (!isset($row['Port'])) {
                    continue;
                }
                $port = (int)$row['Port'];
                $label = trim((string)(isset($row['Label']) ? $row['Label'] : ''));
                if ($port < 1 || $port > $spec['max'] || $label === '') {
                    continue;
                }

                $current = isset($topology[$spec['key']][$port - 1]) ? (string)$topology[$spec['key']][$port - 1] : '';
                if ($current === $label) {
                    continue;
                }

                $lines[] = ($port - 1) . ' ' . $label;
            }

            if (count($lines) > 0) {
                $this->SendBlock($spec['header'], $lines);
                $sent += count($lines);
            }
        }

        if ($seen === 0) {
            echo 'Es kamen keine Zeilen aus dem Formular an – bitte einmal "Aktualisieren" klicken und erneut versuchen.';
            return;
        }

        if ($sent === 0) {
            echo 'Keine Änderung – die ' . $seen . ' Zeilen stimmen mit dem Videohub überein.';
            return;
        }

        echo $sent . ' Beschriftung(en) an den Videohub übertragen.';
    }

    /**
     * Nimmt eine Zeilenliste entgegen, egal ob als Array oder – wie Symcon es
     * aus dem Formular liefert – als JSON-String.
     */
    private function DecodeRows($Value)
    {
        $depth = 0;
        while (is_string($Value) && $depth < 3) {
            $decoded = json_decode($Value, true);
            if ($decoded === null) {
                return [];
            }
            $Value = $decoded;
            $depth++;
        }

        return is_array($Value) ? $Value : [];
    }

    public function SetInputLabel(int $Input, string $Label)
    {
        $this->SendBlock('INPUT LABELS', [($Input - 1) . ' ' . $Label]);
    }

    public function SetOutputLabel(int $Output, string $Label)
    {
        $this->SendBlock('OUTPUT LABELS', [($Output - 1) . ' ' . $Label]);
    }

    /**
     * Fordert den kompletten Status neu an.
     */
    public function RequestStatus()
    {
        foreach (['VIDEOHUB DEVICE', 'INPUT LABELS', 'OUTPUT LABELS', 'VIDEO OUTPUT ROUTING', 'VIDEO OUTPUT LOCKS'] as $header) {
            $this->SendBlock($header, []);
        }
    }

    public function Watchdog()
    {
        $connID = $this->GetConnectionID();
        $status = ($connID > 0) ? (int)IPS_GetInstance($connID)['InstanceStatus'] : 0;
        $interval = max(10, (int)$this->ReadPropertyInteger('WatchdogInterval'));
        $lastRx = (int)$this->GetBuffer('LastRx');

        $online = ($status == 102) && ($lastRx > 0) && ((time() - $lastRx) < ($interval * 3));
        $this->SetValueIfChanged('Online', $online);

        if ($status == 102) {
            // PING wird mit ACK beantwortet und hält damit LastRx frisch
            $this->SendBlock('PING', []);
            return;
        }

        $this->Reconnect(false);
    }

    /**
     * Prüft, ob Port 9990 des Videohubs erreichbar ist (Button im Konfigurationsformular).
     */
    public function TestConnection()
    {
        $host = trim($this->ReadPropertyString('Host'));
        $port = (int)$this->ReadPropertyInteger('Port');
        if ($port <= 0) {
            $port = self::DEFAULT_PORT;
        }

        if ($host === '') {
            echo "Keine Adresse eingetragen.";
            return;
        }

        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, 2.0);
        if (!is_resource($fp)) {
            echo sprintf("Keine Verbindung zu %s:%d – %s (%d)", $host, $port, $errstr, $errno);
            return;
        }

        stream_set_timeout($fp, 2);
        $preamble = '';
        for ($i = 0; $i < 6; $i++) {
            $line = fgets($fp, 512);
            if ($line === false) {
                break;
            }
            $preamble .= $line;
            if (strpos($preamble, 'Model name:') !== false) {
                break;
            }
        }
        fclose($fp);

        $model = '';
        if (preg_match('/Model name:\s*(.+)/', $preamble, $matches)) {
            $model = trim($matches[1]);
        }
        $version = '';
        if (preg_match('/Version:\s*(.+)/', $preamble, $matches)) {
            $version = trim($matches[1]);
        }

        if ($model === '') {
            echo sprintf("Port %d auf %s ist offen, aber es kam keine Videohub-Kennung zurück.", $port, $host);
            return;
        }

        echo sprintf("Verbindung ok: %s (Protokoll %s)", $model, $version !== '' ? $version : '?');
    }

    public function Reconnect(bool $Force)
    {
        $connID = $this->GetConnectionID();
        if ($connID == 0) {
            return;
        }
        if (trim($this->ReadPropertyString('Host')) === '') {
            return;
        }

        $backoffUntil = (int)$this->GetBuffer('ReconnectBackoffUntil');
        if (!$Force && time() < $backoffUntil) {
            return;
        }
        $this->SetBuffer('ReconnectBackoffUntil', (string)(time() + 60));

        $this->ResetParser();
        @IPS_SetProperty($connID, 'Open', false);
        @IPS_ApplyChanges($connID);
        @IPS_SetProperty($connID, 'Open', true);
        @IPS_ApplyChanges($connID);
    }

    // ---------------------------------------------------------------- Empfang

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || !isset($data['DataID']) || $data['DataID'] !== self::IO_RX) {
            return;
        }
        if (!isset($data['Buffer'])) {
            return;
        }

        $this->SetBuffer('LastRx', (string)time());

        $chunk = $this->DecodeBuffer((string)$data['Buffer']);
        $buffer = $this->GetBuffer('RxBuffer') . $chunk;

        if (strlen($buffer) > self::MAX_RX_BUFFER) {
            $this->SendDebug('Rx', 'Puffer übergelaufen, wird verworfen', 0);
            $this->ResetParser();
            return;
        }

        $buffer = str_replace("\r\n", "\n", $buffer);
        $buffer = str_replace("\r", "\n", $buffer);

        $lines = explode("\n", $buffer);
        // Letztes Element ist der noch unvollständige Rest
        $this->SetBuffer('RxBuffer', (string)array_pop($lines));

        foreach ($lines as $line) {
            $this->FeedLine($line);
        }
    }

    private function FeedLine($Line)
    {
        $line = rtrim($Line, " \t");
        $header = (string)$this->GetBuffer('BlockHeader');

        if ($line === '') {
            if ($header !== '') {
                $body = json_decode((string)$this->GetBuffer('BlockBody'), true);
                $this->HandleBlock($header, is_array($body) ? $body : []);
            }
            $this->SetBuffer('BlockHeader', '');
            $this->SetBuffer('BlockBody', '[]');
            return;
        }

        if ($header === '') {
            if (substr($line, -1) === ':') {
                $this->SetBuffer('BlockHeader', strtoupper(trim(substr($line, 0, -1))));
                $this->SetBuffer('BlockBody', '[]');
            } else {
                // Einzeilige Antworten: ACK / NAK
                $this->HandleBlock(strtoupper(trim($line)), []);
            }
            return;
        }

        $body = json_decode((string)$this->GetBuffer('BlockBody'), true);
        if (!is_array($body)) {
            $body = [];
        }
        $body[] = $line;
        $this->SetBuffer('BlockBody', json_encode($body));
    }

    private function HandleBlock($Header, array $Lines)
    {
        $this->SendDebug('Block ' . $Header, implode(' | ', $Lines), 0);

        switch ($Header) {
            case 'ACK':
                return;

            case 'NAK':
                $this->SetValueIfChanged('LastError', 'Videohub hat den letzten Befehl abgelehnt (NAK) – ' . date('d.m.Y H:i:s'));
                return;

            case 'PROTOCOL PREAMBLE':
                $fields = $this->ParseFields($Lines);
                if (isset($fields['version'])) {
                    $topology = $this->GetTopology();
                    $topology['protocol'] = $fields['version'];
                    $this->SaveTopology($topology);
                    $this->UpdateDeviceInfo();
                }
                return;

            case 'VIDEOHUB DEVICE':
                $this->HandleDeviceBlock($this->ParseFields($Lines));
                return;

            case 'INPUT LABELS':
                $this->HandleLabels('inputLabels', $Lines);
                return;

            case 'OUTPUT LABELS':
                $this->HandleLabels('outputLabels', $Lines);
                return;

            case 'VIDEO OUTPUT ROUTING':
                $this->HandleRouting($Lines);
                return;

            case 'VIDEO OUTPUT LOCKS':
                $this->HandleLocks($Lines);
                return;

            default:
                // Unbekannte Blöcke laut Protokoll bewusst ignorieren
                return;
        }
    }

    private function HandleDeviceBlock(array $Fields)
    {
        if (isset($Fields['device present']) && $Fields['device present'] !== 'true') {
            $this->SetValueIfChanged('LastError', 'Videohub meldet "Device present: ' . $Fields['device present'] . '"');
            return;
        }

        $topology = $this->GetTopology();
        $changed = false;

        if (isset($Fields['model name']) && $topology['model'] !== $Fields['model name']) {
            $topology['model'] = $Fields['model name'];
            $changed = true;
        }
        if (isset($Fields['friendly name']) && $topology['friendly'] !== $Fields['friendly name']) {
            $topology['friendly'] = $Fields['friendly name'];
            $changed = true;
        }
        if (isset($Fields['video inputs']) && (int)$topology['inputs'] !== (int)$Fields['video inputs']) {
            $topology['inputs'] = (int)$Fields['video inputs'];
            $changed = true;
        }
        if (isset($Fields['video outputs']) && (int)$topology['outputs'] !== (int)$Fields['video outputs']) {
            $topology['outputs'] = (int)$Fields['video outputs'];
            $changed = true;
        }

        if (!$changed) {
            return;
        }

        $this->SaveTopology($topology);
        $this->SyncInputProfile();
        $this->MaintainPortVariables();
        $this->UpdateDeviceInfo();
    }

    private function HandleLabels($Key, array $Lines)
    {
        $topology = $this->GetTopology();
        $changed = false;

        foreach ($Lines as $line) {
            $parts = explode(' ', $line, 2);
            if (count($parts) < 2 || !is_numeric($parts[0])) {
                continue;
            }
            $port = (int)$parts[0];
            $label = trim($parts[1]);
            if (!isset($topology[$Key][$port]) || $topology[$Key][$port] !== $label) {
                $topology[$Key][$port] = $label;
                $changed = true;
            }
        }

        if (!$changed) {
            return;
        }

        $this->SaveTopology($topology);

        if ($Key === 'inputLabels') {
            $this->SyncInputProfile();
        } else {
            $this->ApplyOutputNames();
        }
    }

    private function HandleRouting(array $Lines)
    {
        foreach ($Lines as $line) {
            $parts = explode(' ', trim($line));
            if (count($parts) < 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
                continue;
            }
            $output = (int)$parts[0] + 1;
            $input = (int)$parts[1] + 1;
            $this->SetValueIfChanged('Output' . $output, $input);
        }
    }

    private function HandleLocks(array $Lines)
    {
        if (!$this->ReadPropertyBoolean('CreateLockVariables')) {
            return;
        }

        foreach ($Lines as $line) {
            $parts = explode(' ', trim($line));
            if (count($parts) < 2 || !is_numeric($parts[0])) {
                continue;
            }
            $output = (int)$parts[0] + 1;
            switch (strtoupper($parts[1])) {
                case 'O':
                    $state = self::LOCK_OWN;
                    break;
                case 'L':
                    $state = self::LOCK_FOREIGN;
                    break;
                default:
                    $state = self::LOCK_FREE;
            }
            $this->SetValueIfChanged('Lock' . $output, $state);
        }
    }

    private function ParseFields(array $Lines)
    {
        $fields = [];
        foreach ($Lines as $line) {
            $position = strpos($line, ':');
            if ($position === false) {
                continue;
            }
            $key = strtolower(trim(substr($line, 0, $position)));
            $fields[$key] = trim(substr($line, $position + 1));
        }
        return $fields;
    }

    // ---------------------------------------------------------------- Senden

    private function SendBlock($Header, array $Lines)
    {
        $connID = $this->GetConnectionID();
        if ($connID == 0 || (int)IPS_GetInstance($connID)['InstanceStatus'] != 102) {
            throw new Exception('Keine Verbindung zum Videohub.');
        }

        $payload = $Header . ":\n";
        foreach ($Lines as $line) {
            $payload .= $line . "\n";
        }
        $payload .= "\n";

        $key = 'BMVH_Send_' . $this->InstanceID;
        if (!@IPS_SemaphoreEnter($key, 1000)) {
            $this->LogMessage('Sende-Semaphore blockiert, Befehl "' . $Header . '" verworfen.', KL_WARNING);
            return;
        }

        try {
            $this->SendDebug('Tx', str_replace("\n", '\n', $payload), 0);
            $this->SendDataToParent(json_encode([
                'DataID' => self::IO_TX,
                'Buffer' => $this->EncodeBuffer($payload)
            ]));
        } finally {
            @IPS_SemaphoreLeave($key);
        }
    }

    // ---------------------------------------------------------------- Topologie

    private function GetTopology()
    {
        $raw = (string)$this->ReadAttributeString('Topology');
        $topology = json_decode($raw, true);
        if (!is_array($topology)) {
            $topology = [];
        }

        $defaults = [
            'model'        => '',
            'friendly'     => '',
            'protocol'     => '',
            'inputs'       => 0,
            'outputs'      => 0,
            'inputLabels'  => [],
            'outputLabels' => []
        ];

        foreach ($defaults as $key => $value) {
            if (!isset($topology[$key])) {
                $topology[$key] = $value;
            }
        }

        return $topology;
    }

    private function SaveTopology(array $Topology)
    {
        $this->WriteAttributeString('Topology', json_encode($Topology));
    }

    private function RestoreFromTopology()
    {
        $topology = $this->GetTopology();
        if ((int)$topology['outputs'] < 1) {
            return;
        }

        $this->SyncInputProfile();
        $this->MaintainPortVariables();
        $this->UpdateDeviceInfo();
    }

    private function InputProfileName()
    {
        return 'BMVH.' . $this->InstanceID . '.Inputs';
    }

    private function LockProfileName()
    {
        return 'BMVH.' . $this->InstanceID . '.Lock';
    }

    private function SyncInputProfile()
    {
        $topology = $this->GetTopology();
        $count = (int)$topology['inputs'];
        if ($count < 1) {
            return;
        }

        $name = $this->InputProfileName();
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileIcon($name, 'HollowLargeArrowRight');
        IPS_SetVariableProfileText($name, '', '');
        IPS_SetVariableProfileValues($name, 1, $count, 1);

        // Assoziationen oberhalb der Eingangszahl entfernen
        $profile = IPS_GetVariableProfile($name);
        foreach ($profile['Associations'] as $association) {
            $value = (int)$association['Value'];
            if ($value < 1 || $value > $count) {
                @IPS_SetVariableProfileAssociation($name, $value, '', '', -1);
            }
        }

        for ($port = 0; $port < $count; $port++) {
            $label = isset($topology['inputLabels'][$port]) ? (string)$topology['inputLabels'][$port] : '';
            if ($label === '') {
                $label = 'Eingang ' . ($port + 1);
            }
            IPS_SetVariableProfileAssociation($name, $port + 1, $label, '', -1);
        }
    }

    private function SyncLockProfile()
    {
        $name = $this->LockProfileName();
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileIcon($name, 'Lock');
        IPS_SetVariableProfileValues($name, 0, 2, 1);
        IPS_SetVariableProfileAssociation($name, self::LOCK_FREE, 'Frei', '', -1);
        IPS_SetVariableProfileAssociation($name, self::LOCK_OWN, 'Gesperrt', '', -1);
        IPS_SetVariableProfileAssociation($name, self::LOCK_FOREIGN, 'Fremd gesperrt', '', -1);
    }

    private function MaintainPortVariables()
    {
        $topology = $this->GetTopology();
        $outputs = (int)$topology['outputs'];
        $withLocks = $this->ReadPropertyBoolean('CreateLockVariables');

        if ($withLocks) {
            $this->SyncLockProfile();
        }

        $profile = $this->InputProfileName();

        for ($port = 1; $port <= $outputs; $port++) {
            $ident = 'Output' . $port;
            $this->RegisterVariableInteger($ident, $this->OutputCaption($topology, $port), $profile, 100 + $port * 2);
            $this->EnableAction($ident);

            $lockIdent = 'Lock' . $port;
            if ($withLocks) {
                $this->RegisterVariableInteger($lockIdent, $this->OutputCaption($topology, $port) . ' – Sperre', $this->LockProfileName(), 101 + $port * 2);
                $this->EnableAction($lockIdent);
            } else {
                $this->RemoveVariable($lockIdent);
            }
        }

        // Variablen entfernen, die es am Gerät nicht mehr gibt
        for ($port = $outputs + 1; $port <= $outputs + 80; $port++) {
            $this->RemoveVariable('Output' . $port);
            $this->RemoveVariable('Lock' . $port);
        }
    }

    private function ApplyOutputNames()
    {
        if (!$this->ReadPropertyBoolean('SyncOutputNames')) {
            return;
        }

        $topology = $this->GetTopology();
        $outputs = (int)$topology['outputs'];

        for ($port = 1; $port <= $outputs; $port++) {
            $caption = $this->OutputCaption($topology, $port);

            $varID = @$this->GetIDForIdent('Output' . $port);
            if ($varID > 0 && IPS_GetName($varID) !== $caption) {
                IPS_SetName($varID, $caption);
            }

            $lockID = @$this->GetIDForIdent('Lock' . $port);
            if ($lockID > 0 && IPS_GetName($lockID) !== $caption . ' – Sperre') {
                IPS_SetName($lockID, $caption . ' – Sperre');
            }
        }
    }

    private function OutputCaption(array $Topology, $Port)
    {
        $index = $Port - 1;
        if ($this->ReadPropertyBoolean('SyncOutputNames')
            && isset($Topology['outputLabels'][$index])
            && trim((string)$Topology['outputLabels'][$index]) !== '') {
            return (string)$Topology['outputLabels'][$index];
        }
        return 'Ausgang ' . $Port;
    }

    private function UpdateDeviceInfo()
    {
        $topology = $this->GetTopology();
        if ((int)$topology['outputs'] < 1) {
            return;
        }

        $name = $topology['friendly'] !== '' ? $topology['friendly'] : $topology['model'];
        $info = sprintf(
            '%s · %d×%d · Protokoll %s',
            $name !== '' ? $name : 'Videohub',
            (int)$topology['inputs'],
            (int)$topology['outputs'],
            $topology['protocol'] !== '' ? $topology['protocol'] : '?'
        );

        $this->SetValueIfChanged('DeviceInfo', $info);
    }

    // ---------------------------------------------------------------- Helfer

    private function ConfigureParent()
    {
        $connID = $this->GetConnectionID();
        if ($connID == 0) {
            return;
        }

        $host = trim($this->ReadPropertyString('Host'));
        $port = (int)$this->ReadPropertyInteger('Port');
        if ($port <= 0) {
            $port = self::DEFAULT_PORT;
        }
        $open = ($host !== '');

        $changed = false;
        if ((string)IPS_GetProperty($connID, 'Host') !== $host) {
            IPS_SetProperty($connID, 'Host', $host);
            $changed = true;
        }
        if ((int)IPS_GetProperty($connID, 'Port') !== $port) {
            IPS_SetProperty($connID, 'Port', $port);
            $changed = true;
        }
        if ((bool)IPS_GetProperty($connID, 'Open') !== $open) {
            IPS_SetProperty($connID, 'Open', $open);
            $changed = true;
        }

        if ($changed) {
            @IPS_ApplyChanges($connID);
        }
    }

    private function GetConnectionID()
    {
        $instance = IPS_GetInstance($this->InstanceID);
        return isset($instance['ConnectionID']) ? (int)$instance['ConnectionID'] : 0;
    }

    /**
     * Der Client Socket transportiert Rohbytes als Latin-1-nach-UTF-8 kodierten
     * String, weil JSON nur gültiges UTF-8 übertragen kann. Beim Empfang muss das
     * zurückgedreht werden, sonst zerfallen Umlaute in den Gerätelabels.
     */
    private function DecodeBuffer($Buffer)
    {
        if (function_exists('mb_convert_encoding')) {
            $decoded = @mb_convert_encoding($Buffer, 'ISO-8859-1', 'UTF-8');
            if (is_string($decoded)) {
                return $decoded;
            }
        }
        return @utf8_decode($Buffer);
    }

    private function EncodeBuffer($Buffer)
    {
        if (function_exists('mb_convert_encoding')) {
            $encoded = @mb_convert_encoding($Buffer, 'UTF-8', 'ISO-8859-1');
            if (is_string($encoded)) {
                return $encoded;
            }
        }
        return @utf8_encode($Buffer);
    }

    private function ResetParser()
    {
        $this->SetBuffer('RxBuffer', '');
        $this->SetBuffer('BlockHeader', '');
        $this->SetBuffer('BlockBody', '[]');
    }

    private function RemoveVariable($Ident)
    {
        $varID = @$this->GetIDForIdent($Ident);
        if ($varID > 0) {
            $this->UnregisterVariable($Ident);
        }
    }

    private function SetValueIfChanged($Ident, $Value)
    {
        $varID = @$this->GetIDForIdent($Ident);
        if ($varID == 0) {
            return;
        }
        if (GetValue($varID) === $Value) {
            return;
        }
        $this->SetValue($Ident, $Value);
    }
}

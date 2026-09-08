# Symcon-Blackmagic

IP-Symcon-Module für Geräte von Blackmagic Design.

Enthalten ist bisher **Videohub** – SDI-Kreuzschienen der Videohub-Familie.

Das Modul spricht das **Blackmagic Videohub Ethernet Protocol** auf TCP 9990 über
einen Client Socket. Der Videohub sendet nach dem Verbindungsaufbau selbständig
einen vollständigen Statusdump und danach bei jeder Änderung ein Delta-Update –
egal ob die Änderung aus Symcon, vom Frontpanel, aus Videohub Control oder von
einem anderen Client kommt. Es wird deshalb **nicht gepollt**, sondern zugehört.

Verifiziert an einem **Blackmagic Videohub 10x10 12G**, Firmware 8.0.1,
Protokoll 2.8.

## Installation

Module Control → Modul hinzufügen:

```
https://github.com/JLDFACE/Symcon-Blackmagic
```

Danach eine Instanz „Blackmagic Videohub" anlegen und die IP-Adresse eintragen.
Über die Konsole wird der Client Socket automatisch angelegt und vom Modul mit
Adresse und Port versorgt.

Wird die Instanz **per Skript** angelegt, muss der Socket mit verbunden werden –
der Kernel lässt eine Instanz sich nicht aus ihrem eigenen `ApplyChanges` heraus
mit einem Parent verbinden. Ohne Socket meldet die Instanz Status 201:

```php
$id   = IPS_CreateInstance('{5C34AC39-6DD6-4EC6-9CA2-1AB49E958998}');
$sock = IPS_CreateInstance('{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}'); // Client Socket
IPS_ConnectInstance($id, $sock);
IPS_SetProperty($id, 'Host', '192.168.0.10');
IPS_ApplyChanges($id);
```

## Konfiguration

| Feld | Bedeutung |
|---|---|
| IP-Adresse oder Hostname | Adresse des Videohubs |
| Port | Standard 9990 |
| Variablennamen aus den Ausgangs-Labels übernehmen | Symcon-Variablen heißen wie die Ausgänge im Videohub |
| Sperr-Variablen je Ausgang anlegen | zusätzlich eine Sperr-Variable pro Ausgang |
| Überwachungsintervall | Abstand der PING-Prüfung, Standard 30 s |

Topologie (Anzahl Ein-/Ausgänge) und Labels liest das Modul selbst vom Gerät –
es ist nichts einzutragen und nichts anzupassen, wenn später eine größere
Kreuzschiene an derselben Adresse steht.

## Variablen

| Ident | Typ | Bedeutung |
|---|---|---|
| `Online` | Boolean | Verbindung steht und Gerät antwortet |
| `DeviceInfo` | String | Gerätename, Größe, Protokollversion |
| `LastError` | String | letzte Ablehnung durch das Gerät |
| `Output<n>` | Integer | anliegender Eingang, schaltbar (Auswahlliste mit den Eingangs-Labels) |
| `Lock<n>` | Integer | 0 = frei, 1 = gesperrt, 2 = fremd gesperrt (optional) |

**Ein- und Ausgänge werden 1-basiert gezählt**, wie am Gehäuse beschriftet.
Das Protokoll selbst zählt ab 0; das rechnet das Modul um.

## Öffentliche Funktionen

```php
BMVH_SetRoute(int $InstanceID, int $Output, int $Input);      // Eingang auf Ausgang legen
BMVH_SetOutputLock(int $InstanceID, int $Output, int $State); // 0 = frei, 1 = sperren
BMVH_SetInputLabel(int $InstanceID, int $Input, string $Label);
BMVH_SetOutputLabel(int $InstanceID, int $Output, string $Label);
BMVH_ApplyLabels(int $InstanceID, string $Labels);            // aus dem Formular
BMVH_RequestStatus(int $InstanceID);                          // kompletten Status neu anfordern
BMVH_Reconnect(int $InstanceID, bool $Force);
BMVH_TestConnection(int $InstanceID);
BMVH_Watchdog(int $InstanceID);                               // Timer-Callback
```

Beispiel – Eingang 3 auf Ausgang 7 legen:

```php
BMVH_SetRoute(12345, 7, 3);
```

## Beschriftung

Im Konfigurationsformular steht unter „Ein- und Ausgänge beschriften" je eine
Liste für Ein- und Ausgänge. Die Felder sind direkt editierbar; ein Klick auf
„Beschriftung zum Videohub übertragen" schickt nur das, was sich geändert hat.

Die Beschriftung liegt im Videohub selbst und gilt damit auch für Videohub
Control und das Frontpanel. Umlaute funktionieren – am Gerät gegengelesen.
Zurück kommen die Labels als Statusupdate: Eingangsnamen landen im Auswahl-
profil der Ausgangsvariablen, Ausgangsnamen werden zu deren Variablennamen
(sofern „Variablennamen aus den Ausgangs-Labels übernehmen" aktiv ist).

## Praxiswissen zum Gerät

- **Ein ungültiger Befehl wird ebenfalls mit `ACK` quittiert.** Der Videohub
  antwortet auf `VIDEO OUTPUT ROUTING: 99 99` mit ACK und ignoriert die Zeile
  still. Auf `NAK` ist kein Verlass, deshalb prüft das Modul Ein- und
  Ausgangsnummern vor dem Senden selbst.
- **Das Modul setzt Werte nie optimistisch.** Eine Änderung erscheint in Symcon
  erst, wenn der Videohub sie im Statusblock bestätigt hat. Bei einer offenen
  Verbindung ist das eine Sache von Millisekunden.
- **Take Mode** (im `CONFIGURATION`-Block) betrifft nur Frontpanel und Videohub
  Control. Über Ethernet greift eine Route sofort.
- Der Client Socket transportiert Rohbytes als Latin-1-nach-UTF-8; das Modul
  dreht das beim Empfang zurück. Ohne diesen Schritt zerfallen Umlaute in den
  Gerätelabels.
- Der Videohub meldet sich per Bonjour als `_videohub._tcp` – im Terminal
  auffindbar mit `dns-sd -B _videohub._tcp local`.

## Getestet

- Blackmagic Videohub 10x10 12G, Firmware 8.0.1, Protokoll 2.8

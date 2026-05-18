# VariableAggregator für IP-Symcon

[![IP-Symcon Version](https://img.shields.io/badge/IP--Symcon-8.1+-blue.svg)](https://www.symcon.de)
[![Lizenz: EUPL-1.2](https://img.shields.io/badge/Lizenz-EUPL--1.2-blue.svg)](LICENSE)

Ein leistungsstarkes IP-Symcon-Modul zum Erstellen virtueller Geräte, die Variablen aus mehreren realen Geräten mit bidirektionaler Synchronisation und optionaler Datentypkonvertierung zusammenfassen.

**[English Version](README.md)**

---

## Inhaltsverzeichnis

- [Funktionen](#funktionen)
- [Voraussetzungen](#voraussetzungen)
- [Installation](#installation)
- [Konfiguration](#konfiguration)
  - [Variablen-Zuordnungen](#variablen-zuordnungen)
- [Variablen](#variablen)
- [PHP-Funktionen](#php-funktionen)
- [Anwendungsfälle](#anwendungsfälle)
- [Lizenz](#lizenz)

---

## Funktionen

- **Variablen-Konsolidierung**: Variablen aus mehreren realen Geräten in einem virtuellen Gerät zusammenfassen
- **Bidirektionale Synchronisation**: 
  - Änderungen an Quellvariablen werden automatisch in virtuellen Variablen übernommen
  - Änderungen an virtuellen Variablen können zur Quelle zurücksynchronisiert werden
  - Konfigurierbare Sync-Richtung pro Variable (bidirektional, nur von Quelle, nur zur Quelle)
- **Datentypkonvertierung**:
  - Konvertierung zwischen Boolean, Integer, Float und String
  - Automatische intelligente Typkonvertierung mit sinnvollen Standardwerten
  - Nützlich für die Integration inkompatibler Geräte oder einheitliche Schnittstellen
- **Flexible Benennung**: Individuelle Namen und Beschreibungen für jede virtuelle Variable
- **Eigenständige Variablen**: Virtuelle Variablen ohne Quelle für manuelle Steuerung erstellen
- **Aktionsunterstützung**: Virtuelle Variablen mit bidirektionaler Sync können über WebFront oder Skripte gesteuert werden
- **Saubere Verwaltung**: Entfernt automatisch alte virtuelle Variablen bei Änderung der Zuordnungen
- **Vollständige Lokalisierung**: Deutsche und englische Sprachunterstützung

---

## Voraussetzungen

- IP-Symcon 8.1 oder höher

---

## Installation

### Über den Module Store (Empfohlen)

1. IP-Symcon Konsole öffnen
2. Navigieren zu **Module** > **Module Store**
3. Nach "VariableAggregator" oder "Variablen-Aggregator" suchen
4. Auf **Installieren** klicken

### Manuelle Installation via Git

1. IP-Symcon Konsole öffnen
2. Navigieren zu **Module** > **Module**
3. Auf **Hinzufügen** (Plus-Symbol) klicken
4. **Modul von URL hinzufügen** wählen
5. Eingeben: `https://github.com/mwlf01/IPSymcon-VariableAggregator.git`
6. Mit **OK** bestätigen

### Manuelle Installation (Dateikopie)

1. Repository klonen oder herunterladen
2. Ordner in das IP-Symcon Module-Verzeichnis kopieren:
   - Windows: `C:\ProgramData\Symcon\modules\`
   - Linux: `/var/lib/symcon/modules/`
   - Docker: Volume-Mapping prüfen
3. Module in der IP-Symcon Konsole neu laden

---

## Konfiguration

Nach der Installation eine neue Instanz erstellen:

1. Navigieren zu **Objekte** > **Objekt hinzufügen** > **Instanz**
2. Nach "VariableAggregator" oder "Variablen-Aggregator" suchen
3. Mit **OK** die Instanz erstellen

### Variablen-Zuordnungen

Konfigurieren Sie, welche Variablen im virtuellen Gerät enthalten sein sollen:

| Einstellung | Beschreibung |
|-------------|--------------|
| **Quellvariable** | Beliebige Variable aus Ihrer IP-Symcon Installation auswählen (optional für eigenständige Variablen) |
| **Name** | Anzeigename für die virtuelle Variable (verwendet Quellname wenn leer). Nach der Erstellung werden manuell geänderte Namen beibehalten, sofern sie nicht explizit in der Konfiguration überschrieben werden |
| **Zieltyp** | Datentyp für die virtuelle Variable: Boolean, Integer, Float oder String (kann nach Erstellung nicht geändert werden) |
| **Sync-Richtung** | Wie Änderungen synchronisiert werden: Bidirektional, Nur von Quelle, oder Nur zur Quelle |
| **Beschreibung** | Optionale Beschreibung für Dokumentationszwecke |
| **ID** | Automatisch generierter eindeutiger Bezeichner (schreibgeschützt, Format: VA_ID_XXXXXXXX) |

#### Sync-Richtungen

- **Bidirektional**: Änderungen synchronisieren in beide Richtungen - Quelländerungen aktualisieren die virtuelle Variable, und Änderungen der virtuellen Variable werden zur Quelle gesendet
- **Nur von Quelle**: Die virtuelle Variable ist schreibgeschützt und spiegelt nur Quelländerungen wider
- **Nur zur Quelle**: Änderungen an der virtuellen Variable werden zur Quelle gesendet, aber Quelländerungen aktualisieren die virtuelle Variable nicht

#### Eigenständige Variablen

Sie können virtuelle Variablen ohne Quelle erstellen, indem Sie einen Namen und Zieltyp angeben. Diese sind nützlich für manuelle Steuerung oder Skript-Zwecke.

---

## Variablen

Virtuelle Variablen werden dynamisch basierend auf Ihren Zuordnungen erstellt. Jede zugeordnete Quellvariable erstellt eine entsprechende virtuelle Variable unter der Instanz.

Die virtuellen Variablen:
- Unterstützen Aktionen wenn die Sync-Richtung Schreiben zur Quelle erlaubt
- Werden automatisch entfernt wenn Zuordnungen gelöscht werden
- Behalten benutzerdefinierte Namen und Positionen nach der Erstellung bei (werden nur überschrieben, wenn sie explizit in der Konfiguration geändert werden)

---

## PHP-Funktionen

Das Modul stellt folgende öffentliche Funktionen für die Verwendung in Skripten bereit:

### SyncAllFromSource

Synchronisiert alle virtuellen Variablen von ihren Quellvariablen (berücksichtigt Sync-Richtung, überspringt "Nur zur Quelle"-Variablen).

```php
VA_SyncAllFromSource(int $InstanceID);
```

**Beispiel:**
```php
// Alle virtuellen Variablen von der Quelle aktualisieren
VA_SyncAllFromSource(12345);
```

### SyncAllToSource

Synchronisiert alle virtuellen Variablen zu ihren Quellvariablen (berücksichtigt Sync-Richtung, überspringt "Nur von Quelle"-Variablen).

```php
VA_SyncAllToSource(int $InstanceID);
```

**Beispiel:**
```php
// Alle Werte der virtuellen Variablen zur Quelle übertragen
VA_SyncAllToSource(12345);
```

### GetVirtualValue

Liefert den Wert einer virtuellen Variable anhand ihres Bezeichners.

```php
mixed VA_GetVirtualValue(int $InstanceID, string $Ident);
```

**Parameter:**
- `$InstanceID` - ID der VariableAggregator-Instanz
- `$Ident` - Bezeichner der virtuellen Variable

**Rückgabe:** Der aktuelle Wert der virtuellen Variable

**Beispiel:**
```php
$value = VA_GetVirtualValue(12345, 'VA_ID_12345678');
echo "Wert: {$value}";
```

### SetVirtualValue

Setzt den Wert einer virtuellen Variable (synchronisiert auch zur Quelle wenn erlaubt).

```php
VA_SetVirtualValue(int $InstanceID, string $Ident, mixed $Value);
```

**Parameter:**
- `$InstanceID` - ID der VariableAggregator-Instanz
- `$Ident` - Bezeichner der virtuellen Variable
- `$Value` - Neuer zu setzender Wert

**Beispiel:**
```php
// Virtuelle Variable setzen und zur Quelle synchronisieren
VA_SetVirtualValue(12345, 'VA_ID_12345678', true);
```

### GetVirtualVariables

Liefert eine Liste aller virtuellen Variablen mit deren Details.

```php
array VA_GetVirtualVariables(int $InstanceID);
```

**Rückgabe:** Array mit Informationen zu virtuellen Variablen

**Beispiel:**
```php
$variables = VA_GetVirtualVariables(12345);
foreach ($variables as $var) {
    echo "Bezeichner: {$var['Ident']}, Name: {$var['Name']}, ID: {$var['VariableID']}\n";
}
```

---

## Anwendungsfälle

### 1. Raum-Dashboard
Temperatur, Luftfeuchtigkeit, Lichtstatus und Fensterkontakte von verschiedenen Geräten in einem einzigen "Raum"-virtuellen Gerät zusammenfassen.

### 2. Geräte-Abstraktion
Eine einheitliche Schnittstelle für ähnliche Geräte verschiedener Hersteller mit unterschiedlichen Variablentypen erstellen.

### 3. Typkonvertierung
Einen Float-Temperatursensor in einen Integer für einfachere Anzeige konvertieren, oder einen Boolean in einen String für Protokollierung.

### 4. Schreibgeschützte Spiegel
Schreibgeschützte Kopien kritischer Variablen für Visualisierung ohne versehentliche Änderung erstellen.

### 5. Nur-Schreiben-Steuerungen
Steuerungsschnittstellen erstellen, die Befehle an Geräte senden ohne Gerätezustandsänderungen zu reflektieren.

---

## Changelog

### Version 2.0.0

**Breaking Change:** Virtuelle Variablen sind nun vor direktem Schreiben von außen geschützt. Skripte, die bisher mit `SetValue($virtuelleVariableID, $wert)` direkt in eine virtuelle Variable des Aggregators geschrieben haben, müssen auf die offizielle API umgestellt werden:

```php
// Alt — funktioniert nicht mehr:
SetValue(12345, true);

// Neu:
VA_SetVirtualValue($AggregatorInstanzID, 'VA_ID_12345678', true);
```

Lesen mit `GetValue($variableID)` funktioniert weiterhin unverändert.

**Weitere Änderungen:**
- Migration zur neuen Basisklasse `IPSModuleStrict` (Empfehlung seit IP-Symcon 8.1) — strikte Typprüfung, bessere Fehlerbehandlung, geschützte virtuelle Variablen
- Lizenz von MIT zu **EUPL v. 1.2** geändert

### Version 1.2.0
- Bereinigung beschränkt sich auf Variablen mit `VA_ID_`-Präfix — manuell angelegte Kind-Variablen der Instanz bleiben erhalten
- Sync-Sperre in `RequestAction` verhindert Echo-Schleifen beim Schreiben in die Quelle
- Sync-Sperre wird beim Start zurückgesetzt, falls sie nach einem Absturz hängen geblieben ist
- Bestehende Quell-Profile werden bei neu angelegten virtuellen Variablen übernommen (sofern Quell- und Zieltyp übereinstimmen)
- `VM_UPDATE` liefert den neuen Wert direkt aus dem Nachrichten-Datenpaket — vermeidet seltene Race-Conditions
- Stabilere Normalisierung der Variablen-Bezeichner ohne doppelte Ausführung des Lifecycles
- Robusterer Existenz-Check für Quellvariablen in `MessageSink`
- Einheitliche Behandlung von Wahrheitswert-Strings in der Typkonvertierung (z. B. „ja", „ein", „on", „true")
- Vollständigere deutsche Lokalisierung

### Version 1.1.0
- Name und Position von Variablen stehen nach der Erstellung unter Benutzerkontrolle (werden nur überschrieben, wenn sie explizit in der Konfiguration geändert werden)
- Vorgenerierte eindeutige Bezeichner für neue Variablen-Zuordnungen
- Datentypauswahl bei neuen Variablen war fälschlicherweise deaktiviert — behoben

### Version 1.0.0
- Erstveröffentlichung
- Variablen-Zuordnung mit bidirektionaler Synchronisation
- Datentypkonvertierung (Boolean, Integer, Float, String)
- Konfigurierbare Sync-Richtung pro Variable
- Eigenständige Variablen ohne Quelle
- Automatische Bereinigung entfernter Zuordnungen
- Vollständige deutsche Lokalisierung

---

## Support

Bei Problemen, Funktionswünschen oder Beiträgen besuchen Sie bitte:
- [GitHub Repository](https://github.com/mwlf01/IPSymcon-VariableAggregator)
- [GitHub Issues](https://github.com/mwlf01/IPSymcon-VariableAggregator/issues)
- [Symcon Community](https://community.symcon.de/) – Benutzer: **mwlf**

---

## Lizenz

Dieses Projekt steht unter der **European Union Public Licence (EUPL) v. 1.2** — siehe die [LICENSE](LICENSE)-Datei für den vollständigen Lizenztext.

Die EUPL ist eine Copyleft-Lizenz: abgeleitete Werke, die weitergegeben werden, müssen ebenfalls unter der EUPL oder einer kompatiblen Lizenz veröffentlicht werden (z. B. GPL, AGPL, MPL, LGPL — die vollständige Kompatibilitätsliste steht im Anhang der EUPL). Frühere Releases bis Version 1.2.0 bleiben weiterhin unter der zuvor genutzten MIT-Lizenz verfügbar.

Die EUPL wird in 24 offiziellen Sprachfassungen veröffentlicht, die rechtlich alle gleichwertig sind. Die Lizenz kann in anderen Sprachen auf der [offiziellen EU-Seite](https://interoperable-europe.ec.europa.eu/collection/eupl/eupl-text-eupl-12) eingesehen werden.

---

## Autor

**mwlf01**

- GitHub: [@mwlf01](https://github.com/mwlf01)
- Symcon Community: [mwlf](https://community.symcon.de/)

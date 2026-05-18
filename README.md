# VariableAggregator for IP-Symcon

[![IP-Symcon Version](https://img.shields.io/badge/IP--Symcon-8.1+-blue.svg)](https://www.symcon.de)
[![License: EUPL-1.2](https://img.shields.io/badge/License-EUPL--1.2-blue.svg)](LICENSE)

A powerful IP-Symcon module for creating virtual devices that consolidate variables from multiple real devices with bidirectional synchronization and optional data type conversion.

**[Deutsche Version](README.de.md)**

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
  - [Variable Mappings](#variable-mappings)
- [Variables](#variables)
- [PHP Functions](#php-functions)
- [Use Cases](#use-cases)
- [License](#license)

---

## Features

- **Variable Consolidation**: Aggregate variables from multiple real devices into a single virtual device
- **Bidirectional Synchronization**: 
  - Changes to source variables are automatically reflected in virtual variables
  - Changes to virtual variables can be synced back to source variables
  - Configurable sync direction per variable (bidirectional, from source only, to source only)
- **Data Type Conversion**:
  - Convert between Boolean, Integer, Float, and String types
  - Automatic intelligent type conversion with sensible defaults
  - Useful for integrating incompatible devices or creating unified interfaces
- **Flexible Naming**: Custom names and descriptions for each virtual variable
- **Standalone Variables**: Create virtual variables without a source for manual control
- **Action Support**: Virtual variables with bidirectional sync can be controlled via WebFront or scripts
- **Clean Management**: Automatically cleans up old virtual variables when mappings change
- **Full Localization**: German and English language support

---

## Requirements

- IP-Symcon 8.1 or higher

---

## Installation

### Via Module Store (Recommended)

1. Open IP-Symcon Console
2. Navigate to **Modules** > **Module Store**
3. Search for "VariableAggregator" or "Variable Aggregator"
4. Click **Install**

### Manual Installation via Git

1. Open IP-Symcon Console
2. Navigate to **Modules** > **Modules**
3. Click **Add** (Plus icon)
4. Select **Add Module from URL**
5. Enter: `https://github.com/mwlf01/IPSymcon-VariableAggregator.git`
6. Click **OK**

### Manual Installation (File Copy)

1. Clone or download this repository
2. Copy the folder to your IP-Symcon modules directory:
   - Windows: `C:\ProgramData\Symcon\modules\`
   - Linux: `/var/lib/symcon/modules/`
   - Docker: Check your volume mapping
3. Reload modules in IP-Symcon Console

---

## Configuration

After installation, create a new instance:

1. Navigate to **Objects** > **Add Object** > **Instance**
2. Search for "VariableAggregator" or "Variable Aggregator"
3. Click **OK** to create the instance

### Variable Mappings

Configure which variables to include in the virtual device:

| Setting | Description |
|---------|-------------|
| **Source Variable** | Select any variable from your IP-Symcon installation (optional for standalone variables) |
| **Name** | Display name for the virtual variable (uses source name if empty). After creation, manually changed names are preserved unless explicitly overridden in the configuration |
| **Target Type** | Data type for the virtual variable: Boolean, Integer, Float, or String (cannot be changed after creation) |
| **Sync Direction** | How changes are synchronized: Bidirectional, From Source Only, or To Source Only |
| **Description** | Optional description for documentation purposes |
| **ID** | Auto-generated unique identifier (read-only, format: VA_ID_XXXXXXXX) |

#### Sync Directions

- **Bidirectional**: Changes sync both ways - source changes update the virtual variable, and virtual variable changes are sent back to the source
- **From Source Only**: The virtual variable is read-only and only reflects source changes
- **To Source Only**: Changes to the virtual variable are sent to the source, but source changes don't update the virtual variable

#### Standalone Variables

You can create virtual variables without a source by providing a Name and Target Type. These are useful for manual control or scripting purposes.

---

## Variables

Virtual variables are created dynamically based on your mappings. Each mapped source variable creates a corresponding virtual variable under the instance.

The virtual variables:
- Support actions if sync direction allows writing to source
- Are automatically removed when mappings are deleted
- Preserve user-defined name and position after creation (only overwritten when explicitly changed in the configuration)

---

## PHP Functions

The module provides the following public functions for use in scripts:

### SyncAllFromSource

Synchronize all virtual variables from their source variables (respects sync direction setting, skips "To Source Only" variables).

```php
VA_SyncAllFromSource(int $InstanceID);
```

**Example:**
```php
// Refresh all virtual variables from source
VA_SyncAllFromSource(12345);
```

### SyncAllToSource

Synchronize all virtual variables to their source variables (respects sync direction setting, skips "From Source Only" variables).

```php
VA_SyncAllToSource(int $InstanceID);
```

**Example:**
```php
// Push all virtual variable values to source
VA_SyncAllToSource(12345);
```

### GetVirtualValue

Get the value of a virtual variable by its identifier.

```php
mixed VA_GetVirtualValue(int $InstanceID, string $Ident);
```

**Parameters:**
- `$InstanceID` - ID of the VariableAggregator instance
- `$Ident` - Identifier of the virtual variable

**Returns:** The current value of the virtual variable

**Example:**
```php
$value = VA_GetVirtualValue(12345, 'VA_ID_12345678');
echo "Value: {$value}";
```

### SetVirtualValue

Set the value of a virtual variable (also syncs to source if allowed).

```php
VA_SetVirtualValue(int $InstanceID, string $Ident, mixed $Value);
```

**Parameters:**
- `$InstanceID` - ID of the VariableAggregator instance
- `$Ident` - Identifier of the virtual variable
- `$Value` - New value to set

**Example:**
```php
// Set a virtual variable and sync to source
VA_SetVirtualValue(12345, 'VA_ID_12345678', true);
```

### GetVirtualVariables

Get a list of all virtual variables with their details.

```php
array VA_GetVirtualVariables(int $InstanceID);
```

**Returns:** Array of virtual variable information

**Example:**
```php
$variables = VA_GetVirtualVariables(12345);
foreach ($variables as $var) {
    echo "Ident: {$var['Ident']}, Name: {$var['Name']}, ID: {$var['VariableID']}\n";
}
```

---

## Use Cases

### 1. Room Dashboard
Consolidate temperature, humidity, light status, and window contacts from different devices into a single "Room" virtual device.

### 2. Device Abstraction
Create a unified interface for similar devices from different manufacturers with different variable types.

### 3. Type Conversion
Convert a float temperature sensor to an integer for simpler display, or convert a boolean to a string for logging.

### 4. Read-Only Mirrors
Create read-only copies of critical variables for visualization without accidental modification.

### 5. Write-Only Controls
Create control interfaces that send commands to devices without reflecting device state changes.

---

## Changelog

### Version 2.0.0

**Breaking Change:** Virtual variables are now protected from direct external writes. Scripts that previously wrote into a virtual aggregator variable via `SetValue($virtualVariableID, $value)` must switch to the official API:

```php
// Old — no longer works:
SetValue(12345, true);

// New:
VA_SetVirtualValue($AggregatorInstanceID, 'VA_ID_12345678', true);
```

Reading via `GetValue($variableID)` continues to work unchanged.

**Other changes:**
- Migrated to the new base class `IPSModuleStrict` (recommended since IP-Symcon 8.1) — strict type checking, improved error handling, protected virtual variables
- Licence changed from MIT to **EUPL v. 1.2**

### Version 1.2.0
- Cleanup only removes variables with the `VA_ID_` prefix — manually created child variables of the instance are preserved
- Sync lock in `RequestAction` prevents echo loops when writing to the source variable
- Sync lock is reset on startup in case it was left in a stuck state after a crash
- Existing source profiles are inherited by newly created virtual variables (when source and target type match)
- `VM_UPDATE` uses the new value directly from the message data — avoids rare race conditions
- More robust identifier normalization without re-running the lifecycle twice
- Hardened source-variable existence check in `MessageSink`
- Unified handling of truthy/falsy strings in type conversion (e.g. "yes", "on", "true")
- More complete German localization

### Version 1.1.0
- Variable name and position are now under user control after creation (only overwritten when explicitly changed in the configuration)
- Pre-generated unique identifiers for new variable mappings
- Fixed data type selection being disabled for new variables

### Version 1.0.0
- Initial release
- Variable mapping with bidirectional synchronization
- Data type conversion (Boolean, Integer, Float, String)
- Configurable sync direction per variable
- Standalone variables without source
- Automatic cleanup of removed mappings
- Full German localization

---

## Support

For issues, feature requests, or contributions, please visit:
- [GitHub Repository](https://github.com/mwlf01/IPSymcon-VariableAggregator)
- [GitHub Issues](https://github.com/mwlf01/IPSymcon-VariableAggregator/issues)
- [Symcon Community](https://community.symcon.de/) – User: **mwlf**

---

## License

This project is licensed under the **European Union Public Licence (EUPL) v. 1.2** — see the [LICENSE](LICENSE) file for the full text.

The EUPL is a copyleft licence: derivative works that are distributed must also be released under the EUPL or a compatible licence (e.g. GPL, AGPL, MPL, LGPL — see the appendix of the EUPL for the full compatibility list). Earlier releases up to version 1.2.0 remain available under the previous MIT licence.

The EUPL is published in 24 official language versions, all legally equivalent. Other language versions are available on the [official EU page](https://interoperable-europe.ec.europa.eu/collection/eupl/eupl-text-eupl-12).

---

## Author

**mwlf01**

- GitHub: [@mwlf01](https://github.com/mwlf01)
- Symcon Community: [mwlf](https://community.symcon.de/)

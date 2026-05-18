<?php

/*
 * VariableAggregator for IP-Symcon
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (c) 2026 mwlf01
 *
 * Licensed under the EUPL, Version 1.2. See the LICENSE file for the full text.
 */

declare(strict_types=1);

class VariableAggregator extends IPSModuleStrict
{
    private const IDENT_PREFIX = 'VA_ID_';

    private const TYPE_BOOLEAN = 0;
    private const TYPE_INTEGER = 1;
    private const TYPE_FLOAT = 2;
    private const TYPE_STRING = 3;

    private const SYNC_BIDIRECTIONAL = 0;
    private const SYNC_FROM_SOURCE = 1;
    private const SYNC_TO_SOURCE = 2;

    private const TRUE_STRINGS = ['true', 'on', 'yes', '1', 'wahr', 'ein', 'an', 'ja'];
    private const FALSE_STRINGS = ['false', 'off', 'no', '0', '', 'falsch', 'aus', 'nein'];

    private ?array $cachedMappings = null;

    /* ================= Lifecycle ================= */
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('VariableMappings', '[]');
        $this->RegisterAttributeBoolean('SyncInProgress', false);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Reset stale sync lock from previous crashes
        $this->WriteAttributeBoolean('SyncInProgress', false);
        $this->cachedMappings = null;

        // If normalization changed identifiers, IPS_ApplyChanges is re-entered and finishes the work
        if ($this->normalizeMappings()) {
            return;
        }

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        $mappings = $this->getVariableMappings();

        if (empty($mappings)) {
            $this->SetStatus(104);
        } else {
            $this->SetStatus(102);
        }

        $position = 1;
        $existingIdents = [];
        $registeredSources = [];

        foreach ($mappings as $mapping) {
            $sourceID = (int)($mapping['SourceVariableID'] ?? 0);
            $ident = trim($mapping['Ident'] ?? '');
            $name = trim($mapping['Name'] ?? '');
            $targetType = (int)($mapping['TargetType'] ?? -1);
            $syncDirection = (int)($mapping['SyncDirection'] ?? 0);

            $hasSource = $sourceID > 0 && @IPS_VariableExists($sourceID);
            $isStandalone = !$hasSource && $name !== '' && $targetType >= 0 && $targetType <= 3;

            if (!$hasSource && !$isStandalone) {
                continue;
            }

            if ($ident === '') {
                // Should not happen after normalizeMappings(), but guard anyway
                continue;
            }

            $existingIdents[] = $ident;

            $sourceType = $hasSource ? IPS_GetVariable($sourceID)['VariableType'] : $targetType;

            if ($targetType < 0 || $targetType > 3) {
                $targetType = $sourceType;
            }

            $existingVarID = @$this->GetIDForIdent($ident);
            if ($existingVarID !== false && @IPS_VariableExists($existingVarID)) {
                $existingType = IPS_GetVariable($existingVarID)['VariableType'];
                if ($targetType !== $existingType) {
                    $this->LogMessage("Type change ignored for variable '$name' (ID: $existingVarID). Type changes are not allowed after creation.", KL_WARNING);
                    $targetType = $existingType;
                }
            }

            if ($name === '' && $hasSource) {
                $name = IPS_GetName($sourceID);
            }

            $profile = $hasSource ? $this->resolveProfile($sourceID, $sourceType, $targetType) : '';

            $this->maintainVariableSmart($ident, $name, $targetType, $profile, $position);

            if ($syncDirection !== self::SYNC_FROM_SOURCE) {
                $this->EnableAction($ident);
            } else {
                $this->DisableAction($ident);
            }

            if ($hasSource) {
                if ($syncDirection !== self::SYNC_TO_SOURCE && !isset($registeredSources[$sourceID])) {
                    $this->RegisterMessage($sourceID, VM_UPDATE);
                    $registeredSources[$sourceID] = true;
                }

                $this->syncFromSource($sourceID, $ident, $sourceType, $targetType);
            }

            $position++;
        }

        $this->cleanupOldVariables($existingIdents);
    }

    /* ================= Configuration Form ================= */
    public function GetConfigurationForm(): string
    {
        return json_encode([
            'elements' => [
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Variable Mappings',
                    'expanded' => true,
                    'items' => [
                        [
                            'type' => 'List',
                            'name' => 'VariableMappings',
                            'caption' => 'Mapped Variables',
                            'rowCount' => 10,
                            'add' => true,
                            'delete' => true,
                            'sort' => [
                                'column' => 'Name',
                                'direction' => 'ascending'
                            ],
                            'loadValuesFromConfiguration' => false,
                            'values' => $this->getFormValues(),
                            'columns' => [
                                [
                                    'caption' => 'Source Variable',
                                    'name' => 'SourceVariableID',
                                    'width' => '350px',
                                    'add' => 0,
                                    'edit' => [
                                        'type' => 'SelectVariable'
                                    ]
                                ],
                                [
                                    'caption' => 'Name',
                                    'name' => 'Name',
                                    'width' => '200px',
                                    'add' => '',
                                    'edit' => [
                                        'type' => 'ValidationTextBox'
                                    ]
                                ],
                                [
                                    'caption' => 'Target Type',
                                    'name' => 'TargetType',
                                    'width' => '150px',
                                    'add' => 0,
                                    'edit' => [
                                        'type' => 'Select',
                                        'options' => [
                                            ['caption' => 'Boolean', 'value' => 0],
                                            ['caption' => 'Integer', 'value' => 1],
                                            ['caption' => 'Float', 'value' => 2],
                                            ['caption' => 'String', 'value' => 3]
                                        ]
                                    ]
                                ],
                                [
                                    'caption' => 'Sync Direction',
                                    'name' => 'SyncDirection',
                                    'width' => '180px',
                                    'add' => 0,
                                    'edit' => [
                                        'type' => 'Select',
                                        'options' => [
                                            ['caption' => 'Bidirectional', 'value' => 0],
                                            ['caption' => 'From Source Only', 'value' => 1],
                                            ['caption' => 'To Source Only', 'value' => 2]
                                        ]
                                    ]
                                ],
                                [
                                    'caption' => 'Description',
                                    'name' => 'Description',
                                    'width' => '200px',
                                    'add' => '',
                                    'edit' => [
                                        'type' => 'ValidationTextBox'
                                    ]
                                ],
                                [
                                    'caption' => 'ID',
                                    'name' => 'Ident',
                                    'width' => '120px',
                                    'add' => '',
                                    'save' => true
                                ]
                            ],
                            'onAdd' => [
                                '$VariableMappings["Ident"] = "VA_ID_" . str_pad((string)random_int(0, 99999999), 8, "0", STR_PAD_LEFT);',
                                'return $VariableMappings;'
                            ],
                            'form' => [
                                'return [',
                                '    [',
                                '        "type" => "SelectVariable",',
                                '        "name" => "SourceVariableID",',
                                '        "caption" => "Source Variable",',
                                '        "width" => "100%"',
                                '    ],',
                                '    [',
                                '        "type" => "ValidationTextBox",',
                                '        "name" => "Name",',
                                '        "caption" => "Name",',
                                '        "width" => "100%"',
                                '    ],',
                                '    [',
                                '        "type" => "Select",',
                                '        "name" => "TargetType",',
                                '        "caption" => "Target Type",',
                                '        "width" => "100%",',
                                '        "enabled" => empty($VariableMappings["Ident"]) || @IPS_GetObjectIDByIdent($VariableMappings["Ident"], $id) === false,',
                                '        "options" => [',
                                '            ["caption" => "Boolean", "value" => 0],',
                                '            ["caption" => "Integer", "value" => 1],',
                                '            ["caption" => "Float", "value" => 2],',
                                '            ["caption" => "String", "value" => 3]',
                                '        ]',
                                '    ],',
                                '    [',
                                '        "type" => "Select",',
                                '        "name" => "SyncDirection",',
                                '        "caption" => "Sync Direction",',
                                '        "width" => "100%",',
                                '        "enabled" => empty($VariableMappings["Ident"]) || @IPS_GetObjectIDByIdent($VariableMappings["Ident"], $id) === false,',
                                '        "options" => [',
                                '            ["caption" => "Bidirectional", "value" => 0],',
                                '            ["caption" => "From Source Only", "value" => 1],',
                                '            ["caption" => "To Source Only", "value" => 2]',
                                '        ]',
                                '    ],',
                                '    [',
                                '        "type" => "ValidationTextBox",',
                                '        "name" => "Description",',
                                '        "caption" => "Description",',
                                '        "width" => "100%"',
                                '    ]',
                                '];'
                            ]
                        ]
                    ]
                ]
            ],
            'actions' => [
                [
                    'type' => 'Button',
                    'caption' => 'Sync All From Source',
                    'onClick' => 'VA_SyncAllFromSource($id);'
                ],
                [
                    'type' => 'Button',
                    'caption' => 'Sync All To Source',
                    'onClick' => 'VA_SyncAllToSource($id);'
                ]
            ],
            'status' => [
                [
                    'code' => 102,
                    'icon' => 'active',
                    'caption' => 'Module is active'
                ],
                [
                    'code' => 104,
                    'icon' => 'inactive',
                    'caption' => 'No variables configured'
                ]
            ]
        ]);
    }

    /* ================= Action Handling ================= */
    public function RequestAction(string $Ident, mixed $Value): void
    {
        $mappings = $this->getVariableMappings();
        $mapping = $this->findMappingByIdent($mappings, $Ident);

        if ($mapping === null) {
            throw new Exception('Unknown ident: ' . $Ident);
        }

        $sourceID = (int)($mapping['SourceVariableID'] ?? 0);
        $targetType = (int)($mapping['TargetType'] ?? -1);
        $syncDirection = (int)($mapping['SyncDirection'] ?? 0);

        $hasSource = $sourceID > 0 && @IPS_VariableExists($sourceID);

        if ($hasSource) {
            $sourceVar = IPS_GetVariable($sourceID);
            $sourceType = $sourceVar['VariableType'];
            if ($targetType < 0 || $targetType > 3) {
                $targetType = $sourceType;
            }
        } else {
            if ($targetType < 0 || $targetType > 3) {
                throw new Exception('Invalid target type for standalone variable: ' . $Ident);
            }
            $sourceType = $targetType;
        }

        if (@$this->GetIDForIdent($Ident) === false) {
            throw new Exception('Virtual variable not found: ' . $Ident);
        }

        $convertedValue = $this->convertValue($Value, $targetType);

        // Guard against echo loop: writing to source triggers VM_UPDATE which MessageSink would
        // otherwise convert back into the virtual variable
        $this->WriteAttributeBoolean('SyncInProgress', true);
        try {
            $this->SetValue($Ident, $convertedValue);

            if ($hasSource && $syncDirection !== self::SYNC_FROM_SOURCE) {
                $sourceValue = $this->convertValue($Value, $sourceType);
                $this->syncToSource($sourceID, $sourceValue);
            }
        } finally {
            $this->WriteAttributeBoolean('SyncInProgress', false);
        }
    }

    /* ================= Message Sink ================= */
    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message !== VM_UPDATE) {
            return;
        }

        if ($this->ReadAttributeBoolean('SyncInProgress')) {
            return;
        }

        if (!@IPS_VariableExists($SenderID)) {
            return;
        }

        $mappings = $this->getVariableMappings();
        $matchingMappings = $this->findMappingsBySourceID($mappings, $SenderID);

        if (empty($matchingMappings)) {
            return;
        }

        $sourceVar = IPS_GetVariable($SenderID);
        $sourceType = $sourceVar['VariableType'];

        // VM_UPDATE delivers Data[0] = new value, avoiding a race with subsequent updates
        $sourceValue = $Data[0] ?? @GetValue($SenderID);

        $this->WriteAttributeBoolean('SyncInProgress', true);
        try {
            foreach ($matchingMappings as $mapping) {
                $syncDirection = (int)($mapping['SyncDirection'] ?? 0);
                if ($syncDirection === self::SYNC_TO_SOURCE) {
                    continue;
                }

                $ident = $this->resolveIdent($mapping);
                if ($ident === '') {
                    continue;
                }

                $targetType = (int)($mapping['TargetType'] ?? -1);
                if ($targetType < 0 || $targetType > 3) {
                    $targetType = $sourceType;
                }

                $this->writeVirtualValue($ident, $sourceValue, $targetType);
            }
        } finally {
            $this->WriteAttributeBoolean('SyncInProgress', false);
        }
    }

    /* ================= Public Functions ================= */

    public function SyncAllFromSource(): void
    {
        $this->WriteAttributeBoolean('SyncInProgress', true);
        try {
            $mappings = $this->getVariableMappings();
            foreach ($mappings as $mapping) {
                $sourceID = (int)($mapping['SourceVariableID'] ?? 0);
                $targetType = (int)($mapping['TargetType'] ?? -1);
                $syncDirection = (int)($mapping['SyncDirection'] ?? 0);

                if ($sourceID <= 0 || !@IPS_VariableExists($sourceID)) {
                    continue;
                }

                if ($syncDirection === self::SYNC_TO_SOURCE) {
                    continue;
                }

                $ident = $this->resolveIdent($mapping);
                if ($ident === '') {
                    continue;
                }

                $sourceVar = IPS_GetVariable($sourceID);
                $sourceType = $sourceVar['VariableType'];

                if ($targetType < 0 || $targetType > 3) {
                    $targetType = $sourceType;
                }

                $this->syncFromSource($sourceID, $ident, $sourceType, $targetType);
            }
        } finally {
            $this->WriteAttributeBoolean('SyncInProgress', false);
        }
    }

    public function SyncAllToSource(): void
    {
        $this->WriteAttributeBoolean('SyncInProgress', true);
        try {
            $mappings = $this->getVariableMappings();
            foreach ($mappings as $mapping) {
                $sourceID = (int)($mapping['SourceVariableID'] ?? 0);
                $syncDirection = (int)($mapping['SyncDirection'] ?? 0);

                if ($sourceID <= 0 || !@IPS_VariableExists($sourceID)) {
                    continue;
                }

                if ($syncDirection === self::SYNC_FROM_SOURCE) {
                    continue;
                }

                $ident = $this->resolveIdent($mapping);
                if ($ident === '') {
                    continue;
                }

                if (@$this->GetIDForIdent($ident) === false) {
                    continue;
                }

                $sourceVar = IPS_GetVariable($sourceID);
                $sourceType = $sourceVar['VariableType'];

                $virtualValue = $this->GetValue($ident);
                $sourceValue = $this->convertValue($virtualValue, $sourceType);
                $this->syncToSource($sourceID, $sourceValue);
            }
        } finally {
            $this->WriteAttributeBoolean('SyncInProgress', false);
        }
    }

    public function GetVirtualValue(string $Ident): mixed
    {
        if (@$this->GetIDForIdent($Ident) === false) {
            throw new Exception('Virtual variable not found: ' . $Ident);
        }
        return $this->GetValue($Ident);
    }

    public function SetVirtualValue(string $Ident, mixed $Value): void
    {
        $this->RequestAction($Ident, $Value);
    }

    public function GetVirtualVariables(): array
    {
        $result = [];
        $mappings = $this->getVariableMappings();

        foreach ($mappings as $mapping) {
            $sourceID = (int)($mapping['SourceVariableID'] ?? 0);
            $ident = $this->resolveIdent($mapping);
            if ($ident === '') {
                continue;
            }

            $name = trim($mapping['Name'] ?? '');
            $hasSource = $sourceID > 0 && @IPS_VariableExists($sourceID);

            if ($name === '' && $hasSource) {
                $name = IPS_GetName($sourceID);
            }

            $varID = @$this->GetIDForIdent($ident);
            if ($varID !== false) {
                $result[] = [
                    'Ident' => $ident,
                    'Name' => $name,
                    'VariableID' => $varID,
                    'SourceVariableID' => $hasSource ? $sourceID : 0
                ];
            }
        }

        return $result;
    }

    /* ================= Private Helper Functions ================= */

    private function getFormValues(): array
    {
        $raw = @json_decode($this->ReadPropertyString('VariableMappings'), true);
        if (!is_array($raw)) {
            return [];
        }

        foreach ($raw as &$mapping) {
            $ident = trim($mapping['Ident'] ?? '');
            if ($ident === '') {
                continue;
            }
            $varID = @$this->GetIDForIdent($ident);
            if ($varID === false || !@IPS_VariableExists($varID)) {
                continue;
            }
            $currentName = IPS_GetObject($varID)['ObjectName'];
            $propertyName = trim($mapping['Name'] ?? '');
            $sourceID = (int)($mapping['SourceVariableID'] ?? 0);
            $sourceName = ($sourceID > 0 && @IPS_VariableExists($sourceID)) ? IPS_GetName($sourceID) : '';

            // Surface manual renames from the object tree, but keep the "empty = follow source" default
            if ($propertyName !== '' || $currentName !== $sourceName) {
                $mapping['Name'] = $currentName;
            }
        }
        unset($mapping);

        return $raw;
    }

    private function getVariableMappings(): array
    {
        if ($this->cachedMappings !== null) {
            return $this->cachedMappings;
        }

        $raw = @json_decode($this->ReadPropertyString('VariableMappings'), true);
        if (!is_array($raw)) {
            $this->cachedMappings = [];
            return [];
        }

        $this->cachedMappings = array_values(array_filter($raw, function ($mapping) {
            $sourceID = (int)($mapping['SourceVariableID'] ?? 0);
            $name = trim($mapping['Name'] ?? '');
            $targetType = (int)($mapping['TargetType'] ?? -1);
            return $sourceID > 0 || ($name !== '' && $targetType >= 0 && $targetType <= 3);
        }));

        return $this->cachedMappings;
    }

    private function normalizeMappings(): bool
    {
        $raw = @json_decode($this->ReadPropertyString('VariableMappings'), true);
        if (!is_array($raw)) {
            return false;
        }

        $modified = false;
        $usedIdents = [];
        foreach ($raw as &$mapping) {
            $ident = trim($mapping['Ident'] ?? '');
            if ($ident === '' || in_array($ident, $usedIdents, true)) {
                $mapping['Ident'] = $this->generateIdent();
                $modified = true;
            }
            $usedIdents[] = $mapping['Ident'];
        }
        unset($mapping);

        if (!$modified) {
            return false;
        }

        // Persist and trigger a fresh ApplyChanges; this method returns true so the caller aborts
        // the current pass and lets the recursive call do the work with normalized data
        IPS_SetProperty($this->InstanceID, 'VariableMappings', json_encode(array_values($raw)));
        IPS_ApplyChanges($this->InstanceID);
        return true;
    }

    private function findMappingByIdent(array $mappings, string $ident): ?array
    {
        foreach ($mappings as $mapping) {
            if ($this->resolveIdent($mapping) === $ident) {
                return $mapping;
            }
        }
        return null;
    }

    private function findMappingsBySourceID(array $mappings, int $sourceID): array
    {
        if ($sourceID <= 0) {
            return [];
        }
        return array_values(array_filter($mappings, function ($mapping) use ($sourceID) {
            return (int)($mapping['SourceVariableID'] ?? 0) === $sourceID;
        }));
    }

    private function resolveIdent(array $mapping): string
    {
        return trim($mapping['Ident'] ?? '');
    }

    private function generateIdent(): string
    {
        return self::IDENT_PREFIX . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }

    private function resolveProfile(int $sourceID, int $sourceType, int $targetType): string
    {
        if ($sourceType !== $targetType) {
            return '';
        }
        $sourceVar = IPS_GetVariable($sourceID);
        return $sourceVar['VariableCustomProfile'] !== ''
            ? $sourceVar['VariableCustomProfile']
            : $sourceVar['VariableProfile'];
    }

    private function syncFromSource(int $sourceID, string $ident, int $sourceType, int $targetType): void
    {
        $sourceValue = @GetValue($sourceID);
        $this->writeVirtualValue($ident, $sourceValue, $targetType);
    }

    private function writeVirtualValue(string $ident, mixed $value, int $targetType): void
    {
        if (@$this->GetIDForIdent($ident) === false) {
            return;
        }
        $convertedValue = $this->convertValue($value, $targetType);
        @$this->SetValue($ident, $convertedValue);
    }

    private function syncToSource(int $sourceID, mixed $value): void
    {
        $sourceVar = IPS_GetVariable($sourceID);
        if ($sourceVar['VariableAction'] > 0 || $sourceVar['VariableCustomAction'] > 0) {
            @RequestAction($sourceID, $value);
        } else {
            @SetValue($sourceID, $value);
        }
    }

    private function convertValue(mixed $value, int $targetType): mixed
    {
        if ($value === null) {
            switch ($targetType) {
                case self::TYPE_BOOLEAN: return false;
                case self::TYPE_INTEGER: return 0;
                case self::TYPE_FLOAT: return 0.0;
                case self::TYPE_STRING: return '';
                default: return $value;
            }
        }

        switch ($targetType) {
            case self::TYPE_BOOLEAN:
                return $this->coerceToBool($value);

            case self::TYPE_INTEGER:
                return $this->coerceToInt($value);

            case self::TYPE_FLOAT:
                return $this->coerceToFloat($value);

            case self::TYPE_STRING:
                if (is_bool($value)) {
                    return $value ? 'true' : 'false';
                }
                return (string)$value;

            default:
                return $value;
        }
    }

    private function coerceToBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $lower = strtolower(trim($value));
            if (in_array($lower, self::FALSE_STRINGS, true)) {
                return false;
            }
            if (in_array($lower, self::TRUE_STRINGS, true)) {
                return true;
            }
            if (is_numeric($lower)) {
                return (float)$lower != 0;
            }
            return $lower !== '';
        }
        if (is_numeric($value)) {
            return $value != 0;
        }
        return (bool)$value;
    }

    private function coerceToInt(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_float($value)) {
            return (int)round($value);
        }
        if (is_string($value)) {
            $lower = strtolower(trim($value));
            if (in_array($lower, self::TRUE_STRINGS, true)) {
                return 1;
            }
            if (in_array($lower, self::FALSE_STRINGS, true)) {
                return 0;
            }
            $normalized = str_replace(',', '.', $lower);
            if (preg_match('/^[+-]?\d*\.?\d+/', $normalized, $matches)) {
                return (int)round((float)$matches[0]);
            }
            return 0;
        }
        return (int)$value;
    }

    private function coerceToFloat(mixed $value): float
    {
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        if (is_string($value)) {
            $lower = strtolower(trim($value));
            if (in_array($lower, self::TRUE_STRINGS, true)) {
                return 1.0;
            }
            if (in_array($lower, self::FALSE_STRINGS, true)) {
                return 0.0;
            }
            $normalized = str_replace(',', '.', $lower);
            if (preg_match('/^[+-]?\d*\.?\d+/', $normalized, $matches)) {
                return (float)$matches[0];
            }
            return 0.0;
        }
        return (float)$value;
    }

    private function maintainVariableSmart(string $ident, string $name, int $targetType, string $profile, int $position): void
    {
        $varID = @$this->GetIDForIdent($ident);

        if ($varID !== false && @IPS_VariableExists($varID)) {
            $obj = IPS_GetObject($varID);
            if ($obj['ObjectName'] !== $name) {
                IPS_SetName($varID, $name);
            }
            // Position and profile are under user control after creation
            return;
        }

        $this->MaintainVariable($ident, $name, $targetType, $profile, $position, true);
    }

    private function cleanupOldVariables(array $currentIdents): void
    {
        $children = IPS_GetChildrenIDs($this->InstanceID);
        foreach ($children as $childID) {
            $obj = IPS_GetObject($childID);
            if ($obj['ObjectType'] !== 2) {
                continue;
            }
            $ident = $obj['ObjectIdent'];
            if ($ident === '' || strpos($ident, self::IDENT_PREFIX) !== 0) {
                continue;
            }
            if (!in_array($ident, $currentIdents, true)) {
                $this->UnregisterVariable($ident);
            }
        }
    }
}

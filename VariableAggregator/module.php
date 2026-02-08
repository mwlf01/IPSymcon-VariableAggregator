<?php
declare(strict_types=1);

class VariableAggregator extends IPSModule
{
    private const VM_UPDATE = 10603;

    private const TYPE_BOOLEAN = 0;
    private const TYPE_INTEGER = 1;
    private const TYPE_FLOAT = 2;
    private const TYPE_STRING = 3;

    /* ================= Lifecycle ================= */
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('VariableMappings', '[]');

        $this->RegisterAttributeBoolean('SyncInProgress', false);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->normalizeMappings();

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

        foreach ($mappings as $mapping) {
            $sourceID = (int)($mapping['SourceVariableID'] ?? 0);
            $ident = trim($mapping['Ident'] ?? '');
            $name = trim($mapping['Name'] ?? '');
            $targetType = (int)($mapping['TargetType'] ?? -1);
            $syncDirection = (int)($mapping['SyncDirection'] ?? 0);

            $hasSource = $sourceID > 0 && @IPS_VariableExists($sourceID);
            $isStandalone = !$hasSource && !empty($name) && $targetType >= 0 && $targetType <= 3;

            if (!$hasSource && !$isStandalone) {
                continue;
            }

            if (empty($ident)) {
                $ident = $this->generateIdent();
            }

            $originalIdent = $ident;
            $counter = 1;
            while (in_array($ident, $existingIdents)) {
                $ident = $originalIdent . '_' . $counter++;
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

            if (empty($name) && $hasSource) {
                $name = IPS_GetName($sourceID);
            }

            $this->maintainVariableSmart($ident, $name, $targetType, $position);

            if ($isStandalone || $syncDirection === 0 || $syncDirection === 2) {
                $this->EnableAction($ident);
            } else {
                $this->DisableAction($ident);
            }

            if ($hasSource) {
                if ($syncDirection === 0 || $syncDirection === 1) {
                    $this->RegisterMessage($sourceID, self::VM_UPDATE);
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
                                    'add' => $this->generateIdent(),
                                    'save' => true
                                ]
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
                                '        "enabled" => empty($VariableMappings["Ident"]),',
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
    public function RequestAction($Ident, $Value)
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

        $varID = @$this->GetIDForIdent($Ident);
        if ($varID === false) {
            throw new Exception('Virtual variable not found: ' . $Ident);
        }

        $convertedValue = $this->convertValue($Value, $targetType);
        SetValue($varID, $convertedValue);

        if ($hasSource && $syncDirection !== 1) {
            $sourceValue = $this->convertValue($Value, $sourceType);
            $this->syncToSource($sourceID, $sourceValue);
        }
    }

    /* ================= Message Sink ================= */
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message !== self::VM_UPDATE) {
            return;
        }

        if ($this->ReadAttributeBoolean('SyncInProgress')) {
            return;
        }

        $mappings = $this->getVariableMappings();
        $matchingMappings = $this->findMappingsBySourceID($mappings, $SenderID);

        if (empty($matchingMappings)) {
            return;
        }

        $sourceVar = IPS_GetVariable($SenderID);
        $sourceType = $sourceVar['VariableType'];

        $this->WriteAttributeBoolean('SyncInProgress', true);
        try {
            foreach ($matchingMappings as $mapping) {
                $syncDirection = (int)($mapping['SyncDirection'] ?? 0);
                if ($syncDirection === 2) {
                    continue;
                }

                $ident = $this->resolveIdent($mapping);
                if (empty($ident)) {
                    continue;
                }

                $targetType = (int)($mapping['TargetType'] ?? -1);
                if ($targetType < 0 || $targetType > 3) {
                    $targetType = $sourceType;
                }

                $this->syncFromSource($SenderID, $ident, $sourceType, $targetType);
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

                if ($syncDirection === 2) {
                    continue;
                }

                $ident = $this->resolveIdent($mapping);
                if (empty($ident)) {
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
                $targetType = (int)($mapping['TargetType'] ?? -1);
                $syncDirection = (int)($mapping['SyncDirection'] ?? 0);

                if ($sourceID <= 0 || !@IPS_VariableExists($sourceID)) {
                    continue;
                }

                if ($syncDirection === 1) {
                    continue;
                }

                $ident = $this->resolveIdent($mapping);
                if (empty($ident)) {
                    continue;
                }

                $sourceVar = IPS_GetVariable($sourceID);
                $sourceType = $sourceVar['VariableType'];

                $virtualVarID = @$this->GetIDForIdent($ident);
                if ($virtualVarID === false || !@IPS_VariableExists($virtualVarID)) {
                    continue;
                }

                $virtualValue = GetValue($virtualVarID);
                $sourceValue = $this->convertValue($virtualValue, $sourceType);
                $this->syncToSource($sourceID, $sourceValue);
            }
        } finally {
            $this->WriteAttributeBoolean('SyncInProgress', false);
        }
    }

    public function GetVirtualValue(string $Ident)
    {
        $varID = @$this->GetIDForIdent($Ident);
        if ($varID === false || !@IPS_VariableExists($varID)) {
            throw new Exception('Virtual variable not found: ' . $Ident);
        }
        return GetValue($varID);
    }

    public function SetVirtualValue(string $Ident, $Value): void
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
            if (empty($ident)) {
                continue;
            }

            $name = trim($mapping['Name'] ?? '');
            $hasSource = $sourceID > 0 && @IPS_VariableExists($sourceID);
            
            if (empty($name) && $hasSource) {
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
            if (!empty($ident)) {
                $varID = @$this->GetIDForIdent($ident);
                if ($varID !== false && @IPS_VariableExists($varID)) {
                    $obj = IPS_GetObject($varID);
                    $mapping['Name'] = $obj['ObjectName'];
                }
            }
        }
        unset($mapping);

        return $raw;
    }

    private function getVariableMappings(): array
    {
        $raw = @json_decode($this->ReadPropertyString('VariableMappings'), true);
        if (!is_array($raw)) {
            return [];
        }
        return array_filter($raw, function ($mapping) {
            $sourceID = (int)($mapping['SourceVariableID'] ?? 0);
            $name = trim($mapping['Name'] ?? '');
            $targetType = (int)($mapping['TargetType'] ?? -1);
            return $sourceID > 0 || (!empty($name) && $targetType >= 0 && $targetType <= 3);
        });
    }

    private function normalizeMappings(): void
    {
        static $normalizing = false;
        if ($normalizing) {
            return;
        }

        $raw = @json_decode($this->ReadPropertyString('VariableMappings'), true);
        if (!is_array($raw)) {
            return;
        }

        $modified = false;
        foreach ($raw as &$mapping) {
            if (empty(trim($mapping['Ident'] ?? ''))) {
                $mapping['Ident'] = $this->generateIdent();
                $modified = true;
            }
        }
        unset($mapping);

        if ($modified) {
            $normalizing = true;
            try {
                IPS_SetProperty($this->InstanceID, 'VariableMappings', json_encode(array_values($raw)));
                IPS_ApplyChanges($this->InstanceID);
            } finally {
                $normalizing = false;
            }
        }
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
        return 'VA_ID_' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }

    private function syncFromSource(int $sourceID, string $ident, int $sourceType, int $targetType): void
    {
        $varID = @$this->GetIDForIdent($ident);
        if ($varID === false) {
            return;
        }

        $sourceValue = @GetValue($sourceID);
        $convertedValue = $this->convertValue($sourceValue, $targetType);

        @SetValue($varID, $convertedValue);
    }

    private function syncToSource(int $sourceID, $value): void
    {
        $sourceVar = IPS_GetVariable($sourceID);
        if ($sourceVar['VariableAction'] > 0 || $sourceVar['VariableCustomAction'] > 0) {
            @RequestAction($sourceID, $value);
        } else {
            @SetValue($sourceID, $value);
        }
    }

    private function convertValue($value, int $targetType)
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
                if (is_string($value)) {
                    $lower = strtolower(trim($value));
                    if (in_array($lower, ['false', 'off', 'no', '0', '', 'falsch', 'aus', 'nein'], true)) {
                        return false;
                    }
                    if (is_numeric($lower)) {
                        return (float)$lower != 0;
                    }
                    return !empty($lower);
                }
                if (is_numeric($value)) {
                    return $value != 0;
                }
                return (bool)$value;

            case self::TYPE_INTEGER:
                if (is_bool($value)) {
                    return $value ? 1 : 0;
                }
                if (is_float($value)) {
                    return (int)round($value);
                }
                if (is_string($value)) {
                    $lower = strtolower(trim($value));
                    if ($lower === 'true' || $lower === 'on' || $lower === 'ja' || $lower === 'ein') {
                        return 1;
                    }
                    if ($lower === 'false' || $lower === 'off' || $lower === 'nein' || $lower === 'aus') {
                        return 0;
                    }
                    $value = str_replace(',', '.', $value);
                    if (preg_match('/^[+-]?\d*\.?\d+/', trim($value), $matches)) {
                        return (int)round((float)$matches[0]);
                    }
                    return 0;
                }
                return (int)$value;

            case self::TYPE_FLOAT:
                if (is_bool($value)) {
                    return $value ? 1.0 : 0.0;
                }
                if (is_string($value)) {
                    $lower = strtolower(trim($value));
                    if ($lower === 'true' || $lower === 'on' || $lower === 'ja' || $lower === 'ein') {
                        return 1.0;
                    }
                    if ($lower === 'false' || $lower === 'off' || $lower === 'nein' || $lower === 'aus') {
                        return 0.0;
                    }
                    $value = str_replace(',', '.', $value);
                    if (preg_match('/^[+-]?\d*\.?\d+/', trim($value), $matches)) {
                        return (float)$matches[0];
                    }
                    return 0.0;
                }
                return (float)$value;

            case self::TYPE_STRING:
                if (is_bool($value)) {
                    return $value ? 'true' : 'false';
                }
                return (string)$value;

            default:
                return $value;
        }
    }

    private function maintainVariableSmart(string $ident, string $name, int $targetType, int $position): void
    {
        $varID = @$this->GetIDForIdent($ident);
        
        if ($varID !== false && @IPS_VariableExists($varID)) {
            $obj = IPS_GetObject($varID);
            if ($obj['ObjectName'] !== $name) {
                IPS_SetName($varID, $name);
            }
            // Position is under user control after creation, don't overwrite
            return;
        }
        
        $this->MaintainVariable($ident, $name, $targetType, '', $position, true);
    }

    private function cleanupOldVariables(array $currentIdents): void
    {
        $children = IPS_GetChildrenIDs($this->InstanceID);
        foreach ($children as $childID) {
            if (IPS_GetObject($childID)['ObjectType'] !== 2) {
                continue;
            }

            $ident = IPS_GetObject($childID)['ObjectIdent'];
            if (!empty($ident) && !in_array($ident, $currentIdents)) {
                $this->UnregisterVariable($ident);
            }
        }
    }

}

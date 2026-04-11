<?php
/**
 * MSPro ResetSystem.txt Parser
 * Reads and writes the specific format of ResetSystem configuration files
 */
class ResetSystemParser
{
    private array $sections = [];
    private array $rawLines = [];
    private ?array $humanReadableCache = null;

    /**
     * Set pre-parsed human readable data (from frontend JSON)
     */
    public function setHumanReadable(array $data): void
    {
        $this->humanReadableCache = $data;
    }

    // Section definitions with their field mappings
    private const SECTION_DEFS = [
        0 => [
            'name' => 'requirements',
            'label' => 'Requirements',
            'fields' => [
                'Index', 'Type', 'AccountLevel', 'SettingIndex', 'RequiredLevelResets',
                'RangeStart', 'RangeEnd', 'ReqWCCoins', 'ReqWPCoins', 'ReqGoblinPoints',
                'ZenType', 'ZenValue', 'ItemReqIndex', 'RewardIndex'
            ]
        ],
        1 => [
            'name' => 'settings',
            'label' => 'Settings',
            'fields' => [
                'SettingIndex', 'Stats', 'CommandDL', 'LevelUpPoints', 'Inventory',
                'Skills', 'Quests', 'SkillTree', 'GoblinPoints', 'ToBaseMap'
            ]
        ],
        2 => [
            'name' => 'itemreq',
            'label' => 'Item Requirements',
            'fields' => [
                'ItemReqIndex', 'Type', 'Index', 'ItemLevel', 'LifeOption',
                'Skill', 'Luck', 'Excellent', 'Durability', 'Count'
            ]
        ],
        3 => [
            'name' => 'rewards',
            'label' => 'Rewards',
            'fields' => [
                'RewardIndex', 'PointsType', 'LevelUpPoints', 'WCCoins',
                'WPCoins', 'GoblinPoints', 'BagIndex'
            ]
        ],
        4 => [
            'name' => 'gifts',
            'label' => 'Reset Gifts',
            'fields' => [
                'Type', 'Count', 'AccountLevel', 'BagIndex', 'BagCount',
                'LevelUpPoints', 'WCCoins', 'WPCoins', 'GoblinPoints', 'Zen'
            ]
        ],
        5 => [
            'name' => 'limits',
            'label' => 'Reset Limits',
            'fields' => [
                'Type', 'Limit_AL0', 'Limit_AL1', 'Limit_AL2', 'Limit_AL3'
            ]
        ],
        6 => [
            'name' => 'limit_time',
            'label' => 'Reset Limit Time',
            'fields' => [
                'Type', 'StartMonth', 'StartDay', 'StartDoW', 'StartHour', 'StartMin'
            ]
        ],
        7 => [
            'name' => 'percentage_points',
            'label' => 'Percentage Points by Class',
            'fields' => [
                'Index', 'DW', 'DK', 'FE', 'MG', 'DL', 'SU', 'RF'
            ]
        ],
        8 => [
            'name' => 'extras',
            'label' => 'Reset Extras',
            'fields' => [
                'Type', 'CountMin', 'CountMax', 'AccountLevel', 'AddLevel',
                'AddMasterLevel', 'AddMLPoints', 'AddResets', 'ResetLevel'
            ]
        ]
    ];

    // Human-readable labels for field values
    private const VALUE_MAPS = [
        'Type' => [0 => 'Reset', 1 => 'Master Reset'],
        'AccountLevel' => [-1 => 'ALL', 0 => 'FREE', 1 => 'VIP 1', 2 => 'VIP 2', 3 => 'VIP 3'],
        'ZenType' => [-1 => 'No remover Zen', 0 => 'Remover cantidad fija', 1 => 'Remover × Resets'],
        'PointsType' => [0 => 'LevelPoints fijo', 1 => 'LevelPoints × Reset', 2 => 'MasterPoints fijo', 3 => 'MasterPoints × MReset'],
        'Inventory' => [0 => 'No', 1 => 'Solo Inventario', 2 => 'Inventario + Equipados'],
        'StartDoW' => [1 => 'Domingo', 2 => 'Lunes', 3 => 'Martes', 4 => 'Miércoles', 5 => 'Jueves', 6 => 'Viernes', 7 => 'Sábado'],
        // Boolean fields
        'Stats' => [0 => 'No', 1 => 'Sí'],
        'CommandDL' => [0 => 'No', 1 => 'Sí'],
        'LevelUpPoints' => [0 => 'No', 1 => 'Sí'],
        'Skills' => [0 => 'No', 1 => 'Sí'],
        'Quests' => [0 => 'No', 1 => 'Sí'],
        'SkillTree' => [0 => 'No', 1 => 'Sí'],
        'GoblinPoints_bool' => [0 => 'No', 1 => 'Sí'],
        'ToBaseMap' => [0 => 'No', 1 => 'Sí'],
        'ResetLevel' => [0 => 'No', 1 => 'Sí'],
    ];

    // MU Online Item Types
    private const ITEM_TYPES = [
        0 => 'Swords', 1 => 'Axes', 2 => 'Maces/Scepters', 3 => 'Spears',
        4 => 'Bows/Crossbows', 5 => 'Staffs', 6 => 'Shields', 7 => 'Helms',
        8 => 'Armors', 9 => 'Pants', 10 => 'Gloves', 11 => 'Boots',
        12 => 'Wings/Capes', 13 => 'Pets/Rings', 14 => 'Misc', 15 => 'Scrolls'
    ];

    /**
     * Parse a ResetSystem.txt content string
     */
    public function parse(string $content): array
    {
        $this->rawLines = explode("\n", $content);
        $this->sections = [];
        $currentSection = null;
        $inDataBlock = false;

        foreach ($this->rawLines as $lineNum => $line) {
            $trimmed = trim($line);

            // Detect section start (a line that is just a number 0-8)
            if (preg_match('/^(\d)$/', $trimmed, $matches)) {
                $sectionId = (int)$matches[1];
                if (isset(self::SECTION_DEFS[$sectionId])) {
                    $currentSection = $sectionId;
                    $inDataBlock = true;
                    $this->sections[$sectionId] = [
                        'id' => $sectionId,
                        'name' => self::SECTION_DEFS[$sectionId]['name'],
                        'label' => self::SECTION_DEFS[$sectionId]['label'],
                        'rows' => []
                    ];
                    continue;
                }
            }

            // Detect section end
            if ($trimmed === 'end' && $inDataBlock) {
                $inDataBlock = false;
                $currentSection = null;
                continue;
            }

            // Parse data rows
            if ($inDataBlock && $currentSection !== null && !empty($trimmed) && $trimmed[0] !== '/') {
                $values = preg_split('/\s+/', $trimmed);
                $values = array_values(array_filter($values, fn($v) => $v !== ''));

                if (!empty($values)) {
                    $fields = self::SECTION_DEFS[$currentSection]['fields'];
                    $row = [];
                    foreach ($fields as $i => $fieldName) {
                        $row[$fieldName] = isset($values[$i]) ? $values[$i] : '0';
                    }
                    $this->sections[$currentSection]['rows'][] = $row;
                }
            }
        }

        return $this->sections;
    }

    /**
     * Generate a human-readable summary of the configuration
     */
    public function toHumanReadable(): array
    {
        $summary = [];

        // Section 0: Requirements (main reset entries)
        if (isset($this->sections[0])) {
            foreach ($this->sections[0]['rows'] as $row) {
                $type = (int)$row['Type'] === 0 ? 'Reset' : 'Master Reset';
                $range = "{$row['RangeStart']} a {$row['RangeEnd']}";
                $accLevel = self::VALUE_MAPS['AccountLevel'][(int)$row['AccountLevel']] ?? $row['AccountLevel'];

                $entry = [
                    'index' => $row['Index'],
                    'title' => "#{$row['Index']} - {$type} {$range}",
                    'type' => $type,
                    'accountLevel' => $accLevel,
                    'range' => $range,
                    'requiredLevel' => $row['RequiredLevelResets'],
                    'costs' => [],
                    'settings' => [],
                    'rewards' => [],
                    'itemReq' => null
                ];

                // Costs
                $zenType = self::VALUE_MAPS['ZenType'][(int)$row['ZenType']] ?? 'Unknown';
                $entry['costs']['zen'] = ['type' => $zenType, 'value' => number_format((int)$row['ZenValue'])];
                if ((int)$row['ReqWCCoins'] > 0) $entry['costs']['wccoins'] = $row['ReqWCCoins'];
                if ((int)$row['ReqWPCoins'] > 0) $entry['costs']['wpcoins'] = $row['ReqWPCoins'];
                if ((int)$row['ReqGoblinPoints'] > 0) $entry['costs']['goblinPoints'] = $row['ReqGoblinPoints'];

                // Settings (from Section 1)
                $settIdx = (int)$row['SettingIndex'];
                if (isset($this->sections[1])) {
                    foreach ($this->sections[1]['rows'] as $settRow) {
                        if ((int)$settRow['SettingIndex'] === $settIdx) {
                            $entry['settings'] = [
                                'resetStats' => (bool)(int)$settRow['Stats'],
                                'resetCommandDL' => (bool)(int)$settRow['CommandDL'],
                                'resetLevelUpPoints' => (bool)(int)$settRow['LevelUpPoints'],
                                'clearInventory' => self::VALUE_MAPS['Inventory'][(int)$settRow['Inventory']] ?? 'No',
                                'resetSkills' => (bool)(int)$settRow['Skills'],
                                'resetQuests' => (bool)(int)$settRow['Quests'],
                                'resetSkillTree' => (bool)(int)$settRow['SkillTree'],
                                'resetGoblinPoints' => (bool)(int)$settRow['GoblinPoints'],
                                'warpToBase' => (bool)(int)$settRow['ToBaseMap'],
                            ];
                            break;
                        }
                    }
                }

                // Rewards (from Section 3)
                $rewIdx = (int)$row['RewardIndex'];
                if (isset($this->sections[3])) {
                    foreach ($this->sections[3]['rows'] as $rewRow) {
                        if ((int)$rewRow['RewardIndex'] === $rewIdx) {
                            $pType = self::VALUE_MAPS['PointsType'][(int)$rewRow['PointsType']] ?? 'Unknown';
                            $entry['rewards'] = [
                                'pointsType' => $pType,
                                'levelUpPoints' => $rewRow['LevelUpPoints'],
                                'wccoins' => $rewRow['WCCoins'],
                                'wpcoins' => $rewRow['WPCoins'],
                                'goblinPoints' => $rewRow['GoblinPoints'],
                                'bagIndex' => $rewRow['BagIndex'],
                            ];
                            break;
                        }
                    }
                }

                // Item Requirements (from Section 2)
                $itemIdx = (int)$row['ItemReqIndex'];
                if ($itemIdx >= 0 && isset($this->sections[2])) {
                    foreach ($this->sections[2]['rows'] as $itemRow) {
                        if ((int)$itemRow['ItemReqIndex'] === $itemIdx) {
                            $itemType = self::ITEM_TYPES[(int)$itemRow['Type']] ?? "Type {$itemRow['Type']}";
                            $entry['itemReq'] = [
                                'type' => $itemType,
                                'typeId' => $itemRow['Type'],
                                'index' => $itemRow['Index'],
                                'level' => $itemRow['ItemLevel'],
                                'lifeOption' => $itemRow['LifeOption'],
                                'skill' => (int)$itemRow['Skill'] > 0 ? 'Sí' : 'No',
                                'luck' => (int)$itemRow['Luck'] > 0 ? 'Sí' : 'No',
                                'excellent' => $itemRow['Excellent'],
                                'durability' => $itemRow['Durability'],
                                'count' => $itemRow['Count'],
                            ];
                            break;
                        }
                    }
                }

                $summary['resets'][] = $entry;
            }
        }

        // Section 4: Gifts
        if (isset($this->sections[4])) {
            foreach ($this->sections[4]['rows'] as $row) {
                $summary['gifts'][] = [
                    'type' => (int)$row['Type'] === 0 ? 'Reset' : 'Master Reset',
                    'count' => $row['Count'],
                    'accountLevel' => self::VALUE_MAPS['AccountLevel'][(int)$row['AccountLevel']] ?? $row['AccountLevel'],
                    'bagIndex' => $row['BagIndex'],
                    'bagCount' => $row['BagCount'],
                    'levelUpPoints' => $row['LevelUpPoints'],
                    'zen' => $row['Zen'],
                ];
            }
        }

        // Section 5: Limits
        if (isset($this->sections[5])) {
            foreach ($this->sections[5]['rows'] as $row) {
                $summary['limits'][] = [
                    'type' => (int)$row['Type'] === 0 ? 'Reset' : 'Master Reset',
                    'limitAL0' => $row['Limit_AL0'],
                    'limitAL1' => $row['Limit_AL1'],
                    'limitAL2' => $row['Limit_AL2'],
                    'limitAL3' => $row['Limit_AL3'],
                ];
            }
        }

        // Section 6: Time limits
        if (isset($this->sections[6])) {
            foreach ($this->sections[6]['rows'] as $row) {
                $summary['timeLimits'][] = [
                    'type' => (int)$row['Type'] === 0 ? 'Reset' : 'Master Reset',
                    'startDay' => self::VALUE_MAPS['StartDoW'][(int)$row['StartDoW']] ?? $row['StartDoW'],
                    'startHour' => $row['StartHour'],
                    'startMin' => $row['StartMin'],
                ];
            }
        }

        // Section 7: Percentage points
        if (isset($this->sections[7])) {
            foreach ($this->sections[7]['rows'] as $row) {
                $summary['percentagePoints'][] = [
                    'resetIndex' => $row['Index'],
                    'DW' => $row['DW'] . '%',
                    'DK' => $row['DK'] . '%',
                    'FE' => $row['FE'] . '%',
                    'MG' => $row['MG'] . '%',
                    'DL' => $row['DL'] . '%',
                    'SU' => $row['SU'] . '%',
                    'RF' => $row['RF'] . '%',
                ];
            }
        }

        // Section 8: Extras
        if (isset($this->sections[8])) {
            foreach ($this->sections[8]['rows'] as $row) {
                $summary['extras'][] = [
                    'type' => (int)$row['Type'] === 0 ? 'Reset' : 'Master Reset',
                    'range' => "{$row['CountMin']} a {$row['CountMax']}",
                    'accountLevel' => self::VALUE_MAPS['AccountLevel'][(int)$row['AccountLevel']] ?? $row['AccountLevel'],
                    'addLevel' => $row['AddLevel'],
                    'addMasterLevel' => $row['AddMasterLevel'],
                    'addMLPoints' => $row['AddMLPoints'],
                    'addResets' => $row['AddResets'],
                    'resetLevel' => (int)$row['ResetLevel'] === 1 ? 'Sí' : 'No',
                ];
            }
        }

        return $summary;
    }

    /**
     * Get raw parsed sections data
     */
    public function getSections(): array
    {
        return $this->sections;
    }

    /**
     * Update a specific section's rows
     */
    public function updateSection(int $sectionId, array $rows): void
    {
        if (!isset(self::SECTION_DEFS[$sectionId])) {
            return; // Invalid section
        }
        
        // Create section if it doesn't exist
        if (!isset($this->sections[$sectionId])) {
            $this->sections[$sectionId] = [
                'name' => self::SECTION_DEFS[$sectionId]['name'] ?? "section_{$sectionId}",
                'rows' => []
            ];
        }
        
        $this->sections[$sectionId]['rows'] = $rows;
        
        // Clear cache so toHumanReadable regenerates from updated sections
        $this->humanReadableCache = null;
    }

    /**
     * Generate the output .txt file content from current sections data
     */
    public function generate(): string
    {
        $output = [];

        // Header
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '// MSPro - Reset Interface System';
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '// Version: 3.0';
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '';
        $output[] = '';

        // Section 0 - Requirements
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '// REQUIREMENTS - SECTION 0';
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '// [Type]:';
        $output[] = '//	0: Reset';
        $output[] = '//	1: MasterReset';
        $output[] = '//--------';
        $output[] = '// [Required Level/Resets]:';
        $output[] = '//	- When use Reset the system will require Level';
        $output[] = '//	- When use MasterReset the system will require Resets';
        $output[] = '//--------';
        $output[] = '// [ZenType]:';
        $output[] = '//	-1: Dont remove Zen';
        $output[] = '//	0: Remove ZenValue';
        $output[] = '//	1: Remove ZenValue * Resets or Master Resets';
        $output[] = '//-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '//												[SECTION 1]																																					[SECTION 2]			[SECTION 3]';
        $output[] = '//	[0~9999]	[0~1]		[-1/0~3]			[0~9999]			[  Required  ]		[ Reset / Master Reset ]       	[0~99999]		[0~99999]		[0~99999]						[0~2000000000]		[-1/0~999]			[-1/0~999]';
        $output[] = '//	[Index]		[Type]		[AccountLevel]		[SETTING Index]		[Level/Resets]		[		 Range   	   ]       	[Req.WCCoins]	[Req.WPCoins]	[Req.GoblinPoints]	[ZenType]	[ZenValue]			[ITEMREQ Index]		[REWARD Index]';
        $output[] = '//-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '0';
        if (isset($this->sections[0])) {
            foreach ($this->sections[0]['rows'] as $row) {
                $output[] = sprintf(
                    "   %-3s\t\t\t%-3s\t\t\t%-3s\t\t\t\t\t%-3s\t\t\t\t\t%-19s%-19s%-15s\t%-15s\t%-15s\t\t\t\t%s\t\t\t%s\t\t\t%s\t\t\t\t\t%s",
                    $row['Index'] ?? '0', $row['Type'] ?? '0', $row['AccountLevel'] ?? '-1',
                    $row['SettingIndex'] ?? '0', $row['RequiredLevelResets'] ?? '400',
                    str_pad($row['RangeStart'] ?? '0', 4, '0', STR_PAD_LEFT),
                    str_pad($row['RangeEnd'] ?? '0', 4, '0', STR_PAD_LEFT),
                    str_pad($row['ReqWCCoins'] ?? '0', 4, '0', STR_PAD_LEFT),
                    str_pad($row['ReqWPCoins'] ?? '0', 4, '0', STR_PAD_LEFT),
                    str_pad($row['ReqGoblinPoints'] ?? '0', 4, '0', STR_PAD_LEFT),
                    $row['ZenType'] ?? '1',
                    str_pad($row['ZenValue'] ?? '0', 10, '0', STR_PAD_LEFT),
                    $row['ItemReqIndex'] ?? '-1', $row['RewardIndex'] ?? '-1'
                );
            }
        }
        $output[] = 'end';
        $output[] = '';

        // Section 1 - Settings
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '// SETTING - SECTION 1';
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '// [Clear Inventory]:';
        $output[] = '//	0: Disabled';
        $output[] = '//	1: Inventory Only';
        $output[] = '//	2: Inventory + Equiped Items';
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '//						[0~1]		[0~1]			[0~1]				[0~2]			[0~1]		[0~1]		[0~1]			[0~1]				[0~1]';
        $output[] = '//						[------------------------------------------------------ REMOVE ------------------------------------------------------]		[  Warp  ]';
        $output[] = '//	[SETTING Index]		[Stats]		[Command(DL)]	[LevelUpPoints]		[Inventory]		[Skills]	[Quests]	[SkillTree]		[GoblinPoints]		[ToBaseMap]';
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '1';
        if (isset($this->sections[1])) {
            foreach ($this->sections[1]['rows'] as $row) {
                $output[] = sprintf(
                    "   %-3s\t\t\t\t\t%s\t\t\t%s\t\t\t\t%s\t\t\t\t\t%s\t\t\t\t%s\t\t\t%s\t\t\t%s\t\t\t\t%s\t\t\t\t\t%s",
                    $row['SettingIndex'], $row['Stats'], $row['CommandDL'],
                    $row['LevelUpPoints'], $row['Inventory'], $row['Skills'],
                    $row['Quests'], $row['SkillTree'], $row['GoblinPoints'], $row['ToBaseMap']
                );
            }
        }
        $output[] = 'end';
        $output[] = '';

        // Section 2 - Item Requirements
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '// ITEMREQ - SECTION 2';
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '//											[-1 / 0~15]		[-1 / 0~7]		[-1 / 0~1]	[-1 / 0~1]	[-1 / 0~63]		[-1 / 1~255]	[1~999]';
        $output[] = '//	[ITEMREQ Index]		[Type]	[Index]		[ItemLevel]		[LifeOption]	[Skill]		[Luck]		[Excellent]		[Durability]	[Count]';
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '2';
        if (isset($this->sections[2])) {
            foreach ($this->sections[2]['rows'] as $row) {
                $output[] = sprintf(
                    "   %-3s\t\t\t\t\t%s\t\t%s\t\t\t%s\t\t\t\t%s\t\t\t\t%s\t\t\t%s\t\t\t%s\t\t\t\t%s\t\t\t\t%s",
                    $row['ItemReqIndex'], $row['Type'], $row['Index'],
                    $row['ItemLevel'], $row['LifeOption'], $row['Skill'],
                    $row['Luck'], $row['Excellent'], $row['Durability'], $row['Count']
                );
            }
        }
        $output[] = 'end';
        $output[] = '';

        // Section 3 - Rewards
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '// REWARD - SECTION 3';
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '// [PointsType]:';
        $output[] = '//	0: LevelPoints';
        $output[] = '//	1: LevelPoints * Reset or MasterReset';
        $output[] = '//	2: M.Points					[MasterReset Only!]';
        $output[] = '//	3: M.Points * M.Resets		[MasterReset Only!]';
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '//						[0 ~ 3]				[0~99999]			[0~99999]		[0~99999]		[0~99999]			[-1 / 0~999]';
        $output[] = '//	[REWARD Index]		[PointsType]		[LevelUpPoints]		[WCCoins]		[WPCoins]		[GoblinPoints]		[BagIndex]';
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '3';
        if (isset($this->sections[3])) {
            foreach ($this->sections[3]['rows'] as $row) {
                $output[] = sprintf(
                    "   %-3s\t\t\t\t\t%s\t\t\t\t\t%s\t\t\t\t\t%s\t\t\t\t%s\t\t\t\t%s\t\t\t\t\t%s",
                    $row['RewardIndex'], $row['PointsType'], $row['LevelUpPoints'],
                    $row['WCCoins'], $row['WPCoins'], $row['GoblinPoints'], $row['BagIndex']
                );
            }
        }
        $output[] = 'end';
        $output[] = '';

        // Section 4 - Gifts
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '// Reset Gift';
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '// [Type]:';
        $output[] = '//	0: Reset';
        $output[] = '//	1: MasterReset';
        $output[] = '// [Count]:';
        $output[] = '//	- Reset or Master Reset Number when Gift will be granted';
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '//	[0~1]		[0~9999]		[-1/0~3]			[-1/0~999]		[1~99]			[0~99999]			[0~99999]		[0~99999]		[0~99999]			[0~2000000000]';
        $output[] = '//	[Type]		[Count]			[AccountLevel]		[BagIndex]		[BagCount]		[LevelUpPoints]		[WCCoins]		[WPCoins]		[GoblinPoints]		[Zen]';
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '4';
        if (isset($this->sections[4])) {
            foreach ($this->sections[4]['rows'] as $row) {
                $output[] = sprintf(
                    "   %-3s\t\t\t%s\t\t    \t%s\t\t\t\t\t%s\t\t        %s\t\t\t\t%s\t\t\t\t\t%s\t\t\t\t%s\t\t\t\t%s\t\t\t\t\t%s",
                    $row['Type'], $row['Count'], $row['AccountLevel'],
                    $row['BagIndex'], $row['BagCount'], $row['LevelUpPoints'],
                    $row['WCCoins'], $row['WPCoins'], $row['GoblinPoints'], $row['Zen']
                );
            }
        }
        $output[] = 'end';
        $output[] = '';

        // Section 5 - Limits
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '// Reset Limit - DO NOT ADD NEW LINES! JUST CHANGE SETTINGS';
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '// [Type]:';
        $output[] = '//	0: Reset';
        $output[] = '//	1: MasterReset';
        $output[] = '// INFO: This limit will allow to do X amount of Reset / Master Resets in certain space of Time';
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '//	[0~1]				[-1 / 1~9999]	[-1 / 1~9999]	[-1 / 1~9999]	[-1 / 1~9999]';
        $output[] = '//	[Type]				[Limit_AL0]		[Limit_AL1]		[Limit_AL2]		[Limit_AL3]';
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '5';
        $s5rows = $this->sections[5]['rows'] ?? [];
        if (empty($s5rows)) {
            // Default: no limits (-1 = unlimited) for both Reset and MR
            $s5rows = [
                ['Type'=>'0','Limit_AL0'=>'-1','Limit_AL1'=>'-1','Limit_AL2'=>'-1','Limit_AL3'=>'-1'],
                ['Type'=>'1','Limit_AL0'=>'-1','Limit_AL1'=>'-1','Limit_AL2'=>'-1','Limit_AL3'=>'-1'],
            ];
        }
        foreach ($s5rows as $row) {
            $output[] = sprintf(
                "\t%s\t\t\t\t\t%s\t\t\t\t%s\t\t\t\t%s\t\t\t\t%s",
                $row['Type'], $row['Limit_AL0'] ?? '-1', $row['Limit_AL1'] ?? '-1',
                $row['Limit_AL2'] ?? '-1', $row['Limit_AL3'] ?? '-1'
            );
        }
        $output[] = 'end';
        $output[] = '';

        // Section 6 - Limit Time
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '// Reset Limit Time Reset - DO NOT ADD NEW LINES! JUST CHANGE SETTINGS';
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '// [Type]:';
        $output[] = '//	0: Reset';
        $output[] = '//	1: MasterReset';
        $output[] = '//--';
        $output[] = '// [StartDoW]:';
        $output[] = '//	1: Sunday / 2: Monday / 3: Tuesday / 4: Wednesday / 5: Thursday / 6: Friday / 7: Saturday';
        $output[] = '//--';
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '// 	[0~1]				[* / 0~12]   [* / 0~31] [* / 1~7]	[* / 0~23]  [* / 0~59]';
        $output[] = '// 	[Type]				StartMonth   StartDay 	StartDoW	StartHour	StartMin';
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '6';
        $s6rows = $this->sections[6]['rows'] ?? [];
        if (empty($s6rows)) {
            // Default: no time restriction (M=-1, D=-1, DoW=1, H=23, Min=59)
            $s6rows = [
                ['Type'=>'0','StartMonth'=>'-1','StartDay'=>'-1','StartDoW'=>'1','StartHour'=>'23','StartMin'=>'59'],
                ['Type'=>'1','StartMonth'=>'-1','StartDay'=>'-1','StartDoW'=>'1','StartHour'=>'23','StartMin'=>'59'],
            ];
        }
        foreach ($s6rows as $row) {
            $output[] = sprintf(
                "   %s\t\t\t\t\t%s\t\t\t%s\t\t\t%s\t\t\t%s\t\t\t%s",
                $row['Type'], $row['StartMonth'] ?? '-1', $row['StartDay'] ?? '-1',
                $row['StartDoW'] ?? '1', $row['StartHour'] ?? '23', $row['StartMin'] ?? '59'
            );
        }
        $output[] = 'end';
        $output[] = '';

        // Section 7 - Percentage Points
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '// Reset Percentage Points - Applies only to SECTION 3 LevelUpPoints related but Related to Main Reset Index';
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '//	[SECTION 0]';
        $output[] = '//	[0~9999]					[ 				  LevelPoints FROM SECTION 3		0~999%				   ]';
        $output[] = '//	[Index]				        [DW]		[DK]		[FE]		[MG]		[DL]		[SU]		[RF]';
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '7';
        // Auto-generate S7 entries at 100% for each S0 entry if missing
        $s7rows = $this->sections[7]['rows'] ?? [];
        $s0rows = $this->sections[0]['rows'] ?? [];
        $s7indices = array_map(fn($r) => $r['Index'], $s7rows);
        foreach ($s0rows as $s0row) {
            $idx = $s0row['Index'];
            if (!in_array($idx, $s7indices)) {
                $s7rows[] = ['Index'=>$idx,'DW'=>'100','DK'=>'100','FE'=>'100','MG'=>'100','DL'=>'100','SU'=>'100','RF'=>'100'];
            }
        }
        foreach ($s7rows as $row) {
            $output[] = sprintf(
                "   %s\t\t\t\t\t\t\t%s\t\t\t%s\t\t\t%s\t\t\t%s\t\t\t%s\t\t\t%s\t\t\t%s",
                $row['Index'], $row['DW'] ?? '100', $row['DK'] ?? '100', $row['FE'] ?? '100',
                $row['MG'] ?? '100', $row['DL'] ?? '100', $row['SU'] ?? '100', $row['RF'] ?? '100'
            );
        }
        $output[] = 'end';
        $output[] = '';

        // Section 8 - Extras
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '// Reset Extras';
        $output[] = '//-----------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '// [Type]:';
        $output[] = '//	0: Reset';
        $output[] = '//	1: MasterReset';
        $output[] = '//-------------------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '//																																		[   For MasterReset ONLY   ]';
        $output[] = '//	[0~1]				[0~9999]		[0~9999]		[-1/0~3]			[0~400]			[0~400]					[0~99999]			[0~99999]		[0/1]';
        $output[] = '//	[Type]				[CountMin]		[CountMax]		[AccountLevel]		[AddLevel]		[AddMasterLevel]		[AddMLPoints]		[AddResets]		[ResetLevel]';
        $output[] = '//-------------------------------------------------------------------------------------------------------------------------------------------------------------------------';
        $output[] = '8';
        if (isset($this->sections[8])) {
            foreach ($this->sections[8]['rows'] as $row) {
                $output[] = sprintf(
                    "\t%s\t\t\t\t\t%s\t\t\t\t%s\t\t\t\t%s\t\t\t\t\t%s\t\t\t%s\t\t\t\t\t\t%s\t\t\t\t\t%s\t\t\t\t%s",
                    $row['Type'], $row['CountMin'], $row['CountMax'],
                    $row['AccountLevel'], $row['AddLevel'], $row['AddMasterLevel'],
                    $row['AddMLPoints'], $row['AddResets'], $row['ResetLevel']
                );
            }
        }
        $output[] = 'end';

        return implode("\r\n", $output);
    }

    /**
     * Get the section definitions (useful for admin panel)
     */
    public static function getSectionDefinitions(): array
    {
        return self::SECTION_DEFS;
    }

    /**
     * Get value maps for dropdowns
     */
    public static function getValueMaps(): array
    {
        return self::VALUE_MAPS;
    }

    /**
     * Get item types
     */
    public static function getItemTypes(): array
    {
        return self::ITEM_TYPES;
    }

    /**
     * Build the system prompt for AI with current config context
     */
    public function buildAISystemPrompt(array $adminRules = []): string
    {
        $summary = $this->humanReadableCache ?? $this->toHumanReadable();
        $summaryJson = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $rulesText = '';
        if (!empty($adminRules)) {
            $rulesText = "\n\nREGLAS DEL ADMINISTRADOR (respetar siempre):\n";
            foreach ($adminRules as $rule) {
                $rulesText .= "- {$rule}\n";
            }
        }

        return <<<PROMPT
Configurador ResetSystem.txt de MU Online. Solo hablás de este archivo.
Si preguntan otra cosa: "Solo configuro ResetSystem.txt. ¿Qué necesitás?"

=== REGLA #1: SER BREVE ===
- Respuestas CORTAS. Nada de explicaciones largas.
- Máximo 5-8 líneas de texto. Tablas compactas.
- No repitas info que el usuario ya sabe.

=== REGLA #2: CONFIRMAR ANTES DE APLICAR ===
Cuando el usuario pida cambios, SIEMPRE:
1. Mostrá un resumen COMPACTO de lo que vas a hacer
2. Preguntá "¿Confirmo?" o "¿Correcto?"
3. SOLO cuando confirme, incluí el bloque config_update

Excepción: si dice "hacelo", "dale", "sí", "confirmo" → aplicá directo.

=== REGLA #3: ACCOUNTLEVEL - CRÍTICO ===
AccountLevel define QUIÉN puede usar esa entrada:
- -1 = TODOS (free + vips juntos)
- 0 = Solo FREE
- 1 = Solo VIP1
- 2 = Solo VIP2
- 3 = Solo VIP3

⚠️ REGLA DE CONSISTENCIA: Si el usuario distingue FREE de VIP, TODOS los rangos de Reset deben tener entradas separadas por tipo de cuenta. NUNCA mezclar AL=-1 con AL=0/1/2/3 en entradas de tipo Reset.

INCORRECTO (mezcla -1 con 0/1/2/3):
#0: AL=0, rango 0-400, lvl 380 (FREE)
#1: AL=1, rango 0-400, lvl 350 (VIP1)
#2: AL=2, rango 0-400, lvl 350 (VIP2)
#3: AL=3, rango 0-400, lvl 350 (VIP3)
#4: AL=-1, rango 401+, lvl 400 ← MAL! Debería ser 4 entradas separadas

CORRECTO:
#0: AL=0, rango 0-400, lvl 380 (FREE)
#1: AL=1, rango 0-400, lvl 350 (VIP1)
#2: AL=2, rango 0-400, lvl 350 (VIP2)
#3: AL=3, rango 0-400, lvl 350 (VIP3)
#4: AL=0, rango 401+, lvl 400 (FREE)
#5: AL=1, rango 401+, lvl 400 (VIP1)
#6: AL=2, rango 401+, lvl 400 (VIP2)
#7: AL=3, rango 401+, lvl 400 (VIP3)
#8: Type=1, AL=-1, MR ← OK, MR puede ser -1 si no distingue

Excepción: MasterReset (Type=1) PUEDE usar AL=-1 si el usuario no pidió distinción ahí.

=== REGLA #4: SOLO MODIFICAR LO PEDIDO ===
- Si el usuario pide cambiar el nivel, SOLO cambiá el nivel
- NO modifiques zen, items, rewards, settings, rangos ni nada que no haya pedido
- Copiá los valores exactos de la config actual para todo lo que no cambie
- Si la config original tiene 3 entradas con mismos valores de zen, las 3 nuevas deben tener el mismo zen

=== REGLA #5: PREGUNTAR LO QUE FALTA ===
Si el usuario no especifica algo, preguntá brevemente:
- "¿Zen? (ej: 50k fijo, 200k×resets)"
- "¿Necesita item? (ej: Jewel of Bless)"
- "¿Mantener rewards actuales?"
- "¿El rango 401+ también diferenciado por cuenta o igual para todos?"
NO asumas valores grandes. Preguntá.

=== SECCIONES DEL ARCHIVO ===
S0-Requirements: Index Type AccountLevel SettingIndex RequiredLevelResets RangeStart RangeEnd ReqWCCoins ReqWPCoins ReqGoblinPoints ZenType ZenValue ItemReqIndex RewardIndex
S1-Settings: SettingIndex Stats CommandDL LevelUpPoints Inventory Skills Quests SkillTree GoblinPoints ToBaseMap (0/1, Inventory: 0/1/2)
S2-ItemReq: ItemReqIndex Type Index ItemLevel LifeOption Skill Luck Excellent Durability Count
S3-Rewards: RewardIndex PointsType LevelUpPoints WCCoins WPCoins GoblinPoints BagIndex (PT: 0=fijo, 1=×resets, 2=MP fijo, 3=MP×MR)
S4-Gifts: Type Count AccountLevel BagIndex BagCount LevelUpPoints WCCoins WPCoins GoblinPoints Zen
S5-Limits: Type Limit_AL0 Limit_AL1 Limit_AL2 Limit_AL3 (DEFAULT todo -1 = sin límite. No tocar salvo que el usuario lo pida)
S6-LimitTime: Type StartMonth StartDay StartDoW StartHour StartMin (DEFAULT: -1 -1 1 23 59 = sin restricción horaria. No tocar salvo que lo pida)
S7-Percentage: Index DW DK FE MG DL SU RF (% puntos por clase, DEFAULT 100 en todas. Solo cambiar si el usuario lo pide explícitamente)
S8-Extras: Type CountMin CountMax AccountLevel AddLevel AddMasterLevel AddMLPoints AddResets ResetLevel

Valores clave:
- Type: 0=Reset, 1=MasterReset
- ZenType: -1=no cobrar, 0=fijo, 1=×resets
- RangeEnd 0 = sin límite
- Referencias: SettingIndex→S1, ItemReqIndex→S2 (-1=ninguno), RewardIndex→S3

=== CONFIG ACTUAL ===
{$summaryJson}
{$rulesText}

=== FORMATO config_update (OBLIGATORIO cuando aplicás cambios) ===
Al final de tu respuesta, incluí SOLO las secciones modificadas:
```config_update
{"sections":{"0":{"rows":[{"Index":"0","Type":"0","AccountLevel":"0","SettingIndex":"0","RequiredLevelResets":"380","RangeStart":"0","RangeEnd":"10","ReqWCCoins":"0","ReqWPCoins":"0","ReqGoblinPoints":"0","ZenType":"1","ZenValue":"50000","ItemReqIndex":"-1","RewardIndex":"0"}]}}}
```
- Solo secciones que CAMBIARON
- TODAS las rows de esa sección (no solo las modificadas)
- Valores como STRINGS
- NO incluyas si solo explicaste algo
PROMPT;
    }
}

<?php

class BonusPartyParser
{
    private array $general = [100, 266, 321, 448, 610, 736, 805, 1012, 1133, 1234];
    private array $special = [100, 286, 352, 534, 690, 788, 885, 1092, 1214, 1351];

    public function getDefaults(): array
    {
        return [
            'general' => $this->general,
            'special' => $this->special,
        ];
    }

    public function parse(string $content): array
    {
        $general = array_fill(0, 10, 100);
        $special = array_fill(0, 10, 100);

        foreach (explode("\n", $content) as $line) {
            $line = trim(str_replace("\r", '', $line));
            if (empty($line) || $line[0] === '/' || $line[0] === '#') continue;

            if (preg_match('/PartyGeneralExperience(\d+)\s*=\s*(\d+)/i', $line, $m)) {
                $idx = (int)$m[1] - 1;
                if ($idx >= 0 && $idx < 10) $general[$idx] = (int)$m[2];
            }
            if (preg_match('/PartySpecialExperience(\d+)\s*=\s*(\d+)/i', $line, $m)) {
                $idx = (int)$m[1] - 1;
                if ($idx >= 0 && $idx < 10) $special[$idx] = (int)$m[2];
            }
        }

        $this->general = $general;
        $this->special = $special;

        return ['general' => $general, 'special' => $special];
    }

    public function updateValues(array $general, array $special): void
    {
        if (count($general) === 10) $this->general = array_map('intval', $general);
        if (count($special) === 10) $this->special = array_map('intval', $special);
    }

    public function generate(): string
    {
        $lines = [];
        $lines[] = "//----------------------------------------------";
        $lines[] = "// Party Experience Configuration";
        $lines[] = "// MSPro Config AI - Generated";
        $lines[] = "//----------------------------------------------";
        $lines[] = "";
        $lines[] = "// General Party Bonus (normal party)";
        $lines[] = "// 1 player = always 100 (base)";
        for ($i = 0; $i < 10; $i++) {
            $n = $i + 1;
            $pad = $n < 10 ? " " : "";
            $lines[] = "PartyGeneralExperience{$n} {$pad}\t= {$this->general[$i]}";
        }
        $lines[] = "";
        $lines[] = "// Special Party Bonus (DW+DK+ELF, MG+DL+SU, DL+SU+RF)";
        $lines[] = "// 1 player = always 100 (base)";
        for ($i = 0; $i < 10; $i++) {
            $n = $i + 1;
            $pad = $n < 10 ? " " : "";
            $lines[] = "PartySpecialExperience{$n}{$pad}\t= {$this->special[$i]}";
        }

        return implode("\r\n", $lines);
    }

    public function toHumanReadable(): array
    {
        return [
            'general' => $this->general,
            'special' => $this->special,
            'summary' => $this->buildSummary(),
        ];
    }

    private function buildSummary(): array
    {
        $rows = [];
        for ($i = 0; $i < 10; $i++) {
            $rows[] = [
                'players' => $i + 1,
                'general' => $this->general[$i],
                'special' => $this->special[$i],
                'generalPct' => $this->general[$i] . '%',
                'specialPct' => $this->special[$i] . '%',
            ];
        }
        return $rows;
    }

    public function buildAISystemPrompt(array $adminRules = [], array $serverConfig = []): string
    {
        $configJson = json_encode([
            'general' => $this->general,
            'special' => $this->special,
        ], JSON_PRETTY_PRINT);

        $rulesText = '';
        if (!empty($adminRules)) {
            $rulesText = "\nREGLAS DEL ADMINISTRADOR:\n";
            foreach ($adminRules as $rule) {
                $rulesText .= "- {$rule}\n";
            }
        }

        $maxLevel = (int)($serverConfig['maxLevel'] ?? 400);
        $formula1000 = (int)($serverConfig['formula1000'] ?? 0);
        $expRate = $serverConfig['expRate'] ?? [200, 200, 200, 200];
        $masterExpRate = $serverConfig['masterExpRate'] ?? [2000, 2000, 2000, 2000];
        $er = (int)($expRate[0] ?? 200);

        // Pre-calculate reference table for common scenarios
        $refTable = $this->buildReferenceTable($maxLevel, $er);

        return <<<PROMPT
Configurador de Party Experience de MU Online. Solo hablás de este tema.
Si preguntan otra cosa: "Solo configuro Party Experience. ¿Qué necesitás?"

=== REGLA #1: SER BREVE ===
Respuestas CORTAS, máximo 8-10 líneas.

=== REGLA #2: NUNCA CALCULÉS MANUALMENTE ===
NO hagas cuentas. Usá ÚNICAMENTE la tabla pre-calculada de abajo.
Si te piden un cálculo que no está en la tabla, decí:
"Usá la pestaña Configuración → Simulación en Vivo para ver el resultado exacto. Cambiá Mob Lvl y Char Lvl y te da el número preciso."

=== TABLA DE REFERENCIA (valores EXACTOS pre-calculados) ===
Server: MaxLevel={$maxLevel}, ExpRate={$er}

{$refTable}

=== CÓMO FUNCIONA PARTY ===
PartyBonus% = EXP TOTAL de la party como % de exp solo. Se REPARTE entre miembros según nivel.

⚠️ CONCEPTO CRÍTICO - ENTENDÉ ESTO ANTES DE CALCULAR:
PartyBonus 106% con 2 jugadores = TOTAL es 106% de solo → cada uno recibe 53% = PIERDE exp.
PartyBonus 200% con 2 jugadores = TOTAL es 200% de solo → cada uno recibe 100% = IGUAL que solo.
PartyBonus 212% con 2 jugadores = TOTAL es 212% de solo → cada uno recibe 106% = GANA 6% más.

REGLA: Para que cada jugador gane lo MISMO que solo, PartyBonusN = 100 × N.
Para que gane X% MÁS que solo: PartyBonusN = (100 + X) × N.

Ejemplos:
- 2p gane +6% c/u: (100+6) × 2 = 212
- 3p gane +6% c/u: (100+6) × 3 = 318
- 4p gane +6% c/u: (100+6) × 4 = 424
- 2p gane +9% c/u (special): (100+9) × 2 = 218

Si el usuario dice "+6% por cada jugador adicional" y "+9% en special":
General: 1p=100, 2p=(106)×2=212, 3p=(112)×3=336, 4p=(118)×4=472, 5p=(124)×5=620...
Special: 1p=100, 2p=(109)×2=218, 3p=(118)×3=354, 4p=(127)×4=508, 5p=(136)×5=680...

NUNCA pongas 106 como PartyBonus para 2 jugadores si quieren +6%. Eso da -47% por jugador.

=== FLUJO PARA CONFIGURAR ===
Cuando pidan configurar, preguntá:
1. "¿Cuánto % MÁS que solo querés que gane CADA jugador por cada miembro extra?"
2. "¿Y en Special Party cuánto %?"
3. Calculá con la fórmula: PartyBonusN = (100 + incremento × (N-1)) × N

Ejemplo completo: "+6% general, +9% special":
| N | %c/u Gen | Bonus Gen | %c/u Spe | Bonus Spe |
| 1 | 100%     | 100       | 100%     | 100       |
| 2 | 106%     | 212       | 109%     | 218       |
| 3 | 112%     | 336       | 118%     | 354       |
| 4 | 118%     | 472       | 127%     | 508       |
| 5 | 124%     | 620       | 136%     | 680       |
| 6 | 130%     | 780       | 145%     | 870       |
| 7 | 136%     | 952       | 154%     | 1078      |
| 8 | 142%     | 1136      | 163%     | 1304      |
| 9 | 148%     | 1332      | 172%     | 1548      |
|10 | 154%     | 1540      | 181%     | 1810      |

=== CONFIG ACTUAL ===
General: {$this->formatArray($this->general)}
Special: {$this->formatArray($this->special)}

Server: MaxLevel={$maxLevel}, Formula1000={$formula1000}
ExpRate: AL0={$expRate[0]}, AL1={$expRate[1]}, AL2={$expRate[2]}, AL3={$expRate[3]}
MasterExpRate: AL0={$masterExpRate[0]}, AL1={$masterExpRate[1]}, AL2={$masterExpRate[2]}, AL3={$masterExpRate[3]}
{$rulesText}

=== FORMATO party_update (OBLIGATORIO cuando hacés cambios) ===
Al final de tu respuesta, incluí:
```party_update
{"general":[100,v2,v3,v4,v5,v6,v7,v8,v9,v10],"special":[100,v2,v3,v4,v5,v6,v7,v8,v9,v10]}
```
REGLAS:
- Siempre 10 valores en cada array
- El primer valor SIEMPRE es 100
- Valores como NÚMEROS enteros
- Solo incluir si hacés cambios
- Special >= General en cada posición

=== DESPUÉS DE APLICAR CAMBIOS (OBLIGATORIO) ===
Cuando incluyas party_update, SIEMPRE terminá con este texto exacto:

"✅ **Valores aplicados.** Verificá en **⚙️ Configuración → Party Experience** y comprobá con la **🎯 Simulación en Vivo** que los números coincidan con lo que esperás."

Mostrá una tabla resumen con los 10 valores nuevos de General y Special para que el usuario pueda comparar.
PROMPT;
    }

    private function calcExpSolo(int $mobLevel, int $charLevel, int $expRate): int
    {
        $base = (int)(($mobLevel + 25) * $mobLevel / 3);
        if ($mobLevel + 10 < $charLevel) {
            $base = (int)($base * ($mobLevel + 10) / $charLevel);
        }
        if ($mobLevel >= 65) {
            $base += (int)(($mobLevel - 64) * (int)($mobLevel / 4));
        }
        $base = max(0, $base);
        $adj = (int)($base + $base / 4);
        return $adj * $expRate;
    }

    private function buildReferenceTable(int $maxLevel, int $expRate): string
    {
        $mobLevels = [30, 50, 60, 80, 100, 120, 150];
        $charLevels = [60, 80, 100, 150, 200, 300, 400];
        
        // Filter relevant char levels
        $charLevels = array_filter($charLevels, fn($cl) => $cl <= $maxLevel);
        if (!in_array($maxLevel, $charLevels) && $maxLevel > 0) {
            $charLevels[] = $maxLevel;
        }
        sort($charLevels);

        $lines = [];
        foreach ($mobLevels as $mob) {
            $line = "Mob {$mob}: ";
            $parts = [];
            foreach ($charLevels as $cl) {
                $exp = $this->calcExpSolo($mob, $cl, $expRate);
                $parts[] = "charLvl{$cl}=" . number_format($exp, 0, '', '.');
            }
            $lines[] = $line . implode(', ', $parts);
            
            // Add party breakdown for this mob with first charLevel
            $soloRef = $this->calcExpSolo($mob, $charLevels[count($charLevels)-1], $expRate);
            $partyLine = "  → Party (char{$charLevels[count($charLevels)-1]}): ";
            $pp = [];
            for ($n = 2; $n <= 5; $n++) {
                $gPct = $this->general[$n-1];
                $adj = (int)($soloRef / $expRate); // back to adjusted
                $perPlayer = (int)($adj * $gPct / $n / 100) * $expRate;
                $pp[] = "{$n}p=" . number_format($perPlayer, 0, '', '.') . "c/u";
            }
            $lines[] = $partyLine . implode(', ', $pp);
        }

        return implode("\n", $lines);
    }

    private function formatArray(array $arr): string
    {
        return implode(', ', $arr);
    }
}

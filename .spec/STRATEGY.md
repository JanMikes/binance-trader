
# Specifikace: Grid/Košíkový bot pro SOL/USDC (Binance) – **zjednodušené zadání**

> Tento dokument popisuje **JAK** má bot rozhodovat o objednávkách („as should be“ stav) každou vteřinu. Je záměrně stručný a technický. Příklady kódu jsou v **PHP** a v kontextu Binance (spot, bez páky).

---

## 1) Cíl funkce

Funkce `computeDesiredOrders()` **deterministicky** spočítá seznam objednávek, které **mají být** na burze otevřené (BUY grid + SELL TP). Orchestrátor pak porovná SHOULD-BE vs. skutečné `open_orders` a provede `create/cancel/replace`.

- Bez rekurze, čistě **iterativně**.
- Idempotentní díky **clientOrderId** schématu.

---

## 2) I/O rozhraní

### Vstupy `computeDesiredOrders(array $cfg, array $state, array $market)`

- `symbol` – např. `"SOLUSDC"`
- `now` – timestamp (ms)
- `anchor_price_P0` – kotva gridu (float)
- `zone_bottom_pct` – např. `0.30` (−30 % pod P0)
- `levels_pct[]` – pole poklesů od P0 v (0 .. zone_bottom_pct], např. `[0.05,0.10,...,0.30]`
- `alloc_weights[]` – stejné N prvků, součet **1.0** (váhy kapitálu na level)
- `tp_start_pct`, `tp_step_pct`, `tp_min_pct`, `tp2_delta_pct`
- `tp1_share`, `tp2_share`, `trail_share` – podíly pozice (součet 1.0)
- `trailing_callback_pct` – pro trailing sell (nebo `null`)
- `hard_stop_mode` – `"none" | "hard" | "extend_zone"`
- `hard_stop_pct`, `extend_zone_bottom_pct` (dle režimu)
- `max_grid_capital_quote` – rozpočet košíku v USDC
- `fee_rate` – poplatek (např. `0.001` = 0.1 %)
- `lot_size`, `tick_size`, `min_notional` – limity trhu
- `place_mode` – `"all_unfilled"` | `"only_next_k"`
- `k_next` – počet aktivních „nejbližších“ levelů pro `"only_next_k"`
- `reanchor_rules` – např. `["close_ratio"=>0.7,"time_TTL_s"=>86400]`
- **State:**
  - `available_quote_balance`, `available_base_balance`
  - `open_orders[]` – aktuální otevřené objednávky (id/side/price/qty/clientId)
  - `position_base_qty` – držené množství SOL v košíku
  - `fills_history[]` – fillované obchody pro běžný košík
  - `basket_id` – identifikátor košíku
- **Market:**
  - `last_trade_price` – poslední cena

### Výstup
```json
{
  "buys":  [OrderSpec...],
  "sells": [OrderSpec...],
  "meta": {
    "basket_id": "string",
    "avg_price":  123.45,   // VWAP držené pozice
    "filled_levels": 3,
    "planned_levels_N": 6,
    "remaining_quote_budget": 652.10,
    "reanchor_suggested": false
  }
}
```
`OrderSpec = { side, type, price|null, qty, clientId }`

---

## 3) Schéma `clientOrderId` (deterministické)

- BUY level *i*: `BOT:{symbol}:{basket_id}:B:{i}`  
- TP1: `BOT:{symbol}:{basket_id}:S:TP1`  
- TP2: `BOT:{symbol}:{basket_id}:S:TP2`  
- TRAIL: `BOT:{symbol}:{basket_id}:S:TRAIL`

Zaručuje idempotenci a jednoduchý reconcile.

---

## 4) Algoritmus (iterativně)

1. **Postav plán levelů**
   - Pro `i=1..N`:
     - `buy_price_i = round_down( P0 * (1 - levels_pct[i]), tick_size )`
     - `planned_quote_i = max_grid_capital_quote * alloc_weights[i]`
     - `planned_qty_i = round_down( planned_quote_i / buy_price_i, lot_size )`
     - pokud `planned_qty_i * buy_price_i < min_notional` → level **neaktivní**
2. **Vyhodnoť fillované nákupy & VWAP**
   - `filled_qty_total`, `filled_quote_total`, `avg_price = filled_quote_total / filled_qty_total` (když >0)
   - `n_filled_levels` = počet unikátních levelů s fill
   - `remaining_quote_budget = max_grid_capital_quote − spendnuté_quote`
3. **Ochrany (zona/stop)**
   - `hard_stop_mode == "hard"` a `last <= P0*(1-hard_stop_pct)` → **žádné další BUY** pod tou hranicí
   - `extend_zone`: přidej druhou řidší sadu levelů do `extend_zone_bottom_pct`
4. **BUY SHOULD-BE**
   - Kandidáti = nevyplněné a aktivní levely
   - Pokud `place_mode=="only_next_k"` → zvol K nejbližších pod aktuální cenou
   - Přidej jen pokud **kryje rozpočet** a **balance**
5. **SELL SHOULD-BE** (pokud držíš qty)
   - `TPn = max(tp_start_pct - tp_step_pct*(n_filled_levels-1), tp_min_pct)`
   - `tp1_price = round_up(avg_price*(1+TPn), tick_size)`
   - `tp2_price = round_up(avg_price*(1+TPn+tp2_delta_pct), tick_size)`
   - Kvóty: `q1=pos*tp1_share`, `q2=pos*tp2_share`, `q3=pos - q1 - q2` (zaokrouhlení `lot_size`)
   - Pokud otevřená SELL neodpovídá cenou/qty → zahrň do SHOULD-BE
   - Trailing: `TRAIL` jako typ (nebo meta pro simulaci)
6. **Reanchor návrh**
   - `reanchor_suggested = (pos==0) || (uzavřeno > close_ratio) || (age > time_TTL)`
7. **Vrátit SHOULD-BE**

---

## 5) PHP – pomocné funkce

```php
function roundDown($x, $step) {
    return floor($x / $step) * $step;
}
function roundUp($x, $step) {
    return ceil($x / $step) * $step;
}
```

---

## 6) PHP – skeleton `computeDesiredOrders()`

> **Pozn.:** Jedná se o zjednodušený skeleton; reálná implementace řeší mapping na Binance API struktury.

```php
<?php

function computeDesiredOrders(array $cfg, array $state, array $market): array {
    $P0       = $cfg['anchor_price_P0'];
    $tick     = $cfg['tick_size'];
    $lot      = $cfg['lot_size'];
    $minNot   = $cfg['min_notional'];
    $N        = count($cfg['levels_pct']);
    $basketId = $state['basket_id'];
    $symbol   = $cfg['symbol'];

    // 1) Build planned levels
    $levels = [];
    for ($i=0; $i<$N; $i++) {
        $drop  = $cfg['levels_pct'][$i];
        $price = roundDown($P0 * (1 - $drop), $tick);
        $quote = $cfg['max_grid_capital_quote'] * $cfg['alloc_weights'][$i];
        $qty   = roundDown($quote / $price, $lot);
        if ($qty * $price >= $minNot && $qty > 0) {
            $levels[] = [
                'idx'   => $i+1,
                'price' => $price,
                'qty'   => $qty,
                'cid'   => "BOT:{$symbol}:{$basketId}:B:".($i+1),
            ];
        }
    }

    // 2) Fills & VWAP
    $filledQty = 0.0; $filledQuote = 0.0;
    $filledIdx = [];
    foreach ($state['fills_history'] as $f) {
        if ($f['side'] === 'BUY') {
            $filledQty   += $f['qty'];
            $filledQuote += $f['price'] * $f['qty'];
            // map na nejbližší level dle ceny (tolerance 1*tick)
            foreach ($levels as $lv) {
                if (abs($lv['price'] - $f['price']) <= $tick) {
                    $filledIdx[$lv['idx']] = true;
                    break;
                }
            }
        }
    }
    $avgPrice = $filledQty > 0 ? $filledQuote / $filledQty : null;
    $nFilled  = count($filledIdx);

    // 3) Zone protection (hard)
    if ($cfg['hard_stop_mode'] === 'hard') {
        $stopPrice = $P0 * (1 - $cfg['hard_stop_pct']);
        $levels = array_values(array_filter($levels, fn($lv) => $lv['price'] >= $stopPrice));
    }

    // 4) BUY SHOULD-BE
    $last  = $market['last_trade_price'];
    $buys  = [];
    $budget = $cfg['max_grid_capital_quote']; // lze přesněji korigovat podle spendu
    // pouze nevyplněné levely
    $candidates = array_values(array_filter($levels, fn($lv) => !isset($filledIdx[$lv['idx']])));

    if ($cfg['place_mode'] === 'only_next_k') {
        // seřaď podle vzdálenosti pod current price
        usort($candidates, fn($a,$b) => ($b['price'] <=> $a['price'])); // odshora dolů
        // vyber K, které jsou pod last price
        $filtered = [];
        foreach ($candidates as $lv) {
            if ($lv['price'] <= $last) $filtered[] = $lv;
            if (count($filtered) >= $cfg['k_next']) break;
        }
        $candidates = $filtered;
    }

    foreach ($candidates as $lv) {
        $cost = $lv['price'] * $lv['qty'];
        if ($cost <= $budget && $cost <= $state['available_quote_balance']) {
            $buys[] = [
                'side'     => 'BUY',
                'type'     => 'LIMIT',
                'price'    => $lv['price'],
                'qty'      => $lv['qty'],
                'clientId' => $lv['cid']
            ];
        }
    }

    // 5) SELL SHOULD-BE
    $sells = [];
    if ($filledQty > 0) {
        $tpn = max($cfg['tp_start_pct'] - $cfg['tp_step_pct'] * max(0, $nFilled - 1), $cfg['tp_min_pct']);
        $tp1 = roundUp($avgPrice * (1 + $tpn), $tick);
        $tp2 = roundUp($avgPrice * (1 + $tpn + $cfg['tp2_delta_pct']), $tick);

        $pos = $state['position_base_qty'];
        $q1  = roundDown($pos * $cfg['tp1_share'], $lot);
        $q2  = roundDown($pos * $cfg['tp2_share'], $lot);
        $q3  = roundDown($pos - $q1 - $q2, $lot);

        if ($q1 > 0) $sells[] = ['side'=>'SELL','type'=>'TAKE_PROFIT_LIMIT','price'=>$tp1,'qty'=>$q1,'clientId'=>"BOT:{$symbol}:{$basketId}:S:TP1"];
        if ($q2 > 0) $sells[] = ['side'=>'SELL','type'=>'TAKE_PROFIT_LIMIT','price'=>$tp2,'qty'=>$q2,'clientId'=>"BOT:{$symbol}:{$basketId}:S:TP2"];
        if ($q3 > 0 && !empty($cfg['trailing_callback_pct'])) {
            $sells[] = ['side'=>'SELL','type'=>'TRAILING','price'=>null,'qty'=>$q3,'clientId'=>"BOT:{$symbol}:{$basketId}:S:TRAIL"];
        }
    }

    $meta = [
        'basket_id'              => $basketId,
        'avg_price'              => $avgPrice,
        'filled_levels'          => $nFilled,
        'planned_levels_N'       => $N,
        'remaining_quote_budget' => null, // volitelně dopočítat přes přesné spendy
        'reanchor_suggested'     => false // vyhodnocuje se v orchestrace podle TTL/close_ratio
    ];

    return ['buys'=>$buys, 'sells'=>$sells, 'meta'=>$meta];
}
```

---

## 7) Výchozí konfigurace (doporučení do startu)

Zjednodušená **lineární** 6-úrovňová varianta do −30 %:

```php
$cfg = [
  'symbol' => 'SOLUSDC',
  'anchor_price_P0'      => 150.000,     // Příklad! Nastav podle aktuální kotvy
  'zone_bottom_pct'      => 0.30,
  'levels_pct'           => [0.05, 0.10, 0.15, 0.20, 0.25, 0.30],
  'alloc_weights'        => [0.08, 0.12, 0.15, 0.18, 0.22, 0.25], // součet 1.0
  'tp_start_pct'         => 0.012,
  'tp_step_pct'          => 0.0015,
  'tp_min_pct'           => 0.003,       // >= 2*fee + rezerva
  'tp2_delta_pct'        => 0.008,
  'tp1_share'            => 0.40,
  'tp2_share'            => 0.35,
  'trail_share'          => 0.25,
  'trailing_callback_pct'=> 0.018,
  'hard_stop_mode'       => 'none',      // 'hard' / 'extend_zone'
  'hard_stop_pct'        => 0.40,
  'extend_zone_bottom_pct'=> 0.50,
  'max_grid_capital_quote'=> 1000.0,     // ≈ 1000 € → pro jednoduchost 1 € ≈ 1 USDC
  'fee_rate'             => 0.001,       // 0.1 %
  'tick_size'            => 0.001,       // UPRAV dle exchange
  'lot_size'             => 0.01,        // UPRAV dle exchange
  'min_notional'         => 5.0,         // UPRAV dle exchange
  'place_mode'           => 'only_next_k',
  'k_next'               => 2,
  'reanchor_rules'       => ['close_ratio'=>0.7,'time_TTL_s'=>86400],
];
```

> **Pozn.:** `tick_size`, `lot_size`, `min_notional` **přizpůsob** aktuálním Binance filtrům pro `SOLUSDC`.

---

## 8) Konkrétní příklad výpočtu (kapitál 1000 €, P0=150 USDC)

Pro jednoduchost uvažuj **1 € ≈ 1 USDC**.  
Levely (lineární 5% kroky do −30 %), alokace: 8/12/15/18/22/25 %.

| Level | Pokles | Cena BUY (USDC) | Alokace (USDC) | Qty SOL (zaokrouhleno lot 0.01) | Nominál (USDC) |
|------:|-------:|-----------------:|---------------:|---------------------------------:|---------------:|
| 1 | −5 %  | 142.500 | 80  | 0.56 | 79.800 |
| 2 | −10 % | 135.000 | 120 | 0.88 | 118.800 |
| 3 | −15 % | 127.500 | 150 | 1.17 | 149.175 |
| 4 | −20 % | 120.000 | 180 | 1.49 | 178.800 |
| 5 | −25 % | 112.500 | 220 | 1.95 | 219.375 |
| 6 | −30 % | 105.000 | 250 | 2.38 | 249.900 |

- **Celkem qty** (pokud se vyplní vše): **8.43 SOL**  
- **Celkem notional**: **~995.85 USDC**

### Ukázka TP po vyplnění prvních 3 levelů
- Vyplněno: L1+L2+L3 → pozice ≈ **2.61 SOL**, **VWAP ≈ 133.2471 USDC**
- Parametry TP: `tp_start=1.2 %`, `tp_step=0.15 %`, `n=3`, `tp_min=0.3 %`  
  → `TP(n) = 0.9 %`
- **TP1** = `round_up(133.2471 * 1.009, 0.001)` = **134.447 USDC**  
- **TP2** = `round_up(133.2471 * (1.009+0.8 %), 0.001)` = **135.513 USDC**
- Kvóty (lot 0.01):
  - `q1 = 40 % * 2.61 = 1.04 SOL`
  - `q2 = 35 % * 2.61 = 0.91 SOL`
  - `q3 = zbytek ≈ 0.66 SOL` (trailing)

> Po každém dalším fillu přepočítej **VWAP** a **TP** (TP se **snižuje** až k `tp_min_pct`).

---

## 9) Orchestrace (mimo funkci)

1. Každou vteřinu načti stav (`open_orders`, `balances`, `fills`, `last_price`).
2. Zavolej `computeDesiredOrders($cfg, $state, $market)`.
3. `reconcile` dle `clientId`:
   - `to_cancel = OPEN − SHOULD_BE`
   - `to_create = SHOULD_BE − OPEN`
   - `to_replace = průnik s rozdílnou price/qty`
4. Prováděj v pořadí: **cancel → create/replace** (rate-limit safe).
5. Pokud `meta.reanchor_suggested` a `position==0` → založ **nový košík** (nový `basket_id`, nové `P0`).

---

## 10) Poznámky k bezpečnosti & hranám

- Ověřuj **minNotional**, **tick/lot** při každém výpočtu.
- Nikdy neumisťuj BUY pod **hard stop** (pokud aktivní).
- V `"only_next_k"` režimu držíš otevřené jen nejbližší **K** levelů → menší zahlcení orderbooku.
- U TP vždy hlídej, aby `TP >= (2*fee + rezerva)`.

---

## 11) Hooky pro Binance

- `clientOrderId` mapuj 1:1 dle schématu výše (Binance to podporuje).
- LIMIT BUY/SELL: `timeInForce = GTC`.
- Trailing: pokud není přímo dostupný typ, řeš **simulací** v orchestrace (posuv `STOP/TP` podle high watermark).

---

*Konec dokumentu.*

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Binance Grid Trading Bot - A Symfony 7 application that implements a grid/basket trading strategy for SOL/USDC spot trading on Binance. The bot uses a deterministic approach with idempotent order management via clientOrderId scheme.

## Critical Development Guidelines

### 🔴 ALWAYS Run Everything in Docker
**NEVER run PHP, Composer, or database commands directly on the host machine.** All commands must be executed inside Docker containers using `docker-compose exec`.

Examples:
```bash
# ✅ CORRECT
docker-compose exec php composer install
docker-compose exec php php bin/console cache:clear
docker-compose exec php vendor/bin/phpstan analyse

# ❌ WRONG - Never do this
composer install
php bin/console cache:clear
vendor/bin/phpstan analyse
```

### 📝 Document All Progress

**TODO.md** - Tracks what needs to be done
- Organized by implementation phases (0-11)
- Check off items as they're completed
- Add new items as requirements are discovered

**CHANGELOG.md** - Documents what was done and why
- Record all implementation steps with dates
- Explain design decisions and rationale
- Document obstacles encountered and how they were resolved
- Describe alternatives considered and why they were rejected
- Always update after completing significant work

### 🔍 Always Run PHPStan After PHP Code Changes

After modifying any PHP file, run static analysis:
```bash
docker-compose exec php vendor/bin/phpstan analyse src/ --level=8
```

PHPStan configuration will be in `phpstan.neon` with level 8 (strictest).

### 💪 Write Strict, Strongly-Typed PHP 8.4 Code

Every PHP file must follow these rules:

1. **Start with strict types declaration:**
```php
<?php

declare(strict_types=1);

namespace App\Service\Trading;
```

2. **Always use type declarations:**
```php
// ✅ CORRECT
public function calculatePnL(int $basketId, float $currentPrice): array
{
    // ...
}

// ❌ WRONG - missing types
public function calculatePnL($basketId, $currentPrice)
{
    // ...
}
```

3. **Use typed properties:**
```php
// ✅ CORRECT
private readonly BinanceApiClient $apiClient;
private float $anchorPrice;
private array $levels = [];

// ❌ WRONG - no types
private $apiClient;
private $anchorPrice;
```

4. **Use constructor property promotion (PHP 8.4):**
```php
// ✅ CORRECT
public function __construct(
    private readonly BinanceApiClient $apiClient,
    private readonly OrderRepository $orderRepository,
) {
}

// ❌ WRONG - old style
private $apiClient;
private $orderRepository;

public function __construct(BinanceApiClient $apiClient, OrderRepository $orderRepository)
{
    $this->apiClient = $apiClient;
    $this->orderRepository = $orderRepository;
}
```

5. **Return early, avoid nesting:**
```php
// ✅ CORRECT
public function processOrder(Order $order): void
{
    if (!$order->isValid()) {
        return;
    }

    if ($order->isFilled()) {
        return;
    }

    $this->executeOrder($order);
}

// ❌ WRONG - deep nesting
public function processOrder(Order $order): void
{
    if ($order->isValid()) {
        if (!$order->isFilled()) {
            $this->executeOrder($order);
        }
    }
}
```

## Technology Stack

- **Framework**: Symfony 7.x
- **Language**: PHP 8.4
- **Database**: PostgreSQL 17
- **Containerization**: Docker + Docker Compose
- **Frontend**: Twig templates with Tailwind CSS
- **API Client**: Symfony HTTP Client for Binance API
- **Process Management**: Supervisor (for long-running bot process)

## Key Commands

### Docker Operations
```bash
# Start all services
docker-compose up -d

# View bot logs
docker-compose logs -f worker

# Access PHP container
docker-compose exec php bash

# Access database
docker-compose exec postgres psql -U trader -d binance_trader

# Restart bot worker
docker-compose restart worker
```

### Database
```bash
# Run migrations
docker-compose exec php php bin/console doctrine:migrations:migrate

# Create new migration
docker-compose exec php php bin/console doctrine:migrations:generate

# Database backup
docker-compose exec postgres pg_dump -U trader binance_trader > backup_$(date +%Y%m%d).sql
```

### Bot Operations
```bash
# Start bot manually (already runs via worker service)
docker-compose exec php php bin/console app:trading-bot

# Access dashboard
open http://localhost:8080
```

### Development
```bash
# Install dependencies
docker-compose exec php composer install

# Run PHPStan static analysis (ALWAYS after code changes)
docker-compose exec php vendor/bin/phpstan analyse src/ --level=8

# Clear cache
docker-compose exec php php bin/console cache:clear

# Validate Symfony configuration
docker-compose exec php php bin/console lint:container

# Run tests (when implemented)
docker-compose exec php php bin/phpunit
```

### Development Workflow
1. Check TODO.md for current phase and next task
2. Implement the feature/fix
3. Run PHPStan: `docker-compose exec php vendor/bin/phpstan analyse src/ --level=8`
4. Fix any type errors reported by PHPStan
5. Test the changes (manual testing or automated tests)
6. Update TODO.md (check off completed items)
7. Update CHANGELOG.md (document what was done, decisions made, obstacles encountered)

## Architecture Patterns

### Core Design Principle: Deterministic "Should-Be" State

The bot operates on a reconciliation loop model:
1. **Strategy Layer** computes desired state (`computeDesiredOrders()`)
2. **Reconciler** compares desired vs actual state from Binance
3. **Executor** performs cancel/create actions to align states

This ensures idempotency and crash-safety.

### ClientOrderId Scheme (Critical for Idempotency)

All orders use deterministic clientOrderId format:
```
BOT:{symbol}:{basket_id}:{side}:{identifier}

Examples:
- BOT:SOLUSDC:basket_42:B:1      (BUY level 1)
- BOT:SOLUSDC:basket_42:B:2      (BUY level 2)
- BOT:SOLUSDC:basket_42:S:TP1    (SELL take-profit 1)
- BOT:SOLUSDC:basket_42:S:TP2    (SELL take-profit 2)
- BOT:SOLUSDC:basket_42:S:TRAIL  (SELL trailing stop)
```

**Never generate random order IDs** - always follow this scheme to maintain reconciliation integrity.

### Service Architecture

**BinanceApiClient** (`src/Service/Binance/BinanceApiClient.php`)
- Low-level Binance API wrapper
- Handles rate limiting (1200 req/min)
- Automatic retry with exponential backoff
- Signature generation for authenticated requests

**StrategyInterface** (`src/Service/Strategy/StrategyInterface.php`)
- Contract: `computeDesiredOrders(array $config, array $state, array $market): array`
- Returns desired order state (no side effects)
- GridStrategy implements the grid trading logic

**GridStrategy** (`src/Service/Strategy/GridStrategy.php`)
- **Iterative** algorithm (no recursion)
- Builds planned levels based on anchor price (P0)
- Calculates VWAP for filled positions
- Dynamically adjusts take-profit based on filled levels: `TP(n) = max(tp_start - tp_step*(n-1), tp_min)`
- Zone protection (hard stop, zone extension)
- Suggests reanchor when position closes or TTL expires

**OrderReconciler** (`src/Service/Trading/OrderReconciler.php`)
- Compares SHOULD_BE (from Strategy) vs ACTUAL (from Binance)
- Generates action plan: `to_cancel`, `to_create`, `to_replace`
- Matching by clientOrderId ensures correct reconciliation

**Orchestrator** (`src/Service/Orchestrator.php`)
- Main coordination loop (runs every N seconds)
- Syncs state from Binance (orders, fills, balances, price)
- Calls Strategy → Reconciler → Executor pipeline
- Handles errors gracefully (log & retry)

**PositionCalculator** (`src/Service/Trading/PositionCalculator.php`)
- Calculates realized P&L (from filled round-trips)
- Calculates unrealized P&L (mark-to-market)
- Computes VWAP for average entry price

**EmergencyCloseService** (`src/Service/Trading/EmergencyCloseService.php`)
- Emergency shutdown: cancel all orders + market exit
- Uses safety margin (default 3% below market price)
- Atomic transaction for consistency

### Database Schema

**baskets** - Trading sessions (each basket = one grid instance)
- Stores anchor_price (P0), config (JSONB), status, timestamps

**orders** - All orders (historical + current)
- Key indexes: basket_id, client_order_id, status
- Tracks Binance order lifecycle

**fills** - Trade execution history
- Records each fill event with price, qty, commission
- Used for VWAP and P&L calculations

**account_snapshots** - Periodic balance tracking

**bot_config** - Key-value store for strategy parameters

### Grid Strategy Logic

The grid strategy operates in levels below an anchor price (P0):

1. **Level Planning**: Define N levels at specific percentage drops (e.g., -5%, -10%, -15%)
2. **Capital Allocation**: Each level gets a weight (e.g., [0.08, 0.12, 0.15, 0.18, 0.22, 0.25])
3. **BUY Orders**: Place LIMIT orders at unfilled levels (respects `place_mode`)
   - `only_next_k`: Only K nearest levels below current price
   - `all_unfilled`: All unfilled levels
4. **SELL Orders**: After fills, calculate VWAP and place 3-tier exit:
   - TP1: First take-profit (40% of position)
   - TP2: Second take-profit (35% of position)
   - TRAIL: Trailing stop (25% of position)
5. **Dynamic TP**: As more levels fill, take-profit percentage decreases: `TP(n) = max(1.2% - 0.15%*(n-1), 0.3%)`

**Key Helper Functions** (always use these for consistency):
```php
roundDown($value, $tick_size)  // For BUY prices
roundUp($value, $tick_size)    // For SELL prices
calculateVWAP($fills)          // Weighted average entry
```

### Configuration Management

Strategy config is stored in:
- `bot_config` table (persistent, can be updated via dashboard)
- `baskets.config` JSONB field (snapshot per basket)

Key parameters:
- `anchor_price_P0`: Grid anchor
- `levels_pct`: Array of drop percentages
- `alloc_weights`: Capital distribution (must sum to 1.0)
- `tp_start_pct`, `tp_step_pct`, `tp_min_pct`: TP dynamics
- `hard_stop_mode`: `"none" | "hard" | "extend_zone"`
- `place_mode`: `"all_unfilled" | "only_next_k"`

## Safety & Best Practices

### ALWAYS Use Testnet for Development
- Set `BINANCE_USE_TESTNET=true` in `.env.local`
- Never commit real API keys
- Use `https://testnet.binance.vision` for testing

### Order Validation
Before placing any order, validate:
- Price rounded to `tick_size`
- Quantity rounded to `lot_size`
- `price * quantity >= min_notional`
- Sufficient balance available

### Rate Limiting
- Binance limit: 1200 requests/minute
- BinanceApiClient handles rate limiting automatically
- Avoid manual `sleep()` calls - let the client manage backoff

### Error Handling
- Binance API errors → log, skip cycle, retry next iteration
- Database errors → rollback transaction
- Strategy errors → pause basket, alert
- Never let exceptions crash the bot worker

### Idempotency
- Reconciliation loop is safe to run multiple times
- Deterministic clientOrderId prevents duplicate orders
- Database constraints on unique fields

## Common Development Tasks

### Adding a New Strategy
1. Implement `StrategyInterface` in `src/Service/Strategy/`
2. Return `['buys' => [...], 'sells' => [...], 'meta' => [...]]`
3. Use the clientOrderId scheme for order identification
4. Update `services.yaml` to inject new strategy
5. Update dashboard to show strategy-specific metrics

### Modifying Grid Levels
1. Update config in `bot_config` table or basket JSONB
2. Strategy will automatically reconcile on next cycle
3. No need to cancel existing orders manually

### Adding Dashboard Features
1. Create controller in `src/Controller/`
2. Add route in `config/routes.yaml`
3. Create Twig template in `templates/`
4. Use PositionCalculator for P&L metrics
5. Respect CSRF tokens for POST actions

### Testing Changes
1. Use testnet environment
2. Monitor worker logs: `docker-compose logs -f worker`
3. Check order reconciliation in dashboard
4. Verify fills are recorded correctly in database

## File Locations

- **Project Management**: `TODO.md`, `CHANGELOG.md`, `CLAUDE.md`
- **Specifications**: `.spec/architecture.md`, `.spec/strategy.md`
- **Entities**: `src/Entity/` (Basket, Order, Fill, AccountSnapshot, BotConfig)
- **Repositories**: `src/Repository/` (Doctrine repositories)
- **Services**: `src/Service/` (organized by domain: Binance/, Strategy/, Trading/)
- **Commands**: `src/Command/TradingBotCommand.php`
- **Controllers**: `src/Controller/` (DashboardController, TradingController)
- **Templates**: `templates/` (Twig templates)
- **Config**: `config/packages/` (Symfony config)
- **Migrations**: `migrations/` (Doctrine migrations)
- **Docker**: `docker/` (Dockerfiles and configs)
- **PHPStan**: `phpstan.neon` (static analysis configuration)

## Important Implementation Notes

### Reconciliation Algorithm
```
SHOULD_BE = Strategy output
ACTUAL = Open orders from Binance

to_cancel = ACTUAL - SHOULD_BE (by clientOrderId)
to_create = SHOULD_BE - ACTUAL (by clientOrderId)
to_replace = SHOULD_BE ∩ ACTUAL where (price or qty differs)

Execute: cancel all → create new
```

### VWAP Calculation
```php
filled_qty_total = sum(fills.qty where side=BUY)
filled_quote_total = sum(fills.price * fills.qty where side=BUY)
avg_price = filled_quote_total / filled_qty_total
```

### Dynamic Take-Profit Formula
```php
n = count(filled_levels)
TP(n) = max(tp_start_pct - tp_step_pct * (n - 1), tp_min_pct)

// Example: tp_start=1.2%, tp_step=0.15%, tp_min=0.3%
// n=1: TP=1.2%
// n=2: TP=1.05%
// n=3: TP=0.9%
// n=4: TP=0.75%
// n=5+: TP=0.3% (floor)
```

### Trailing Stop Implementation
If Binance doesn't support native trailing, simulate in orchestrator:
- Track high water mark for position
- When price drops by `trailing_callback_pct`, place LIMIT sell
- Update trailing stop on each price update

## Troubleshooting

### Bot Not Placing Orders
1. Check worker logs: `docker-compose logs -f worker`
2. Verify Binance API credentials in `.env.local`
3. Check account balance on Binance
4. Verify `place_mode` and `k_next` config
5. Ensure no active hard stop is blocking orders

### Orders Not Reconciling
1. Verify clientOrderId format matches scheme
2. Check for duplicate clientOrderIds in database
3. Review reconciler logs for mismatches
4. Validate open_orders query returns Binance data

### P&L Incorrect
1. Verify all fills are synced from Binance: `syncFillsFromBinance()`
2. Check commission is recorded correctly
3. Validate VWAP calculation includes all BUY fills
4. Ensure realized P&L only counts closed positions

### Performance Issues
1. Add indexes on frequently queried columns
2. Limit fills history query to recent N days
3. Cache exchange info (tick_size, lot_size) for 24h
4. Consider WebSocket for real-time price updates

## Production Checklist

- [ ] Set `BINANCE_USE_TESTNET=false` in production `.env.local`
- [ ] Use strong `DB_PASSWORD` and `APP_SECRET`
- [ ] Never commit `.env.local` with real credentials
- [ ] Set up database backups (pg_dump cron job)
- [ ] Monitor worker process (supervisor ensures restart)
- [ ] Set up alerts for basket status changes
- [ ] Test emergency close procedure
- [ ] Document reanchor process for operators
- [ ] Review rate limiting for production load
- [ ] Enable HTTPS for dashboard (nginx SSL config)

## References

- **Project Management**: `TODO.md` (implementation roadmap), `CHANGELOG.md` (progress history)
- **Specification**: `.spec/strategy.md` (grid strategy details in Czech)
- **Architecture**: `.spec/architecture.md` (full system design, 980 lines)
- **Binance API**: https://binance-docs.github.io/apidocs/spot/en/
- **Symfony Docs**: https://symfony.com/doc/current/index.html
- **PHP 8.4 Docs**: https://www.php.net/releases/8.4/en.php
- **PHPStan Docs**: https://phpstan.org/user-guide/getting-started

## Quick Start for New Claude Code Instances

1. Read `TODO.md` to understand current implementation phase
2. Read `CHANGELOG.md` to see what's been done and why
3. Check `.spec/architecture.md` and `.spec/strategy.md` for detailed specifications
4. Follow "Critical Development Guidelines" above strictly
5. Always run commands in Docker containers
6. Always run PHPStan after code changes
7. Always update TODO.md and CHANGELOG.md after completing work

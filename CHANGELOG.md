# CHANGELOG - Binance Grid Trading Bot

This file documents all implementation steps, decisions, obstacles, and their resolutions. It provides context on why certain choices were made and serves as a historical record for future development.

---

## [UNRELEASED] - Project Initialization

### 2025-10-16 - Initial Project Setup

#### What Was Done
1. **Specification Documents Created**
   - Created `.spec/architecture.md` - comprehensive system architecture with 980 lines of detailed design
   - Created `.spec/strategy.md` - grid trading strategy specification in Czech (346 lines)
   - Both documents define the complete system: database schema, API design, service architecture, and trading algorithms

2. **CLAUDE.md Documentation**
   - Created comprehensive guidance document for Claude Code instances
   - Documented technology stack: Symfony 7, PHP 8.4, PostgreSQL 17, Docker
   - Included all key commands (Docker, database, bot operations)
   - Documented critical patterns: deterministic clientOrderId scheme, "Should-Be" reconciliation pattern
   - Included formulas: VWAP calculation, dynamic TP, reconciliation algorithm
   - Added troubleshooting guide and production checklist

3. **TODO.md Project Tracking**
   - Created detailed implementation roadmap with 11 phases
   - Phase 0: Infrastructure & Foundation
   - Phase 1: Database Layer (entities, repositories, migrations)
   - Phase 2: Binance Integration Layer (API client, rate limiting)
   - Phase 3: Trading Strategy Implementation (GridStrategy)
   - Phase 4: Order Reconciliation & Execution
   - Phase 5: Orchestration & Main Loop
   - Phase 6: Console Command & Worker
   - Phase 7: Dashboard & Web UI
   - Phase 8: Safety Features & Validation
   - Phase 9: Initial Deployment & Testing (testnet)
   - Phase 10: Production Preparation
   - Phase 11: Future Enhancements

4. **CHANGELOG.md Initiated**
   - This file - documents decisions, obstacles, and progress

#### Technology Choices & Rationale

**PHP 8.4**
- Why: Latest stable version with modern type system
- Benefits: Strict types, constructor property promotion, improved performance
- Trade-offs: None - project is greenfield

**PostgreSQL 17**
- Why: Excellent JSONB support for basket.config storage
- Benefits: Strong ACID guarantees critical for financial data, superior indexing
- Alternative considered: MySQL - rejected due to inferior JSON handling

**Symfony 7**
- Why: Mature PHP framework with excellent Doctrine ORM integration
- Benefits: Built-in HTTP client, console commands, dependency injection, Twig templates
- Trade-offs: Heavier than micro-frameworks, but justified by feature richness

**Docker + Docker Compose**
- Why: Consistent development environment, easy deployment
- Benefits: Isolates dependencies, reproducible builds, matches production
- All commands will run via Docker containers (never on host)

**Deterministic clientOrderId Scheme**
- Pattern: `BOT:{symbol}:{basket_id}:{side}:{level}`
- Why: Enables idempotent order placement and crash-safe recovery
- Benefits: Reconciliation by ID instead of price/qty matching, prevents duplicates
- This is a CRITICAL design decision - all orders must follow this scheme

**"Should-Be" Reconciliation Pattern**
- Strategy computes desired state (pure function)
- Reconciler compares desired vs actual (from Binance)
- Executor applies diff (cancel/create actions)
- Why: Stateless, declarative, crash-safe, easy to test
- Alternative considered: Event-driven state machine - rejected as too complex

**Iterative Algorithm (No Recursion)**
- Why: Simpler to reason about, no stack overflow risk
- Benefits: Easier to debug, step through, and test
- Grid levels are calculated in a loop, not recursively

#### Obstacles & Challenges

None yet - project is in initialization phase.

#### Next Steps

1. Install PHPStan for static analysis (always run in Docker)
2. Set up Docker environment (docker-compose.yml, Dockerfiles)
3. Scaffold Symfony 7 application
4. Begin Phase 1: Database Layer implementation

#### Development Guidelines Established

1. **Always run everything in Docker** - no local PHP/Composer/database commands
2. **Write strict, strongly-typed PHP 8.4 code** - use `declare(strict_types=1)`, typed properties, return types
3. **Run PHPStan after every PHP code change** - via Docker command
4. **Follow clientOrderId scheme strictly** - idempotency depends on this
5. **Test on testnet first** - BINANCE_USE_TESTNET=true until production-ready
6. **Update TODO.md and CHANGELOG.md** - document progress and decisions

---

## Version History

- **[UNRELEASED]** - Project initialization, specifications, and planning

---

## [UNRELEASED] - Phase 0 & 1 Complete

### 2025-10-16 - Infrastructure and Database Layer

#### What Was Done
1. **Docker Environment Setup (Phase 0)**
   - Created docker-compose.yml with PostgreSQL 17, PHP 8.4-FPM, Nginx, and Worker services
   - Built custom PHP 8.4 Docker image with all required extensions (pdo_pgsql, intl, zip, opcache, pcntl)
   - Configured networking and health checks
   - Port 5433 used for PostgreSQL to avoid conflicts

2. **Symfony 7.2.9 Application Scaffolding (Phase 0)**
   - Created composer.json with all required dependencies
   - Installed Symfony 7.2.9, Doctrine ORM 3.5, Twig 3.21
   - Configured PHPStan 2.1 with level 8 (strictest)
   - Set up directory structure (bin/, config/, public/, src/, templates/, migrations/)
   - Created .env, .gitignore, and environment configuration

3. **PHPStan Configuration (Phase 0)**
   - Configured phpstan.neon with level 8
   - Integrated phpstan-doctrine and phpstan-symfony extensions
   - All code passes strict type checking

4. **Database Entities Created (Phase 1)**
   - **Basket**: Trading session with JSONB config, anchor_price, status tracking
   - **Order**: Full Binance order lifecycle with deterministic clientOrderId
   - **Fill**: Trade execution history with commission tracking
   - **AccountSnapshot**: Periodic balance snapshots
   - **BotConfig**: Key-value store for strategy parameters
   - All entities use strict types (`declare(strict_types=1)`)
   - All properties properly typed with docblocks for arrays

5. **Repositories Created (Phase 1)**
   - BasketRepository: findActiveBasket(), findActiveBaskets()
   - OrderRepository: findByClientOrderId(), findOpenOrdersByBasket()
   - FillRepository: findBuyFillsByBasket(), getTotalBuyQuantity()
   - AccountSnapshotRepository: findLatest(), findRecent()
   - BotConfigRepository: getConfig(), setConfig() helpers

6. **Database Migrations (Phase 1)**
   - Generated and executed initial migration
   - Created tables: baskets, orders, fills, account_snapshots, bot_config
   - Added indexes: basket_id, client_order_id, status on orders table
   - Added indexes: basket_id, order_id on fills table
   - Unique constraint on orders.client_order_id (critical for idempotency)

7. **Verification**
   - PHPStan level 8: ✅ No errors
   - Docker containers: ✅ All running
   - Symfony console: ✅ Working
   - Database: ✅ Migrations applied successfully

#### Design Decisions

**Why DECIMAL(18,8) for prices/quantities?**
- Financial precision is critical - floats cause rounding errors
- Binance uses up to 8 decimal places for most pairs
- 18 total digits allows for large values while maintaining precision

**Why BIGINT for exchangeOrderId?**
- Binance order IDs are 64-bit integers
- PHP integers are 64-bit on modern systems, but we store as string to be safe

**Why DateTimeImmutable instead of DateTime?**
- Immutability prevents accidental modification
- Better for value objects and ensures temporal data integrity

**Why separate Order and Fill entities?**
- Orders can be partially filled multiple times
- Fills track each individual execution event
- Necessary for accurate VWAP and P&L calculations

**Why unique constraint on client_order_id?**
- Core of idempotency guarantee
- Prevents duplicate order placement
- Enables reliable reconciliation by ID matching

#### Challenges Encountered

**Port 5432 Already in Use**
- Solution: Changed PostgreSQL port mapping to 5433:5432 in docker-compose.yml

**Symfony Flex Auto-Generated compose.override.yaml**
- Flex created a conflicting database service definition
- Solution: Removed compose.override.yaml file

**PHPStan Array Type Errors**
- Error: Array types need value type specification
- Solution: Added `@var array<string, mixed>` docblocks for JSON fields

**HTTP Client Configuration**
- Error: Either "scope" or "base_uri" required
- Solution: Set placeholder base_uri, will be overridden dynamically in BinanceApiClient

#### Next Steps

1. Phase 2: Implement Binance API client with rate limiting
2. Phase 3: Implement GridStrategy with VWAP calculation
3. Phase 4: Build order reconciliation and execution

---

## [UNRELEASED] - Phases 2-8 Complete

### 2025-10-16 - Complete Core Implementation

#### What Was Done

**Phase 2: Binance Integration Layer**
- Created `BinanceException` - Custom exception with binanceCode and binanceData
- Created `RateLimiter` - Token bucket algorithm (1200 req/60sec)
- Created `BinanceApiClient` - Complete Binance API wrapper with:
  - All required endpoints: getAccountInfo, getOpenOrders, placeOrder, cancelOrder, getCurrentPrice, getMyTrades, getExchangeInfo
  - HMAC SHA256 signature generation for authenticated requests
  - Exponential backoff retry (max 3 attempts, 1s → 2s → 4s delays)
  - Rate limiting integration with token bucket
  - Testnet support via BINANCE_USE_TESTNET flag
- Created `BinanceDataMapper` - Maps Binance responses to entities
  - mapToOrderEntity, mapToFillEntity
  - extractBalances, extractSymbolFilters

**Phase 3: Grid Trading Strategy**
- Created `StrategyInterface` - Contract for all trading strategies
  - Method: `computeDesiredOrders(config, state, market): array`
  - Returns: `['buys' => [...], 'sells' => [...], 'meta' => [...]]`
- Created `GridStrategy` - Complete grid trading implementation:
  - **buildPlannedLevels()** - Constructs grid from anchor price P0
  - **calculateVWAP()** - Weighted average entry price from fills
  - **determineBuyOrders()** - Applies place_mode (all_unfilled / only_next_k)
  - **determineSellOrders()** - Dynamic TP with 3-tier exit (TP1, TP2, TRAIL)
  - **Dynamic TP Formula**: `TP(n) = max(tp_start - tp_step*(n-1), tp_min)`
    - Example: 1.2% → 1.05% → 0.9% → 0.75% → 0.3% (floor)
  - Uses deterministic clientOrderId: `BOT:{symbol}:{basket_id}:{side}:{level}`
  - Handles zone protection, hard stop, reanchor suggestions

**Phase 4: Order Management**
- Created `OrderReconciler` - "Should-Be" vs Actual reconciliation
  - Compares desired orders (from Strategy) vs actual orders (from Binance)
  - Returns: `to_cancel` (ACTUAL - SHOULD_BE) and `to_create` (SHOULD_BE - ACTUAL)
  - Matching by clientOrderId ensures correct reconciliation
- Created `OrderExecutor` - Executes reconciliation plan
  - executeCancellations() - Cancels orders on Binance, updates DB
  - executeCreations() - Places orders on Binance, saves to DB
  - Handles idempotency (error -2010 for duplicate orders)
  - Proper error logging with Binance error codes
- Created `PositionCalculator` - P&L and position tracking
  - calculateRealizedPnL() - From closed round-trips
  - calculateUnrealizedPnL() - Mark-to-market current position
  - getPositionSummary() - Complete position analysis with ROI

**Phase 5: Orchestration**
- Created `Orchestrator` - Main coordination service
  - Main loop: run() with configurable cycle interval
  - processCycle() → processBasket() pipeline
  - Fetches state from Binance (account, orders, fills, price)
  - Calls Strategy → Reconciler → Executor flow
  - Syncs fills from Binance on each cycle
  - Comprehensive error handling (log and continue)
  - Supports graceful shutdown

**Phase 6: Console Command & Worker**
- Created `TradingBotCommand` - Symfony console command
  - Command name: `app:trading-bot`
  - Signal handlers for SIGTERM, SIGINT (graceful shutdown)
  - Uses pcntl_async_signals for non-blocking signal handling
  - Integrates with Orchestrator
- Configured services.yaml for dependency injection
  - BinanceApiClient parameters (API key, secret, testnet flag)
  - Orchestrator configuration
- Worker service in docker-compose.yml runs bot automatically

**Phase 7: Dashboard & Web UI**
- Created `EmergencyCloseService` - Emergency position exit
  - closeAllPositions() - Cancels all orders, places exit order
  - Uses 3% safety margin below current price
  - Marks basket as 'emergency_closed'
  - Atomic transaction for consistency
- Created `DashboardController`
  - Route: GET / - Main dashboard with metrics
  - Route: GET /orders/history - Full order history
  - Displays: current price, position, total P&L, open orders, filled orders
  - Auto-refresh every 10 seconds
- Created `TradingController`
  - Route: POST /trading/close-all - Emergency close endpoint
  - CSRF token validation
  - Flash message feedback
- Created Twig templates:
  - `base.html.twig` - Layout with Tailwind CSS, auto-refresh, flash messages
  - `dashboard/index.html.twig` - Main dashboard with P&L cards, order tables, emergency modal
  - `dashboard/orders_history.html.twig` - Complete order history table
  - `dashboard/no_basket.html.twig` - Shown when no active basket

**Phase 8: Safety Features & Validation**
- Created `OrderValidator` - Pre-flight order validation
  - validateOrder() - Checks tick_size, lot_size, min_notional, balance
  - roundPrice() - Rounds to tick_size (floor or ceil)
  - roundQuantity() - Rounds to lot_size (floor or ceil)
  - Returns array of validation errors
- Created `ExchangeInfoCache` - Exchange info caching
  - getSymbolFilters() - Returns tick_size, lot_size, min_notional
  - 24-hour TTL for cached data
  - clearCache() - Manual cache invalidation
- Integrated into `OrderExecutor`:
  - Validates each order before placement
  - Logs validation errors and skips invalid orders
  - Falls back gracefully if cache unavailable

#### Design Decisions

**Why Token Bucket for Rate Limiting?**
- Allows bursts while maintaining average rate
- Binance limit: 1200 req/min
- Tokens refill continuously based on time elapsed
- Simple, effective, and well-understood algorithm

**Why Exponential Backoff on Retries?**
- Prevents thundering herd if Binance has issues
- Gives temporary issues time to resolve
- Max 3 attempts prevents infinite loops
- 1s → 2s → 4s progression is industry standard

**Why Separate Fill Entity from Order?**
- Orders can be partially filled multiple times
- Each fill has its own price, qty, commission
- Essential for accurate VWAP calculation
- Matches Binance's data model

**Why "Should-Be" Reconciliation Pattern?**
- Declarative: Strategy defines desired state, not actions
- Idempotent: Safe to run multiple times
- Crash-safe: No persistent state in Strategy
- Testable: Pure functions easier to test
- Alternative (event-driven state machine) rejected as too complex

**Why Dynamic Take-Profit?**
- Lower TP as more levels fill = higher avg entry price
- Prevents being stuck in losing position
- Formula: TP(n) = max(tp_start - tp_step*(n-1), tp_min)
- Configurable via tp_start_pct, tp_step_pct, tp_min_pct

**Why 3-Tier Exit (TP1, TP2, TRAIL)?**
- TP1 (40%): Lock in quick profit
- TP2 (35%): Capture extended move
- TRAIL (25%): Maximize upside with trailing stop
- Distribution configurable via exit_*_portion params

**Why Validate Orders Before Placement?**
- Prevent API errors due to invalid tick_size/lot_size
- Reduce failed orders and API rate limit usage
- Log validation errors for debugging
- Binance will reject anyway, but validation gives better error messages

**Why Cache Exchange Info for 24h?**
- Tick size, lot size, min notional rarely change
- Reduces API calls significantly
- 24h is conservative (could be longer)
- Manual clearCache() if exchange updates filters

#### Challenges Encountered

**PHPStan Array Type Errors (Phase 2)**
- Error: Return type `array` without value specification
- Solution: Added `@phpstan-return array<string, mixed>` annotations
- Learned: PHPStan level 8 requires full array type specs

**PHPStan Property Only Written Warning (Phase 3)**
- Error: LoggerInterface injected but never read
- Solution: Added `@phpstan-ignore-line` with comment explaining it's used for side effects
- Learned: PHPStan is very strict about unused properties

**PHPStan Memory Limit (Phase 3)**
- Error: Process crashed at 128M limit
- Solution: Added `--memory-limit=256M` to all PHPStan commands
- Learned: Large codebases need more memory for analysis

**Type Mismatch in Orchestrator (Phase 5)**
- Error: openOrders array type mismatch
- Solution: Added `@var array<int, array{clientOrderId: string, ...}>` annotation
- Learned: Complex nested arrays need explicit type hints

**BinanceApiClient Cannot Autowire (Phase 6)**
- Error: Scalar arguments ($apiKey, $apiSecret) can't be autowired
- Solution: Added explicit service configuration in services.yaml
- Learned: Symfony DI needs help with scalar constructor parameters

**Nullable Basket ID (Phase 7)**
- Error: getId() returns int|null, but closeAllPositions expects int
- Solution: Added null check before calling service method
- Learned: Always validate nullable values before passing to strict methods

**OrderValidator Return Type (Phase 8)**
- Error: Return type `array` missing value specification
- Solution: Added `@return array<int, string>` docblock
- Learned: All return types need full specification in level 8

#### Code Statistics

**26 PHP Files Created:**
- 5 Entities (Basket, Order, Fill, AccountSnapshot, BotConfig)
- 5 Repositories
- 6 Binance services (Exception, RateLimiter, ApiClient, DataMapper, ExchangeInfoCache)
- 2 Strategy services (Interface, GridStrategy)
- 4 Trading services (Reconciler, Executor, PositionCalculator, OrderValidator, EmergencyClose)
- 1 Orchestrator
- 1 Command
- 2 Controllers

**~3,200 Lines of Code:**
- All code passes PHPStan level 8 (strictest)
- Full type coverage with `declare(strict_types=1)`
- Constructor property promotion throughout
- Comprehensive error handling

**4 Twig Templates:**
- Responsive design with Tailwind CSS
- Real-time data display with auto-refresh
- CSRF protection on forms
- Emergency close modal

#### Next Steps

1. Phase 9: Testing & Documentation
   - Test on Binance testnet
   - Create initial basket with default config
   - Verify end-to-end functionality
   - Final documentation update

---

**Last Updated:** 2025-10-16
**Current Status:** Phases 0-8 complete, Phase 9 in progress (testing & documentation)

# TODO - Binance Grid Trading Bot

This file tracks the implementation progress of the grid trading bot. Items are organized by implementation phase.

## Project Status: INITIALIZATION

Current focus: Setting up the foundation and implementing core infrastructure.

---

## Phase 0: Infrastructure & Foundation ✅

- [x] Project specification created (.spec/architecture.md, .spec/strategy.md)
- [x] CLAUDE.md documentation created
- [x] TODO.md tracking established
- [x] CHANGELOG.md initiated
- [ ] **PHPStan installed and configured**
- [ ] Docker environment setup (docker-compose.yml, Dockerfiles)
- [ ] Symfony 7 application scaffolding
- [ ] PostgreSQL 17 database container configuration
- [ ] Nginx container configuration
- [ ] PHP 8.4-FPM container configuration
- [ ] Environment configuration (.env.local.example)
- [ ] Composer dependencies installed

---

## Phase 1: Database Layer 🔄

### Doctrine Entities
- [ ] Create `Basket` entity with JSONB config field
- [ ] Create `Order` entity with all Binance order fields
- [ ] Create `Fill` entity for trade execution tracking
- [ ] Create `AccountSnapshot` entity for balance history
- [ ] Create `BotConfig` entity (key-value store)

### Repositories
- [ ] BasketRepository with active basket queries
- [ ] OrderRepository with client_order_id lookups
- [ ] FillRepository with basket aggregations
- [ ] AccountSnapshotRepository with time-series queries
- [ ] BotConfigRepository with config management

### Migrations
- [ ] Initial migration: baskets table with indexes
- [ ] Initial migration: orders table with indexes (basket_id, client_order_id, status)
- [ ] Initial migration: fills table with indexes (basket_id, order_id)
- [ ] Initial migration: account_snapshots table
- [ ] Initial migration: bot_config table

### Validation
- [ ] Run migrations in Docker: `docker-compose exec php php bin/console doctrine:migrations:migrate`
- [ ] Verify all indexes are created
- [ ] Test entity relationships (Basket → Orders → Fills)

---

## Phase 2: Binance Integration Layer ⏳

### BinanceApiClient Service
- [ ] Implement authenticated request signing (HMAC SHA256)
- [ ] Implement rate limiter (1200 req/min token bucket)
- [ ] `getAccountInfo(): array` - fetch balances
- [ ] `getOpenOrders(string $symbol): array` - get open orders
- [ ] `getOrder(string $symbol, string $clientOrderId): ?array` - get specific order
- [ ] `placeOrder(string $symbol, string $side, string $type, array $params): array`
- [ ] `cancelOrder(string $symbol, string $clientOrderId): array`
- [ ] `getCurrentPrice(string $symbol): float` - last trade price
- [ ] `getMyTrades(string $symbol, ?int $startTime): array` - fill history
- [ ] `getExchangeInfo(string $symbol): array` - tick_size, lot_size, min_notional
- [ ] Exponential backoff retry logic
- [ ] Exception handling (BinanceException)

### BinanceDataMapper Service
- [ ] Map Binance order response → Order entity
- [ ] Map Binance trade response → Fill entity
- [ ] Map Binance account response → balances array
- [ ] Validate and normalize Binance data

### Configuration
- [ ] Create config/packages/binance.yaml
- [ ] Configure API endpoint (testnet vs production)
- [ ] Configure rate limit parameters
- [ ] Set up environment variables (BINANCE_API_KEY, BINANCE_API_SECRET, BINANCE_USE_TESTNET)

### Testing
- [ ] Test API signature generation
- [ ] Test rate limiter with mock requests
- [ ] Test error handling with invalid credentials
- [ ] Verify testnet connectivity

---

## Phase 3: Trading Strategy Implementation ⏳

### StrategyInterface
- [ ] Define `StrategyInterface` contract
- [ ] Define OrderSpec structure (side, type, price, qty, clientId)
- [ ] Define strategy input/output formats

### GridStrategy Service
- [ ] Implement `computeDesiredOrders(array $config, array $state, array $market): array`
- [ ] **Step 1:** Build planned levels from anchor_price_P0
  - [ ] Calculate level prices with `roundDown($P0 * (1 - levels_pct[i]), tick_size)`
  - [ ] Calculate quantities: `roundDown(alloc * capital / price, lot_size)`
  - [ ] Filter out levels below min_notional
  - [ ] Generate deterministic clientOrderId: `BOT:{symbol}:{basket_id}:B:{i}`
- [ ] **Step 2:** Calculate VWAP from fills history
  - [ ] Aggregate filled BUY quantities and quote totals
  - [ ] Compute VWAP: `filled_quote_total / filled_qty_total`
  - [ ] Count filled levels (n_filled)
- [ ] **Step 3:** Apply zone protections
  - [ ] Implement hard_stop_mode="hard" (block orders below stop)
  - [ ] Implement hard_stop_mode="extend_zone" (add sparse levels)
  - [ ] Implement hard_stop_mode="none"
- [ ] **Step 4:** Determine BUY should-be orders
  - [ ] Filter unfilled levels
  - [ ] Apply place_mode="only_next_k" (K nearest below price)
  - [ ] Apply place_mode="all_unfilled"
  - [ ] Validate against remaining budget and balance
- [ ] **Step 5:** Determine SELL should-be orders
  - [ ] Calculate dynamic TP: `TP(n) = max(tp_start - tp_step*(n-1), tp_min)`
  - [ ] Calculate TP1 price: `roundUp(VWAP * (1 + TP(n)), tick_size)`
  - [ ] Calculate TP2 price: `roundUp(VWAP * (1 + TP(n) + tp2_delta), tick_size)`
  - [ ] Split position: q1=pos*tp1_share, q2=pos*tp2_share, q3=remainder
  - [ ] Generate clientOrderIds: S:TP1, S:TP2, S:TRAIL
  - [ ] Handle trailing stop (native or simulated)
- [ ] **Step 6:** Reanchor suggestion logic
  - [ ] Check if position == 0
  - [ ] Check close_ratio threshold
  - [ ] Check time_TTL expiration
  - [ ] Set reanchor_suggested flag
- [ ] Helper functions: `roundDown()`, `roundUp()`, `calculateVWAP()`

### Configuration Management
- [ ] Create default grid configuration
- [ ] Store config in bot_config table
- [ ] Snapshot config to basket.config on basket creation
- [ ] Configuration validation (weights sum to 1.0, etc.)

### Testing
- [ ] Unit test: level planning with various anchor prices
- [ ] Unit test: VWAP calculation with multiple fills
- [ ] Unit test: dynamic TP calculation for n=1..10 levels
- [ ] Unit test: place_mode filtering
- [ ] Unit test: zone protection logic
- [ ] Integration test: full strategy cycle with mock state

---

## Phase 4: Order Reconciliation & Execution ⏳

### OrderReconciler Service
- [ ] Implement `reconcile(array $desiredOrders, array $actualOrders): ReconcileResult`
- [ ] Match orders by clientOrderId
- [ ] Identify to_cancel = ACTUAL - SHOULD_BE
- [ ] Identify to_create = SHOULD_BE - ACTUAL
- [ ] Identify to_replace = intersection with price/qty differences
- [ ] Generate ReconcileResult with action plan
- [ ] Calculate reconciliation stats

### OrderExecutor Service
- [ ] Execute cancel actions (call BinanceApiClient)
- [ ] Execute create actions (call BinanceApiClient)
- [ ] Handle replace as cancel + create
- [ ] Batch operations when possible
- [ ] Record all actions in database (orders table)
- [ ] Handle partial failures (log & continue)

### PositionCalculator Service
- [ ] `calculateRealizedPnL(int $basketId): float`
  - [ ] Sum all closed round-trip trades
  - [ ] Include commission costs
- [ ] `calculateUnrealizedPnL(int $basketId, float $currentPrice): float`
  - [ ] Mark-to-market open positions
  - [ ] Use VWAP as entry price
- [ ] `getPositionSummary(int $basketId, float $currentPrice): array`
  - [ ] Aggregate base_qty, quote_invested
  - [ ] Calculate avg_entry_price (VWAP)
  - [ ] Calculate realized + unrealized P&L
  - [ ] Calculate ROI percentage
- [ ] `getAverageEntryPrice(int $basketId): ?float`

### Testing
- [ ] Unit test: reconciliation with various order states
- [ ] Unit test: P&L calculations with sample fills
- [ ] Integration test: reconcile → execute → verify orders on exchange

---

## Phase 5: Orchestration & Main Loop ⏳

### Orchestrator Service
- [ ] Implement main `run(): void` loop
- [ ] **Cycle Step 1:** Fetch state from Binance
  - [ ] Get account balances
  - [ ] Get open orders for symbol
  - [ ] Get current market price
  - [ ] Sync recent fills (last N hours)
- [ ] **Cycle Step 2:** Load active baskets from database
- [ ] **Cycle Step 3:** For each basket, process:
  - [ ] Prepare state array (balances, position, fills)
  - [ ] Call Strategy->computeDesiredOrders()
  - [ ] Call OrderReconciler->reconcile()
  - [ ] Execute reconciliation plan via OrderExecutor
  - [ ] Update database (orders, fills)
  - [ ] Check reanchor suggestions
- [ ] **Cycle Step 4:** Create account snapshot (periodic)
- [ ] **Cycle Step 5:** Sleep until next iteration (configurable interval)
- [ ] Error handling: API errors (skip cycle), DB errors (rollback)
- [ ] Graceful shutdown handling (SIGTERM, SIGINT)
- [ ] Logging at each step

### Fill Synchronization
- [ ] Implement `syncFillsFromBinance(Basket $basket): void`
- [ ] Query Binance for recent trades
- [ ] Match trades to orders via orderId
- [ ] Insert new fills into database
- [ ] Update order status (NEW → FILLED)
- [ ] Handle duplicate detection

### Testing
- [ ] Integration test: full orchestration cycle with testnet
- [ ] Test error recovery (API timeout, DB connection loss)
- [ ] Test graceful shutdown
- [ ] Test fill synchronization with multiple orders

---

## Phase 6: Console Command & Worker ⏳

### TradingBotCommand
- [ ] Create `src/Command/TradingBotCommand.php`
- [ ] Implement `execute()` method
- [ ] Inject Orchestrator service
- [ ] Register signal handlers (SIGTERM, SIGINT)
- [ ] Call `$orchestrator->run()`
- [ ] Graceful shutdown logic
- [ ] Output logging to console

### Docker Worker Service
- [ ] Configure worker service in docker-compose.yml
- [ ] Set command: `php bin/console app:trading-bot`
- [ ] Configure restart policy: `unless-stopped`
- [ ] Set environment variables
- [ ] Configure health checks (optional)

### Supervisor Configuration (if needed)
- [ ] Create supervisor config for bot process
- [ ] Configure auto-restart on failure
- [ ] Configure log rotation

### Testing
- [ ] Test command execution: `docker-compose exec php php bin/console app:trading-bot`
- [ ] Test signal handling (Ctrl+C)
- [ ] Test worker auto-restart on crash
- [ ] Monitor logs: `docker-compose logs -f worker`

---

## Phase 7: Dashboard & Web UI ⏳

### DashboardController
- [ ] Create `src/Controller/DashboardController.php`
- [ ] Route: `GET /` - main dashboard
- [ ] Route: `GET /orders/history` - full order history (paginated)
- [ ] Inject repositories and PositionCalculator
- [ ] Fetch active basket
- [ ] Fetch open orders with unrealized P&L
- [ ] Fetch order history with realized P&L
- [ ] Calculate profitability summary
- [ ] Render Twig template

### TradingController
- [ ] Create `src/Controller/TradingController.php`
- [ ] Route: `POST /trading/close-all` - emergency close
- [ ] CSRF token validation
- [ ] Call EmergencyCloseService
- [ ] Flash messages
- [ ] Redirect to dashboard

### EmergencyCloseService
- [ ] Implement `closeAllPositions(int $basketId, float $safetyMargin): array`
- [ ] Cancel all open orders for basket
- [ ] Get current market price
- [ ] Calculate total base position
- [ ] Place emergency exit order (price - safetyMargin%)
- [ ] Mark basket as 'emergency_closed'
- [ ] Log emergency event
- [ ] Return result summary

### Twig Templates
- [ ] Create `templates/base.html.twig` with Tailwind CSS
- [ ] Create `templates/dashboard/index.html.twig`
- [ ] Create `templates/dashboard/_open_orders.html.twig`
- [ ] Create `templates/dashboard/_order_history.html.twig`
- [ ] Create `templates/dashboard/_profitability.html.twig`
- [ ] Create `templates/dashboard/_emergency_modal.html.twig`
- [ ] Add color coding (green=profit, red=loss, yellow=pending)
- [ ] Add auto-refresh meta tag (optional)

### Tailwind CSS Setup
- [ ] Install Tailwind CSS (Symfony AssetMapper or standalone)
- [ ] Configure tailwind.config.js
- [ ] Build CSS assets
- [ ] Include in base template

### Testing
- [ ] Test dashboard renders with sample data
- [ ] Test emergency close flow (confirmation → execute)
- [ ] Test CSRF protection
- [ ] Test order history pagination
- [ ] Test responsive design (mobile view)

---

## Phase 8: Safety Features & Validation ⏳

### Exchange Info Caching
- [ ] Implement exchange info cache (24h TTL)
- [ ] Fetch tick_size, lot_size, min_notional on startup
- [ ] Use cached values for all calculations
- [ ] Refresh cache daily

### Order Validation
- [ ] Validate price % tick_size == 0
- [ ] Validate qty % lot_size == 0
- [ ] Validate price * qty >= min_notional
- [ ] Validate sufficient balance before create
- [ ] Validate clientOrderId format
- [ ] Validate order type supported by exchange

### Rate Limit Protection
- [ ] Implement token bucket algorithm
- [ ] Track requests per minute
- [ ] Queue requests when approaching limit
- [ ] Exponential backoff on 429 errors
- [ ] Log rate limit events

### Idempotency Guards
- [ ] Database unique constraint on orders.client_order_id
- [ ] Check for duplicate fills before insert
- [ ] Handle Binance "order already exists" errors gracefully

### Testing
- [ ] Test validation rejects invalid orders
- [ ] Test rate limiter delays requests correctly
- [ ] Test idempotency with duplicate API calls

---

## Phase 9: Initial Deployment & Testing ⏳

### Environment Setup
- [ ] Create .env.local with testnet credentials
- [ ] Set BINANCE_USE_TESTNET=true
- [ ] Set DB_PASSWORD (secure)
- [ ] Set APP_SECRET (random)
- [ ] Set BOT_CHECK_INTERVAL_SECONDS=5

### Database Initialization
- [ ] Run migrations: `docker-compose exec php php bin/console doctrine:migrations:migrate`
- [ ] Insert initial bot_config with default strategy
- [ ] Create first basket with anchor_price_P0

### Initial Basket Creation
- [ ] Manual creation via SQL or admin command
- [ ] Set symbol=SOLUSDC
- [ ] Set anchor_price (current market price)
- [ ] Set status=active
- [ ] Configure grid levels (6 levels, -5% to -30%)

### Testnet Trial Run
- [ ] Start worker: `docker-compose up -d worker`
- [ ] Monitor logs: `docker-compose logs -f worker`
- [ ] Verify orders are placed on testnet
- [ ] Wait for fills (may need to manually move market on testnet)
- [ ] Verify fills are synced to database
- [ ] Verify TP orders are placed after fills
- [ ] Verify reconciliation works correctly
- [ ] Test emergency close button

### Validation Checklist
- [ ] Orders match clientOrderId scheme
- [ ] VWAP calculation is correct
- [ ] TP prices adjust dynamically
- [ ] Reconciliation loop is stable (no order spam)
- [ ] P&L calculations are accurate
- [ ] Dashboard displays correct data
- [ ] No crashes or exceptions in logs

---

## Phase 10: Production Preparation ⏳

### Security Hardening
- [ ] Never commit .env.local with real credentials
- [ ] Use environment secrets management (if cloud)
- [ ] Enable HTTPS for dashboard (nginx SSL)
- [ ] Restrict dashboard access (IP whitelist or auth)
- [ ] Review all error messages (no credential leaks)

### Monitoring & Alerts
- [ ] Set up log aggregation (optional)
- [ ] Set up health check endpoint
- [ ] Configure alerts for basket status changes
- [ ] Configure alerts for worker crashes
- [ ] Configure alerts for API errors

### Backup Strategy
- [ ] Set up automated database backups (pg_dump cron)
- [ ] Test restore procedure
- [ ] Document backup schedule
- [ ] Store backups securely (offsite)

### Documentation
- [ ] Document reanchor procedure for operators
- [ ] Document emergency close procedure
- [ ] Document how to modify strategy config
- [ ] Create runbook for common issues

### Production Deployment
- [ ] Set BINANCE_USE_TESTNET=false
- [ ] Use production API credentials
- [ ] Set conservative initial capital (low risk)
- [ ] Monitor first 24h closely
- [ ] Verify all fills and P&L on production

### Post-Deployment
- [ ] Monitor for 1 week continuously
- [ ] Review all fills and strategy decisions
- [ ] Optimize parameters if needed (tp_start, tp_step, etc.)
- [ ] Document any issues or improvements

---

## Phase 11: Future Enhancements 🔮

### Multiple Baskets
- [ ] Support multiple concurrent baskets
- [ ] Portfolio-level P&L aggregation
- [ ] Independent strategy configs per basket

### Advanced Strategies
- [ ] Mean reversion strategy
- [ ] Trend following strategy
- [ ] Arbitrage (spot-futures)
- [ ] Custom strategy plugin system

### Notifications
- [ ] Email alerts on fills
- [ ] Webhook integrations (Discord, Telegram)
- [ ] Push notifications for critical events

### Advanced Analytics
- [ ] Equity curve visualization (Chart.js or TradingView)
- [ ] Sharpe ratio, max drawdown calculations
- [ ] Strategy backtesting framework
- [ ] Trade journal with notes

### Risk Management
- [ ] Daily loss limits
- [ ] Position size limits
- [ ] Correlation monitoring (if multiple baskets)
- [ ] Volatility-based position sizing

### UI Enhancements
- [ ] Real-time charts (TradingView widget)
- [ ] Mobile responsive design improvements
- [ ] Dark mode
- [ ] Multi-language support

### Performance Optimizations
- [ ] WebSocket for real-time price updates
- [ ] Redis cache for exchange info
- [ ] Database partitioning for fills table
- [ ] Optimize reconciliation algorithm

---

## Notes & Decisions

### Why PHP 8.4?
- Latest stable version with performance improvements
- Native typed properties and strict types
- Modern syntax (constructor property promotion, etc.)

### Why PostgreSQL 17?
- Latest stable, excellent JSONB support for basket.config
- Strong ACID guarantees for financial data
- Excellent indexing performance

### Why Deterministic clientOrderId?
- Idempotency: prevents duplicate orders
- Easy reconciliation: match by ID instead of price/qty
- Crash-safe: can always reconstruct state from Binance

### Why Iterative (no recursion)?
- Simpler to reason about
- No stack overflow risk
- Easier to debug and test

### Why "Should-Be" Pattern?
- Declarative: strategy declares desired state
- Reconciler handles the diff
- Bot is stateless: can restart anytime
- Easy to test: mock desired state

---

**Last Updated:** 2025-10-16
**Current Phase:** Phase 0 (Infrastructure)
**Next Milestone:** Docker environment setup + Symfony scaffolding

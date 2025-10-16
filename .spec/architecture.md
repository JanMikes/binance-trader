# Binance Trader - Architecture & Implementation Plan

## 📋 Architecture Overview

### Technology Stack
- **Framework:** Symfony 7.x
- **Database:** PostgreSQL 16
- **Containerization:** Docker + Docker Compose
- **Frontend:** Twig templates with Tailwind CSS
- **API Client:** Symfony HTTP Client for Binance API
- **Process Management:** Supervisor (for long-running bot)

---

## 🗄️ Database Schema

### Tables

#### 1. `baskets`
Trading basket/session tracking - each basket represents one grid trading session.

| Column | Type | Description |
|--------|------|-------------|
| id | SERIAL PRIMARY KEY | Unique basket identifier |
| symbol | VARCHAR(20) | Trading pair (e.g., SOLUSDC) |
| anchor_price | DECIMAL(18,8) | Grid anchor price (P0) |
| created_at | TIMESTAMP | Basket creation time |
| closed_at | TIMESTAMP NULL | Basket closure time |
| status | VARCHAR(20) | active, closed, error |
| config | JSONB | Full strategy configuration |

#### 2. `orders`
All orders - both historical and current.

| Column | Type | Description |
|--------|------|-------------|
| id | SERIAL PRIMARY KEY | Internal order ID |
| basket_id | INTEGER | Foreign key to baskets |
| exchange_order_id | BIGINT NULL | Binance order ID |
| client_order_id | VARCHAR(100) | Deterministic client ID |
| side | VARCHAR(10) | BUY, SELL |
| type | VARCHAR(30) | LIMIT, STOP_LOSS_LIMIT, etc. |
| price | DECIMAL(18,8) | Order price |
| quantity | DECIMAL(18,8) | Order quantity |
| status | VARCHAR(20) | NEW, FILLED, CANCELED, etc. |
| created_at | TIMESTAMP | Order creation time |
| filled_at | TIMESTAMP NULL | Order fill time |
| updated_at | TIMESTAMP | Last update time |

**Indexes:**
- `idx_orders_basket_id` on (basket_id)
- `idx_orders_client_order_id` on (client_order_id)
- `idx_orders_status` on (status)

#### 3. `fills`
Trade execution history - records each fill event.

| Column | Type | Description |
|--------|------|-------------|
| id | SERIAL PRIMARY KEY | Fill ID |
| order_id | INTEGER | Foreign key to orders |
| basket_id | INTEGER | Foreign key to baskets |
| side | VARCHAR(10) | BUY, SELL |
| price | DECIMAL(18,8) | Execution price |
| quantity | DECIMAL(18,8) | Executed quantity |
| commission | DECIMAL(18,8) | Trading fee |
| commission_asset | VARCHAR(10) | Fee currency |
| executed_at | TIMESTAMP | Execution timestamp |

**Indexes:**
- `idx_fills_basket_id` on (basket_id)
- `idx_fills_order_id` on (order_id)

#### 4. `account_snapshots`
Periodic account balance snapshots for historical tracking.

| Column | Type | Description |
|--------|------|-------------|
| id | SERIAL PRIMARY KEY | Snapshot ID |
| timestamp | TIMESTAMP | Snapshot time |
| quote_balance | DECIMAL(18,8) | Available quote currency (USDC) |
| base_balance | DECIMAL(18,8) | Available base currency (SOL) |
| total_value | DECIMAL(18,8) | Total account value in quote |

#### 5. `bot_config`
Bot configuration storage (key-value store for strategy params).

| Column | Type | Description |
|--------|------|-------------|
| id | SERIAL PRIMARY KEY | Config ID |
| key | VARCHAR(100) UNIQUE | Configuration key |
| value | JSONB | Configuration value |
| updated_at | TIMESTAMP | Last update time |

---

## 📁 Directory Structure

```
binance-trader/
├── .spec/
│   ├── strategy.md              # Grid strategy specification
│   └── architecture.md          # This file
├── config/
│   ├── packages/
│   │   ├── doctrine.yaml
│   │   ├── binance.yaml         # Binance API configuration
│   │   └── messenger.yaml
│   ├── routes.yaml
│   └── services.yaml
├── docker/
│   ├── nginx/
│   │   ├── Dockerfile
│   │   └── default.conf
│   ├── php/
│   │   ├── Dockerfile
│   │   └── supervisor.conf      # For long-running bot
│   └── postgres/
│       └── init.sql
├── docker-compose.yml
├── migrations/
│   └── Version*.php
├── public/
│   └── index.php
├── src/
│   ├── Command/
│   │   └── TradingBotCommand.php          # Main bot loop
│   ├── Controller/
│   │   ├── DashboardController.php        # Dashboard UI
│   │   └── TradingController.php          # Emergency controls
│   ├── Entity/
│   │   ├── Basket.php
│   │   ├── Order.php
│   │   ├── Fill.php
│   │   ├── AccountSnapshot.php
│   │   └── BotConfig.php
│   ├── Repository/
│   │   ├── BasketRepository.php
│   │   ├── OrderRepository.php
│   │   ├── FillRepository.php
│   │   ├── AccountSnapshotRepository.php
│   │   └── BotConfigRepository.php
│   ├── Service/
│   │   ├── Binance/
│   │   │   ├── BinanceApiClient.php       # Binance API wrapper
│   │   │   ├── BinanceDataMapper.php      # Map API DTOs
│   │   │   └── BinanceException.php
│   │   ├── Strategy/
│   │   │   ├── StrategyInterface.php      # Strategy contract
│   │   │   ├── GridStrategy.php           # Grid implementation
│   │   │   └── StrategyState.php          # State DTO
│   │   ├── Trading/
│   │   │   ├── OrderReconciler.php        # Reconcile should-be vs actual
│   │   │   ├── PositionCalculator.php     # P&L calculations
│   │   │   ├── EmergencyCloseService.php  # Close all positions
│   │   │   └── OrderExecutor.php          # Execute order actions
│   │   └── Orchestrator.php               # Main coordination logic
│   └── Kernel.php
├── templates/
│   ├── base.html.twig
│   └── dashboard/
│       ├── index.html.twig
│       ├── _open_orders.html.twig
│       ├── _order_history.html.twig
│       ├── _profitability.html.twig
│       └── _emergency_modal.html.twig
├── .env
├── .env.local.example
├── composer.json
└── symfony.lock
```

---

## 🔧 Core Components

### 1. BinanceApiClient

**Responsibility:** Low-level Binance API communication.

**Methods:**
- `getAccountInfo(): array` - Fetch account balances
- `getOpenOrders(string $symbol): array` - Get open orders for symbol
- `getOrder(string $symbol, string $clientOrderId): ?array` - Get specific order
- `placeOrder(string $symbol, string $side, string $type, array $params): array` - Place new order
- `cancelOrder(string $symbol, string $clientOrderId): array` - Cancel order
- `getCurrentPrice(string $symbol): float` - Get latest price
- `getMyTrades(string $symbol, ?int $startTime = null): array` - Get trade history

**Features:**
- Rate limiting (Binance limits: 1200 req/min)
- Automatic retry with exponential backoff
- Signature generation for authenticated requests
- Comprehensive error handling

**Configuration (config/packages/binance.yaml):**
```yaml
binance:
    api_key: '%env(BINANCE_API_KEY)%'
    api_secret: '%env(BINANCE_API_SECRET)%'
    base_url: 'https://api.binance.com'
    testnet_url: 'https://testnet.binance.vision'
    use_testnet: '%env(bool:BINANCE_USE_TESTNET)%'
    rate_limit_per_minute: 1200
```

---

### 2. StrategyInterface

**Contract for all trading strategies.**

```php
<?php

namespace App\Service\Strategy;

interface StrategyInterface
{
    /**
     * Compute the desired order state based on current market and account state.
     *
     * @param array $config Strategy configuration (from bot_config or basket.config)
     * @param array $state Current account state (balances, positions, open orders, fills)
     * @param array $market Current market data (last price, orderbook depth if needed)
     *
     * @return array {
     *   'buys': OrderSpec[],
     *   'sells': OrderSpec[],
     *   'meta': array
     * }
     */
    public function computeDesiredOrders(
        array $config,
        array $state,
        array $market
    ): array;
}
```

**OrderSpec structure:**
```php
[
    'side' => 'BUY|SELL',
    'type' => 'LIMIT|STOP_LOSS_LIMIT|TAKE_PROFIT_LIMIT',
    'price' => 123.456,
    'qty' => 1.23,
    'clientId' => 'BOT:SOLUSDC:basket123:B:1',
    'stopPrice' => null, // for stop orders
]
```

---

### 3. GridStrategy

**Implementation of the grid trading strategy from strategy.md.**

**Key Features:**
- Deterministic clientOrderId scheme: `BOT:{symbol}:{basket_id}:{side}:{level}`
  - BUY level 1: `BOT:SOLUSDC:basket123:B:1`
  - SELL TP1: `BOT:SOLUSDC:basket123:S:TP1`
  - SELL TP2: `BOT:SOLUSDC:basket123:S:TP2`
  - SELL TRAIL: `BOT:SOLUSDC:basket123:S:TRAIL`
- Iterative level planning (no recursion)
- VWAP calculation for average entry price
- Dynamic take-profit adjustment based on filled levels
- Zone protection (hard stop, zone extension)
- Reanchor suggestions

**Algorithm Steps:**
1. Build planned levels based on anchor price and level percentages
2. Evaluate filled buys from history, calculate VWAP
3. Apply zone/stop protections
4. Determine BUY should-be orders (unfilled levels)
5. Determine SELL should-be orders (TP1, TP2, TRAIL based on position)
6. Suggest reanchor if needed

**Helper Functions:**
```php
private function roundDown(float $value, float $step): float;
private function roundUp(float $value, float $step): float;
private function calculateVWAP(array $fills): ?float;
private function filterActiveLevels(array $levels, array $config): array;
```

---

### 4. OrderReconciler

**Responsibility:** Compare desired state (from Strategy) vs actual state (from Binance).

**Algorithm:**
```
SHOULD_BE = Strategy output (desired orders)
ACTUAL = Open orders from Binance

to_cancel = ACTUAL - SHOULD_BE (by clientOrderId)
to_create = SHOULD_BE - ACTUAL (by clientOrderId)
to_replace = SHOULD_BE ∩ ACTUAL where (price or qty differs)

Actions:
1. Cancel all in to_cancel
2. Cancel all in to_replace
3. Create all in to_create + to_replace
```

**Methods:**
- `reconcile(array $desiredOrders, array $actualOrders): ReconcileResult`
- `private function buildActionPlan(...): array`
- `private function matchByClientOrderId(...): array`

**ReconcileResult:**
```php
[
    'to_cancel' => [clientOrderId, ...],
    'to_create' => [OrderSpec, ...],
    'stats' => [
        'canceled' => 5,
        'created' => 3,
        'unchanged' => 10
    ]
]
```

---

### 5. PositionCalculator

**Responsibility:** Calculate P&L for open and closed positions.

**Methods:**
- `calculateRealizedPnL(int $basketId): float` - Sum of all filled round-trips
- `calculateUnrealizedPnL(int $basketId, float $currentPrice): float` - Mark-to-market
- `getPositionSummary(int $basketId, float $currentPrice): array` - Complete P&L summary
- `getAverageEntryPrice(int $basketId): ?float` - VWAP from fills

**Position Summary:**
```php
[
    'base_qty' => 5.23,
    'quote_invested' => 650.00,
    'avg_entry_price' => 124.28,
    'current_price' => 130.00,
    'realized_pnl' => 12.50,
    'unrealized_pnl' => 29.91,
    'total_pnl' => 42.41,
    'roi_percent' => 6.52
]
```

---

### 6. Orchestrator

**Responsibility:** Main coordination loop - the "brain" of the bot.

**Main Loop (executed every N seconds):**
```
1. Fetch current state from Binance:
   - Account balances
   - Open orders
   - Recent fills (sync with DB)
   - Current price

2. Load active basket(s) from DB

3. For each active basket:
   a. Prepare state array (balances, fills, position)
   b. Call Strategy->computeDesiredOrders()
   c. Call OrderReconciler->reconcile()
   d. Execute reconciliation plan:
      - Cancel orders (via OrderExecutor)
      - Create new orders (via OrderExecutor)
   e. Update DB (orders, fills, snapshots)
   f. Check for reanchor suggestions

4. Log cycle completion
5. Sleep until next iteration
```

**Error Handling:**
- Binance API errors: log, skip cycle, retry next time
- Database errors: log, attempt recovery
- Strategy errors: log, pause basket, alert

**Methods:**
- `run(): void` - Main infinite loop
- `private function processCycle(): void`
- `private function syncFillsFromBinance(Basket $basket): void`
- `private function executeReconciliation(ReconcileResult $result): void`

---

### 7. TradingBotCommand

**Symfony console command for running the bot as a long-running process.**

```php
<?php
// src/Command/TradingBotCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\Orchestrator;

class TradingBotCommand extends Command
{
    protected static $defaultName = 'app:trading-bot';

    public function __construct(
        private Orchestrator $orchestrator
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Starting Binance Trading Bot...');

        // Register signal handlers for graceful shutdown
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn() => $this->shutdown($output));
        pcntl_signal(SIGINT, fn() => $this->shutdown($output));

        $this->orchestrator->run();

        return Command::SUCCESS;
    }

    private function shutdown(OutputInterface $output): void
    {
        $output->writeln('Shutting down gracefully...');
        exit(0);
    }
}
```

**Execution:**
```bash
docker-compose exec worker php bin/console app:trading-bot
```

---

### 8. DashboardController

**Responsibility:** Web UI for monitoring and control.

**Routes:**
- `GET /` - Main dashboard
- `GET /orders/history` - Full order history (paginated)

**Dashboard Sections:**

#### A. Open Positions Table
Displays all currently open orders with real-time P&L.

| Order ID | Side | Type | Price | Quantity | Current Price | Unrealized P&L |
|----------|------|------|-------|----------|---------------|----------------|
| BT:...:B:1 | BUY | LIMIT | 142.50 | 0.56 | 150.00 | +4.20 USDC |
| BT:...:S:TP1 | SELL | LIMIT | 134.45 | 1.04 | 150.00 | - |

#### B. Order History Table
Chronological list of filled orders.

| Time | Side | Price | Quantity | Realized P&L |
|------|------|-------|----------|--------------|
| 2025-10-16 14:23 | SELL | 135.50 | 1.04 | +8.32 USDC |
| 2025-10-16 12:15 | BUY | 127.50 | 1.17 | - |

#### C. Profitability Summary
Key metrics at a glance.

```
Total Realized P&L: +42.50 USDC
Total Unrealized P&L: +12.30 USDC
Combined P&L: +54.80 USDC
ROI: 5.48%

Total Trades: 24
Win Rate: 87.5%
Avg Win: +2.85 USDC
Avg Loss: -0.45 USDC
```

#### D. Emergency Controls
- **Close All Positions** button (red, requires confirmation)

---

### 9. EmergencyCloseService

**Responsibility:** Emergency shutdown of all trading activity.

**closeAllPositions() algorithm:**
```
1. BEGIN TRANSACTION
2. Cancel ALL open orders for the basket
3. Get current market price
4. Calculate total base position
5. If position > 0:
   a. Calculate emergency price = current_price * (1 - safety_margin)
   b. Round to tick_size
   c. Place LIMIT order to sell entire position
   d. Log emergency close event
6. Mark basket as 'emergency_closed'
7. COMMIT TRANSACTION
8. Return result summary
```

**Safety Margin:** Default 2-3% below market price to ensure fast fill.

**Methods:**
- `closeAllPositions(int $basketId, float $safetyMarginPercent = 0.03): array`
- `private function cancelAllOrders(Basket $basket): int`
- `private function createEmergencyExitOrder(Basket $basket, float $price, float $qty): void`

---

### 10. TradingController

**Responsibility:** Handle emergency user actions from dashboard.

**Routes:**
- `POST /trading/close-all` - Close all positions (requires CSRF token)

**Implementation:**
```php
#[Route('/trading/close-all', name: 'trading_close_all', methods: ['POST'])]
public function closeAll(
    Request $request,
    EmergencyCloseService $closeService
): Response {
    // Validate CSRF token
    if (!$this->isCsrfTokenValid('close-all', $request->request->get('_token'))) {
        throw new InvalidCsrfTokenException();
    }

    // Get active basket
    $basket = $this->basketRepository->findActiveBasket();

    // Execute emergency close
    $result = $closeService->closeAllPositions($basket->getId());

    // Flash message
    $this->addFlash('success', sprintf(
        'All positions closed. %d orders canceled, emergency exit placed.',
        $result['canceled_count']
    ));

    return $this->redirectToRoute('dashboard');
}
```

---

## 🐳 Docker Configuration

### docker-compose.yml

```yaml
version: '3.8'

services:
  postgres:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: binance_trader
      POSTGRES_USER: trader
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - postgres_data:/var/lib/postgresql/data
      - ./docker/postgres/init.sql:/docker-entrypoint-initdb.d/init.sql
    ports:
      - "5432:5432"
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U trader"]
      interval: 10s
      timeout: 5s
      retries: 5

  php:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    volumes:
      - .:/app
    environment:
      DATABASE_URL: postgresql://trader:${DB_PASSWORD}@postgres:5432/binance_trader
      BINANCE_API_KEY: ${BINANCE_API_KEY}
      BINANCE_API_SECRET: ${BINANCE_API_SECRET}
      BINANCE_USE_TESTNET: ${BINANCE_USE_TESTNET:-true}
    depends_on:
      postgres:
        condition: service_healthy

  nginx:
    build:
      context: .
      dockerfile: docker/nginx/Dockerfile
    ports:
      - "8080:80"
    volumes:
      - .:/app
    depends_on:
      - php

  worker:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    command: php bin/console app:trading-bot
    volumes:
      - .:/app
    environment:
      DATABASE_URL: postgresql://trader:${DB_PASSWORD}@postgres:5432/binance_trader
      BINANCE_API_KEY: ${BINANCE_API_KEY}
      BINANCE_API_SECRET: ${BINANCE_API_SECRET}
      BINANCE_USE_TESTNET: ${BINANCE_USE_TESTNET:-true}
      BOT_CHECK_INTERVAL_SECONDS: ${BOT_CHECK_INTERVAL_SECONDS:-5}
    depends_on:
      postgres:
        condition: service_healthy
    restart: unless-stopped

volumes:
  postgres_data:
```

### docker/php/Dockerfile

```dockerfile
FROM php:8.3-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    postgresql-dev \
    icu-dev \
    libzip-dev \
    git \
    unzip

# Install PHP extensions
RUN docker-php-ext-install \
    pdo_pgsql \
    intl \
    zip \
    opcache \
    pcntl

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files
COPY composer.json composer.lock ./

# Install dependencies
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copy application
COPY . .

# Run post-install scripts
RUN composer dump-autoload --optimize

# Set permissions
RUN chown -R www-data:www-data /app/var

CMD ["php-fpm"]
```

### docker/nginx/Dockerfile

```dockerfile
FROM nginx:alpine

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

WORKDIR /app
```

### docker/nginx/default.conf

```nginx
server {
    listen 80;
    server_name localhost;
    root /app/public;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass php:9000;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }

    location ~ \.php$ {
        return 404;
    }
}
```

---

## 🔐 Environment Variables

### .env.local.example

```bash
# Database
DB_PASSWORD=your_secure_password

# Binance API
BINANCE_API_KEY=your_api_key
BINANCE_API_SECRET=your_api_secret
BINANCE_USE_TESTNET=true

# Bot Configuration
BOT_CHECK_INTERVAL_SECONDS=5

# Symfony
APP_ENV=prod
APP_SECRET=generate_random_secret_here
```

---

## 🎯 ClientOrderId Scheme (Idempotency)

All orders use deterministic `clientOrderId` to ensure idempotency and easy reconciliation.

**Format:** `BOT:{symbol}:{basket_id}:{side}:{identifier}`

**Examples:**
- `BOT:SOLUSDC:basket_42:B:1` - BUY level 1
- `BOT:SOLUSDC:basket_42:B:2` - BUY level 2
- `BOT:SOLUSDC:basket_42:S:TP1` - SELL take-profit 1
- `BOT:SOLUSDC:basket_42:S:TP2` - SELL take-profit 2
- `BOT:SOLUSDC:basket_42:S:TRAIL` - SELL trailing stop

**Benefits:**
- Prevents duplicate order creation
- Easy reconciliation (match by clientOrderId)
- Clear order identification in logs
- Supports multiple concurrent baskets

---

## ⚠️ Safety Mechanisms

### 1. Validation Before Every Order
- Check `min_notional` requirement
- Round price to `tick_size`
- Round quantity to `lot_size`
- Verify sufficient balance
- Validate against Binance exchange info

### 2. Rate Limiting
- Respect Binance limits (1200 req/min)
- Implement token bucket algorithm
- Queue requests during high activity
- Exponential backoff on rate limit errors

### 3. Error Handling
- API errors: log, skip cycle, retry
- Network errors: automatic retry with backoff
- Database errors: rollback transactions
- Strategy errors: pause basket, alert admin

### 4. Idempotency
- Deterministic clientOrderId prevents duplicates
- Database constraints on unique fields
- Reconciliation loop is safe to run multiple times

### 5. Emergency Stop
- Manual "Close All" button with confirmation
- Atomic transaction for consistency
- Safety margin on emergency exit price
- Comprehensive logging

### 6. Testing Safeguards
- **ALWAYS use testnet for development**
- Environment variable controls testnet vs production
- Clear visual indicator in UI when using production
- Require explicit confirmation for production deployment

---

## 📊 Dashboard UI Features

### Real-time Updates
- Auto-refresh every 10 seconds (optional)
- WebSocket support for live updates (future enhancement)
- Visual indicators for order status (pending, filled, canceled)

### Profitability Metrics
- **Realized P&L:** Sum of all filled round-trip trades
- **Unrealized P&L:** Mark-to-market for open positions
- **Total P&L:** Realized + Unrealized
- **ROI:** (Total P&L / Capital Invested) × 100%
- **Win Rate:** (Winning Trades / Total Trades) × 100%

### Visual Design
- **Tailwind CSS** for responsive design
- Color coding:
  - Green: Profitable positions/orders
  - Red: Loss positions
  - Yellow: Pending orders
  - Gray: Canceled orders
- Charts (future): Equity curve, P&L over time

### Emergency Modal
```html
<div id="emergency-modal" class="modal">
    <h2>⚠️ Close All Positions</h2>
    <p>This will:</p>
    <ul>
        <li>Cancel ALL open orders</li>
        <li>Close entire position with LIMIT order</li>
        <li>Use price 3% below current market</li>
    </ul>
    <p><strong>This action cannot be undone!</strong></p>

    <form method="POST" action="/trading/close-all">
        <input type="hidden" name="_token" value="{{ csrf_token('close-all') }}">
        <button type="button" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn-danger">Confirm Close All</button>
    </form>
</div>
```

---

## 🚀 Deployment & Operations

### Initial Setup

```bash
# 1. Clone repository
git clone <repo-url> binance-trader
cd binance-trader

# 2. Configure environment
cp .env.local.example .env.local
# Edit .env.local with your API keys

# 3. Start containers
docker-compose up -d

# 4. Run migrations
docker-compose exec php php bin/console doctrine:migrations:migrate

# 5. Access dashboard
open http://localhost:8080
```

### Starting the Bot

```bash
# Bot runs automatically via worker service in docker-compose
docker-compose logs -f worker
```

### Monitoring

```bash
# View logs
docker-compose logs -f worker
docker-compose logs -f php

# Database access
docker-compose exec postgres psql -U trader -d binance_trader

# Check running processes
docker-compose ps
```

### Backup

```bash
# Database backup
docker-compose exec postgres pg_dump -U trader binance_trader > backup_$(date +%Y%m%d).sql

# Restore
docker-compose exec -T postgres psql -U trader binance_trader < backup_20251016.sql
```

---

## 📈 Performance Considerations

### Database Optimization
- Indexes on frequently queried columns (basket_id, client_order_id, status)
- Partition fills table by date for large datasets
- Regular VACUUM ANALYZE for PostgreSQL health

### API Efficiency
- Batch order cancellations when possible
- Cache exchange info (tick_size, lot_size) for 24h
- Use WebSocket for real-time price updates (future)

### Memory Management
- Limit fills history query to recent N days
- Paginate dashboard queries
- Clear old account snapshots (keep configurable retention)

---

## 🔮 Future Enhancements

### Phase 2 Features (Post-MVP)
1. **Multiple Concurrent Baskets**
   - Trade multiple symbols simultaneously
   - Independent strategy configs per basket
   - Portfolio-level P&L aggregation

2. **Advanced Strategies**
   - Mean reversion
   - Trend following
   - Arbitrage (spot-futures)
   - Custom strategy plugin system

3. **Notifications**
   - Email/SMS alerts on fills
   - Webhook integrations (Discord, Telegram)
   - Push notifications for critical events

4. **Advanced Analytics**
   - Equity curve visualization
   - Sharpe ratio, max drawdown
   - Strategy backtesting framework
   - Trade journal with notes

5. **Risk Management**
   - Daily loss limits
   - Position size limits
   - Correlation monitoring
   - Volatility-based position sizing

6. **UI Enhancements**
   - Real-time charts (TradingView integration)
   - Mobile responsive design
   - Dark mode
   - Multi-language support

---

## 📝 Summary

This architecture provides:

✅ **Complete Symfony Application** - Modern PHP framework with best practices
✅ **Docker Containerization** - Easy deployment and scaling
✅ **PostgreSQL Database** - Reliable data persistence with proper indexing
✅ **Grid Strategy Implementation** - Based on detailed strategy.md specification
✅ **Dashboard with P&L Tracking** - Real-time monitoring and analytics
✅ **Emergency Controls** - Safe position closure with confirmations
✅ **Idempotency Guarantees** - Deterministic clientOrderId scheme
✅ **Rate Limiting & Error Handling** - Production-ready reliability
✅ **Safety First** - Multiple validation layers and testnet support

**Estimated Implementation:** ~40 files, ready for grid strategy deployment and future strategy extensions.

---

*Generated: 2025-10-16*
*Version: 1.0*

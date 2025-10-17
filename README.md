# Binance Grid Trading Bot

A sophisticated grid trading bot for SOL/USDC spot trading on Binance, built with Symfony 7 and PHP 8.4.

## Features

- **Grid Trading Strategy**: Automated dollar-cost averaging with configurable grid levels
- **Dynamic Take-Profit**: Adjusts TP percentage based on filled levels to optimize exits
- **3-Tier Exit System**: TP1 (40%), TP2 (35%), and trailing stop (25%) for maximum profit capture
- **VWAP Calculation**: Accurate position tracking with volume-weighted average price
- **Idempotent Operations**: Deterministic clientOrderId scheme prevents duplicate orders
- **"Should-Be" Reconciliation**: Declarative order management for crash-safety
- **Emergency Close**: One-click position exit with safety margin
- **Real-Time Dashboard**: Web UI with P&L tracking, order history, and emergency controls
- **Rate Limiting**: Token bucket algorithm respects Binance API limits (1200 req/min)
- **Order Validation**: Pre-flight checks for tick_size, lot_size, min_notional

## Architecture

### Core Components

- **GridStrategy**: Computes desired order state based on anchor price and filled levels
- **OrderReconciler**: Compares desired vs actual orders, generates action plan
- **OrderExecutor**: Executes cancel/create actions on Binance
- **Orchestrator**: Main coordination loop (fetch → strategy → reconcile → execute)
- **PositionCalculator**: Computes realized and unrealized P&L

### Key Design Patterns

**Deterministic ClientOrderId Scheme**
```
BOT:{symbol}:{basket_id}:{side}:{level}

Examples:
- BOT:SOLUSDC:basket_1:B:1      (BUY level 1)
- BOT:SOLUSDC:basket_1:B:2      (BUY level 2)
- BOT:SOLUSDC:basket_1:S:TP1    (SELL take-profit 1)
- BOT:SOLUSDC:basket_1:S:TP2    (SELL take-profit 2)
- BOT:SOLUSDC:basket_1:S:TRAIL  (SELL trailing stop)
```

**Dynamic Take-Profit Formula**
```php
TP(n) = max(tp_start_pct - tp_step_pct * (n - 1), tp_min_pct)

// Example: tp_start=1.2%, tp_step=0.15%, tp_min=0.3%
// n=1: TP=1.2%
// n=2: TP=1.05%
// n=3: TP=0.9%
// n=4: TP=0.75%
// n=5+: TP=0.3% (floor)
```

## Tech Stack

- **Framework**: Symfony 7.2.9
- **Language**: PHP 8.4 with strict types
- **Database**: PostgreSQL 17
- **Frontend**: Twig templates + Tailwind CSS
- **Container**: Docker + Docker Compose
- **Static Analysis**: PHPStan level 8
- **API**: Binance REST API (with testnet support)

## Quick Start

### Prerequisites

- Docker and Docker Compose installed
- Binance testnet API credentials ([Get them here](https://testnet.binance.vision/))

### Installation

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd binance-trader
   ```

2. **Set up environment**
   ```bash
   cp .env.local .env.local.backup
   # Edit .env.local with your testnet credentials
   ```

   Update these values in `.env.local`:
   ```
   BINANCE_API_KEY="your_testnet_api_key_here"
   BINANCE_API_SECRET="your_testnet_api_secret_here"
   BINANCE_USE_TESTNET=true
   ```

3. **Build and start services**
   ```bash
   docker-compose build
   docker-compose up -d
   ```

4. **Run database migrations**
   ```bash
   docker-compose exec php php bin/console doctrine:migrations:migrate --no-interaction
   ```

5. **Initialize testnet basket and configuration**
   ```bash
   docker-compose exec postgres psql -U trader -d binance_trader -f /app/scripts/init_testnet_basket.sql
   ```

6. **Start the trading bot**
   ```bash
   docker-compose up -d worker
   docker-compose logs -f worker
   ```

7. **Access the dashboard**
   ```
   Open http://localhost:8080 in your browser
   ```

## Configuration

The bot is configured via the `bot_config` table and basket configuration (JSONB). Key parameters:

### Grid Levels
```json
{
  "anchor_price_P0": 100.0,           // Grid anchor price
  "levels_pct": [-5, -10, -15, -20, -25, -30],  // Drop percentages
  "alloc_weights": [0.08, 0.12, 0.15, 0.18, 0.22, 0.25]  // Capital allocation
}
```

### Take-Profit Settings
```json
{
  "tp_start_pct": 1.2,    // Initial TP percentage
  "tp_step_pct": 0.15,    // TP reduction per filled level
  "tp_min_pct": 0.3       // Minimum TP percentage
}
```

### Exit Distribution
```json
{
  "exit_tp1_portion": 0.40,      // 40% at TP1
  "exit_tp2_portion": 0.35,      // 35% at TP2
  "exit_trail_portion": 0.25,    // 25% trailing stop
  "trailing_callback_pct": 0.8   // Trailing stop callback
}
```

### Order Placement
```json
{
  "place_mode": "only_next_k",  // "only_next_k" or "all_unfilled"
  "k_next": 2                    // Number of levels to place below current price
}
```

### Safety Features
```json
{
  "hard_stop_mode": "none",           // "none", "hard", or "extend_zone"
  "hard_stop_threshold_pct": -35.0,   // Hard stop trigger
  "extend_zone_trigger_pct": -30.0    // Zone extension trigger
}
```

## Docker Commands

### General Operations
```bash
# View all logs
docker-compose logs -f

# View bot worker logs
docker-compose logs -f worker

# Restart the bot
docker-compose restart worker

# Stop all services
docker-compose down

# Rebuild after code changes
docker-compose build php worker
docker-compose up -d
```

### Database Operations
```bash
# Access PostgreSQL
docker-compose exec postgres psql -U trader -d binance_trader

# Run migrations
docker-compose exec php php bin/console doctrine:migrations:migrate

# Create new migration
docker-compose exec php php bin/console make:migration

# Database backup
docker-compose exec postgres pg_dump -U trader binance_trader > backup_$(date +%Y%m%d).sql
```

### Development
```bash
# Run PHPStan (always after code changes)
docker-compose exec php vendor/bin/phpstan analyse src/ --level=8 --memory-limit=256M

# Clear Symfony cache
docker-compose exec php php bin/console cache:clear

# Install new Composer package
docker-compose exec php composer require vendor/package

# Access PHP container shell
docker-compose exec php bash
```

## Dashboard Features

### Main Dashboard (/)
- Current price display
- Position size (base quantity)
- Total P&L (realized + unrealized)
- Open orders table
- Recent filled orders (last 20)
- Emergency close button

### Order History (/orders/history)
- Complete order history
- Filterable by status, side, date
- Shows execution details

### Emergency Close
- One-click position liquidation
- Cancels all open orders
- Places LIMIT sell order at 3% below market
- CSRF-protected for security

## Safety & Best Practices

### Always Use Testnet for Development
```bash
# In .env.local
BINANCE_USE_TESTNET=true
```

### Never Commit Credentials
```bash
# .env.local is in .gitignore
# Never commit real API keys to git
```

### Monitor Worker Health
```bash
# Worker auto-restarts on crash
# Check logs regularly
docker-compose logs -f worker
```

### Validate Orders Before Execution
- Tick size validation (price precision)
- Lot size validation (quantity precision)
- Minimum notional validation
- Balance check

### Rate Limit Awareness
- Binance limit: 1200 requests/minute
- Bot uses token bucket algorithm
- Exponential backoff on errors

## Troubleshooting

### Bot Not Placing Orders
1. Check worker logs: `docker-compose logs -f worker`
2. Verify API credentials in `.env.local`
3. Check Binance testnet account balance
4. Verify `place_mode` and `k_next` configuration
5. Check for errors in database queries

### Orders Not Reconciling
1. Verify clientOrderId format matches scheme
2. Check for duplicate clientOrderIds in database
3. Review reconciler logs for mismatches
4. Ensure open orders query returns Binance data

### P&L Incorrect
1. Verify all fills are synced: check fills table
2. Validate VWAP calculation includes all BUY fills
3. Check commission is recorded correctly
4. Ensure realized P&L only counts closed positions

### PHPStan Errors
```bash
# Always run PHPStan after code changes
docker-compose exec php vendor/bin/phpstan analyse src/ --level=8 --memory-limit=256M

# Common fixes:
# - Add @var annotations for arrays: @var array<string, mixed>
# - Add @return annotations: @return array<int, string>
# - Use strict types: declare(strict_types=1)
```

## Production Checklist

Before going live with real funds:

- [ ] Test thoroughly on testnet for at least 1 week
- [ ] Set `BINANCE_USE_TESTNET=false` in production `.env.local`
- [ ] Set up database backups (pg_dump cron job)
- [ ] Configure monitoring and alerts
- [ ] Enable HTTPS for dashboard (nginx SSL config)
- [ ] Restrict dashboard access (IP whitelist or authentication)
- [ ] Document emergency procedures
- [ ] Start with conservative capital allocation
- [ ] Monitor first 24-48 hours continuously

## Development Workflow

1. Read specifications in `.spec/` directory
2. Check `TODO.md` for current implementation phase
3. Read `CHANGELOG.md` to understand what's been done
4. Follow `CLAUDE.md` for development guidelines
5. Always run commands in Docker containers
6. Run PHPStan after every code change
7. Update `TODO.md` and `CHANGELOG.md` after completing work

## Project Structure

```
binance-trader/
├── .spec/                    # Architecture and strategy specifications
├── config/                   # Symfony configuration
├── docker/                   # Docker configurations
│   ├── nginx/
│   └── php/
├── migrations/               # Doctrine migrations
├── public/                   # Web public directory
├── scripts/                  # Utility scripts
│   └── init_testnet_basket.sql
├── src/
│   ├── Command/             # Console commands
│   ├── Controller/          # Web controllers
│   ├── Entity/              # Doctrine entities
│   ├── Repository/          # Doctrine repositories
│   └── Service/
│       ├── Binance/         # Binance API integration
│       ├── Strategy/        # Trading strategies
│       └── Trading/         # Order management
├── templates/               # Twig templates
│   └── dashboard/
├── CHANGELOG.md            # Development history
├── CLAUDE.md               # Developer guide for Claude Code
├── README.md               # This file
├── TODO.md                 # Implementation roadmap
├── docker-compose.yml      # Docker services definition
└── phpstan.neon           # PHPStan configuration
```

## API Reference

### Binance Testnet
- Base URL: `https://testnet.binance.vision`
- Docs: https://testnet.binance.vision/
- Get API keys: https://testnet.binance.vision/

### Key Endpoints Used
- `GET /api/v3/account` - Account information
- `GET /api/v3/openOrders` - Open orders
- `POST /api/v3/order` - Place order
- `DELETE /api/v3/order` - Cancel order
- `GET /api/v3/ticker/price` - Current price
- `GET /api/v3/myTrades` - Trade history
- `GET /api/v3/exchangeInfo` - Symbol filters

## Contributing

When making changes:

1. Always use `declare(strict_types=1)` at the top of PHP files
2. Run PHPStan level 8 before committing
3. Update tests if adding new features
4. Document design decisions in CHANGELOG.md
5. Update TODO.md with progress

## License

[Add your license here]

## Support

For issues or questions:
- Check `CLAUDE.md` for development guidelines
- Review `CHANGELOG.md` for known issues and solutions
- Check `TODO.md` for planned features

---

**Status**: Phase 9 (Testing & Documentation) - Ready for testnet testing

**Last Updated**: 2025-10-16

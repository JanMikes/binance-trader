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

**Last Updated:** 2025-10-16
**Current Status:** Initialization complete, ready to begin infrastructure setup

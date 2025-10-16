-- Initialize bot configuration for testnet
-- Run this after migrations: docker-compose exec postgres psql -U trader -d binance_trader -f /app/scripts/init_testnet_basket.sql

-- Clear existing data (testnet only!)
TRUNCATE TABLE fills, orders, baskets, account_snapshots, bot_config RESTART IDENTITY CASCADE;

-- Insert bot configuration
INSERT INTO bot_config (key, value, created_at, updated_at) VALUES
-- Trading parameters
('symbol', '"SOLUSDC"', NOW(), NOW()),
('anchor_price_P0', '100.0', NOW(), NOW()),
('base_capital_usdc', '1000.0', NOW(), NOW()),

-- Grid levels configuration
('levels_pct', '[-5.0, -10.0, -15.0, -20.0, -25.0, -30.0]', NOW(), NOW()),
('alloc_weights', '[0.08, 0.12, 0.15, 0.18, 0.22, 0.25]', NOW(), NOW()),

-- Take-profit parameters
('tp_start_pct', '1.2', NOW(), NOW()),
('tp_step_pct', '0.15', NOW(), NOW()),
('tp_min_pct', '0.3', NOW(), NOW()),

-- Exit distribution
('exit_tp1_portion', '0.40', NOW(), NOW()),
('exit_tp2_portion', '0.35', NOW(), NOW()),
('exit_trail_portion', '0.25', NOW(), NOW()),
('trailing_callback_pct', '0.8', NOW(), NOW()),

-- Order placement strategy
('place_mode', '"only_next_k"', NOW(), NOW()),
('k_next', '2', NOW(), NOW()),

-- Safety settings
('hard_stop_mode', '"none"', NOW(), NOW()),
('hard_stop_threshold_pct', '-35.0', NOW(), NOW()),
('extend_zone_trigger_pct', '-30.0', NOW(), NOW()),

-- Reanchor settings
('reanchor_on_close', 'true', NOW(), NOW()),
('anchor_ttl_hours', '24', NOW(), NOW()),

-- Operational parameters
('orchestrator_cycle_sec', '10', NOW(), NOW())
;

-- Create initial basket for testnet
INSERT INTO baskets (symbol, anchor_price, status, config, started_at, created_at, updated_at) VALUES
(
    'SOLUSDC',
    100.0,
    'active',
    '{
        "anchor_price_P0": 100.0,
        "base_capital_usdc": 1000.0,
        "levels_pct": [-5.0, -10.0, -15.0, -20.0, -25.0, -30.0],
        "alloc_weights": [0.08, 0.12, 0.15, 0.18, 0.22, 0.25],
        "tp_start_pct": 1.2,
        "tp_step_pct": 0.15,
        "tp_min_pct": 0.3,
        "exit_tp1_portion": 0.40,
        "exit_tp2_portion": 0.35,
        "exit_trail_portion": 0.25,
        "trailing_callback_pct": 0.8,
        "place_mode": "only_next_k",
        "k_next": 2,
        "hard_stop_mode": "none",
        "hard_stop_threshold_pct": -35.0,
        "extend_zone_trigger_pct": -30.0,
        "reanchor_on_close": true,
        "anchor_ttl_hours": 24
    }'::jsonb,
    NOW(),
    NOW(),
    NOW()
);

-- Display results
SELECT 'Bot configuration initialized:' as status;
SELECT key, value FROM bot_config ORDER BY key;

SELECT 'Initial basket created:' as status;
SELECT id, symbol, anchor_price, status, started_at FROM baskets;

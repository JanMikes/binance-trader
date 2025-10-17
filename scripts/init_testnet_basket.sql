-- Initialize bot configuration for testnet
-- Run this after migrations: cat scripts/init_testnet_basket.sql | docker-compose exec -T postgres psql -U postgres -d trader

-- Clear existing data (testnet only!)
TRUNCATE TABLE fills, orders, baskets, account_snapshots, bot_config CASCADE;

-- Insert bot configuration
INSERT INTO bot_config (id, key, value, updated_at) VALUES
-- Trading parameters
(gen_random_uuid(), 'symbol', '"SOLUSDC"', NOW()),
(gen_random_uuid(), 'anchor_price_P0', '100.0', NOW()),
(gen_random_uuid(), 'base_capital_usdc', '1000.0', NOW()),

-- Grid levels configuration
(gen_random_uuid(), 'levels_pct', '[-5.0, -10.0, -15.0, -20.0, -25.0, -30.0]', NOW()),
(gen_random_uuid(), 'alloc_weights', '[0.08, 0.12, 0.15, 0.18, 0.22, 0.25]', NOW()),

-- Take-profit parameters
(gen_random_uuid(), 'tp_start_pct', '1.2', NOW()),
(gen_random_uuid(), 'tp_step_pct', '0.15', NOW()),
(gen_random_uuid(), 'tp_min_pct', '0.3', NOW()),

-- Exit distribution
(gen_random_uuid(), 'exit_tp1_portion', '0.40', NOW()),
(gen_random_uuid(), 'exit_tp2_portion', '0.35', NOW()),
(gen_random_uuid(), 'exit_trail_portion', '0.25', NOW()),
(gen_random_uuid(), 'trailing_callback_pct', '0.8', NOW()),

-- Order placement strategy
(gen_random_uuid(), 'place_mode', '"only_next_k"', NOW()),
(gen_random_uuid(), 'k_next', '2', NOW()),

-- Safety settings
(gen_random_uuid(), 'hard_stop_mode', '"none"', NOW()),
(gen_random_uuid(), 'hard_stop_threshold_pct', '-35.0', NOW()),
(gen_random_uuid(), 'extend_zone_trigger_pct', '-30.0', NOW()),

-- Reanchor settings
(gen_random_uuid(), 'reanchor_on_close', 'true', NOW()),
(gen_random_uuid(), 'anchor_ttl_hours', '24', NOW()),

-- Operational parameters
(gen_random_uuid(), 'orchestrator_cycle_sec', '10', NOW()),
(gen_random_uuid(), 'system_status', '{"status": "running"}', NOW())
;

-- Create initial basket for testnet
INSERT INTO baskets (id, symbol, anchor_price, status, config, created_at) VALUES
(
    gen_random_uuid(),
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
    NOW()
);

-- Display results
SELECT 'Bot configuration initialized:' as status;
SELECT key, value FROM bot_config ORDER BY key;

SELECT 'Initial basket created:' as status;
SELECT id, symbol, anchor_price, status, created_at FROM baskets;

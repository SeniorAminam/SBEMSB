# SBEMSB (Smart Building Energy Management Telegram Bot)

سامانه مدیریت هوشمند مصرف انرژی ساختمان با ربات تلگرام + شبیه‌سازی IoT.

## Features

- Admin/Manager/Consumer panels
- IoT simulation (water/electricity/gas)
- Digital Twin (scenario/season/eco/devices)
- Consumption analysis (over-consumption / leak suspected / low credit)
- Energy credits system + dynamic pricing
- Carbon footprint feature (unit + building)
- Admin tools inside bot (Seed/Simulate/Presets/DB Status/Reward)

## Requirements

- PHP >= 8.1
- MySQL >= 8
- Composer

## Setup

1) Install dependencies

```bash
composer install
```

2) Create `.env`

- Copy `.env.example` to `.env` and fill values.
- **Never commit `.env`** (already ignored).

3) Create database

- Create database `smart_building`
- Import schema:

```bash
mysql -u root -p smart_building < migrations/schema.sql
```

## Run

### Webhook (production)

- Deploy project
- Set `TELEGRAM_WEBHOOK_URL` and `TELEGRAM_WEBHOOK_SECRET` in `.env`
- Point Telegram webhook to `index.php`

### Polling (local)

```bash
php tools/run_polling.php
```

## Admin Access

- First user becomes admin if no admin exists.
- Or set `ADMIN_TELEGRAM_IDS` in `.env` as comma-separated Telegram IDs.

## Admin Tools (inside bot)

Admin Panel → `🧪 ابزارها`

- Seed sample data
- Simulate system
- Simulation presets (guest/high/low/reset)
- DB status
- Reward low consumers

## License

MIT

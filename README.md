# AAISP SMS Proxy

A self-hosted proxy enabling two-way SMS in Groundwire via AAISP VoIP numbers. It:

- Translates AAISP's plain-text SMS API into Groundwire-compatible XML for outbound messages
- Receives inbound SMS webhooks from AAISP and stores them for Groundwire to fetch
- Sends push notifications to Groundwire via Acrobits' push service when a message arrives

AAISP credentials are kept server-side and never sent to the app.

**Requirements:**
- An [Andrews & Arnold (AAISP)](https://aa.net.uk) VoIP number with SMS enabled
- [Groundwire](https://www.acrobits.net/groundwire/) — available for [iOS](https://apps.apple.com/app/groundwire-voip-sip-softphone/id378503081) and [Android](https://play.google.com/store/apps/details?id=cz.acrobits.softphone.aliengroundwire)
- A server to host the proxy (Docker + a reverse proxy such as Caddy)

---

## Architecture

```
Outbound:  Groundwire → index.php → AAISP SMS API → recipient
Inbound:   sender → AAISP → receive.php → SQLite → fetch.php → Groundwire
Push:      receive.php → pnm.cloudsoftphone.com → iOS notification → Groundwire
Token reg: Groundwire → push_register.php → SQLite
Heartbeat: cron → heartbeat.php → pnm.cloudsoftphone.com → notification → Groundwire
```

---

## Groundwire Configuration

Apply the same URLs to **each** SIP account in Groundwire. The `%account[username]%` placeholder is automatically replaced by Groundwire with the SIP username (your AAISP number), so no per-account customisation is needed.

### SMS Sender
Settings > Account > Web Services > SMS Sender

| Field | Value |
|---|---|
| URL | `https://your-domain.example.com/index.php?token=YOUR_SMS_TOKEN&account=%account[username]%&da=%sms_to%&ud=%sms_body%` |
| Method | GET |
| Everything else | leave blank |

### SMS Fetcher
Settings > Account > Web Services > SMS Fetcher

| Field | Value |
|---|---|
| URL | `https://your-domain.example.com/fetch.php?token=YOUR_SMS_TOKEN&account=%account[username]%&last_known_sms_id=%last_known_sms_id%` |
| Method | GET |
| Everything else | leave blank |

Groundwire polls this automatically. Observed behaviour:
- **Active use**: polls every ~30–60 seconds
- **Idle**: backs off to ~3 minute intervals
- **Immediate poll**: opening the Groundwire messages screen triggers a fetch right away

> **Note:** `fetch.php` deletes messages after returning them. Use `status.php` for diagnostics instead (see below).

### Push Token Reporter
Settings > Account > Web Services > Push Token Reporter

| Field | Value |
|---|---|
| URL | `https://your-domain.example.com/push_register.php?token=YOUR_SMS_TOKEN&account=%account[username]%&selector=%selector%&push_token=%pushTokenOther%&push_appid=%pushappid_other%` |
| Method | GET |
| Everything else | leave blank |

Groundwire calls this on startup to register its push token. Once registered, `receive.php` will send a push notification via `pnm.cloudsoftphone.com` whenever a message arrives, waking the app. On iOS 13+, the notification will appear as an alert even if the app is backgrounded.

---

## AAISP Control Panel: Inbound SMS Target

Set the inbound SMS target **per number** in the AAISP control panel. Use a separate token per number so each has independent credentials:

```
Number 1: https://your-domain.example.com/receive.php?token=YOUR_RECEIVE_TOKEN_1
Number 2: https://your-domain.example.com/receive.php?token=YOUR_RECEIVE_TOKEN_2
```

> **Important:** Configure this at the per-number level only, not at the account level. Configuring it at both levels causes AAISP to deliver each message twice, resulting in duplicate push notifications.

AAISP retries webhook delivery on a fixed schedule (~30s, 30s, then longer). The proxy deduplicates by checking for identical sender, recipient, and message content within a 2-minute window, so retries are silently discarded.

---

## Fresh deployment

1. Clone the repo and copy the env file:
   ```bash
   git clone git@github.com:dannymcc/aaisp-sms-proxy.git
   cd aaisp-sms-proxy
   cp .env.example .env
   # Edit .env with your credentials and tokens
   ```

2. Create the data directory with correct permissions:
   ```bash
   mkdir -p data
   sudo chown www-data:www-data data
   sudo chmod 750 data
   ```

3. Build and start:
   ```bash
   docker compose up -d --build
   ```

4. Configure your reverse proxy (e.g. Caddy) to forward your domain to the container on port 80.

5. Open Groundwire to trigger push token registration, then send a test SMS.

> The container has `restart: unless-stopped` so it will recover automatically from crashes and host reboots. The SQLite tables are created automatically on first use. Push tokens are re-registered by Groundwire the next time the app is opened.

---

## Adding a new AAISP number

1. Edit `.env` and add:
   ```
   AAISP_44XXXXXXXXXX_USERNAME=+44XXXXXXXXXX
   AAISP_44XXXXXXXXXX_PASSWORD=thepassword
   RECEIVE_TOKEN_3=another-random-token
   ```
   Note: the key is the number without the leading + (e.g. 447911000000 for +447911000000)

2. Recreate the container to load the new env:
   ```
   docker compose up -d --force-recreate
   ```

3. In Groundwire, add the account and apply the same URLs above — no changes to the URLs needed.

4. In the AAISP control panel, set the per-number inbound SMS target using the new token.

---

## Diagnostics and operations

### Health check

`health.php` confirms the container and SQLite database are up:

```
https://your-domain.example.com/health.php
```

Returns `{"status":"ok"}` (HTTP 200) when healthy, or `{"status":"error"}` (HTTP 500) if the database is unreachable. Suitable for use with uptime monitors.

### Checking pending messages

`status.php` shows messages currently queued in SQLite without deleting them:

```
https://your-domain.example.com/status.php?token=YOUR_SMS_TOKEN
```

Returns a JSON array of up to 20 pending messages. If a message appears here but not in Groundwire, wait for the next poll or open the Groundwire messages screen to trigger one immediately.

Rate limited to **10 requests per IP per minute**.

### Message pruning

A `prune.php` script deletes messages older than 14 days as a safety net for any that were never fetched. It is run nightly via cron:

```
0 3 * * * docker exec aaisp-sms-proxy php /var/www/html/prune.php >> /home/danny/docker/aaisp-sms-proxy/data/prune.log 2>&1
```

It also clears stale rate_limit entries older than 1 day. Under normal operation, `fetch.php` deletes messages immediately after returning them to Groundwire, so the pruner should rarely find anything to remove.

### Heartbeat

`heartbeat.php` sends a push notification to Groundwire once a day to confirm the server is up and running. It uses the `NotifyGenericTextMessage` verb, which delivers a single notification directly without writing to the database or triggering a poll — so no inbox entry is created and no badge count is shown.

The heartbeat only runs during daytime hours to avoid disturbing you at night. Defaults are 08:00–21:00 in the container's local timezone (`Europe/London`); adjust with `HEARTBEAT_START_HOUR` / `HEARTBEAT_END_HOUR` in `.env`. Set `HEARTBEAT_ENABLED=false` to disable entirely.

Run via cron at a fixed daytime hour (the script does its own window check as a safety net):

```
0 13 * * * docker exec aaisp-sms-proxy php /var/www/html/heartbeat.php >> /home/danny/docker/aaisp-sms-proxy/data/heartbeat.log 2>&1
```

The target account is auto-detected from the first `AAISP_*_USERNAME` in the environment. Override with `HEARTBEAT_ACCOUNT=+44XXXXXXXXXX` if needed. A push token must be registered (i.e. Groundwire must have been opened at least once) for the heartbeat to send.

### Apache log sanitisation

The container mounts a custom Apache log format that strips query strings from access logs. This prevents tokens from appearing in log output. The format logs the request path (`%U`) without the query string, keeping everything else (IP, method, status, etc.) intact.

---

## .env structure

See `.env.example` for the full template.

---

## Data retention

Inbound messages are stored in SQLite only until Groundwire fetches them. Once delivered, they are deleted immediately. A nightly cron job prunes any messages older than 14 days as a safety net. No SMS content is retained indefinitely.

---

## File layout

```
/
├── Dockerfile
├── docker-compose.yml
├── apache.conf             custom log format (strips query strings from access logs)
├── php.ini                 disables display_errors, enables error logging
├── .env                    (not committed — copy from .env.example)
├── .env.example
├── config/
│   ├── index.php           outbound SMS (Groundwire → AAISP)
│   ├── receive.php         inbound webhook (AAISP → proxy, deduplicates retries)
│   ├── fetch.php           message fetcher (Groundwire polls this — deletes on read)
│   ├── push_register.php   stores Groundwire push tokens per account
│   ├── status.php          read-only diagnostic view of pending messages (rate limited)
│   ├── health.php          liveness check — returns {"status":"ok"} if DB is reachable
│   ├── prune.php           deletes messages older than 14 days (run via cron)
│   └── heartbeat.php       sends a daily push notification to confirm the server is running (run via cron)
└── data/                   (not committed — created at runtime)
    └── messages.db         SQLite store for messages and push tokens
```

---

## Licence

MIT. Use for anything, attribution appreciated. No warranty — use at your own risk.

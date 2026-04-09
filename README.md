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

## Checking pending messages (diagnostics)

`status.php` shows messages currently queued without deleting them:

```
https://your-domain.example.com/status.php?token=YOUR_SMS_TOKEN
```

Returns a JSON array of up to 20 pending messages. If a message appears here but not in Groundwire, wait for the next poll or open the Groundwire messages screen to trigger one immediately.

---

## .env structure

See `.env.example` for the full template.

---

## Data retention

Inbound messages are stored in SQLite only until Groundwire fetches them. Once delivered, they are deleted automatically. No SMS content is persisted long-term.

---

## File layout

```
/
├── Dockerfile
├── docker-compose.yml
├── .env                    (not committed — copy from .env.example)
├── .env.example
├── config/
│   ├── index.php           outbound SMS (Groundwire → AAISP)
│   ├── receive.php         inbound webhook (AAISP → proxy, deduplicates retries)
│   ├── fetch.php           message fetcher (Groundwire polls this — deletes on read)
│   ├── push_register.php   stores Groundwire push tokens per account
│   └── status.php          read-only diagnostic view of pending messages
└── data/                   (not committed — created at runtime)
    └── messages.db         SQLite store for messages and push tokens
```

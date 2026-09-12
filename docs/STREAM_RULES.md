# Stream Rules — automatically stop or kick streams

*Stream Rules* extends the Session Control module: on every poll cycle
(every 30 s) the enabled rules are evaluated against all active Jellyfin
sessions. When a rule matches, the session is stopped automatically (or the
device is kicked) and the admin is notified through the configured
notification channels. The pattern comes from Tautulli ("Custom Conditions"
plus JBOPS `kill_stream.py`); the evaluation engine is a PHP port of it.

## The Stream Rules page

The page is admin-only and lists all rules with their kill counters. Actions
per rule: **Edit**, **Disable/Enable**, **Delete**.

### Anatomy of a rule

| Part | Meaning |
|---|---|
| Name | Free-form; appears in the kill notification |
| Up to 4 condition rows | `Field ▸ Operator ▸ Value` — blank rows are skipped |
| Logic string (optional) | How the rows combine, e.g. `{1} and ({2} or {3})` |
| Action | **Stop playback** (playback ends, device stays signed in) or **Kick device** (device is signed out) |

#### Logic string

- `{1}`…`{4}` reference the condition rows (in the order the fields appear in
  the form; completely blank rows are removed when saving).
- `and` binds tighter than `or`, brackets group: `{1} and ({2} or {3})`.
- **An empty logic string** means every row must match (AND).
- Values within a row may be comma-separated — that acts as an OR ("any of
  these match"). `~` stands for the empty string.

## Fields (parameters)

| Parameter | Type | Source |
|---|---|---|
| `user` | Text | Jellyfin username |
| `client` | Text | Client app (e.g. "Jellyfin Android TV") |
| `device` | Text | Device name |
| `title` | Text | Currently playing title |
| `seriesName` | Text | Series (for episodes) |
| `library` | Text | Library label (Movies/Series/…) |
| `playMethod` | Text | `DirectPlay` / `DirectStream` / `Transcode` |
| `quality` | Text | Session quality label |
| `bitrate` | Number | Bitrate in kbps |
| `progressPct` | Number | Playback progress in % |
| `watchedMin` | Number | Minutes watched in this session |
| `ip` | Text | Viewer IP address |
| `ipIndex` | Number | The user's IP slot: 1 = first/established IP, 3 = third new IP … |
| `userIpCount` | Number | Distinct active IPs of this user |
| `userStreams` | Number | Concurrent streams of this user |

The aggregate fields (`ipIndex`, `userIpCount`, `userStreams`) are computed
per poll cycle from the session snapshot — they turn cross-session questions
like "more than 2 IPs" or "more than 3 streams per user" into simple rules.

## Operators

Text: `contains` · `does not contain` · `is` · `is not` · `begins with` ·
`does not begin with` · `ends with` · `does not end with`
Numbers: `is greater than` · `is less than`
(Comparisons are case-insensitive; numeric values are cast before comparing.)

## Example rules

**No transcodes**
```
playMethod | is | transcode        → Stop playback
```

**Max. 2 IPs per user** (the third new IP is stopped, the two established
ones keep watching)
```
ipIndex | is greater than | 2      → Stop playback
```

**Max. 3 concurrent streams per user**
```
userStreams | is greater than | 3  → Stop playback
```

**Block a specific device entirely**
```
device | contains | Fire TV        → Kick device
```

Time-based rules ("block the kids' library after 10 pm") are not possible
yet — clock fields are not mapped as parameters.

## Safety mechanisms

- **Kill guard:** The same session is killed at most once every 15 minutes
  per rule — a session that lingers for a poll cycle or two after its stop
  command does not create an endless loop of kills and notifications.
- **IP slots with memory:** The per-user order of IPs (first seen) is
  persisted. An IP that does not appear for 7 days loses its slot, so
  returning viewers do not block the limit forever. The same IP on multiple
  devices counts once.

## Notifications

Every kill sends a message to all configured channels (Telegram / Pushover /
Discord / Web Push):
> ⛔ Auto-stopped: `<User>` — `"<Title>"` on `<Device>` — rule `"<Name>"` matched.

E-mail is deliberately not included — the channels above already exist with
queue and retries; SMTP would only add value if the channels go unread.
(Jellyfin clients do not reliably show a user-facing reason when a session is
stopped, so the explanation goes to the admin instead of the viewer.)

## Under the hood

- Rules live in the `stream_rules` table (conditions as JSON, logic string,
  action, kill counter), the kill guard in `stream_rule_kills`, IP slots in
  `stream_rule_ips` (schemas are created automatically on the first enforce
  run).
- Execution: `bin/console.php stream-control:enforce` — runs in the same
  poll loop as `history:poll` (see `docker/entrypoint.sh`).
- Engine: `modules/session-control/src/StreamRuleEngine.php` (a port of
  Tautulli's `notify_custom_conditions` and `parse_condition_logic_string`),
  wiring: `StreamRuleEnforcer.php`, editor: `StreamRulesController.php`.

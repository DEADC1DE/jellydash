# Downloads

Downloads monitors SABnzbd, qBittorrent, Transmission, Deluge and NZBGet without changing their queues. Add up to ten named clients in Settings. The sidebar entry appears once a client is configured; a compact indicator on Now Playing shows current download activity. Monitoring is enabled by default.

## Setup

1. If Jellydash login is enabled, sign in with an account that can manage Settings. No Jellydash account is needed when `AUTH_ENABLED=false`.
2. In the Downloads card in Settings, leave **Enable Downloads** checked, then select **Manage clients** and choose the client type.
3. Enter its name and base URL, including a port or reverse-proxy path if needed. Use an address reachable from the Jellydash container. `localhost` inside that container means Jellydash itself. Jellydash accepts a pasted Transmission `/transmission/rpc`, Deluge `/json`, or NZBGet `/jsonrpc` endpoint and stores the base URL.
4. Enter the SABnzbd full API key, qBittorrent Web UI username and password, Transmission RPC username and password, Deluge web password, or NZBGet control password and optional control username. For Deluge, connect to its web interface, not directly to the daemon. That web instance needs exactly one saved daemon connection; the test fails if it has none or several. Keep certificate verification enabled unless this client uses a certificate you explicitly trust but the container cannot verify.
5. Test the connection. Choose all downloads or select categories for SABnzbd and NZBGet, categories or tags for qBittorrent, or labels for Transmission and Deluge. Transmission can match any of several labels; **Unlabelled** matches torrents without labels. Deluge uses one label per torrent and requires its built-in Label plugin for label filtering. If that plugin is unavailable, a saved label filter does not expand to all downloads. Names support up to 256 characters and must match the client exactly.
6. Save. The background collector will populate the page.

Client management follows the same access rules as global Settings. With login enabled, owners and admins can manage clients. With login disabled, anyone who can reach Jellydash can manage them.

Blank credentials on an existing client mean keep the saved credential. Changing the URL or username requires entering it again and testing. Only retain a client's identity and history when moving that same server. Add a new client for a different server.

Disabling a client stops new collection and hides its downloads. Removing it deletes its Jellydash connection and retained download history. Neither action changes anything in the download client.

Clear **Enable Downloads** in Settings to turn off monitoring for every client. This hides the Downloads page and Now Playing indicator and stops collecting new results. Saved client settings and recorded history remain in Jellydash. Turn it on again to resume collection. Jellydash can only recover missed results that the downloader still exposes when it next checks.

## What the page shows

The Docker app runs the Downloads collector on a 15-second schedule; no separate support server is needed. The page reads the local cache every five seconds while visible. It never contacts a downloader during normal viewing. If you run Jellydash locally without the Docker worker, schedule `php bin/console.php downloads:poll` separately. `POLLER_ENABLED=false` disables the built-in collector along with the other background workers, but an external scheduler can still collect while **Enable Downloads** is on. If updates stop, the last known data becomes stale.

The main speed follows the categories, tags or labels selected in each connection's settings. qBittorrent, Transmission and Deluge provide per-download speeds, so Jellydash adds matching speeds together when their current item list is complete. A small Outside filters reading shows excluded download traffic when it is known and nonzero.

SABnzbd and NZBGet report a client total rather than a reliable speed for each download. Jellydash can use that total when all active downloads match, or show zero when none match. If included and excluded downloads run together, the filtered speed stays unavailable and the client total is shown separately. Jellydash does not estimate a category split. Missing speed, progress or ETA values stay unknown; a combined speed is unavailable if any enabled client's contribution is unknown.

Fresh downloading items contribute to the Now Playing indicator. Processing, queued, paused and stalled items remain distinguishable. Completed torrents and seeding activity do not count as active downloads. After a collection failure or a stale cache, the page marks the last known items and stops presenting their speed or ETA as current.

Recent activity shows the newest 20 matching results across enabled clients, labelled Completed, Failed or Needs attention. NZBGet can report Needs attention when the download finished but postprocessing had a warning. Finished Usenet failures appear here instead of staying in the active list. A current torrent with an error stays in the main list because it may resume. Check the downloader for failure details or to retry a download.

Jellydash keeps at most 250 results per client for up to 90 days, including failed attempts. The source's finish time is preferred; when unavailable, the time Jellydash first observed the result is used. Changing filters does not delete recorded activity, but new filters determine what is shown and collected next.

Each update keeps up to 1,000 current items per client. Transmission and Deluge read a full, minimal torrent inventory through their RPC APIs. NZBGet's `history(false)` returns all visible history, with no server-side limit or offset. Ordinary responses are bounded at 2 MiB. NZBGet history is read one record at a time, keeping at most 100 matching recent outcomes in memory. This read has a 32 MiB response limit, a 256 KiB limit per record and a five-second request timeout. It still transfers the visible history from NZBGet; it does not add pagination to that API. If history is too large or cannot be read, live queue and speed updates continue, and Recent activity keeps its saved results with a small update notice. These limits do not affect the downloader itself. If current items may be missing, the page shows a notice directing you to the client's full list. Recent results do not take space away from current items.

qBittorrent 5.2 supports separate checks for checking, moving and errored torrents, so a large seeding library does not hide those jobs. On older supported versions, a library above 1,000 torrents can show the incomplete-list notice because Jellydash cannot confirm that all current jobs were returned.

SABnzbd and qBittorrent recent-history reads use 20-entry pages, looking for 20 matching results within at most 100 source entries per list and a five-second scan budget. SABnzbd checks regular and archived history separately. These history scans normally run once a minute; failed reads retry less often, up to five minutes apart. SABnzbd separately requests unfinished processing jobs on every live poll, so older jobs remain visible while history refreshes less often. If that processing request fails, its current list is marked incomplete. qBittorrent also records confirmed completions already present in its live inventory. NZBGet history follows the same one-minute refresh and retry schedule. Transmission and Deluge reuse their current torrent inventory. The torrent adapters and NZBGet keep up to 100 newest matching terminal results per update. The page shows up to 20 matching results from those it has recorded, so a narrow filter may show fewer. Downloads removed before Jellydash observes them, or pushed beyond a client's recent window between checks, may be missed.

The adapter targets are Transmission 4.x (4.0 or newer), Deluge 2.x (2.0 or newer), and NZBGet 21 through 26. These are code compatibility bounds, not a claim that every release or server setup has passed live acceptance. The connection test checks identity and access without adding or changing downloads. Transmission, Deluge and NZBGet use read-only RPC methods, even where the protocol sends the request with HTTP POST.

## Updates and credential storage

Saved clients, credentials and reusable downloader sessions use the existing Jellydash database. Downloads needs no separate encryption key, new volume mapping or required environment variables. Update Compose installations as usual with `docker compose pull && docker compose up -d`, or use Unraid's normal container update. Keep the database storage you already use for Jellydash; the supplied Compose setups and Unraid template already persist it.

Credentials and downloader sessions are stored unencrypted in the database. They stay server-side and are excluded from monitor and client-management responses. Database backups contain these values, so keep backups private. Your existing Jellydash login or other access protection still applies.

If you used an earlier Downloads build with encrypted credentials, Jellydash converts them when it reads the connections and the original key is still available. If that key was lost during container replacement, edit the affected client and enter its credential again. Other clients remain usable, and new saves need no key file.

## Existing SABnzbd environment setup

`SAB_API_URL` and `SAB_API_KEY`, with optional `SAB_VERIFY_SSL=true`, expose one environment-backed client. Empty or incomplete settings leave it unconfigured. Editing it in the UI creates an explicit override; keeping the credential blank retains the environment reference rather than copying its key into the database.

Disabling that client creates an override so it stays disabled. **Use environment settings** removes the override and returns to the current environment configuration. New clients should normally be added through Settings.

The old private module named `downloads` is no longer loaded. Remove its mount from a custom Compose override when adopting the core feature. Other modules continue to load normally.

# Monitoring exclusions

Monitoring exclusions let you hide a Jellyfin user's activity from Jellydash and stop recording new activity for that user.

An excluded user is hidden from:

- Now Playing
- History
- Statistics
- History CSV exports

Jellydash also skips new background collection and history imports for that user. Rows recorded before the exclusion stay in the database. If you remove the exclusion later, that earlier activity appears again. Activity skipped while the exclusion was active cannot be reconstructed by Jellydash.

Open **Settings > Exclusions > Monitoring** to select users that Jellydash has already seen. You can enter additional usernames in the comma-separated field. Saving an empty selection overrides an `IGNORE_USERS` value from the environment, so the Settings page remains the active source after you save it.

For an initial environment-based setup, use a comma-separated list:

```env
IGNORE_USERS=Alice,Bob
```

Matching uses the complete username and ignores letter case. For example, `Alice` matches `alice`, but it does not match `Alice TV`. User IDs and partial-name matching are not supported.

Exclusions follow usernames, not accounts. If a Jellyfin account is renamed, update its exclusion to the new username.

Monitoring exclusions and notification exclusions are separate settings. `IGNORE_USERS` controls collection and visibility. `PUSH_IGNORE_USERS` prevents playback alerts for selected users, and `PUSH_IGNORE_LIBRARIES` prevents alerts for selected libraries. Both notification settings leave monitoring unchanged.

Open **Settings > Exclusions > Notifications** to select a library, such as Music. You can also enter a library name when Jellyfin does not list it. The match uses the complete library name and ignores letter case. If you rename the library, update the exclusion. Jellydash waits for a new play's library to be identified before sending an alert when any library exclusion is active. If it remains unknown for 10 minutes, the alert expires. The play remains in History and Statistics.
For manually entered names or `PUSH_IGNORE_LIBRARIES`, put a library name containing a comma in double quotes.

## Background theme media

Jellydash excludes confirmed theme songs and theme videos from playback monitoring. These are the background files a media server plays while you browse a movie or series. Regular music playback remains monitored.

Theme detection uses the server's theme-media classification or a recognised file path, such as an audio file named `theme.mp3`, audio in a `theme-music` folder, or a video in a `backdrops` folder. A title such as "Theme" alone is not enough to hide a play. The same rules apply to metadata returned by Jellyfin and Emby.

Confirmed theme items are hidden from Now Playing, History, Statistics, library playback summaries and History CSV exports. They are also excluded from playback notifications and new history writes.

The background history poller checks older recorded items in small batches. When it identifies a theme item, its existing plays disappear from the visible history and totals. The original history rows stay in the database; no manual cleanup is needed.

Older entries may remain visible until that check reaches them. If the server is unavailable, an item has been removed, or its metadata does not identify the file, Jellydash keeps the entry visible and retries later. It does not guess from the title or delete history to resolve an uncertain match.

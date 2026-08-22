# History CSV format

Jellydash can export every play matching the filters chosen in the History
export dialog. The dialog starts with the filters currently used on the History
page, shows the exact number of matching plays and can reset to all History.
The download is not limited to the current page.

The file uses UTF-8 with a byte order mark, comma separators, RFC 4180 quoting
and one play per row. Dates use `YYYY-MM-DD HH:MM:SS` in the Jellydash
application timezone. The first column identifies the format version. Version 1
uses this exact column order:

```text
jellydash_history_version
session_key
started_at
updated_at
ended_at
user_id
user_name
item_id
item_type
series_name
item_name
season_ep
library
library_resolved_at
play_method
play_method_detail
client
device
source_video_codec
source_audio_codec
source_container
target_video_codec
target_audio_codec
target_container
is_video_direct
is_audio_direct
transcode_reasons
watched_sec
runtime_sec
is_finished
```

`jellydash_history_version` is `1` for every exported play. Optional values are
empty. Boolean values are `1`, `0`, or empty when Jellyfin did not report them.
Transcode reasons remain JSON so a future Jellydash importer can restore the
original list without guessing where one reason ends and another starts.

## Spreadsheet safety

Media titles, user names and other text can begin with characters spreadsheet
apps interpret as formulas. Jellydash prefixes those cells with an apostrophe.
A literal value that already begins with an apostrophe receives a second one.
This keeps the export safe to open and makes the transformation reversible for
the native importer.

The database row ID and notification flag are intentionally excluded. They are
local implementation details and must not be reused when moving plays between
Jellydash installations.

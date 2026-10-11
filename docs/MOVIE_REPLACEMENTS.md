# Replacing a deleted movie

Deleting and re-adding a movie in Jellyfin can give it a different item ID. Jellydash keeps the ID recorded with each play, so the old and current items appear separately in Statistics. Artwork for the deleted ID may also stop loading.

Use a movie replacement mapping when you've checked that a deleted item and its current replacement are the same movie. The mapping combines their Statistics and monthly recap entries, includes all their plays in the movie's History drilldown, and loads artwork from the current item.

This is a manual repair. Jellydash does not match movies by title or automatically reconcile provider IDs. Older history has no saved TMDB or IMDb identity, so the confirmation flag records your decision that the two IDs represent the same movie. Separate movies with the same title stay separate unless you explicitly link them.

## Preview the replacement

Run the command from the Jellydash project directory. It uses the database and Jellyfin server configured for that installation.

```sh
php bin/console.php history:replace-movie OLD_ITEM_ID CURRENT_ITEM_ID
```

Copy the old item ID exactly as recorded, including its letter case and hyphens. The current ID is checked against Jellyfin, and the mapping uses the ID returned by the server. The preview shows the old movie group's recorded titles and play count, the current movie's title and provider IDs, and any existing mapping. It does not save a replacement. Jellyfin must be reachable, the old ID must have recorded movie plays or incoming mappings from recorded movies, and it must no longer exist in Jellyfin. The current ID must identify one existing movie. Non-movie history on either side, invalid metadata and failed requests stop the repair.

Check the IDs against the movie you intend to repair. A matching title alone is not enough, especially for remakes or separate editions. When you're sure, save the mapping:

```sh
php bin/console.php history:replace-movie OLD_ITEM_ID CURRENT_ITEM_ID --apply --confirm-same-movie
```

The command checks the metadata again before saving. Repeating the same mapping is safe. If the movie is replaced again, link the former current ID to the new current ID. The existing mappings follow the new replacement.

## Undo a mapping

Preview the mapping to remove:

```sh
php bin/console.php history:unlink-movie OLD_ITEM_ID
```

Then remove it:

```sh
php bin/console.php history:unlink-movie OLD_ITEM_ID --apply
```

This removes the mapping for that old ID. Its plays become a separate item again, and its poster may be unavailable if Jellyfin no longer has its artwork.

## History and backups

Replacement mappings do not rewrite original history IDs, delete plays, create playback alerts, or modify Jellyfin. Watch time and import deduplication still use the recorded playback data. Filtered History exports include all plays in the replacement group, while each CSV row keeps its original item ID.

Mappings belong to the configured Jellyfin server. Keep the Jellyfin URL stable so the installation continues to use the same mappings. Back up the full Jellydash database to preserve them. MariaDB-to-SQLite migration preserves mappings; a History CSV alone does not include them, so importing that CSV into another installation does not restore replacement groups.

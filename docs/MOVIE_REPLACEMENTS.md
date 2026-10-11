# Replacing a deleted movie

Deleting and re-adding a movie in Jellyfin can give it a different item ID. Jellydash keeps the ID recorded with each play, so the old and current items appear separately in Statistics. Artwork for the deleted ID may also stop loading.

Jellydash checks for replacements automatically. It saves movie identity metadata while the item is available, then uses matching TMDB or IMDb IDs to reconnect a deleted item to its current replacement. The mapping combines their Statistics and monthly recap entries, includes all their plays in the movie's History drilldown, and loads artwork from the current item.

Checks run in small batches through the normal history worker. Opening Settings, History, Statistics or Now Playing as a manager also advances them. There's no separate command to run. The first check can take a while on a large library, and Jellydash checks the library twice before making an automatic repair.

Automatic repair requires one matching current movie, a confirmed missing old item, and no conflicting identity, year or library information. Failed requests and incomplete scans are retried. Multiple copies or uncertain matches stay separate. A replacement can be found before anyone plays it.

## Older history and uncertain matches

Open **Settings > Movie replacements** to review suggested movies. History and Statistics show a link there when suggestions need attention. This section is available to managers, or anyone using an installation with authentication disabled.

Older deleted items may have only a saved title, with no TMDB or IMDb identity. Jellydash can suggest a current movie with that title, but it needs your confirmation. Check its title, year and poster, then choose **Use this movie**. Choose **Keep separate** if it isn't the same movie. You don't need to look up item IDs or open a terminal.

If no suitable movie is found, the original history stays saved. Jellydash can't reliably identify every deleted item from its title alone.

## Undo a repair

Expand **Reconnected movies** in Settings and choose **Undo**. For repairs made here or automatically, this restores the affected replacement group to its previous state. Every original play stays saved, and artwork may become unavailable again for deleted items.

Undone and dismissed entries appear under **Kept separate**. They stay separate during later checks until you choose **Check again**.

## Advanced command-line repair

The commands below remain available for installations that need an explicit mapping. The Settings workflow handles ordinary replacements.

### Preview the replacement

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

### Remove a mapping with the command line

Preview the mapping to remove:

```sh
php bin/console.php history:unlink-movie OLD_ITEM_ID
```

Then remove it:

```sh
php bin/console.php history:unlink-movie OLD_ITEM_ID --apply
```

This removes only the mapping for that old ID. Its plays become a separate item again, and its poster may be unavailable if Jellyfin no longer has its artwork. Use **Undo** in Settings when you also want to prevent automatic checks from recreating the link.

## History and backups

Replacement mappings do not rewrite original history IDs, delete plays, create playback alerts, or modify Jellyfin. Watch time and import deduplication still use the recorded playback data. Filtered History exports include all plays in the replacement group, while each CSV row keeps its original item ID.

Mappings and saved movie metadata belong to the configured Jellyfin server. Keep the Jellyfin URL stable so the installation continues to use them. If a different Jellyfin server identity appears at that address, recovery pauses and existing mappings stop being applied.

Back up the full Jellydash database to preserve mappings, metadata and recovery decisions. MariaDB-to-SQLite migration preserves them. A History CSV alone does not include them, so importing that CSV into another installation does not restore replacement groups or saved identity evidence.

# Jellydash on Unraid

This template installs the Jellydash Docker image with SQLite, so it runs as one container. It is intended for a new installation. If Jellydash already runs through Docker Compose on your Unraid server, keep using that setup. Installing this template does not move your existing database or settings.

## Before you install

Create an API key in Jellyfin under **Dashboard > API Keys**. The template asks for your Jellyfin URL and the key. Use an address Jellydash can reach from its container, such as your server's LAN IP and Jellyfin port. `localhost` inside the container points back to Jellydash.

Set **Timezone** to your IANA timezone, such as `Europe/Prague`, so History and Statistics use your local date boundaries. The default is UTC.

The template stores the SQLite database and runtime files in `/mnt/user/appdata/jellydash/var`, and uploads in `/mnt/user/appdata/jellydash/uploads`. You can change those host paths during installation. Keep both paths on persistent storage and include them in your backups. The database file is inside `var/data`.

Jellydash listens on container port 80. The template maps it to host port 8080 by default. Change the host port if 8080 is already in use, then open Jellydash from its WebUI link.

## Optional login

Login is off by default. If you enable it in the template's advanced settings, also enter an initial admin username and password before starting the container. The startup script creates the admin user only if it does not already exist. You can change the password inside Jellydash later.

If you make Jellydash reachable from outside your trusted network, read the [optional login guidance](https://github.com/themartz90/jellydash#optional-login) first.

## Updates and support

The template uses `ghcr.io/themartz90/jellydash:latest`. Unraid's normal container update action pulls new releases. Keep the appdata and uploads paths mapped when updating.

For optional Jellyseerr, notification channels, or a MariaDB setup, see the [main Jellydash README](https://github.com/themartz90/jellydash#readme). The Unraid template starts with SQLite and the settings needed for a basic Jellyfin connection. Ask for help in [Jellydash Discussions](https://github.com/themartz90/jellydash/discussions).

The Jellydash mascot is based on a [jellyfish icon](https://www.flaticon.com/free-icon/jellyfish_2977310) by Magnific from [Flaticon](https://www.flaticon.com), modified for this project. The code is MIT licensed; the mascot artwork is covered by its separate Flaticon license.

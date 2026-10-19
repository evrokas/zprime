# ZPrime

A personal home dashboard, built mainly for phones and also laid out for desktop. It is meant to be
the home screen of every device you use.

- **Header:** live clock, date and the temperature at your home location.
- **Websites:** a grid of favorite sites. Add, edit and remove them.
- **Places:** favorite places with a **Drive** button that opens Google Maps driving directions
  (the Maps app on a phone). A place can be a street address, coordinates, or a pasted Google Maps
  link that contains coordinates.
- **Sign-in:** username and password, with "keep me signed in" for 30 days. Several accounts are
  supported, each with its own websites and places.
- **Installable:** add it to a phone's home screen and it opens full-screen like an app.

Built on the [ZeusFW](https://github.com/evrokas/zeusfw) framework, like ZPMS. Plain PHP, MariaDB/MySQL,
vanilla JS and CSS. No Composer, no npm.

## Install

ZPrime expects the shared ZeusFW checkout on the server, linked in two places (both are gitignored):

```sh
ln -s /path/to/common/zeusfw fw          # in the ZPrime root
ln -s ../fw/core web/core                # in the ZPrime root
```

The web server's document root is `web/` (Apache `mod_rewrite`, see `web/.htaccess`; PHP 8.1 or newer
with `pdo_mysql`, `curl`, `mbstring`).

```sh
cp config/site.info.yaml.in config/site.info.yaml   # set home.lat / home.lon / home.label
fw/bin/init.sh      # asks for DB credentials, writes config/db.php, creates the database
fw/bin/update.sh    # generates the tables from web/classes/yaml/*.yaml and the ZeusFW core tables
php bin/setup.php --user=evangelos --name="Evangelos Rokas" --email=you@example.com
```

`bin/setup.php` asks for a password (at least 10 characters), creates the `owner` role and your
account. Run it again to reset a password or unlock an account. The owner role also opens
`/admin/users` for adding more accounts.

Open the site, sign in, then use the browser's "Add to Home Screen". An installed iPhone app keeps its
own cookies, so sign in once inside the installed app; the 30-day cookie then applies to it.

### Apache

`deploy/apache-zprime.conf` is a plain-HTTP virtual host (no SSL directives, so `certbot --apache`
can add them later). Edit the hostnames and the `/var/www/zprime` paths, then:

```sh
sudo a2enmod rewrite headers expires
sudo cp deploy/apache-zprime.conf /etc/apache2/sites-available/zprime.conf
sudo a2ensite zprime && sudo apache2ctl configtest && sudo systemctl reload apache2
sudo certbot --apache -d dash.example.com      # once the hostname points here
```

It serves only `web/`, lets only `index.php` run, and refuses `web/core/**/*.php`, YAML and SQL, plus
`classes/`, `cache/`, `files/` and dotfiles. List extra hostnames as `ServerAlias` (and pass each to
certbot). The user Apache runs as (`www-data` on Debian) must be able to write `web/cache` and `web/files`.

**Run command-line scripts as the web user**, for example `sudo -u www-data php bin/setup.php ...`.
ZeusFW appends to `/tmp/fwkernel.log`; if root creates that file first, `www-data` cannot open it
and every request ends in a 500. If you already hit this: `sudo rm /tmp/fwkernel.log`.

### Configuration

`config/site.info.yaml` (see the `.in` template for comments):

| Key | Meaning |
|---|---|
| `remember_me_days` | Lifetime of the "keep me signed in" cookie. 30 here; ZeusFW's default is 10. |
| `home.lat`, `home.lon`, `home.label` | Where the temperature is read for. |
| `temperature_unit` | `celsius` or `fahrenheit`. |
| `weather_cache_seconds` | How long a reading is reused (default 600). |

Weather comes from [Open-Meteo](https://open-meteo.com/) (no API key). The server fetches it and caches
it in `web/cache/`, so many open devices cost one request per interval. If the service is down the last
reading is shown, and failed fetches are not retried for a minute.

### Several hostnames

The same install can be served on more than one hostname and the same accounts sign in on each.
Browser cookies belong to one hostname, so signing in on one does not sign you in on another: each
hostname keeps its own 30-day cookie. True single sign-on would use the ErnsAuth module in ZeusFW.

## Backups and monitoring (zops)

`deploy/` holds the [zops](https://github.com/evrokas/zops) handlers, the same way ZPMS does:
`backup-handler.php` (database dump and config), `health-handler.php`, a reference
`backup.conf.example` and `zprime-backup.cron`. Clone zops into `lib/zops` and follow its
`docs/INSTALL.md`. These handlers were checked for syntax only; run `zops-doctor --site=zprime` after
installing.

## Layout

```
config/            db.php.in, db.test.php.in, site.info.yaml.in, settings.info.yaml (routes, regions, modules)
sql/               msql.sh and friends used by fw/bin/*.sh
bin/               setup.php (first account), run_tests.sh
deploy/            Apache virtual host, zops handlers
tests/             regression suite (see tests/README.md)
web/
  index.php        boots ZeusFW: new Kernel(...)->boot([...])
  dash.php         route handlers
  dash_lib.php     validation, Google Maps URLs, weather lookup + cache
  ClassesEx.php    owner-scoped queries on the generated entity classes
  classes/yaml/    dash_links.yaml, dash_places.yaml (the tables)
  modules/         clock_weather, favorite_links, favorite_places (one ZeusFW module per block)
  templates/       page shell, login form, one template per block
  css/ js/         dashboard.css, dashboard.js
  manifest.webmanifest, assets/   installable-app files and icons
```

### Adding a block later

A new block is a module directory under `web/modules/<name>/` (a `<name>.info.yaml` and a class
extending `moduleClass`), a template under `web/templates/modules/`, and two lines in
`config/settings.info.yaml`: the name under `modules:` and a slot under `structure:`. A block that needs
data adds a YAML table in `web/classes/yaml/` and runs `fw/bin/update.sh`.

## Security notes

- Every website and place query includes the signed-in user's id, so one account cannot read or change
  another's rows.
- Website addresses must be http or https. `javascript:`, `data:` and similar are rejected.
- Every write is a POST with a CSRF token. Output is HTML-escaped.
- Sign-in locks an account after 5 wrong passwords (ZeusFW's lockout). Unlock with `bin/setup.php`.
- Anything a person types is stored and shown as text only.

## Tests

```sh
bin/run_tests.sh                 # static checks, then functional tests
bin/run_tests.sh --static-only
```

See `tests/README.md` for the one-time test database setup.

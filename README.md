# ZPrime

A personal home dashboard, built mainly for phones and also laid out for desktop. It is meant to be
the home screen of every device you use.

- **Header:** live clock, date and the temperature at your home location.
- **Websites:** a vertical list of favorite sites, each with the site's own icon. Add, edit and remove them. The edit dialog has a **Refresh icon** button.
- **Ordering:** tap **Edit** on a block. Drag an item by its handle (the three-bar button) with a finger or the
  mouse. The order is saved per account and kept across devices.
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

### Update button (git pull)

With `git_pull_button: true` in `config/site.info.yaml`, the owner account sees a small **Update** link in the
header. It asks for confirmation, then runs `git pull --ff-only` in this installation's folder and shows git's
output. It never merges or rewrites local work: if the checkout has local commits or edits that conflict, the
pull is refused and nothing changes. It does not run database changes; if the update touches
`web/classes/yaml/`, the result says to run `fw/bin/update.sh`.

It is **off by default**, and only an account whose role is a superuser (the `owner` role from
`bin/setup.php`) can use it; every other account gets 403, and with the setting off the route answers 404.
Turn it on only if you accept this: whoever can sign in as the owner can make the server pull whatever is on the
remote branch, so protect that remote and the owner password.

What the server needs before the button can work:

- The web server's user (`www-data` on Debian) must be able to write to the checkout (at least `.git/` and the
  tracked files), and git must accept the folder: if the checkout belongs to another user git says
  "dubious ownership"; either own it as `www-data` or run, once,
  `sudo -u www-data git config --global --add safe.directory /var/www/zprime`.
- The remote must be reachable without a prompt: an https URL with a stored token
  (`git config credential.helper store`) or an ssh deploy key without a passphrase, for that same user.
  Prompts are switched off, so a missing credential fails at once with git's message.
- The checkout must be on a branch that tracks a remote branch (`git branch -u origin/main`).

The pull has a 60 second limit, one runs at a time, and each request is written to the server's error log.
The shared ZeusFW folder (`fw`) is a separate checkout and is not touched.

### Website icons

The server fetches each site's icon (the `<link rel="icon">` or Apple touch icon it declares, closest to
64 px, else `/favicon.ico`) and keeps it in the database, so your browser never contacts those sites.
Saving a website is instant; the page fetches missing icons in the background, one at a time, and tries
each link once. **Refresh icon** in the edit dialog fetches again; if that fails the old icon stays.
Changing a link to another hostname clears its icon and fetches the new one. PNG, GIF, JPEG, WebP and ICO
are accepted up to 64 KB. SVG is refused because it can carry script.

Because the address is typed by a person, the fetcher is guarded against being aimed at your own network:
http(s) on ports 80 and 443 only, every address a hostname resolves to must be public, the checked address
is the one connected to, redirects are re-checked at each hop, and sizes and times are capped. Sites that
only exist on a private network (a router page, `localhost`) therefore get the letter tile, not an icon.

`ZPRIME_FETCH_ALLOW_PRIVATE=1` switches that guard off. It exists for the test suite and must never be set
on a server.

**Upgrading an install from before icons:** run `fw/bin/update.sh` and apply the three `ADD icon...`
statements it proposes for `dash_links` (it also lists `cdate` "changes" for both tables; those are the
framework's `DATETIME`/`datetime` spelling noise and can be skipped).

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

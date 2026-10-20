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

`bin/setup.php` asks for a password (at least 10 characters) and creates the account. The first account
ever created becomes the **owner** (the superuser role). Run it again to reset a password or unlock an
account.

### Adding more people

Each person gets their own account with their own websites and places. Two ways:

**On the web (as the owner):** sign in, tap **Users** in the header, then **Add new**. Fill in name, email,
username and a password and press Save. **Active** is ticked for you; leave it ticked or the account cannot
sign in. Tell the person their username and password; there is no email invitation. They sign in at the
same address, tick "Keep me signed in" if they want, and see an empty dashboard of their own.

**On the command line:** `sudo -u www-data php bin/setup.php --user=maria --name="Maria"` asks for a
password. Later accounts are ordinary users; add `--owner` only for someone who should be able to manage
users.

Things to know:

- An **ordinary user** can use the dashboard and nothing else: no Users link, and
  `/admin/*` answers "unauthorized". Give someone the **owner** role (Users, Edit, Roles) only if you trust
  them with every account.
- **Switching someone off:** edit the user and untick **Active** (or tick **Expired**). They are signed out on
  their next request, their 30-day "keep me signed in" cookies stop working, and they cannot sign in again
  until you switch them back on. **Delete** removes the account and ends its session; their websites and
  places stay in the database but nobody can reach them.
- **Forgot a password:** as the owner, edit the user and type a new password. **Locked out:** five wrong
  passwords lock an account, and the web form cannot unlock it (changing the password there does not reset
  the count). Run `sudo -u www-data php bin/setup.php --user=name` on the server: it sets a new password,
  unlocks the account and switches it on. That is also the way back in if the owner is ever locked out or
  switches themselves off.
- The Delete button asks for confirmation. There is no undo.

Open the site, sign in, then use the browser's "Add to Home Screen". An installed iPhone app keeps its
own cookies, so sign in once inside the installed app; the 30-day cookie then applies to it.

### Icons

`design/app-icon-source.png` holds the icon twice, large and small. `bin/make_icons.py` (a developer tool;
needs Python with Pillow and numpy) cuts them out and writes every icon file:

| File | Made from | Used for |
|---|---|---|
| `web/assets/icon-512.png`, `icon-192.png` | the large icon | the web app manifest (home-screen icon) |
| `web/assets/icon-maskable-512.png` | the large icon, on a full-bleed background | Android icon shapes (circle, squircle) |
| `web/assets/apple-touch-icon.png` | the large icon, square | iPhone/iPad home screen |
| `web/assets/favicon-32.png`, `favicon-48.png`, `web/favicon.ico` | the **small** icon | browser tabs and bookmarks |

To change the icon, replace the source picture, adjust the two crop boxes at the top of the script if the
layout changed, and run `python3 bin/make_icons.py`. Phones keep the old icon of an app already on the home
screen: remove it and add it again.

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

## Weather forecast

The **Forecast** block shows the next 24 hours (temperature, conditions, chance of rain, scrollable) and the next 7 days
(conditions, low/high range bars, rain and wind) for the home location set in `config/site.info.yaml` (`home:`).
Data comes from Open-Meteo (no key), cached server-side for `forecast_cache_seconds` (default 1800, minimum 300), so any
number of open devices cost one request per window. If the service is down the last forecast is shown, marked
"Last known forecast", and a failed refresh is not retried for a minute. Icons are emoji by default (fog is 🌁); set `forecast_icons: drawn` in `config/site.info.yaml` for built-in line icons that look identical on every device. Each day's condition is worked out from its daytime hours (07:00-21:00), not taken from Open-Meteo's daily code, which is the *most severe* condition of the day and so turns a sunny day into "Fog" because of one foggy early hour: thunderstorm, snow, rain or drizzle count when they last two hours or more, fog only when it fills most of the daytime, otherwise the most common sky. Units follow `temperature_unit`
(celsius: °C, km/h, mm; fahrenheit: °F, mph, in). Times are the place's own local time, not the viewer's.

## System status (sysmonitor)

The **System** block on the home page shows live CPU, memory, disk, temperature and network for the machines
you watch with [sysmonitor](https://github.com/evrokas/sysmonitor), each tile with a sparkline; tap a tile for a
history chart (1 h to 90 days, hover or touch for values, a table view, arrow keys on the chart).

Install sysmonitor on each machine first (see its README), then add to `config/site.info.yaml`
(template: `config/site.info.yaml.in`):

```yaml
sysmonitor:
  sysmonitor_path: /opt/sysmonitor       # only needed for the local host
  hosts:
    - name: "This server"
      local: true                         # this machine: read in-process, no URL or token
    - name: "NAS"
      url: "https://nas.example.com/sysmonitor/api.php"
      token: "token from sysmonitor's bin/token.php"
```

- The browser never sees an agent's address or token: ZPrime asks the agents server-side. Plain `http://` is refused
  unless the host has `allow_http: true` (trusted LAN only, the token travels in clear).
- Only the **owner** sees the block and its endpoints, because machine details are sensitive. `allow_all_users: true`
  opens it to every account.
- Answers are cached for `cache_seconds` (default 30). A host that fails is not retried for a minute and the last data is
  shown, marked "Showing last known data"; "Collector silent" means the machine answers but its collector stopped writing.
- For a `local: true` host the web user must be able to read sysmonitor's `data/` directory *and* write the SQLite
  `-shm` file (shared group, group-writable `data/`): see sysmonitor's README.
- Leave `sysmonitor:` out and the block does not appear.

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
bin/               setup.php (first account), run_tests.sh, make_icons.py (developer tool)
design/            the icon source picture
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

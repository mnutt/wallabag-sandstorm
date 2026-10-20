# wallabag for Sandstorm

A Sandstorm packaging repository for wallabag. The build downloads the prepared
wallabag 2.6.14 distribution and the outbound HTTP adapter at the versions and
SHA-256 checksums recorded in `upstream.lock`. Upstream files and binaries are
build outputs, not vendored source. Sandstorm changes live in `integration/` and
small, ordered patches in `patches/`.

## Development

Install `spktool` and Lima, then run:

```sh
spktool vm up
spktool dev
```

The development server is http://local.sandstorm.io:6091. Sign in with a local
Dev account, open Apps → wallabag, and create a reading library. Keep `spktool dev`
running while testing. The VM uses Debian 12, PHP 8.2, nginx, and SQLite. Go from
Debian backports builds the HTTP adapter. Nothing runs directly on the host.

The app identity has already been initialized with `spktool init`.
The signing key is stored by spktool outside this repository. Back it up: future
updates to this app ID require the same key. Other developers should import the
key only if authorized, or generate their own development app identity.

After changing VM dependencies, run `spktool upgradevm` and
`spktool vm provision`. After changing the app, restart `spktool dev` to rebuild.
For an existing Lima VM, changes to its port forwarding may also require
`limactl edit` followed by a VM restart; the project configuration alone does not
update an existing Lima instance.

## Integration

- One grain is one library, backed by a single internal wallabag user. Sandstorm
  authenticates visitors. Anonymous sharing links work when they grant `access`.
- Collaborator grants full library access, including deletion and export.
  Feed reader grants only GET/HEAD access to the four RSS endpoints. It is not a
  read-only library UI role. Both nginx and the Symfony authenticator enforce
  these permissions; requests without either relevant grant are denied.
  Do not expose nginx outside the grain.
- Account login, registration, password recovery, administration, and public
  article sharing are disabled. Account settings and native client setup are
  hidden for this prototype. Sandstorm webkeys expose the API with the same
  Library access permission as the grain UI; OAuth remains disabled.
- SQLite, images, private secrets, sessions, small writable caches, and proxy
  capabilities live in `/var/lib/wallabag`. First launch copies a prepared database
  seed and generates private credentials, then atomically installs the database.
  Migrations run only when the packaged schema version changes. Compiled container,
  template, translation, and ORM files are shared from the read-only package.
  Old per-grain compiled caches are removed automatically on upgrade.
- The bridge automatically supplies a capability-aware HTTP proxy. The bundled
  `powerbox-http-proxy` adapter translates wallabag's ordinary HTTP/HTTPS requests
  into capability calls and requests website access through Powerbox. Its local
  HTTPS certificate is trusted only inside this grain; upstream TLS remains the
  responsibility of Sandstorm's HTTP capability provider. Grants require the
  requesting user's `access` permission.
- Graby's local DNS-based SSRF preflight is removed in this package: local DNS
  is unavailable in a grain. Its client is explicitly injected and sends all
  requests through the capability proxy, where Sandstorm enforces network grants.
- Saving a URL uses fetch to keep the document and Powerbox websocket alive while
  the request is running. Article fetching and image fetching use the adapter.

## Packaging and updates

Exercise the app in `spktool dev`, inspect `.sandstorm/sandstorm-files.list`, then:

```sh
mkdir -p dist
spktool pack dist/wallabag.spk
spktool verify dist/wallabag.spk
```

The manifest explicitly includes the full application, PHP extensions, integration
files, and proxy executable in addition to traced system dependencies. Review
that inclusion list after changing the runtime. App-market metadata and publication
are not part of the prototype.

To upgrade wallabag, update its URL, version, and checksum in `upstream.lock`,
refresh patches against that release, and increment the manifest's `appVersion`
and marketing version. Check the release's PHP requirements before changing the
base VM. Test both a new grain and an existing library with articles and images.
Builds verify the application archives, but the Debian image and apt repositories
are not snapshot-pinned, so this is not yet a bit-for-bit reproducible build.

## Prototype limits

Unmodified mobile apps and browser extensions, background imports that survive grain suspension,
and a read-only library UI require further integration. Reloading articles and large
imports also need end-to-end testing with Powerbox. Back up grains before testing
upgrades: migrations are applied forward and automatic downgrade is not supported.

## Browser smoke test

`tests/smoke.cjs` uses Playwright (tested with 1.63.0) and Chromium. Install
Playwright in your test environment, or set `NODE_PATH` to an existing installation.
Export the development account's Playwright storage state to a file outside the
repository. Run against a disposable grain:

```sh
GRAIN_URL=http://local.sandstorm.io:6091/grain/REPLACE_ME \
SANDSTORM_STORAGE_STATE=/tmp/wallabag-browser-state.json \
node tests/smoke.cjs
```

The test checks automatic login, denial of account configuration, a real HTTPS
article fetch through Powerbox, local image storage, and persistence after a grain
restart. It grants the disposable grain access to `https://www.gnu.org` and saves
an illustrated article from that site. Login state files contain credentials and
must not be committed.

## Warm theme

The package includes a warm minimalist theme via wallabag's native `custom.css`
hook. Edit `integration/warm.css` for the palette and layout, and
`integration/warm.js` for the original line icons. The build copies these into
the web directory; the small `wallabag-warm-theme.patch` loads the icon script.
No external fonts, assets, or frontend build tools are needed.

Navigation, controls, page headings, entry-list titles, and metadata use the system sans serif.
The article reader's title and reading text use a local serif stack. Paper and linen surfaces,
fine rules, and restrained sage/clay accents replace cyan bars, elevation, ripples,
and uppercase buttons. The original wallabag logo is retained. The underlying Materialize components
still provide menus, filters, and dialogs; this is a skin, not a framework rewrite.
Unknown icons fall back to their upstream glyph so controls remain usable.

Choose **Light**, **Dark**, or **Automatic** from the account menu (or the reader's
appearance menu). These are wallabag's existing controls: explicit choices persist
in a browser cookie, and Automatic follows the operating system preference.
Dark mode uses warm charcoal with ivory text. Theme choices are per browser,
not a shared library setting.

Rebuild with `spktool dev`. To check both themes and mobile layouts on a grain
containing an unread article:

```sh
GRAIN_URL=http://local.sandstorm.io:6091/grain/REPLACE_ME \
SANDSTORM_STORAGE_STATE=/tmp/wallabag-browser-state.json \
SCREENSHOT_DIR=/tmp/wallabag-theme \
node tests/theme.cjs
```

The test temporarily switches grid/list mode and restores the previous mode.

## Browser extension

Use the adapted Wallabagger build described in [extension/README.md](extension/README.md).
It uses a revocable Sandstorm webkey and captures the open browser page, so saving
does not need an open grain or an interactive outbound website grant.

## RSS subscriptions

Version `2.6.14-sandstorm.2` adds RSS 2.0 feeds. Open the account menu in wallabag,
choose **RSS subscriptions**, select unread/starred/archived/all articles and an
optional tag, then click **Create subscription credentials**. Copy the displayed
feed URL, username, and password into a reader that supports HTTP Basic auth.
Readers that accept credentials embedded in a URL can use the combined
subscription URL instead. Clients that cannot authenticate private feeds are
not supported; feeds are not published anonymously.

The setup screen uses Sandstorm's offer-template mechanism with the **Feed reader**
role. The credential stays inside Sandstorm's separate frame rather than being
readable by wallabag JavaScript. Use it within five minutes; the first request
activates it for continued polling. Revoke it in Sandstorm's **Get Webkey** menu.
Each credential permits all feed views, not only the filter selected during setup.
It cannot access the rest of the library or create/edit/delete articles.

Endpoints: `/feeds/unread.xml`, `/feeds/starred.xml`, `/feeds/archive.xml`, and
`/feeds/all.xml`. They support HTTP Basic (any username; token as password) and
Bearer authentication. No wallabag account password or native wallabag feed token
is needed. The old `/feed/USERNAME/TOKEN/...` routes remain disabled.

Feeds include full saved HTML and tag labels, newest saves first, with stable
item GUIDs independent of the access token and selected filter. Article links go
to the original website. Images saved inside the grain are not exposed to feed
credentials and may not display in readers. Feed-only credentials do not permit
interactive library access via a Sandstorm sharing link.

Query options: `limit=1..100` (default 50), `page=1..10000` (default 1), and
`tag=SLUG` (wallabag slugs can include the `t:` prefix; the setup screen handles
this automatically). Generated URLs also include a non-secret `origin` value:
Sandstorm strips the external hostname from API sessions, so this supplies valid
absolute feed/self/pagination links. Without it, feeds still work but omit self
and pagination links and use the wallabag project website as the channel link.
No token is included in the XML. Feed responses use `Cache-Control: private, no-store`.

`tests/feeds.cjs` checks XML and full text, special-character escaping, filtering,
pagination, Basic/Bearer and embedded-URL credentials, HEAD, permission isolation
(including spoofed headers), invalid parameters, and missing authentication. Use
Playwright and a disposable grain, and supply a private JSON file containing
`feedUrl` and `token` for a Feed reader credential:

```sh
GRAIN_URL=http://local.sandstorm.io:6091/grain/REPLACE_ME \
SANDSTORM_STORAGE_STATE=/tmp/wallabag-browser-state.json \
WALLABAG_FEED_FILE=/tmp/wallabag-feed-test.json \
node tests/feeds.cjs
```

The installed `.2` package passed the RSS, extension, and grain smoke tests.
A feed credential also continued working after a grain restart and returned 403
after revocation through the Sandstorm UI. No specific third-party reader UI has
been tested; HTTP Basic and combined-URL subscriptions were tested using an HTTP client.

## Startup and build footprint

Version `2.6.14-sandstorm.3` moves the installer and Symfony cache warmup into the
build. A fresh grain copies the prepared ~216 KiB database, creates its own secret
and disabled internal-login credential, and starts the services. It does not run
the installer or migrations. Existing grains preserve their database and secret;
the old `parameters.yml` is retained for compatibility but no longer loaded as
application configuration. Migration markers are updated only after success.

`build/wallabag-cache` contains the compiled container, routes, templates,
translations, and Doctrine proxies/metadata at their final installed paths.
Configuration uses runtime environment placeholders for the database and secret;
the seed-build secret is not embedded in the cache. Application/settings cache
misses use per-request memory. The small caches that libraries generate dynamically
(JS routes, serialization metadata, rules, and cookies) have writable paths under
`/var`. Do not point Symfony's entire cache back at `/var` or copy the shared cache
into each grain. Per-grain files are for private state, not duplicated build output.

The build independently fingerprints wallabag inputs and proxy inputs. An unchanged
build skips extraction, patching, Go compilation, seed creation, and cache warmup.
Changes to the relevant source version, patches, integration, build script, PHP
version/extensions, or Go version invalidate the appropriate generated output.
To force rebuilding, remove the generated `build/app-version` and/or
`build/proxy-version` markers. A clean/changed build pays the initialization cost
once; incremental builds reuse that work.

Measured on this development VM (illustrative single-run timings, not guarantees):

| Measurement | Previous implementation | Optimized implementation |
|---|---:|---:|
| Fresh grain, click to first page | 6.23 s | 1.57 s |
| Initial allocated grain storage | 9–22 MiB, mostly compiled cache | 380 KiB |
| Unchanged build script in VM | Always extracted and rebuilt | 0.07 s |

`spktool`/VM startup adds its own overhead to the build-script timing. Shared
package storage increases by the warmed cache, but that space is not duplicated
in each grain. Saved articles, downloaded images, sessions, and API use naturally
increase grain storage after initialization.

`tests/runtime-state.php` checks database integrity, per-grain credential isolation,
upgrade-secret preservation, empty seed contents, schema markers, and removal of
legacy compiled caches. Run it in the VM against disposable fresh/upgraded grain
sandbox paths as a user with access to those paths. The browser smoke, extension,
and RSS tests also exercise the runtime with the package tree read-only.

## Navigation and personal theme

The app sends Sandstorm's `setPath` message on document navigation, history
changes, and fragment changes. The outer grain URL includes the current path,
query string, and fragment, so reloading or bookmarking an article or filtered
list opens that same location.

Light, dark, and automatic theme choices are saved per `X-Sandstorm-User-Id`,
inside this grain under `/var/lib/wallabag/preferences`. They are independent of
the shared wallabag `library` account and survive fresh Sandstorm sessions and
browser changes. Each saved choice is a few bytes in a private file (typically
one filesystem block). Saving requires library access and a valid CSRF token.
The server renders the saved theme and initializes the upstream theme cookie
before the page's JavaScript runs. Automatic mode follows each browser's OS
color scheme.

Sandstorm's iframe origin changes between sessions, so localStorage and app
cookies cannot provide durable preferences across those sessions. Anonymous
visitors without a Sandstorm identity keep upstream's cookie behavior; their
choice does not become a shared setting for other anonymous visitors.

`tests/persistence.cjs` checks deep-link reloads, query strings, fragments, browser
Back, and all three themes across fresh browser contexts with distinct iframe
origins. It uses `GRAIN_URL` and `SANDSTORM_STORAGE_STATE`, like the other browser
tests, and expects an unread article. `php tests/theme-state.php` runs in the VM
and checks identity isolation, first-render cookies, and anonymous/API behavior.

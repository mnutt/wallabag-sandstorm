# Developing wallabag for Sandstorm

Build, integration, packaging, and testing notes for contributors. See
[README.md](README.md) for the project overview and basic usage.

A Sandstorm packaging repository for wallabag. The build downloads the prepared
wallabag 2.6.15 distribution and the outbound HTTP adapter at the versions and
SHA-256 checksums recorded in `upstream.lock`. Upstream files and binaries are
build outputs, not vendored source. Sandstorm changes live in `integration/` and
small, ordered patches in `patches/`.

## Development environment

Install `spktool` and Lima, then run:

```sh
spktool vm up
extension/build.sh # requires Node.js 22+ on the host
spktool dev
```

The development server is http://local.sandstorm.io:6091. Sign in with a local
Dev account, open Apps → wallabag, and create a reading library. Keep `spktool dev`
running while testing. The VM uses Debian 12, PHP 8.2, nginx, and SQLite. Go from
Debian backports builds the HTTP adapter. The app runs in the VM; extension archives
are built on the host.

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
- Optional **Import → Connect IpNetwork** requests an unencrypted `IpNetwork` through
  Powerbox and saves it with the same `access` requirement. `integration/proxy-network.go`
  restores that capability for each HTTP request, resolves DNS through it, and tunnels
  TCP using Cap'n Proto streams. HTTPS verifies upstream certificates against the
  packaged system CA bundle. There is no direct socket/DNS fallback. Disconnect drops
  the durable token and closes active RPC connections; per-site grants are untouched.
  A revoked or unavailable saved grant fails requests until disconnected or replaced.
  Run `bash tests/proxy-startup.sh` in the VM for transport, revocation, TLS, permission,
  and startup certificate tests. The opt-in auto-disconnect setting is persisted
  beside the grant. After all queues drain and publishers finish, the worker calls
  a loopback-only completion endpoint before releasing its wake lock. Revocation
  failures retain the active marker and retry, including after restart. Pausing
  does not revoke; finishing a batch with unsuccessful entries does.
  `tests/network.cjs` exercises the real Powerbox grant, HTTPS article fetch, restart,
  manual disconnect, automatic revocation after a background import, and restored
  per-site prompts.
  Run it with the smoke test's environment variables against a fresh disposable
  grain, using a server administrator's browser state.
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
files, license notices, and proxy executable in addition to traced system dependencies.
Review that inclusion list after changing the runtime. The manifest includes icons,
demo-library screenshots, descriptions, categories, changelog, and maintainer contact.
Metadata assets live in `.sandstorm/assets/`; the SVG derives from wallabag's
MIT-licensed logo. The source URL is the intended public repository
`https://github.com/sandstorm-org/wallabag-sandstorm`.

Packages are signed with the existing app key. Optional PGP author verification is
not configured; it requires a separate author signature and public keyring. Building
a package does not publish it to the app market. Original integration code is MIT
licensed (see `LICENSE`); bundled projects retain their own licenses.

To upgrade wallabag, update its URL, version, and checksum in `upstream.lock`,
refresh patches against that release, and increment the manifest's `appVersion`
and marketing version. Check the release's PHP requirements before changing the
base VM. Test both a new grain and an existing library with articles and images.
Builds verify the application archives, but the Debian image and apt repositories
are not snapshot-pinned, so this is not yet a bit-for-bit reproducible build.

## Prototype limits

Unmodified mobile apps and browser extensions and a read-only library UI require
further integration. Reloading articles and each upstream import format need more
end-to-end testing with Powerbox. Back up grains before testing
upgrades: migrations are applied forward and automatic downgrade is not supported.

## Background imports

Imports use wallabag's Redis producers and consumers. Redis listens only on a private
Unix socket and persists jobs under `/var/lib/wallabag/redis` using an append-only log
with synchronous fsync. One supervisor processes one entry at a time, launching a
fresh PHP process per entry to bound memory and isolate failed Doctrine state.
Uploaded files use unique temporary names under `/var/lib/wallabag/imports`, outside
the web root, and are removed after parsing, including rejection or exceptions.
Nginx also blocks the legacy `/uploads/import/` path.
Existing grains have Redis imports enabled at startup too.

The small `import-notify awake` helper holds a Sandstorm API wake lock while jobs run.
It implements the same API as the `stay-awake` utility, with a readiness handshake
and process exit on cancellation (the pinned utility does not signal cancellation
to its caller). Cancelling its
notification pauses processing until **Import → Resume**. Queue claims are
atomic moves into a processing list; interrupted entries return to the queue on
restart. Imports use upstream duplicate detection, so recovery is at-least-once,
not an exactly-once transaction spanning both SQLite and Redis. A SQLite checkpoint
is committed with each new entry, allowing retries to finish image processing
without parsing the bookmark again. Image downloads run outside the transaction;
already-local images are reused. A deleted unfinished entry is not recreated.

Failed entries remain available under **Import → Retry unsuccessful entries**.
Each entry has a five-minute execution limit, including time spent waiting for
website permissions. Optionally authorize IpNetwork in **Import → Connect IpNetwork**
before importing. Otherwise, keep the library open to grant access to new sites; previously
granted sites work in the background. Reopening a grain resumes pending work after
a server restart. Images that cannot be fetched may retain external URLs in storage,
but the app CSP blocks remote images and media when viewing saved articles.

Newly downloaded raster images are re-encoded with GD and reduced to at most
1,920 pixels wide, 8,192 pixels high, and four megapixels, preserving aspect ratio
without upscaling. Library previews use separate thumbnails fitting within
640 × 640 pixels; an image already downloaded for the article is reused. JPEG
orientation is applied before metadata is stripped, and transparency is preserved.
AVIF inputs become WebP. JPEG/WebP use quality 80; PNG remains lossless. GIFs are saved as static PNGs
(the existing GD path also discarded animation). SVGs retain upstream sanitization.
Existing saved images are not migrated. Static SVG
responses carry `Content-Disposition: attachment; filename="image.svg"`, which
Sandstorm preserves. This mitigates direct navigation to SVG documents while
retaining image embedding (verified in Chromium). Run `python3 tests/svg-disposition.py`
in the build VM to verify nginx response headers and access controls. Browser captures sanitize SVG uploads, remove nonlocal href references, and store
them as local vectors; their previews use the same SVG file.

Browser captures process uploaded image bytes
through the same resizer, without fetching URLs from inside the grain. The extension
uploads up to 32 raster or SVG images (3 MiB each, 12 MiB total) with a 12-second fetch
budget. Unavailable or rejected images are omitted. Enable **Allow images from other sites**
in extension settings to include images from CDNs that require browser host access.

The processor rejects inputs above 20 MiB, 12 megapixels, or 16,384 pixels on
either side, and checks a conservative memory estimate before decoding. These
are resource guards, not isolation from GD decoder bugs; the HTTP client may
already have buffered the response before the byte limit is checked. Failed
processing may leave upstream external-image URLs in ordinary URL imports, but
CSP blocks loading them. Browser captures remove remote image fallbacks entirely. Raster files are
published with atomic renames only after successful encoding. Run `php tests/images.php`
in the build VM to check resizing, previews, orientation, alpha, and input limits.

The initiating session subscribes to import activity via `post-activity`.
Completion uses `SandstormApi.backgroundActivity()` through the bridge, so it does
not depend on that session remaining open. Events require library access, excluding
feed-only readers. Notification delivery is attempted once per batch: an RPC error
may occur after delivery, so automatic retries could generate duplicate alerts.
The development Sandstorm server currently rejects desktop notifications for
background events with a missing `appActivity.user` field; the bell entry is still
created, but its sender renders as `undefined`. This package does not modify Sandstorm.

`tests/imports.cjs` exercises a wallabag JSON upload, paused queue persistence across
restart, resume, background processing, and the completion bell entry. Run with the
same `GRAIN_URL`, `SANDSTORM_STORAGE_STATE`, and Playwright setup as the smoke test,
against an empty disposable installed-package grain.
`tests/import-controls.cjs` additionally checks malformed-entry retention, explicit
retry, and cancellation through Sandstorm's ongoing notification. It intentionally
leaves failed and paused test jobs behind; use a disposable grain.

Browser backups are flattened into individual bookmark jobs before queuing. Upstream
otherwise queues folders that its consumer discards as invalid, reporting completion
without importing their bookmarks. `php tests/browser-import.php` in the build VM
checks nested Firefox/Chrome backups, per-bookmark counts, and mark-as-read across
fresh workers. Backups submitted before this fix must be uploaded again.

## Browser smoke test

PHP responses expose duration-only diagnostic headers, using Sandstorm's allowed
`X-Sandstorm-App-*` prefix: `Bootstrap-Ms` measures front-controller setup;
`Kernel-Ms` measures Symfony handling (including lazy kernel boot, database work
and rendering); `Upstream-Header-Seconds` measures nginx's wait for FPM headers,
including worker queueing. The first two use a monotonic clock and exclude script
compilation, response transfer and kernel termination. Compare their sum with the
upstream duration and browser TTFB before changing worker counts or cache sizes.

`tests/assets.cjs` uses the same environment variables as the browser smoke test
below. It checks gzip negotiation and transfer sizes through an installed SPK,
ETag reuse on repeated navigation, and uncompressed PHP HTML. nginx compresses
static text assets only. The bridge does not forward ordinary `Cache-Control` or
`Expires` response headers, so adding nginx freshness directives alone would not
eliminate browser revalidation. Asset caches are also tied to Sandstorm's UI
origin and cannot be assumed to survive a new session origin.

Grain logs include `[startup]` checkpoints with UTC timestamps and elapsed wall
time in milliseconds from launcher entry. Redis readiness is measured independently
of proxy certificate generation. The HTTP-ready probe checks nginx's permission
denial without booting Symfony; the later proxy `Called Connect()` message marks
the browser's websocket connection, after the page has loaded its integration JS.
Capture both when investigating cold-start latency. No request data is logged by
these checkpoints.

Run `php tests/csrf-scheme.php` in the build VM to check HTTP and HTTPS CSRF
handling. The bridge supplies the external scheme; nginx passes it to PHP as
`HTTPS`. Without that mapping, Symfony temporarily trusting loopback during
inline form rendering changes the CSRF namespace on HTTPS installations, so
tokens from the navigation form fail validation on submission.

Run `python3 tests/redirect-origin.py` in the build VM to verify that nginx and
PHP-FPM preserve the external host and port in Symfony redirects. Debian's
FastCGI defaults strip the port; override them with the Host supplied by the
Sandstorm bridge so canonical-path redirects remain on the grain's origin.
`tests/deep-links.cjs` checks direct import links in Chromium, including query
strings and fragments, using `GRAIN_URL` and `SANDSTORM_STORAGE_STATE`.

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
appearance menu). Automatic follows the operating system preference. Dark mode
uses warm charcoal with ivory text. Choices persist per Sandstorm user; anonymous
visitors use a browser cookie. See [Navigation and personal theme](#navigation-and-personal-theme)
for persistence details.

Rebuild with `spktool dev`. To check both themes and mobile layouts on a grain
containing an unread article:

```sh
GRAIN_URL=http://local.sandstorm.io:6091/grain/REPLACE_ME \
SANDSTORM_STORAGE_STATE=/tmp/wallabag-browser-state.json \
SCREENSHOT_DIR=/tmp/wallabag-theme \
node tests/theme.cjs
```

The test temporarily switches grid/list mode and restores the previous mode.

Theme geometry is centralized alongside the palette in `warm.css`: icon size,
page and list gutters, and collapsed/expanded reader widths. Component selectors
retain the specificity needed to override the upstream stylesheet.

For a CSS refactor, first save the old stylesheet outside the repository, then
run the same test with `THEME_BASELINE_CSS=/tmp/warm-before.css`. The optional
comparison checks computed styles and bounds on the same DOM, including
pseudo-elements, responsive breakpoints, and print mode. It compares against
`integration/warm.css` by default, or `THEME_CANDIDATE_CSS` if supplied. Transitions
are disabled only during these comparisons; the normal UI checks still exercise
hover and tooltip behavior.

## Browser extension

Open **Account → Browser extensions** for Chrome/Chromium and Firefox development
downloads, installation instructions, and Sandstorm-generated connection credentials.
Credentials grant Collaborator access and are displayed by Sandstorm without exposing
the token to app JavaScript. Connect within five minutes to activate them.
See [extension/README.md](extension/README.md) for details.

Before building the app, run `extension/build.sh` on the host (Node.js 22+ required).
The app packages these ZIPs for download and refuses extension outputs whose source
fingerprint is stale. Rebuild the extensions after changing anything in `extension/`.
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

## Maintaining the patches

Every patch includes a comment header explaining its intent, dependencies,
upgrade assumptions, and relevant verification. Keep changes narrow and use
upstream configuration or extension hooks where available. In particular, the
Graby DNS-check removal is only valid with Sandstorm network isolation and our
capability-aware client; it must never be carried into a normal wallabag deployment.

Builds apply patches in C-locale filename order against checksum-pinned archives.
They reject fuzzy matches and reversed/already-applied patches without prompting.
Line-number offsets are allowed when the surrounding context still matches.
Patch backups are not included in the generated application.

Validate a patch update without replacing the running dev build:

```sh
# Inside the build VM (PHP and Go):
python3 tests/patches.py --group proxy --group wallabag
# Where the extension's Node tooling is available:
python3 tests/patches.py --group extension
```

These checks use the cached archives, validate changed source syntax, and reject
repeat application. Add `--compare-ref HEAD` for documentation/context-only edits
to verify that the patched source is byte-for-byte identical to that revision.
For upstream upgrades or behavior changes, also rebuild and run the grain/browser
tests named in the patch headers: a clean patch application cannot detect an
upstream API or security-semantics change. Vendor cache/client edits and the
Wallabagger authentication/capture integration deserve particular attention.

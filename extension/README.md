# Wallabagger for Sandstorm

An adapted build of [Wallabagger 1.25.0](https://github.com/wallabag/wallabagger/releases/tag/v1.25.0).
This is an unofficial Sandstorm adaptation, not the extension published in browser stores.
The upstream MIT license is included in each build.

## Install and connect

1. Install or upgrade to this repository's wallabag package, version
   `2.6.14-sandstorm.1` or newer.
2. In Chromium/Chrome, open `chrome://extensions`, enable Developer mode, click
   **Load unpacked**, and select `dist/wallabagger-sandstorm-chrome`.
   For Firefox development, open `about:debugging#/runtime/this-firefox`, choose
   **Load Temporary Add-on**, and select the manifest in
   `dist/wallabagger-sandstorm-firefox`. Temporary Firefox installation lasts until
   the browser restarts; a distributable Firefox release still needs signing.
3. Open your wallabag grain. In Sandstorm's **Get Webkey** (key) menu, create a
   webkey named “Wallabagger” with the Collaborator role (full library access).
4. Open the extension's Options. Paste the complete webkey, including its `#token`,
   and your library address from the browser address bar (`https://…/grain/ID`).
   Click **Connect**, then allow access to that API host when the browser asks.
5. Open an article and click the Wallabagger toolbar icon or press **Alt+W**.
   The popup saves it and lets you change its title, tags, starred/archive state,
   or delete it. **Alt+Shift+W** saves without opening the popup. Clicking the
   article title opens its stable Sandstorm grain address.

Open links in a tab before saving; URL-only link context-menu saving is excluded.
The page context menu can save the current page. Browser-internal pages and other
pages where extensions cannot run cannot be captured.

## Behavior and limitations

The extension sends the current page HTML to your library, including content you
are signed in to read. Wallabag sanitizes that HTML. It does not fetch the article
again or download linked images in browser-capture mode. Images remain remote
references and may not display under Sandstorm's content policy. Saving directly
inside the grain retains the existing Powerbox-based image download behavior.

The webkey grants full access to this library. It is stored in the extension's
local browser storage, not synchronized. Requests use a Bearer header and omit
cookies. The token is never put into an article link. Revoke it through the
Sandstorm webkey menu, then reconnect with a new one. There is no wallabag password
or OAuth client secret to configure. HTTPS is required except on local test hosts.
No analytics or third-party service is added by this adaptation.

Only the API host receives a persistent host permission. Capturing the current
article uses the extension's existing `activeTab` and `scripting` permissions.
The permission manifest lists optional HTTP/HTTPS hosts so the user can select
their server; setup requests only the selected API origin.

## Build and maintenance

Run `extension/build.sh` on a host with Bash, curl, tar, patch, Node.js 22+, and
Python 3. It verifies the source archive against `extension/upstream.lock`, applies
`wallabagger-sandstorm.patch`, overlays the Sandstorm settings screen, and produces
unpacked Chrome/Firefox directories and ZIPs in `dist/`. Build output is ignored
by git. No submodule or full vendored fork is required.

The patch adds Sandstorm token authentication, authenticated version checks,
stable grain links, browser-capture saves, useful API errors, and capture error
handling. Ordinary OAuth code remains available in the upstream class, but this
build's settings screen configures only Sandstorm. For a future upstream PR, the
same token mode can be integrated into upstream's normal settings UI.

The matching app patch accepts `sandstorm_capture: true` on POST `/api/entries.json`.
It requires nonempty `content`, retains Graby's HTML sanitization, disables URL
fetch fallback, and skips the image-download subscriber for that request. API PATCH edits also
skip image downloads, so tagging and starring cannot wait for a website grant. A
Sandstorm webkey is still required, and normal grain permission checks still apply.

Chromium was tested with the real host permission dialog, toolbar popup, page
capture, starring, article links, and rejection after revoking the webkey. The
signed app package passed the API/extension regression after installation. Firefox output is built but has not been
browser-tested or signed. Automated API/extension tests are in
`tests/extension.cjs`; see its header for usage.

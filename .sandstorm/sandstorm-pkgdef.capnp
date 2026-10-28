@0x859bbb42e2e9d199;
using Spk = import "/sandstorm/package.capnp";
const pkgdef :Spk.PackageDefinition = (
  id = "gvqr0x3nx6mwh3f1x8u7xt7m63ymajakc36nq2fw99770488q6u0",
  manifest = (
    appTitle = (defaultText = "wallabag"),
    appVersion = 4,
    appMarketingVersion = (defaultText = "2.6.14-sandstorm.4"),
    minUpgradableAppVersion = 0,
    actions = [(nounPhrase = (defaultText = "reading library"),
      description = (defaultText = "Create an independent library of saved articles."),
      input = (none = void), command = .command)],
    continueCommand = .command,
    metadata = (
      website = "https://wallabag.org",
      codeUrl = "https://github.com/sandstorm-org/wallabag-sandstorm",
      icons = (
        appGrid = (svg = embed "assets/icon.svg"),
        grain = (svg = embed "assets/icon.svg"),
        market = (svg = embed "assets/icon.svg"),
        marketBig = (svg = embed "assets/icon.svg"),
      ),
      license = (openSource = mit, notices = (defaultText = embed "assets/notices.txt")),
      categories = [media, productivity],
      author = (
        upstreamAuthor = "Nicolas Lœuillet and the wallabag contributors",
        contactEmail = "michael@nuttnet.net",
        # Optional PGP author verification is separate from the SPK signing key.
        # Add pgpSignature and metadata.pgpKeyring only with a verified author key.
      ),
      shortDescription = (defaultText = "Read articles later"),
      description = (defaultText = embed "assets/description.txt"),
      changeLog = (defaultText = embed "assets/changelog.txt"),
      screenshots = [
        (width = 1200, height = 968, png = embed "assets/library.png"),
        (width = 1200, height = 968, png = embed "assets/reader.png"),
      ],
    ),
  ),
  sourceMap = (searchPath = [
    (sourcePath = "."),
    (sourcePath = "/", hidePaths = ["home", "proc", "sys", "etc/passwd", "etc/hosts", "etc/host.conf", "etc/nsswitch.conf", "etc/resolv.conf"]),
  ]),
  fileList = "sandstorm-files.list",
  alwaysInclude = [
    "opt/app/build/wallabag", "opt/app/build/powerbox-http-proxy", "opt/app/build/licenses",
    "opt/app/build/runtime-version", "opt/app/build/schema-version",
    "opt/app/build/wallabag-cache", "opt/app/build/library-seed.sqlite", "opt/app/integration",
    "opt/app/.sandstorm/launcher.sh", "etc/php/8.2", "etc/nginx/mime.types",
    "etc/ssl/certs/ca-certificates.crt", "etc/nginx/fastcgi_params", "usr/lib/php", "usr/share/php8.2-common",
    "usr/bin/redis-server", "usr/bin/redis-cli", "opt/app/build/import-notify",
    "usr/lib/x86_64-linux-gnu/libatomic.so.1", "usr/lib/x86_64-linux-gnu/libjemalloc.so.2",
    "usr/lib/x86_64-linux-gnu/liblzf.so.1",
    "opt/app/.sandstorm/utils/post-activity",
  ],
  bridgeConfig = (
    viewInfo = (
      eventTypes = [
        (name = "importQueued", verbPhrase = (defaultText = "queued an import"),
          requiredPermission = (permissionIndex = 0), autoSubscribeToGrain = true, suppressUnread = true),
        (name = "importComplete", verbPhrase = (defaultText = "completed an import"),
          requiredPermission = (permissionIndex = 0), notifySubscribers = true),
        (name = "importAttention", verbPhrase = (defaultText = "finished an import needing attention"),
          requiredPermission = (permissionIndex = 0), notifySubscribers = true),
      ],
      permissions = [(name = "access", title = (defaultText = "Library access"),
        description = (defaultText = "Read, save, edit, export, and delete articles in this library.")),
        (name = "feeds", title = (defaultText = "Read feeds"),
        description = (defaultText = "Read saved article content and tags through RSS feeds only."))],
      roles = [(title = (defaultText = "Collaborator"), permissions = [true, true],
        verbPhrase = (defaultText = "can use and change the library"),
        description = (defaultText = "Full access to all articles, tags, and annotations."), default = true),
        (title = (defaultText = "Feed reader"), permissions = [false, true],
        verbPhrase = (defaultText = "can subscribe to feeds"),
        description = (defaultText = "Read RSS feeds only; no library UI, API, or editing access."))],
    ),
    apiPath = "/",
  ),
);
const command :Spk.Manifest.Command = (
  argv = ["/sandstorm-http-bridge", "8000", "--", "/bin/bash", "/opt/app/.sandstorm/launcher.sh"],
  environ = [(key = "PATH", value = "/usr/local/bin:/usr/bin:/bin"),
             (key = "SANDSTORM", value = "1")],
);

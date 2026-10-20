@0x859bbb42e2e9d199;
using Spk = import "/sandstorm/package.capnp";
const pkgdef :Spk.PackageDefinition = (
  id = "gvqr0x3nx6mwh3f1x8u7xt7m63ymajakc36nq2fw99770488q6u0",
  manifest = (
    appTitle = (defaultText = "wallabag"),
    appVersion = 3,
    appMarketingVersion = (defaultText = "2.6.14-sandstorm.3"),
    actions = [(nounPhrase = (defaultText = "reading library"), command = .command)],
    continueCommand = .command,
    metadata = (
      website = "https://wallabag.org",
      shortDescription = (defaultText = "Read articles later"),
      description = (defaultText = "Save web articles into a personal or shared reading library. Prototype: collaborators have full library access; native mobile clients are not yet supported."),
    ),
  ),
  sourceMap = (searchPath = [
    (sourcePath = "."),
    (sourcePath = "/", hidePaths = ["home", "proc", "sys", "etc/passwd", "etc/hosts", "etc/host.conf", "etc/nsswitch.conf", "etc/resolv.conf"]),
  ]),
  fileList = "sandstorm-files.list",
  alwaysInclude = [
    "opt/app/build/wallabag", "opt/app/build/powerbox-http-proxy",
    "opt/app/build/runtime-version", "opt/app/build/schema-version",
    "opt/app/build/wallabag-cache", "opt/app/build/library-seed.sqlite", "opt/app/integration",
    "opt/app/.sandstorm/launcher.sh", "etc/php/8.2", "etc/nginx/mime.types",
    "etc/nginx/fastcgi_params", "usr/lib/php", "usr/share/php8.2-common",
  ],
  bridgeConfig = (
    viewInfo = (
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

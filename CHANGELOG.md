# Changelog

## 0.1.0 - 2026-11-11

First release.

- Parser functions `{{#caliber:}}` (also `{{#calibre:}}`), `{{#firearmspecs:}}` and `{{#costperround:}}`.
- Special:FirearmSpecs to read up to 50 titles at once.
- Settings `$wgFirearmSpecsBarrelUnit`, `$wgFirearmSpecsCaliberLinks` (off by default) and
  `$wgFirearmSpecsCurrencySymbol`.
- Uses gunsnprices/gunsnprices 0.1.
- Tested with MediaWiki 1.43.11 (PHP 8.3) and 1.46.2 (PHP 8.5).

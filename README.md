# FirearmSpecs

FirearmSpecs is a MediaWiki extension for wikis about firearms and ammunition. It adds parser functions that
read the caliber, model, magazine capacity, barrel length and rounds per box from a product title, and that
compute the cost per round of ammunition.

```wikitext
{{#firearmspecs:Glock 19 Gen 5 MOS 9mm 4.02in 15rd Pistol PA195S203MOS}}
```

shows this table:

| Type          | Firearm            |
|---------------|--------------------|
| Brand         | Glock              |
| Model         | Glock 19 Gen 5 MOS |
| Caliber       | 9×19mm Parabellum  |
| Capacity      | 15 rounds          |
| Barrel length | 4.02 in            |

```wikitext
{{#caliber:9MM LUGER}}                      → 9×19mm Parabellum
{{#caliber:300 BLK|type}}                   → rifle
{{#firearmspecs:Sig Sauer P365 XL 9mm 3.7" 12+1 Optic Ready|model}}   → Sig Sauer P365 XL
{{#costperround:18.99|50}}                  → 0.38
{{#costperround:449.99|Federal American Eagle 5.56x45mm 55gr FMJ 1000 Rounds}}   → 0.45
```

The extension uses the open source title parser of [GunsNPrices](https://gunsnprices.com), a price comparison
site for US gun and ammo stores. The parser knows 293 calibers with the spellings retailers use ("9MM LUGER",
"5.56 NATO", "12GA", "300 BLK") and the models of 50 brands. It runs on your server. The extension makes no
HTTP requests and has no tracking.

- Extension page: https://www.mediawiki.org/wiki/Extension:FirearmSpecs
- Source and issues: https://github.com/gunsnprices/mediawiki-extensions-FirearmSpecs
- Parser library: https://github.com/gunsnprices/gunsnprices-php

## Requirements

- MediaWiki 1.43 or later (tested with 1.43 LTS and 1.46)
- PHP 8.1 or later
- [Composer](https://getcomposer.org/), to install the
  [gunsnprices/gunsnprices](https://packagist.org/packages/gunsnprices/gunsnprices) library (pure PHP, no
  dependencies)

## Installation

1. Download the extension into a directory called `FirearmSpecs` in your `extensions/` folder:

   ```bash
   cd extensions/
   git clone https://github.com/gunsnprices/mediawiki-extensions-FirearmSpecs.git FirearmSpecs
   ```

2. Install the parser library with Composer. Choose one of these ways:

   - In the extension directory (the library goes to `extensions/FirearmSpecs/vendor/`):

     ```bash
     cd extensions/FirearmSpecs
     composer install --no-dev
     ```

   - Or with MediaWiki's `composer.local.json` (the library goes to MediaWiki's `vendor/`). In the MediaWiki
     root, create `composer.local.json` from `composer.local.json-sample`, or add the extension to its
     `merge-plugin` list:

     ```json
     {
         "extra": {
             "merge-plugin": {
                 "include": [
                     "extensions/FirearmSpecs/composer.json"
                 ]
             }
         }
     }
     ```

     Then run `composer update --no-dev` in the MediaWiki root.

3. Add this line at the bottom of `LocalSettings.php`:

   ```php
   wfLoadExtension( 'FirearmSpecs' );
   ```

4. Open Special:Version on your wiki to check that the extension is installed. Open Special:FirearmSpecs to try it.

No database changes are needed, so you do not need to run `update.php`.

If the library is missing, the parser functions and Special:FirearmSpecs show the error "the
gunsnprices/gunsnprices library is not installed". Run step 2.

## Configuration

Set these in `LocalSettings.php`, after `wfLoadExtension( 'FirearmSpecs' );`.

| Variable | Default | Description |
|---|---|---|
| `$wgFirearmSpecsBarrelUnit` | `'in'` | Unit of barrel lengths: `'in'` (inches), `'cm'` or `'mm'`. |
| `$wgFirearmSpecsCaliberLinks` | `false` | If `true`, caliber names in the `{{#firearmspecs:}}` table, in `{{#caliber:}}` without a field, and on Special:FirearmSpecs link to their price comparison pages on gunsnprices.com. |
| `$wgFirearmSpecsCurrencySymbol` | `'$'` | Currency symbol before the cost per round in the `{{#firearmspecs:}}` table. |

Pages keep their parsed output in the parser cache. After you change a setting, purge the pages that use the
functions (or wait until the cache expires) to see the change.

## Usage

### {{#caliber:}}

```wikitext
{{#caliber: text | field }}
```

Finds the caliber in a caliber name ("9MM LUGER", "308 WIN (7.62X51 NATO)", "12 GA") or in any text that
contains one ("Hornady Critical Defense 9mm Luger 115gr FTX 25rd").

| Field | Result | Example for `9mm` |
|---|---|---|
| (none) | canonical name; linked if `$wgFirearmSpecsCaliberLinks` is on | 9×19mm Parabellum |
| `name` | canonical name, never linked | 9×19mm Parabellum |
| `id` | stable id, letters and digits only | 9x19mmparabellum |
| `type` | `handgun`, `rifle`, `rimfire`, `shotgun`, `airgun` or `muzzleloader` | handgun |
| `aliases` | other spellings that retailers use, separated by commas | 9mm, 9x19, 9x19 mm, ... |

`{{#calibre:}}` is the same function.

### {{#firearmspecs:}}

```wikitext
{{#firearmspecs: title | field | kind= | brand= | mpn= | price= | unit= | decimals= }}
```

Reads a product title. Without a field, it returns a table of all specs found (a `wikitable` with the class
`firearmspecs`). With a field, it returns one value:

| Field | Result | Example |
|---|---|---|
| `kind` | `firearm` or `ammo` (guessed from the title) | firearm |
| `brand` | brand of the identified model, or the `brand=` option | Glock |
| `model` | model, firearms only | Glock 19 Gen 5 MOS |
| `family` | model family, firearms only | Glock 19 |
| `caliber` | first caliber in the title | 9×19mm Parabellum |
| `calibers` | all calibers, separated by commas | .44 Magnum, .44 S&W Special |
| `caliberid` | id of the first caliber | 9x19mmparabellum |
| `calibertype` | type of the first caliber | handgun |
| `capacity` | magazine capacity, firearms only (for "15+1" it is 15) | 15 |
| `barrel` | barrel length in the configured unit, without the unit | 4.02 |
| `rounds` | rounds per box, ammunition only | 1000 |
| `costperround` | price of one round; needs `price=` | 0.45 |

Options, all optional, in any order after the title:

- `kind=firearm` or `kind=ammo`: skip the guess. Short ammunition titles such as "Magtech 9mm 50rd" need
  `kind=ammo`.
- `brand=`: the brand, for titles that do not name it.
- `mpn=`: the manufacturer part number. It identifies Glock models when the title does not.
- `price=`: price of the box, such as `18.99` or `$18.99`. Adds the cost per round to the table.
- `unit=in`, `unit=cm` or `unit=mm`: overrides `$wgFirearmSpecsBarrelUnit`.
- `decimals=`: decimal places of the cost per round, 0 to 4 (default 2).

A title that contains `|` or `=` can be put in `<nowiki>` tags:
`{{#firearmspecs:<nowiki>Ruger 10/22 | 18.5" | .22 LR</nowiki>|barrel}}`.

### {{#costperround:}}

```wikitext
{{#costperround: price | round count or title | decimals= }}
```

Returns the price of one round with a dot as the decimal separator and no currency symbol, so that the result
can be used in `{{#expr:}}` or after your own currency sign:

```wikitext
${{#costperround:18.99|50}} per round        → $0.38 per round
{{#costperround:$1,299.00|1,000|decimals=3}} → 1.299
{{#costperround:21.99|Winchester 9mm 115gr FMJ Box of 50}} → 0.44
```

The price can have a currency sign before it and commas as thousands separators. "18,99" is not read as a price.

### Values, empty results and errors

- Single values are plain text. Numbers use a dot as the decimal separator and no thousands separator. Text
  from the title or from the library is escaped, so it is shown as written and is never read as wiki markup.
- If the title does not contain a value, the result is empty, so you can use
  `{{#if:{{#firearmspecs:{{{title}}}|capacity}}|...}}`.
- Invalid input (an unknown field, a price that is not a number) gives an error in `<strong class="error">`,
  which `{{#iferror:}}` recognizes.
- To compare calibers in templates, use the `id` field: `{{#ifeq:{{#caliber:{{{1}}}|id}}|9x19mmparabellum|...}}`.
  Names can contain characters such as `&` that are escaped in the output.

### Special:FirearmSpecs

Type up to 50 product titles, one per line, to see what the parser reads from each. The form uses GET, so you
can link to results, and `Special:FirearmSpecs/<title>` reads one title from the URL. The page is open to all
users and changes nothing on the wiki.

## Accuracy

Measured on 63,755 listings from 40+ US stores (October 2026): when a title contains the value, the parser finds
the caliber of a firearm correctly in 97.8% of cases, the caliber of ammunition in 94.9%, the magazine capacity
in 95.6%, the barrel length in 94.4% and the rounds per box in 96.6%. Many titles do not contain the caliber or
the barrel length; then the result is empty. Details are in the
[library README](https://github.com/gunsnprices/gunsnprices-php#accuracy).

Titles in English from US retailers work best. Models are known for 50 brands; other brands still get calibers
and numbers.

## Performance

A call takes about 0.3 ms. The library reads its data and compiles its patterns on the first call in a request,
which takes about 30 ms. Parser output is cached, so this happens only when a page is parsed.

## Development

The tests run with MediaWiki's test runners. With the extension installed in a MediaWiki checkout that has its
development dependencies (`composer update` in the MediaWiki root):

```bash
# parser tests (tests/parser/firearmSpecs.txt)
php tests/parser/parserTests.php --file=extensions/FirearmSpecs/tests/parser/firearmSpecs.txt

# PHPUnit tests (unit and integration)
composer phpunit:entrypoint -- extensions/FirearmSpecs/tests/phpunit

# coding conventions, with the code sniffer from MediaWiki's dev dependencies
vendor/bin/phpcs -p -s --standard=extensions/FirearmSpecs/.phpcs.xml extensions/FirearmSpecs
```

The extension's `composer.json` has no development dependencies on purpose: the exact tool versions pinned by
MediaWiki core differ between releases, and `composer-merge-plugin` would merge conflicting pins.

## License

The extension is licensed under the GNU General Public License, version 2 or later (GPL-2.0-or-later), like
MediaWiki. See [LICENSE](LICENSE).

The gunsnprices/gunsnprices library is not part of this repository; Composer installs it from Packagist. Its
code is under the MIT License, and its data files (the caliber list and the model dictionaries) are by
GunsNPrices (gunsnprices.com) under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/). Both licenses are
compatible with the GPL. Special:FirearmSpecs shows the credit that CC BY 4.0 asks for.

Questions and corrections: open an issue on GitHub or write to app@gunsnprices.com.

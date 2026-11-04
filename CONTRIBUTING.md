# Contributing

Thanks for helping out. Bug reports, fixes and small features are welcome.
For anything bigger, open an issue first so we can agree on the approach.

## Setup

You need PHP 8.4 or 8.5 (64-bit) and Composer.

```
composer install
composer check
```

`composer check` validates the manifest, lints, runs PHPUnit and PHPStan (strict rules).
It has to pass before a pull request is merged.

The Quark server suites (`scripts/verify.ps1`, `scripts/run-quark.py`, `scripts/run-bedrock.py`)
run the library on a real server. They need a Quark checkout at the commit pinned in `composer.json`;
`scripts/prepare-quark.ps1` fetches it. Run them when you touch entity, lifecycle or networking code.

## Code style

- Tabs, `declare(strict_types=1)`, see `.editorconfig`
- New behaviour needs a test
- Don't change the public API (`NpcManager`, `Npc` and the typed handles) without discussing it first.
  Everything under `NpcLib\internal` is not part of it.

## Pull requests

- Describe what changed and how you checked it

By contributing you agree that your work is released under the [MIT license](LICENSE).

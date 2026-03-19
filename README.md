# dux-encrypt-runtime

Pure PHP runtime package for executing `dux-encrypt` protected bundles without PHP extensions.

Current public package version: `0.0.1`

## Scope

This package only contains the public runtime:

- `bootstrap.php`
- `src/Runtime.php`
- `composer.json`

It does not contain:

- compiler implementation
- parser worker
- packaging commands
- internal smoke / acceptance fixtures

## Install

```bash
composer require duxweb/dux-encrypt-runtime:0.0.1
```

The runtime is autoloaded via Composer `files`, so no extra bootstrap step is required after normal Composer autoload is enabled.

## Production Environment

Recommended environment:

```bash
export DUX_ENCRYPT_RUNTIME_SECRET='your-secret'
export DUX_ENCRYPT_CACHE_DIR=/var/lib/dux-encrypt-cache
export DUX_ENCRYPT_EPHEMERAL_CACHE=1
```

Recommendations:

- build protected bundles with compiler `--release`
- always set `DUX_ENCRYPT_RUNTIME_SECRET` for production bundles
- keep `DUX_ENCRYPT_CACHE_DIR` outside webroot
- enable `DUX_ENCRYPT_EPHEMERAL_CACHE=1` unless you explicitly need persistent JIT cache files

## Security Notes

- release bundles use wrapped `payload_key`, not plain `payload_key`
- release bundles include manifest HMAC integrity verification
- this package raises reverse-engineering cost; it is not equivalent to native binary protection

## Publishing Model

- only `duxweb/dux-encrypt-runtime` is published externally
- compiler stays internal and is intended to be embedded into Dux app modules

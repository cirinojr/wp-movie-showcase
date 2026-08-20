# Contributing

## Local setup

Use PHP 7.4 or newer, Node.js 22, Composer 2, and npm:

```bash
composer install
npm ci
```

The plugin runs from the committed `build/` directory. During frontend development, use:

```bash
npm run start
```

## Quality checks

Before opening a pull request, run:

```bash
composer validate --strict
composer test
composer lint
npm run lint
npm run build
git diff --exit-code -- build
```

Run `npm run benchmark` separately when changing cache behavior. Benchmark changes must describe the mocked upstream delay and must not be presented as production measurements.

## Pull requests

- Keep changes focused and preserve public behavior unless the change explains the compatibility impact.
- Add or update tests for cache policy, concurrency, invalidation, or failure-handling changes.
- Never commit an OMDb API key, production response payload, or other secret.
- Do not bypass failing checks with suppressions unless the reason is narrow, documented, and reviewable.

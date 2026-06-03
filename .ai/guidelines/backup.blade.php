## Package development (jiannius/backup)

This is a Laravel **package** (composer `type: library`, PSR-4 `Jiannius\Backup\`), not an app. There is no `artisan` binary — dev/test/Boost tooling runs through Orchestra Testbench's `vendor/bin/testbench` against a throwaway Laravel app configured by `testbench.yaml`.

### Testbench is the artisan

- Run artisan commands as `vendor/bin/testbench <command>` (e.g. `vendor/bin/testbench route:list`, `vendor/bin/testbench tinker --execute='...'`). There is no `php artisan` here.
- Do NOT use `make:` generators — they scaffold into the throwaway Testbench app, not this package. Create package files manually under `src/`, following the existing structure and namespaces (check sibling files first).

### Conventions

- Follow the existing code conventions; when creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods (`isRegisteredForDiscounts`, not `discount()`).
- Stick to the existing directory structure — don't create new base folders without approval.
- Don't change the package's dependencies without approval.
- Only create documentation files if explicitly requested.
- Be concise in explanations — focus on what's important rather than obvious details.

### PHP style

- Always use curly braces for control structures, even single-line bodies.
- Use PHP 8 constructor property promotion; no empty zero-parameter constructors unless private.
- Explicit return types and type hints on all parameters.
- Prefer PHPDoc over inline comments; every public/private method gets a one-line PHPDoc. Use array-shape definitions in PHPDoc where useful.
- Backed enums mix in `Jiannius\Backup\Traits\Enum`; cases are `FULL_UPPERCASE`.

### Models & data

- Main tables use ULID primary keys (`HasUlids` + `$table->ulid('id')->primary()`) plus a nullable `data` json column for metadata. Plain auto-increment ids are fine for pivot tables.
- When adding a model, add its factory (and a seeder if useful) and wire `newFactory()` — package factory namespaces aren't auto-discovered.
- Use named routes and `route()` when generating links.
- The package's public API hangs off the `Backup` singleton (`app('backup')` / the `backup()` helper).

### Testing (Pest 4 + Testbench)

- Every change must be programmatically tested: write or update a Pest test in `tests/Feature` or `tests/Unit` (both extend `Tests\TestCase` — Orchestra Testbench, in-memory sqlite), then run the affected tests.
- Don't write one-off verification scripts or tinker probes when a test can prove the behavior — tests are the source of truth.
- Run the minimum tests needed: `vendor/bin/pest --filter='name'` or a single file; `composer test` for the full suite.
- In tests, build models with factories; check for custom factory states before configuring manually. Use `fake()->word()`-style faker calls.
- There is no `make:test` — create test files manually under `tests/Feature` or `tests/Unit`.
- Do NOT delete tests without approval.

### Code style (Pint)

- After modifying PHP files, run `vendor/bin/pint --dirty` (or `composer lint`) before finalizing changes — run it to fix, not just `--test` to check.

### Workflow

- Always squash-merge when exiting a worktree, then remove the worktree.
- Plan mode: no need to use the superpowers skills.

### Working guidelines

**Think before coding.** State assumptions; if multiple interpretations exist, surface them rather than picking silently. If something is unclear, stop and ask.

**Simplicity first.** Minimum code that solves the problem. No speculative features, abstractions for single-use code, or error handling for impossible scenarios.

**Surgical changes.** Touch only what the task requires. Match existing style. Don't refactor unrelated code; remove only orphans your own change created.

**Goal-driven execution.** Turn the task into a verifiable goal ("write a failing test, then make it pass") and loop until verified.

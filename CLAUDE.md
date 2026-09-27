# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

**See `AGENTS.md` for full project conventions** (code style, architecture, CI commands, git workflow, design principles). Everything below is Claude Code-specific guidance that supplements AGENTS.md.

## Single-file commands

```bash
# Run a single test
docker compose run --rm buildbox .build/bin/phpunit --filter testMethodName tests/Unit/Path/To/TestFile.php

# PHPStan on a single file
docker compose run --rm buildbox .build/bin/phpstan analyze src/Path/To/File.php --memory-limit=-1

# Classes in no layer / the classes a layer holds (Deptrac)
docker compose run --rm buildbox .build/bin/deptrac debug:unassigned
docker compose run --rm buildbox .build/bin/deptrac debug:layer Metadata

# Run CLI command
make run CMD="rename:exif /path --dry-run"
```

## DI Container

Symfony DI with autowiring (`config/Services.yaml`). All `src/` classes auto-registered except `Renamer.php`, `Dependencies.php`, `Constants.php`, and `Model/`. Service interfaces bound explicitly. `MetadataReader` created via static factory. Container cached at `.build/cache/DependencyContainer.php` — delete after changing `Services.yaml`.

Constructor parameters never default to `new Foo()` (`tests/Unit/Architecture/ConstructorWiringArchitectureTest`): a new collaborator is wired by the container, and tests pass it explicitly.

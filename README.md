# Streaming PostgreSQL `COPY FROM STDIN` in Modern PHP 8.4+ Backends

[![PHP 8.4 + PostgreSQL 17 Benchmark](https://github.com/Eelprofesor14/php-postgresql-copy-benchmarks/actions/workflows/benchmark.yml/badge.svg)](https://github.com/Eelprofesor14/php-postgresql-copy-benchmarks/actions/workflows/benchmark.yml)

Companion code listings and reproducible PostgreSQL 17 bulk-ingestion benchmark suite by **Santiago Zazo** (*PHP Architect*, 2026).

## Included Listings

- `listings/listing1.php` — Memory-bounded generator pipeline with `pg_copy_from()`
- `listings/listing2.php` — Forward-compatible PDO loader using `Pdo\Pgsql::copyFromArray()` (PHP 8.4/8.5 ready)
- `listings/listing3.php` — Deterministic PostgreSQL `COPY` text-format sanitizer (`\`, `\t`, `\n`, `\r`, `\N`, NUL bytes)
- `listings/listing4.php` — Atomic `TEMP` staging table upsert + JSONL dead-letter isolation
- `listings/listing5.php` — Self-contained 50,000-row CLI benchmark harness comparing single-row prepared `INSERT`, 500-row batched `INSERT`, and generator-fed `COPY FROM STDIN`

## Run Locally

```bash
PG_DSN="pgsql:host=127.0.0.1;port=5432;dbname=bench" PG_USER=postgres PG_PASS=postgres php listings/listing5.php 50000
```

## License

MIT License — Copyright (c) 2026 Santiago Zazo.

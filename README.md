# gilles-g/dump-loader

Symfony bundle that loads a SQL dump (`.sql` / `.sql.gz`) into the application database from a web
UI, running a sequence of steps followed in real time: status, progress, steps, log, abort, history.

> **Warning** — this bundle overwrites the database of the application it is installed in. It is
> meant for development and pre-production environments, behind an authenticated route.

## Features

- Web dashboard: pick a dump, tick the optional steps, confirm, follow the run live.
- The same journey in a terminal with `dump-loader:load`, scriptable for a CI.
- Dumps read through Flysystem: a local directory in V1, S3 or SFTP later without touching the domain.
- Steps: `DropAllTables` and `RestoreDatabase` (mandatory), `RunMigrations` and `ClearCaches`
  (optional).
- Streaming restore: the dump is piped into the `mysql` client, memory stays flat on large files.
- Detached console process, polled over HTTP; abort at any time; job history.
- Standalone monitor: progress, abort and history are served by a kernel of their own, which keeps
  answering while the application kernel cannot boot on a half-restored database.
- Extensible: an application declares its own step and it shows up in the UI.

## Getting started

1. Install the package:

    ```bash
    composer require gilles-g/dump-loader
    ```

    and add this line to `config/bundles.php`:

    ```php
    DumpLoader\DumpLoaderBundle::class => ['all' => true],
    ```

2. Configure the source and the target database in `config/packages/dump_loader.yaml`:

    ```yaml
    dump_loader:
        source:
            directory: '%kernel.project_dir%/var/dumps'
        target_database:
            dsn: '%env(DATABASE_URL)%'
    ```

3. Import the routes in `config/routes/dump_loader.yaml`:

    ```yaml
    dump_loader:
        resource: '@DumpLoaderBundle/Resources/config/routes.php'
        prefix: /dump-loader
    ```

4. Protect them in `config/packages/security.yaml`:

    ```yaml
    security:
        access_control:
            - { path: ^/dump-loader, roles: ROLE_ADMIN }
    ```

5. Mount the monitor in `public/index.php`, before the application kernel is built:

    ```php
    return function (array $context) {
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        if (str_starts_with($uri, '/dump-loader/monitor') && \in_array($context['APP_ENV'], ['dev', 'staging'], true)) {
            return new DumpLoader\Monitor\MonitorKernel(
                $context['APP_ENV'],
                (bool) $context['APP_DEBUG'],
                dirname(__DIR__),
                '/dump-loader/monitor',
            );
        }

        return new App\Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
    };
    ```

    The mount path must match `monitor_path`. The guard on `APP_ENV` matters: the bundle is usually
    registered in `dev` and `staging` only, and the monitor would otherwise answer in production.

    The monitor is built before the application configuration is read. Whenever the application
    cache is warmed up, the bundle publishes `var_dir`, `pending_grace_period` and `retention` to
    `var/dump-loader.monitor.json`, where the monitor reads them: nothing to repeat here. A
    `DumpLoader\Monitor\MonitorSettings` passed as a fifth argument still wins over that file.

6. Check the requirements, drop a dump in `var/dumps`, open `/dump-loader`:

    ```bash
    bin/console dump-loader:check
    ```

### Requirements

- PHP >= 8.2 with `zlib` and `pdo_mysql`, Symfony 6.4, 7.4 or 8.0.
- The `mysql` client binary in the PHP image — official images do not ship it.
- `var/dump-loader` writable and `bin/console` executable by the php-fpm user.
- `clear_env = no` in the php-fpm pool, so the detached job inherits `DATABASE_URL`.
- MySQL / MariaDB. Windows is out of scope (`setsid`, `flock`, `exec`).

## Options

See `configure()` for the full tree.

| Key | Default | Purpose |
|---|---|---|
| `source.directory` | — | Directory holding the dumps (exclusive with `source.flysystem`) |
| `source.flysystem` | — | Service id of a `FilesystemOperator` (S3, SFTP…) |
| `target_database.dsn` | — | DSN of the database being overwritten |
| `mysql.binary` | `mysql` | Path to the MySQL client |
| `mysql.ssl` | `false` | Let the MariaDB client use TLS (see the pitfalls below) |
| `console` | `%kernel.project_dir%/bin/console` | Console entry point used by the steps |
| `php_binary` | `PhpExecutableFinder` | PHP binary running the detached job |
| `var_dir` | `%kernel.project_dir%/var/dump-loader` | Where job files, logs and signals live |
| `monitor_path` | `/dump-loader/monitor` | Where the monitor kernel is mounted in `public/index.php` |
| `execution` | `detached` | `detached` or `synchronous` |
| `pending_grace_period` | `60` | Seconds a pending job has to take its lock |
| `retention` | `50` | Finished jobs kept with their files; older ones are deleted when a load is requested |
| `after_load` | `[]` | Console commands offered as options once the load is over (see below) |

### After-load commands

A console command that has to run once the dump is in place — putting back a known password,
anonymizing data — does not need a PHP class. Declare it under `after_load`: each key becomes an
optional step, checked in the request form, ranked after the built-in ones and run in declaration
order.

```yaml
dump_loader:
    after_load:
        reset_admin_password:
            label: 'Reset the admin password'
            description: 'Puts back a known password on the admin account.'
            command:
                - 'dbal:run-sql'
                - 'UPDATE `gn_admin_user` SET `password` = "%env(ADMIN_PASSWORD)%" WHERE `id` = 3282;'
        anonymize_emails:
            enabled_by_default: false
            command: ['app:anonymize-emails']
```

Each entry of `command` is one argument passed as-is to `bin/console`: no shell is involved, so
quotes, spaces and semicolons inside a SQL statement need no escaping. Environment variables are
resolved when the step runs, and the arguments are kept out of the job log — only the label is
written, so a secret passed that way does not leak into the monitor. What the command prints itself
is logged as usual.

A literal `%` — a SQL `LIKE` pattern, typically — is written `%%`, as everywhere else in a Symfony
configuration file.

`label` defaults to the key, `description` to nothing, `enabled_by_default` to `true`. The key must
be lowercase letters, digits and underscores, and must not reuse the name of a built-in step.

## How it works

Every run starts by dropping the views and the tables of the target database. A dump only replaces
what it carries: a table created by a migration since the dump was taken would survive the restore
while `doctrine_migration_versions` goes back to the state of the dump, and the next `migrate` would
replay that migration against a table that already exists.

That drop only happens once the `RestorePolicy` has accepted the dump. Its header is enough to
refuse a missing file, a gzip whose beginning cannot be inflated, or a dump produced with
`mysqldump --databases`, which carries its own `CREATE DATABASE`/`USE`. In all three cases nothing
is dropped. The same check runs when the job is requested, so such a dump gets a message on the
dashboard instead of a failed job. A dump that breaks further on still fails during the restore, for
instance a gzip truncated past its first kilobytes. The database is then left partial.

1. The dashboard lists the dumps the `DumpSource` can see, and the steps the container knows about.
2. Confirming the form dispatches `RequestLoadJob`: the sequence is planned and **frozen** into the
   job, which is saved as `var/dump-loader/jobs/<id>.json` before anything runs.
3. The job is launched as a detached console process (`dump-loader:run <id>`), which holds a `flock`
   on `<id>.lock` for its whole life.
4. The operator is redirected to the monitor, served by `MonitorKernel`: it polls
   `<monitor_path>/progress` every second and shows the current step, the percentage, the log and an
   abort button. That kernel boots FrameworkBundle and TwigBundle only, reads nothing but
   `<var_dir>/jobs` and carries its own session cookie, so it keeps answering while `DROP TABLE`
   statements make the application kernel unbootable — and it never touches the application session.

   Without SecurityBundle, the monitor cannot check who is asking. The dashboard does the check
   instead: the redirect after a request, or the "Follow it live" link, hands the browser a cookie
   limited to `monitor_path`. That cookie is signed with `APP_SECRET` and valid for eight hours. The
   monitor answers every request without that cookie with a 403, and stops the polling of a page
   whose access has expired.
5. Aborting writes an `<id>.abort` signal file. The running job reads it between chunks, stops the
   `mysql` client with `SIGTERM` and ends as `Aborted` — the database is then partial, and the UI
   says so.
6. If the process dies without ending its job (`kill -9`, container restart), its lock is released
   while the job still claims to be running. Any read of the dashboard then shows it as `Lost`, and
   the next requested job persists that verdict.

## Extending: add a step

Implement `DumpLoader\Step\Step` to always run, or `DumpLoader\Step\OptionalStep` to be offered as a
checkbox in the UI — the option key *is* the step name. Autoconfiguration tags it, `rank` places it
in the sequence (50 / 100 / 200 / 300 are the ranks shipped by the bundle), and `weight` says how
much of the overall progress it represents.

```php
namespace App\DumpLoader;

use DumpLoader\Step\LoadContext;
use DumpLoader\Step\OptionalStep;
use DumpLoader\Step\StepDefinition;
use DumpLoader\Step\StepTracker;
use DumpLoader\LoadJob\LogTag;
use DumpLoader\LoadJob\Percentage;

final readonly class AnonymizeCustomers implements OptionalStep
{
    public function __construct(private Connection $connection)
    {
    }

    public function definition(): StepDefinition
    {
        return new StepDefinition(
            name: 'anonymize_customers',
            label: 'step.anonymize_customers.label',
            description: 'step.anonymize_customers.description',
            rank: 150,
            weight: 2,
        );
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function run(LoadContext $context, StepTracker $tracker): void
    {
        $tracker->log(LogTag::Run, 'Anonymizing customers.');
        $this->connection->executeStatement('UPDATE customer SET email = CONCAT("user", id, "@example.com")');
        $tracker->progress(Percentage::complete());
    }
}
```

A step never touches the job: it talks to the `StepTracker`, which logs, reports progress and
answers `abortRequested()`. Throwing from `run()` fails the job and records the message.

Label and description go through the `DumpLoaderBundle` translation domain.

The `AnonymizeCustomers` example above is deliberately minimal. For anonymization beyond a single
`UPDATE`, see [`gilles-g/db-anonymizer`](https://github.com/gilles-g/db-anonymizer): it declares
rules in YAML, keeps the generated SQL script's size independent of the row count, and plugs into
this bundle as an `OptionalStep` at rank 250 — after migrations, so it targets the up-to-date schema
rather than the schema of the dump.

### Steps configured from `dump_loader.steps`

A library can let the application configure its step in YAML without a service definition of its
own: it ships a `DumpLoader\Step\StepFactory`, autoconfigured like a step. Its static
`configurationKey()` names an entry under `steps`, and `create()` receives that entry as an array
and returns the `Step`.

```yaml
dump_loader:
    steps:
        anonymization:
            seed: '%env(ANONYMIZER_SEED)%'
            tables:
                users:
                    columns:
                        email: { mask: email }
```

This is the entry [`gilles-g/db-anonymizer`](https://github.com/gilles-g/db-anonymizer) reads; its
README lists the options. Each entry becomes a step at container compilation. A key that no
installed factory handles fails the build and lists the keys it knows; the options themselves are
validated by the factory when the step is first built.

## Architecture

`src/` holds one folder per capability; ports and value objects sit at the root of each folder,
technical implementations in a subfolder named after the technology. `LoadJob` is the aggregate
root and the only writer of its JSON file; steps never touch it, they report to a `StepTracker`.
Job state is stored as files, never in the database — the database is precisely what gets
overwritten. `Monitor` holds the second kernel: its read model, its controllers and the wiring of
the handful of services it needs, none of which come from the application container.

## Commands

| Command | Purpose |
|---|---|
| `bin/console dump-loader:check` | Reports whether the host can run a job — the same report the UI shows as a banner |
| `bin/console dump-loader:run <id>` | Runs a job that was already requested; this is what the launcher detaches |
| `bin/console dump-loader:load [dump]` | Chooses a dump and its options, confirms, starts the job and follows it to a final report |

`dump-loader:load` goes through the same use cases as the dashboard: one job at a time, the same
history, the configured launcher. It asks for whatever the command line leaves out, then follows the
job until it is over; Ctrl+C asks for its abort, like the button of the monitor.

| Option | Effect |
|---|---|
| `--option=NAME` | Runs this optional step; repeatable. Without it, the options are asked, or the defaults apply with `-n` |
| `--no-options` | Runs no optional step |
| `--force` | Drops every table of the target database without asking; required with `-n` |
| `--detach` | Returns as soon as the job has started; follow it in the monitor |
| `--requester=NAME` | Recorded as the requester; defaults to `$USER` |
| `--no-tui` | Keeps the plain console output |

With [`symfony/tui`](https://symfony.com/doc/current/tui.html) installed (PHP 8.4.1+, Symfony 8.1+)
and an interactive terminal, the choices and the follow-up use a rich terminal UI; otherwise the
command falls back to the console. From a CI, without any question:

```bash
bin/console dump-loader:load dumps/prod.sql.gz --option=run_migrations --force -n
```

The exit code is `0` only when the job completed.

## Testing

Everything goes through the Makefile:

```bash
make quality   # cs-fix + phpstan + the whole test suite
make test      # tests only (purges the test kernel cache first)
make ci        # same checks as the CI, without writing anything
make coverage  # coverage report (needs pcov or xdebug)
```

`make help` lists every target.

The tests in the `mysql` group restore real `mysqldump` output into a real server, so they are
excluded from the default run. They need a reachable server in `DUMP_LOADER_TEST_DSN` and a client
binary, optionally pinned with `DUMP_LOADER_TEST_MYSQL_BINARY`:

```bash
make test-mysql-docker  # against the MariaDB of compose.yml
make test-mysql         # against the server in DUMP_LOADER_TEST_DSN
```

## Known pitfalls

- The MariaDB client refuses a server without TLS since 11.4 (`ERROR 2026 (HY000): TLS/SSL error:
  SSL is required, but the server does not support it`), and recent PHP images ship that client.
  The bundle therefore writes `skip-ssl` for it in a `[client-mariadb]` group; set `mysql.ssl: true`
  when the server requires TLS. The MySQL client never reads that group and negotiates TLS on its own.
- `DEFINER=` in views and triggers fails with error 1449 when the user does not exist on the target:
  produce dumps without definers.
- `max_allowed_packet` on the server side, and `mysqldump --hex-blob` for binary columns.
- Aborting during `doctrine:migrations:migrate` leaves an inconsistent schema: MySQL DDL is not
  transactional.
- Kernel boot listeners hitting the database while it is empty.
- An aborted restore leaves a partial database; the UI says so.
- A job file the bundle cannot read, written in another snapshot format or edited by hand, blocks
  every new load, since it may hide a running job. The dashboard names the file: remove it once no
  load runs.
- The access to the monitor is only as strong as the protection of the dashboard, which hands it
  out: keep `^/dump-loader` behind `access_control`, and the monitor behind the `APP_ENV` guard. It
  is signed with `APP_SECRET`, which both kernels must see; with a `framework.secret` that does not
  come from `APP_SECRET`, every access is refused. A bookmark to the monitor answers 403 once its
  access has expired: open it again from the dashboard.
- The monitor follows the settings published at the last cache warm-up. Until the cache is warmed up
  again, a change of `var_dir`, `pending_grace_period` or `retention` makes it show no job, or judge
  a pending job lost too early. With no published file, it reads the defaults.

## Contributing

Issues and pull requests are welcome. Run `make quality` before opening one.

## Upgrading

See [UPGRADE.md](UPGRADE.md) for what is covered by backward compatibility and what is not.

## License

MIT — see [LICENSE](LICENSE).

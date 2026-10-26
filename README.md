# dump-loader

Load a SQL dump into your Symfony app's database from a web UI, and watch it run live.

> [!WARNING]
> Drops every table of the target database. Dev and staging only, behind an authenticated route.

- Pick a dump (`.sql`, `.sql.gz`), tick options, follow steps, progress and log in real time.
- Abort at any time. Every job is kept in history.
- Streaming restore: memory stays flat, whatever the dump size.
- The monitor runs on its own kernel and keeps answering while the app cannot boot.
- Same flow from the terminal, scriptable in CI.
- Add your own steps: password reset, anonymization…

## Requirements

- PHP 8.2+ with `zlib` and `pdo_mysql`, Symfony 6.4, 7.4 or 8.0
- MySQL or MariaDB, and the `mysql` client in the PHP image
- `clear_env = no` in the php-fpm pool
- `var/dump-loader` writable, `bin/console` executable by php-fpm
- Linux or macOS

## Install

```bash
composer require gilles-g/dump-loader
```

Register `DumpLoader\DumpLoaderBundle::class => ['all' => true]` in `config/bundles.php`.

`config/packages/dump_loader.yaml`

```yaml
dump_loader:
    source:
        directory: '%kernel.project_dir%/var/dumps'
    target_database:
        dsn: '%env(DATABASE_URL)%'
```

`config/routes/dump_loader.yaml`

```yaml
dump_loader:
    resource: '@DumpLoaderBundle/Resources/config/routes.php'
    prefix: /dump-loader
```

`config/packages/security.yaml`

```yaml
security:
    access_control:
        - { path: ^/dump-loader, roles: ROLE_ADMIN }
```

`public/index.php`: mount the monitor before the app kernel, so it survives the `DROP TABLE`.

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

Keep the `APP_ENV` guard: without it, the monitor answers in production.

Then check the host, drop a dump in `var/dumps` and open `/dump-loader`:

```bash
bin/console dump-loader:check
```

## Command line

```bash
bin/console dump-loader:load
bin/console dump-loader:load dumps/prod.sql.gz --option=run_migrations --force -n
```

Interactive by default, fully scripted with `-n`. Exits `0` only if the load completed.

## Run a command after the load

```yaml
dump_loader:
    after_load:
        reset_admin_password:
            label: 'Reset the admin password'
            command: ['dbal:run-sql', 'UPDATE admin_user SET password = "%env(ADMIN_PASSWORD)%" WHERE id = 1']
```

Shows up as a checkbox in the UI. Need PHP? Implement `DumpLoader\Step\OptionalStep`, see
[custom steps](doc/steps.md).

## Documentation

- [Configuration](doc/configuration.md)
- [Custom steps](doc/steps.md)
- [Command line](doc/cli.md)
- [How it works](doc/how-it-works.md)
- [Troubleshooting](doc/troubleshooting.md)
- [Upgrading](UPGRADE.md)

## Contributing

Run `make quality` before opening a pull request. `make help` lists every target.

## License

MIT, see [LICENSE](LICENSE).

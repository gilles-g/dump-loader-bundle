# Configuration

| Key | Default | Purpose |
|---|---|---|
| `source.directory` | — | Directory holding the dumps (exclusive with `source.flysystem`) |
| `source.flysystem` | — | Service id of a `FilesystemOperator` (S3, SFTP…) |
| `target_database.dsn` | — | DSN of the database being overwritten |
| `mysql.binary` | `mysql` | Path to the MySQL client |
| `mysql.ssl` | `false` | Let the MariaDB client use TLS, see [troubleshooting](troubleshooting.md) |
| `console` | `%kernel.project_dir%/bin/console` | Console entry point used by the steps |
| `php_binary` | `PhpExecutableFinder` | PHP binary running the detached job |
| `var_dir` | `%kernel.project_dir%/var/dump-loader` | Job files, logs and signals |
| `monitor_path` | `/dump-loader/monitor` | Must match the mount path in `public/index.php` |
| `execution` | `detached` | `detached` or `synchronous` |
| `pending_grace_period` | `60` | Seconds a pending job has to take its lock |
| `retention` | `50` | Finished jobs kept; older ones are deleted on the next request |
| `after_load` | `[]` | Console commands offered once the load is over |
| `steps` | `[]` | Steps built by a `StepFactory`, see [custom steps](steps.md#from-yaml) |

## after_load

```yaml
dump_loader:
    after_load:
        reset_admin_password:
            label: 'Reset the admin password'
            description: 'Puts back a known password on the admin account.'
            command:
                - 'dbal:run-sql'
                - 'UPDATE admin_user SET password = "%env(ADMIN_PASSWORD)%" WHERE id = 1;'
        anonymize_emails:
            enabled_by_default: false
            command: ['app:anonymize-emails']
```

- Each key becomes an optional step, after the built-in ones, in declaration order.
- One item of `command` is one argument to `bin/console`. No shell, no escaping.
- A literal `%` is written `%%`.
- Env vars are resolved at run time. Arguments never reach the log, only the label.
- `label` defaults to the key, `enabled_by_default` to `true`.
- Keys: lowercase letters, digits, underscores. No built-in step name.

## Monitor settings

The monitor boots before the app configuration. On every cache warm-up, the bundle publishes
`var_dir`, `pending_grace_period` and `retention` to `var/dump-loader.monitor.json`. A
`DumpLoader\Monitor\MonitorSettings` passed as fifth argument of `MonitorKernel` overrides it.

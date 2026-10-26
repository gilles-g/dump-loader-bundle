# Command line

| Command | Purpose |
|---|---|
| `dump-loader:check` | Can this host run a job? Same report as the UI banner |
| `dump-loader:load [dump]` | Pick, confirm, run and follow a load to its final report |
| `dump-loader:run <id>` | Run an already requested job (what the launcher detaches) |

`dump-loader:load` shares the dashboard's rules: one job at a time, same history. It asks for
whatever is missing. Ctrl+C aborts the job.

| Option | Effect |
|---|---|
| `--option=NAME` | Run this optional step, repeatable |
| `--no-options` | Run no optional step |
| `--force` | Drop the tables without asking, required with `-n` |
| `--detach` | Return once the job has started |
| `--requester=NAME` | Recorded requester, defaults to `$USER` |
| `--no-tui` | Plain console output |

With [`symfony/tui`](https://symfony.com/doc/current/tui.html) (PHP 8.4.1+, Symfony 8.1+) and an
interactive terminal, you get a rich terminal UI.

```bash
bin/console dump-loader:load dumps/prod.sql.gz --option=run_migrations --force -n
```

Exit code `0` only when the job completed.

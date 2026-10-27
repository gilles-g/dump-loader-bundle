# Upgrading

This file records the changes an application has to make when upgrading `gilles-g/dump-loader-bundle`.
It follows [semantic versioning](https://semver.org): only major versions may break an application.

## What is covered by backward compatibility

Public and documented:

- the configuration tree under `dump_loader:`;
- the routes exposed by `Resources/config/routes.php` and their names — `dump_loader_dashboard`,
  `dump_loader_request` and `dump_loader_open_monitor`; the progress and abort routes now belong to
  the monitor kernel, under `Resources/config/monitor/routes.php`;
- `DumpLoader\Monitor\MonitorKernel` and its constructor `(string $environment, bool $debug,
  string $projectDir, string $mountPath, ?MonitorSettings $settings = null)`, mounted from an
  application's `public/index.php`, and `DumpLoader\Monitor\MonitorSettings`;
- the extension points `DumpLoader\Step\Step`, `DumpLoader\Step\OptionalStep` and
  `DumpLoader\Step\StepFactory`, and everything their
  signatures reach: `StepDefinition`, `LoadContext`, `StepTracker`, `Percentage`, `LogTag`;
- the service aliases an application can inject: `DumpSource`, `DatabaseClient`, `RestorePolicy`,
  `ProcessRunner`, `LoadJobRepository`, `AbortSignal`, `JobLock`, `LoadJobLauncher`,
  `TargetDatabase`, `StepSequence`, `CurrentRequester`, `CheckRequirements`, `DashboardQuery`,
  `MonitorAccess`;
- the commands `dump-loader:check`, `dump-loader:run` and `dump-loader:load` — its arguments, options
  and exit codes, not the look of its rich terminal UI, which rests on the experimental `symfony/tui`;
- the Twig templates under `@DumpLoader`, overridable from `templates/bundles/DumpLoaderBundle/`;
- the translation keys of the `DumpLoaderBundle` domain.

Not covered — these may change in any release:

- the classes under `DumpLoader\*\<Technology>\` (`Flysystem`, `Mysql`, `Process`, `File`), which are
  implementations of the ports listed above;
- the layout of the files written under `var_dir`, except the `"version"` field of a job snapshot,
  which is bumped when the format changes, and of `var/dump-loader.monitor.json`;
- the HTML structure and the CSS classes of the templates;
- everything under `DumpLoader\Tests\`.

Adding a method to a port is a breaking change for applications that implement it by hand, and only
happens in a major version.

## Job snapshot format

Job files carry a `"version"` field. Reading a snapshot written in another format is refused rather
than guessed, and such a file blocks every new load until it is removed, since it may hide a running
job. Jobs are history, not state to migrate: after a major upgrade that changes the format, wait for
the running load to end, then empty `var/dump-loader/jobs`.

## Unreleased

`DatabaseClient` gained `dropAllTables(TargetDatabase $database, StepTracker $tracker): void`. An
application implementing that port by hand has to implement the method; the aliases and the bundled
`Mysql` implementation need nothing.

A load now starts with the mandatory `drop_all_tables` step, which drops every view and table of the
target database before the restore. A job planned by an earlier version and not yet run keeps its
frozen sequence, and so never drops anything.

That step first asks the new `DumpLoader\Database\RestorePolicy` port whether the dump can be
restored, and drops nothing when it cannot. Requesting a job asks the same question. A refusal
implements the new `DumpLoader\Dump\DumpNotRestorable` interface, which `CorruptedDump` implements
too. An application that constructs `DropAllTables` or `RequestLoadJobHandler` itself has to pass
the policy: `dump_loader.restore_policy` is the bundled one.

`LoadJobRepository::get()` throws the new `DumpLoader\LoadJob\UnreadableLoadJob` for a job that
exists but cannot be read, where it threw `LoadJobNotFound`. `active()` throws it too instead of
leaving that job out, so the dashboard refuses new loads and the monitor stops following; `history()`
still leaves it out. An application implementing `LoadJobRepository` by hand should do the same, or an
unreadable job lets a second load start.

The monitor now refuses, with a 403, any browser that holds no access handed over by the dashboard.
The redirect after a request, and the new `dump_loader_open_monitor` route behind the "Follow it
live" link, grant that access for eight hours, signed with `APP_SECRET`. A link to the monitor
from the application's own pages has to point to `dump_loader_open_monitor`, or return
`MonitorAccess::open()`. Nothing changes for an application that only uses the dashboard. An
overridden `_active.html.twig` no longer receives `monitorPath`: link to
`path('dump_loader_open_monitor')` instead.

The monitor now follows `var_dir`, `pending_grace_period` and `retention` as set under
`dump_loader:`: a cache warmer publishes them to `var/dump-loader.monitor.json`, which `MonitorKernel`
reads when it is given no settings. Warm the cache up once after the upgrade. `MonitorKernel` takes
an optional `MonitorSettings` as its fifth argument, which wins over the published file; an
application passing it only to repeat its configuration can drop it. The cache of the monitor
container depends on those settings, so the first request after the upgrade compiles it again.

An abort refused because of an invalid token, an unknown job or an unreadable job file now shows a
message on the monitor instead of redirecting silently.

`retention` now deletes files instead of only trimming the history on display. Each request prunes
the finished jobs beyond it, with their log, lock and abort files; active jobs and unreadable job
files are never touched. `LoadJobRepository` gained `prune(int $keep): void`, which an application
implementing that port by hand has to implement, and `RequestLoadJobHandler` takes the retention as
its last argument: `%dump_loader.retention%`.

The target database is now read from its DSN when it is used, through the new
`DumpLoader\Database\TargetDatabaseDsn` (`dump_loader.target_database_dsn`). With an unreadable DSN,
the dashboard now opens: it lists that requirement as unmet and shows no request form, where it used
to answer a 500. `DropAllTables`, `RestoreDatabase` and `DashboardQuery` take a `TargetDatabaseDsn`
in place of a `TargetDatabase`. `CheckRequirements` takes it in place of the DSN string, and a
`DumpLoader\Database\Mysql\MysqlBinary` (`dump_loader.mysql.binary`) in place of the binary name. An
application constructing any of them itself has to follow. The `TargetDatabase` alias is unchanged.
`dump-loader:check` now looks for the MySQL client the way a load does: it accepts an absolute path
and falls back to `mariadb`.

The MySQL credentials file is no longer written in the system temporary directory when `var_dir`
cannot hold it: the load fails instead. A DSN whose host, user, password or charset holds a line
break is refused.

The monitor now follows a load as a vertical timeline: each step done shows how long it took, the
running step shows the last lines of the log, and the full log folds away. Steps are timed, so a
job snapshot gains `startedAt` and `finishedAt` on each step; the format version is unchanged and a
job written earlier is still read, its steps simply show no duration. The translation keys
`progress.title` and `progress.step` are gone, replaced by `progress.heading.<status>` and the other
new `progress.*` keys: an application overriding them has to follow. The monitor carries its own
styles in `Resources/views/monitor.css`, inlined in the page, so it needs no asset of the
application.

`dump_loader.steps` is new and optional: each entry is handed to the `DumpLoader\Step\StepFactory`
whose static `configurationKey()` matches, which builds a step. A key that no installed factory
handles now fails the container build. Nothing changes for an application that does not use it.

## 1.0.0

First release; nothing to upgrade from.

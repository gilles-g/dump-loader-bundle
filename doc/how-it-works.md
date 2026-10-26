# How it works

1. **Check.** The `RestorePolicy` reads the dump header and refuses a missing file, a broken gzip,
   or a `mysqldump --databases` dump. Nothing is dropped.
2. **Freeze.** The step sequence is planned and saved in `var/dump-loader/jobs/<id>.json`.
3. **Launch.** `dump-loader:run <id>` runs detached and holds a `flock` on `<id>.lock`.
4. **Drop.** Every view and table goes, so tables created by later migrations cannot clash with
   the restored `doctrine_migration_versions`.
5. **Restore.** The dump is streamed into the `mysql` client.
6. **Follow.** The browser polls the monitor every second.

## Monitor

A second kernel, FrameworkBundle and TwigBundle only. It reads `<var_dir>/jobs` and nothing else,
so it keeps answering while the app kernel cannot boot.

It has no SecurityBundle. The dashboard hands out access instead: a cookie scoped to
`monitor_path`, signed with `APP_SECRET`, valid 8 hours. No cookie, 403.

## Abort and crash

- **Abort:** an `<id>.abort` file. The job stops `mysql` with `SIGTERM` and ends `Aborted`.
- **Crash** (`kill -9`, container restart): the lock is released, the job shows as `Lost`.

Both leave a partial database. The UI says so.

## Layout

One folder per capability in `src/`: ports and value objects at the root, implementations in a
subfolder named after the technology. `LoadJob` is the aggregate and sole writer of its file. Job
state lives in files, never in the database: the database is what gets overwritten.

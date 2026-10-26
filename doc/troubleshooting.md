# Troubleshooting

| Symptom | Fix |
|---|---|
| `ERROR 2026: SSL is required, but the server does not support it` | MariaDB client 11.4+. The bundle disables TLS by default; set `mysql.ssl: true` if the server needs it |
| Error 1449 on views or triggers | Produce dumps without `DEFINER=` |
| Restore fails on large rows | Raise `max_allowed_packet` on the server |
| Corrupted binary columns | Dump with `mysqldump --hex-blob` |
| Broken schema after an abort | Aborted during migrations: MySQL DDL is not transactional. Reload |
| App crashes while loading | A kernel boot listener queries the empty database |
| Every load refused, a job file named | Unreadable job file. Delete it once no load runs |
| Monitor answers 403 | Access expired: reopen it from the dashboard |
| Every monitor access refused | `framework.secret` does not come from `APP_SECRET` |
| Monitor shows no job | `var_dir` changed: warm up the cache |

Security of the monitor is that of the dashboard: keep `^/dump-loader` behind `access_control` and
the monitor behind the `APP_ENV` guard.

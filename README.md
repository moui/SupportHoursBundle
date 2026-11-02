# SupportHoursBundle

[![CI](https://github.com/moui/SupportHoursBundle/actions/workflows/linting.yaml/badge.svg)](https://github.com/moui/SupportHoursBundle/actions/workflows/linting.yaml)

A Kimai plugin that answers "how many hours did we spend on support last month, and for which customers?". You decide which projects count as support (by project name, by individual project or by whole customer); the plugin adds a report under *Reporting*, an Excel workbook with one sheet per customer, and a console command that emails that workbook every month.

It adds no database tables and runs no raw SQL: data is read through Doctrine (DQL) and settings are stored in Kimai's configuration table.

## Features

- **Report** at *Reporting → Support hours*: a month or a date range, a customer filter, totals (hours, entries, customers, users), a per-customer summary and a paginated detail list.
- **Excel download** of the current filters: one sheet per customer plus a summary sheet.
- **Settings page** to choose what counts as support and who receives the monthly email, with a live list of the projects the saved rules match and why.
- **Console command** `kimai:support-hours:export` that writes the workbook and/or emails it; dry-run unless `--send`, so it is safe to schedule.
- Translated into English and Spanish.

## Requirements

- Kimai 2.66 or later
- PHP 8.2 or later

## Installation

Copy the plugin into your Kimai plugins directory. The directory must be called `SupportHoursBundle` (Kimai rejects plugin directories that contain a version number):

```bash
cd /opt/kimai/var/plugins/
git clone https://github.com/moui/SupportHoursBundle.git
```

Or download a release and extract it to `var/plugins/SupportHoursBundle`.

Then clear the cache:

```bash
cd /opt/kimai/
bin/console kimai:reload -n
```

The plugin has no database migrations, so no install command is needed.

### Docker

Mount or copy the plugin into `/opt/kimai/var/plugins/SupportHoursBundle` and reload as the web server user:

```bash
docker compose exec -u www-data kimai bin/console kimai:reload -n
```

Running as `www-data` matters: run as root, `kimai:reload` rebuilds `var/cache` owned by root, Apache can no longer write to it, and the JSON API starts failing. If that happens, repair it with `docker compose exec kimai chown -R www-data:www-data /opt/kimai/var/cache`.

## Configuration

Everything is edited on *Reporting → Support hours → Settings*.

### What counts as support

A timesheet is support when its project matches **any** of these rules:

| Rule | Example | Notes |
|---|---|---|
| Project name | every project called `Support`, for every customer | Exact name, comma-separated list. New projects with that name are picked up without touching the settings. |
| Individual projects | `Viewer [Customer X]` picked from the list | For one-off projects with another name. |
| Whole customers | every project of customer *ACME* | Includes projects created later. |

**Nothing is configured by default.** Until at least one rule is saved, the report shows a hint linking to the settings page instead of an empty table, and the console command warns and exits without creating a file or an email. The settings page refuses to save without at least one rule.

The settings page also lists the projects the *saved* rules match, with the reason for each (name, project added, whole customer); hidden projects and customers are shown greyed out. A project or customer that was hidden after being picked stays selectable, so saving the page never silently drops it.

Other rules worth knowing:

- Entries of **disabled** users are excluded.
- **Running** timesheets (no end time) are skipped.
- An entry belongs to the day of its *local* date (`date_tz`), not its UTC start time, so an entry recorded around midnight never moves to another month.

### Recipients

One email address per line (or comma-separated). They are used by `kimai:support-hours:export` when `--email` is not given. Invalid addresses are rejected on save.

### Storage

Values live in Kimai's configuration table as `support_hours.recipients`, `support_hours.project_names`, `support_hours.project_ids` and `support_hours.customer_ids` (comma-separated). A key without a saved row uses the default (empty); a saved empty value means "none". Saving goes through Kimai's configuration service, so its cache is refreshed immediately.

## Report

*Reporting → Support hours* (`/{locale}/reporting/support-hours`) defaults to the previous month. You can pick a month, or a date range (which takes precedence over the month), and filter by customer. The customer filter only offers customers with support hours in the selected period.

The page shows:

- totals: hours, entries, customers and users;
- a per-customer summary, largest first;
- the detail list with `Project [Customer]` labels, 50 entries per page.

**Excel** downloads the workbook for the current filters, in the language of the current user.

## Workbook

- One sheet per customer with the columns *Date*, *User (Email)*, *Duration (hours)*, *Description* and a bold *TOTAL* row. Sheet names follow Excel's rules (31 characters, no `[]:*?/\`, unique).
- Then a *Summary* sheet, customers sorted by hours, with a *GRAND TOTAL* row.

Sheet names and headers are translated. The file is named `support_hours_<Month>_<Year>.xlsx` (month name in the workbook's language, e.g. `support_hours_September_2026.xlsx`), or `support_hours_<from>_to_<to>.xlsx` for a custom range.

## Permissions

| Permission | Default roles | Grants |
|---|---|---|
| `support_hours_report` | `ROLE_ADMIN`, `ROLE_SUPER_ADMIN` | View the report and download the workbook. |
| `support_hours_settings` | `ROLE_ADMIN`, `ROLE_SUPER_ADMIN` | Change what counts as support and the email recipients. |

Both also require Kimai's `view_reporting`. The report lists every customer's support entries with descriptions, so it starts admin-only; grant the permissions to other roles on Kimai's *Roles* screen if needed.

## Console commands

```bash
# Previous month: print totals and preview the email to the saved recipients (dry-run)
bin/console kimai:support-hours:export

# A given month: write the file and send it
bin/console kimai:support-hours:export \
  --month=2026-09 --output=/opt/kimai/var/data --email=reports@example.com --send
```

| Option | Meaning |
|---|---|
| `--month` | `YYYY-MM`; default previous month |
| `--from` / `--to` | explicit range `YYYY-MM-DD`; overrides `--month` |
| `--output` | directory to write the `.xlsx` into (in the official Docker image, `/opt/kimai/var/data` is the persistent volume) |
| `--email` | recipient, repeatable; defaults to the recipients saved on the settings page |
| `--send` | actually send; otherwise the email is only previewed |

Recipients are arbitrary addresses, so the workbook and the email are written in Kimai's default user language (system settings). Mail goes through Kimai's mailer (`MAILER_URL` / `MAILER_FROM`); check it first with `bin/console kimai:mail:test you@example.com`. Periods without support hours produce neither a file nor an email.

To run it monthly, add a crontab entry on the host, for example:

```cron
0 7 1 * * cd /path/to/kimai-compose && docker compose exec -T -u www-data kimai bin/console kimai:support-hours:export --email=reports@example.com --send
```

In Docker, prefix the commands with `docker compose exec -u www-data kimai`.

## Translations

The plugin ships English (`en`, the fallback) and Spanish (`es`) in `Resources/translations/`, in two domains: `messages` (page texts, workbook and email) and `reporting` (the report title in the *Reporting* menu). Console output is always English.

To add a language, copy `messages.en.xlf` and `reporting.en.xlf` to `messages.<locale>.xlf` and `reporting.<locale>.xlf`, set `target-language` and translate the `<target>` elements. Then run `bin/console kimai:reload -n`. Pull requests with new languages are welcome.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT, see [LICENSE](LICENSE).

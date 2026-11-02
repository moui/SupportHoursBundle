# Changelog

## 0.3.0

- First public release: translations (English, Spanish) for the report, the settings page,
  the Excel workbook and the export email. Console output is English.
- **Default change:** no support criterion is configured out of the box (all of
  `support_hours.project_names`, `project_ids` and `customer_ids` default to empty). Until
  one is saved, the report shows a hint linking to the settings page and
  `kimai:support-hours:export` warns and exits without a file or email. Installations that
  relied on the previous built-in defaults must save their rules on the settings page.
- Period labels (report, email subject, workbook filename) use the month name of the
  current or default locale instead of a fixed Spanish name; date-range labels and the
  detail table's dates use locale-aware formats.
- The workbook (sheet names, headers, total rows) is translated: the web download uses the
  current user's language, the console command Kimai's default language.
- Removed company-specific defaults, examples and references to the previous tooling.
- Repository tooling: MIT license, PHP-CS-Fixer, PHPStan (level 9) and a GitHub Actions
  linting workflow.

## 0.2.0

- New **Settings** page (Reporting → Support hours → Settings, permission
  `support_hours_settings`, admins by default): pick individual projects and whole customers
  from searchable lists, keep the project-name rule, manage the monthly report's
  recipients, and see which projects currently count as support.
- Settings are stored in Kimai's configuration table (`support_hours.*`) instead of the
  `SUPPORT_HOURS_*` environment variables, which are gone.
- `kimai:support-hours:export` emails the configured recipients when `--email` is omitted.
- The report's detail table is paginated (50 per page) and the customer filter is
  searchable.

## 0.1.0

- First release, targeting Kimai 2.66 (`extra.kimai.require` 26600) and PHP 8.2+.
- Report **Reporting → Support hours**: month or date range, customer filter, summary
  cards, per-customer totals, detail table and an Excel download. Admin-only by default
  (`support_hours_report` permission).
- Console command `kimai:support-hours:export`: one sheet per customer plus a summary,
  optional email through Kimai's mailer. Dry-run unless `--send`.
- Support criterion configurable with `SUPPORT_HOURS_PROJECT_NAMES` /
  `SUPPORT_HOURS_CUSTOMER_IDS`.

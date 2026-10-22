# however (How(ever) / How2 archive)

Drupal site for the digital archive of the literary journals How(ever) and How2.

## Platform and local development

- Platform: Drupal, core 11.3.13 per `composer.lock`.
- Local environment: DDEV, project name `however-drupal`.
- DDEV project type in `.ddev/config.yaml`: `drupal10`.
- Project root: `/Users/gilbert/Sites/LINODE/var/www/html/however/`
- Web root: `web/`
- PHP: 8.4 (`.ddev/config.yaml`; the `composer.json` platform setting matches).
- Database: MySQL 8.0. Web server: nginx-fpm.
- Run Drupal commands through DDEV (`ddev drush`, `ddev composer`).
- `settings.php` and `settings.local.php` are not tracked by Git.

## Code locations

- Custom module: `web/modules/custom/however_customizations` (see its `README.md`).
- Other custom modules: `web/modules/custom/prf_ckeditor_footnotes`, `web/modules/custom/prf_references`.
- Custom theme: `web/themes/custom/however` (see its `README.md`; asset build scripts are in its `package.json`).
- Configuration export: `config/` in the project root.

## Environments

- Public site: `howeverhow2archive.org`, hosted on a university library server. Project root on that server: `/var/www/html/drupal`.
- Staging copy: `however.osmarea.com`. Project root on that server: `/var/www/html/however`.
- Deploy steps used previously (from the 2026.06.02a security-updates log): `git pull`, `composer install`, `drush updb -y`, `drush cr`.

## Unknowns to confirm

- Active config sync directory: `settings.ddev.php` sets `sites/default/files/sync` (not present locally) and `settings.local.php` sets `../config`. Confirm before running any config import or export.
- Git remote and default branch: not recorded here.

## Working rules for this project

- Ask before running Git, Composer, npm, or any linter or formatter.
- Commands that change the database or configuration (`updb`, `cim`, `cex`, `sql-*`) need approval, and are never run against production.
- Site notes and branch logs live in `/Users/gilbert/Sites/LINODE/home/bixgomez/dev_assets/site_notes/however/`.
- Start with `PROJECT_OVERVIEW.md` in that directory; `TO_DO.md` and `OPEN_QUESTIONS.md` hold current status.

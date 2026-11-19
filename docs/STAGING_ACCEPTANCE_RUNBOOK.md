# PARSIAN FINAL — Staging acceptance runbook

This runbook is for an isolated WordPress **Staging** site only. Do not run the fixture tests on Production.

## 1. Before updating the plugin

1. Confirm the hostname is the Staging hostname and the database is not shared with Production.
2. Export a database backup using the hosting panel or WP-CLI. Example from the WordPress root:

   ```bash
   wp db export "$HOME/parsian-staging-before-0.4.11.sql"
   ```

3. Keep the backup outside the public web root. Confirm that the file exists and is non-empty.
4. Install the generated PARSIAN FINAL development ZIP from the GitHub Actions package artifact. Do not merge PR #21 and do not update Production.

## 2. Static PHP validation

Run from the WordPress root:

```bash
find wp-content/plugins/parsian-final -type f -name '*.php' -print0 | xargs -0 -n1 php -l
wp plugin is-active parsian-final
```

If the plugin directory slug differs, replace the path and plugin slug with the installed directory name.

## 3. Confirm environment and migration

```bash
wp eval 'echo "environment=" . wp_get_environment_type() . PHP_EOL; echo "plugin_schema=" . get_option("parsian_final_schema_version", "missing") . PHP_EOL;'
wp eval 'global $wpdb; $t=$wpdb->prefix . "parsian_auth_tokens"; echo ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t)) === $t) ? "auth_table=present
" : "auth_table=MISSING
";'
```

The environment must report `staging` (or a clearly isolated non-production environment), schema should report `0.4.9`, and the token table must be present.

## 4. Run acceptance harnesses

Run each command as a separate WP-CLI process:

```bash
wp eval 'define("PARSIAN_ALLOW_FINANCE_TESTS", true); require WP_PLUGIN_DIR . "/parsian-final/tests/finance-ledger-acceptance.php";'
wp eval 'define("PARSIAN_ALLOW_FINANCE_TESTS", true); require WP_PLUGIN_DIR . "/parsian-final/tests/auth-sync-acceptance.php";'
```

These tests create temporary QA records and clean them up. The test guard blocks a Production environment.

## 5. REST health check

```bash
curl -fsS "https://YOUR-STAGING-HOST/wp-json/parsian-final/v1/health"
```

Expected fields include `status=ok`, plugin version `0.4.9`, and the current schema version.

## 6. Manual role checks

- Manager: can open PARSIAN OS, see manager modules, assign a customer to a seller, link a customer login, and export/validate a database snapshot.
- Accountant: can see finance modules but must not see seller CRM or manager backup/assignment controls.
- Seller: sees only customers assigned to that seller, owned leads and their own proformas; no inventory module.
- Customer: must be linked through the manager-only endpoint and sees only that customer's invoices, proformas and payment records.
- Windows: test from a clean Windows VM after an MSI/NSIS artifact exists. Confirm login, refresh, device revoke, and credential removal on logout.
- Android: install both debug flavors on test devices; verify login, assigned data scoping, offline lead draft, reconnect/push, pull cursor, and logout data purge.

## 7. Return results

Share only sanitized command output and error messages. Do not paste passwords, access/refresh tokens, database dumps, customer personal data, or production URLs.

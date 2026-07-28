#!/usr/bin/env bash
set -euo pipefail

archive="${1:?Plugin ZIP path is required}"
topology="${2:?Topology is required}"
wp_path="${RUNNER_TEMP:?RUNNER_TEMP is required}/wordpress"
root_url="https://example.test"

case "${topology}" in
  subdirectory|domain-mapped)
    convert_args=()
    ;;
  subdomain)
    convert_args=(--subdomains)
    ;;
  *)
    echo "Unsupported Multisite topology: ${topology}" >&2
    exit 2
    ;;
esac

table_exists() {
  local table="$1"
  test "$(wp db query "SHOW TABLES LIKE '${table}';" --skip-column-names --path="${wp_path}")" = "${table}"
}

table_absent() {
  local table="$1"
  test -z "$(wp db query "SHOW TABLES LIKE '${table}';" --skip-column-names --path="${wp_path}")"
}

scalar() {
  wp db query "$1" --skip-column-names --path="${wp_path}"
}

wp core download --version="6.8" --path="${wp_path}" --force
wp config create --path="${wp_path}" --dbname=wordpress --dbuser=root --dbpass=root --dbhost=127.0.0.1:3306
wp core install --path="${wp_path}" --url="${root_url}" --title=UCCM --admin_user=admin --admin_password=test-password --admin_email=admin@example.test --skip-email
wp core multisite-convert --path="${wp_path}" --title=UCCM "${convert_args[@]}"

existing_id="$(wp site create --path="${wp_path}" --slug=existing --title=Existing --email=existing@example.test --porcelain)"
test "${existing_id}" = "2"

case "${topology}" in
  subdirectory)
    existing_url="https://example.test/existing"
    ;;
  subdomain)
    existing_url="https://existing.example.test"
    ;;
  domain-mapped)
    wp site update "${existing_id}" --path="${wp_path}" --domain=tenant.example.test --path=/
    existing_url="https://tenant.example.test"
    ;;
esac

unzip -q "${archive}" -d "${wp_path}/wp-content/plugins"

# Individual-site activation must initialize only the selected site.
wp plugin activate uk-cookie-consent-manager --path="${wp_path}" --url="${root_url}"
table_exists "wp_uccm_consents"
table_absent "wp_2_uccm_consents"
wp plugin deactivate uk-cookie-consent-manager --path="${wp_path}" --url="${root_url}"

# Network Activation must initialize all existing sites.
wp plugin activate uk-cookie-consent-manager --network --path="${wp_path}"
wp plugin is-active uk-cookie-consent-manager --network --path="${wp_path}"
table_exists "wp_uccm_consents"
table_exists "wp_2_uccm_consents"

# A site created after Network Activation must receive its schema and schedules.
later_id="$(wp site create --path="${wp_path}" --slug=later --title=Later --email=later@example.test --porcelain)"
test "${later_id}" = "3"

case "${topology}" in
  subdirectory)
    later_url="https://example.test/later"
    ;;
  subdomain)
    later_url="https://later.example.test"
    ;;
  domain-mapped)
    wp site update "${later_id}" --path="${wp_path}" --domain=later.example.test --path=/
    later_url="https://later.example.test"
    ;;
esac

table_exists "wp_3_uccm_consents"

for site_url in "${root_url}" "${existing_url}" "${later_url}"; do
  wp cron event list --url="${site_url}" --path="${wp_path}" --fields=hook --format=csv > "${RUNNER_TEMP}/cron.csv"
  grep -q '^uccm_monthly_scan$' "${RUNNER_TEMP}/cron.csv"
  grep -q '^uccm_retention_cleanup$' "${RUNNER_TEMP}/cron.csv"
  wp eval 'if ( ! get_role( "administrator" )->has_cap( "manage_uccm_settings" ) ) { throw new RuntimeException( "The site administrator capability is missing." ); }' --url="${site_url}" --path="${wp_path}"
done

# Network defaults, locks and site-owned legal values must resolve independently.
wp eval '
update_site_option(
    \UCCM\Multisite::SETTINGS_OPTION,
    array(
        "defaults" => array( "consent_lifetime_days" => 240, "retention_days" => 450 ),
        "locked"   => array( "retention_days" ),
    )
);
foreach ( array( 1 => "main-policy", 2 => "existing-policy", 3 => "later-policy" ) as $site_id => $policy ) {
    switch_to_blog( $site_id );
    $settings = get_option( \UCCM\Settings::OPTION_NAME, array() );
    $settings["consent_policy_version"] = $policy;
    $settings["consent_lifetime_days"] = 365 + $site_id;
    update_option( \UCCM\Settings::OPTION_NAME, $settings, false );
    update_option( \UCCM\Settings::OVERRIDES_OPTION, array( "consent_lifetime_days" ), false );
    $effective = \UCCM\Settings::current();
    if ( $policy !== $effective["consent_policy_version"] || 365 + $site_id !== $effective["consent_lifetime_days"] || 450 !== $effective["retention_days"] ) {
        throw new RuntimeException( "Network precedence or site policy isolation failed for site " . $site_id );
    }
    restore_current_blog();
}
' --path="${wp_path}"

# Insert site-specific inventory markers and ensure lifecycle operations preserve them.
wp eval '
global $wpdb;
foreach ( array( 1, 2, 3 ) as $site_id ) {
    switch_to_blog( $site_id );
    $table = \UCCM\Database::table_names()["cookie_inventory"];
    $now = gmdate( "Y-m-d H:i:s" );
    $stored = $wpdb->insert(
        $table,
        array(
            "storage_key" => "network-marker-" . $site_id,
            "domain" => "site-" . $site_id . ".example.test",
            "provider" => "UCCM acceptance",
            "party" => "first",
            "storage_type" => "cookie",
            "purpose" => "Multisite isolation marker",
            "category" => "necessary",
            "duration" => "session",
            "source_url" => home_url( "/" ),
            "first_seen_at" => $now,
            "last_seen_at" => $now,
            "last_reviewed_at" => $now,
            "status" => "approved",
            "fingerprint" => hash( "sha256", "network-marker-" . $site_id ),
        )
    );
    if ( false === $stored ) {
        throw new RuntimeException( "Could not store the site isolation marker." );
    }
    restore_current_blog();
}
' --path="${wp_path}"

for site_id in 1 2 3; do
  prefix="wp_"
  if test "${site_id}" != "1"; then
    prefix="wp_${site_id}_"
  fi
  test "$(scalar "SELECT COUNT(*) FROM ${prefix}uccm_cookie_inventory WHERE storage_key='network-marker-${site_id}';")" = "1"
done

wp plugin deactivate uk-cookie-consent-manager --network --path="${wp_path}"
for site_id in 1 2 3; do
  prefix="wp_"
  if test "${site_id}" != "1"; then
    prefix="wp_${site_id}_"
  fi
  test "$(scalar "SELECT COUNT(*) FROM ${prefix}uccm_cookie_inventory WHERE storage_key='network-marker-${site_id}';")" = "1"
done

wp plugin activate uk-cookie-consent-manager --network --path="${wp_path}"
for site_id in 1 2 3; do
  prefix="wp_"
  if test "${site_id}" != "1"; then
    prefix="wp_${site_id}_"
  fi
  test "$(scalar "SELECT COUNT(*) FROM ${prefix}uccm_cookie_inventory WHERE storage_key='network-marker-${site_id}';")" = "1"
done

# Retention must run only against the site whose scheduled event is executed.
wp db query "
INSERT INTO wp_uccm_consents
(receipt_id, occurred_at, action, choices, policy_version, language, wording_version, plugin_version, site_identifier, wp_user_id, ip_masked, ip_fingerprint, ip_ciphertext, integrity_hash, created_at)
VALUES
('11111111-1111-1111-1111-111111111111', '2020-01-01 00:00:00', 'reject', '{}', '1', 'en_GB', '1', '0.1.0-ci', REPEAT('1',64), NULL, '192.0.2.0', REPEAT('a',64), NULL, REPEAT('b',64), '2020-01-01 00:00:00');
INSERT INTO wp_2_uccm_consents
(receipt_id, occurred_at, action, choices, policy_version, language, wording_version, plugin_version, site_identifier, wp_user_id, ip_masked, ip_fingerprint, ip_ciphertext, integrity_hash, created_at)
VALUES
('22222222-2222-2222-2222-222222222222', '2020-01-01 00:00:00', 'reject', '{}', '1', 'en_GB', '1', '0.1.0-ci', REPEAT('2',64), NULL, '192.0.2.0', REPEAT('c',64), NULL, REPEAT('d',64), '2020-01-01 00:00:00');
" --path="${wp_path}"

wp cron event run uccm_retention_cleanup --url="${root_url}" --path="${wp_path}"
test "$(scalar "SELECT COUNT(*) FROM wp_uccm_consents WHERE receipt_id='11111111-1111-1111-1111-111111111111';")" = "0"
test "$(scalar "SELECT COUNT(*) FROM wp_2_uccm_consents WHERE receipt_id='22222222-2222-2222-2222-222222222222';")" = "1"

# A scheduled scan on one site must not create a run for another site.
test "$(scalar "SELECT COUNT(*) FROM wp_uccm_scan_runs;")" = "0"
test "$(scalar "SELECT COUNT(*) FROM wp_2_uccm_scan_runs;")" = "0"
wp cron event run uccm_monthly_scan --url="${root_url}" --path="${wp_path}"
test "$(scalar "SELECT COUNT(*) FROM wp_uccm_scan_runs;")" = "1"
test "$(scalar "SELECT COUNT(*) FROM wp_2_uccm_scan_runs;")" = "0"

echo "Multisite acceptance passed for ${topology}."

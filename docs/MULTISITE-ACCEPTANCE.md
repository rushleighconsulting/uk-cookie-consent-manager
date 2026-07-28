# WordPress Multisite acceptance

This checklist is the hands-on acceptance gate for [UCCM-31](https://rushleighconsulting.atlassian.net/browse/UCCM-31). Automated package tests are necessary evidence, but they do not replace an administrator checking the real Network Admin and Site Admin experience.

## Test environment

Use a disposable or staging WordPress Multisite installation. Do not use the production network for destructive tests.

Record:

- WordPress and PHP versions.
- UCCM release and package checksum.
- Network type: subdirectory, subdomain or domain-mapped.
- Browser and operating-system versions.
- Date, tester and network URL.
- Scheduler configuration, including whether WP-Cron is disabled or replaced by a system scheduler.

## Acceptance sequence

1. Create two sites before activating UCCM.
2. Activate UCCM on one site only.
3. Confirm that the selected site receives Cookie Consent menus, tables and scheduled work while the other site is unchanged.
4. Deactivate the site-specific activation, then Network Activate UCCM.
5. In **Network Admin → Cookie Consent**, wait for the installation batch to report **Completed**.
6. Confirm both existing sites have their own Banner, Scans, Inventory, Consent records, Privacy and Advanced screens.
7. Save different policy versions and scan settings on each site. Confirm that changing one site never changes the other.
8. Set one network default, verify that an inheriting site receives it, then create an explicit site override.
9. Lock that network setting. Confirm the site can see but cannot replace the locked value.
10. Create a third site after Network Activation. Confirm its tables, capabilities and scheduled scan and retention events are present.
11. Create a consent receipt and scan run on two sites. Confirm each record appears only on its owning site.
12. Run retention and a scan on one site. Confirm the other site's records and scan state are unchanged.
13. Update UCCM through WordPress. Confirm the Network Admin batch completes and all site data remains present.
14. Roll back the shared plugin files in staging. Confirm all site data remains present and the documented compatible version loads.
15. Network Deactivate UCCM. Confirm site data remains stored.
16. Re-enable UCCM and confirm the data is still present.
17. Verify that network-wide deletion remains off by default. Do not test deletion until a verified database backup exists.
18. Repeat the topology-sensitive checks on a representative subdirectory, subdomain or domain-mapped network not already covered by the primary environment.

## Pass criteria

The result is **Pass** only when:

- network and site administrators see only the controls allowed to them;
- settings, policies, consent evidence, inventories, scans, findings and schedules remain site-owned;
- network defaults and locks follow the documented precedence;
- existing and newly created sites initialize successfully;
- upgrades, rollback, deactivation and reactivation preserve data;
- bounded network work completes without exhausting the request;
- the tested topology behaves correctly; and
- no critical error, unexpected email, stalled work or cross-site leakage occurs.

Attach screenshots or a short screen recording for Network Admin status, site-specific settings, the new-site result and the final data-preservation check. Record any failure in Jira before accepting the story.

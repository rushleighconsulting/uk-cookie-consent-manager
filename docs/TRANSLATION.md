# Translating UK Cookie Consent Manager

UCCM uses WordPress’s native gettext and JavaScript translation APIs with the
`uk-cookie-consent-manager` text domain. It does not require or contact a
proprietary translation service.

## For translators

The canonical template is
`languages/uk-cookie-consent-manager.pot`. WordPress.org translations should be
contributed through the plugin’s Translate WordPress.org project once the
directory listing exists. WordPress installs approved language packs under
`wp-content/languages/plugins`; UCCM also recognises development translations
placed under its local `languages` directory.

Never add markup, scripts, event handlers, URLs or credentials to a translation.
Keep numbered placeholders such as `%1$d` and `%2$s` unchanged so translators
may safely reorder a sentence. Supply both singular and plural forms where the
template provides them.

## For maintainers

1. Install WP-CLI with its `i18n` command.
2. Generate the deterministic template:

   `bash scripts/generate-pot.sh`

3. Run:

   `bash scripts/check-translations.sh`

4. Test the visitor and administration interfaces in English, Welsh and at
   least one right-to-left locale. Check the first-visit banner, preference
   dialog, settings icon, administration menus, scan progress, browser check,
   reports and error notices.
5. Confirm variables, numbers, dates and plurals retain their meaning, and test
   narrow mobile and enlarged-text layouts because translations can be longer
   than the English source.

CI regenerates the POT file, rejects a stale template, audits visible JavaScript
literals, runs Welsh output-escaping tests and exercises a translated
right-to-left banner in the browser suite.

## JavaScript

Visitor-facing JavaScript uses the WordPress `wp-i18n` script dependency.
Enqueued scripts must call `wp_set_script_translations()` with the UCCM text
domain. Machine identifiers, consent category keys and error codes remain
stable and are not translated; any label shown to a person is translated
separately.

## Scope boundary

This translation workflow makes the plugin’s own interface translation-ready.
Selecting different administrator-authored consent copy, policies or links for
each language version of a multilingual site belongs to UCCM-44.


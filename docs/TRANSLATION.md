# Translating Rushleigh Cookie Choices

UCCM uses WordPress’s native gettext and JavaScript translation APIs with the
`rushleigh-cookie-choices` text domain. It does not require or contact a
proprietary translation service.

## For translators

The canonical template is
`languages/rushleigh-cookie-choices.pot`. WordPress.org translations should be
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

## Multilingual consent content

The Banner screen also accepts locally authored consent content for each
language used by the site. Each entry has a WordPress locale, wording version,
reading direction, policy URL, banner and preferences wording, action labels,
and category labels and descriptions. `{days}` in authored copy is replaced
with the current Consent lifetime setting.

UCCM resolves the public-page language in this order:

1. WordPress's current page locale;
2. the current WPML language, when WPML is active;
3. the current Polylang locale, when Polylang is active; and
4. the value returned by the `uccm_public_locale` filter.

The filter is the documented integration point for another multilingual
plugin. It receives the locale found by the preceding steps and must return a
WordPress locale or HTML language tag.

Exact locale matches are preferred, followed by the same base language and
then the configured default consent language. The Banner screen reports
incomplete authored entries; empty fields use the safe default wording. UCCM
does not call a translation service.

The complete local language catalogue is sent with the dependency-free consent
script. Before displaying controls, the browser selects content from the
current page's `<html lang>` value. This keeps the consent fragment aligned
with a language-specific cached page instead of relying only on a
server-selected fragment. Full-page caches must still keep the site's own
language-specific URLs or cache variants separate.

Consent receipts record the selected language, policy version and wording
version. Automatic right-to-left layout is provided for Arabic, Persian,
Hebrew, Pashto and Urdu; administrators can explicitly choose either direction
for another locale.

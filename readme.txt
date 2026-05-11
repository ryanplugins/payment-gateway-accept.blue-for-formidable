=== Payment gateway: accept.blue for Formidable ===
Contributors: ryanplugins
Tags: formidable, payment, accept.blue, gateway, credit card
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept credit card payments through accept.blue directly inside your Formidable Forms. Free Lite version — upgrade to Pro for recurring billing, refunds, webhooks, fraud protection, and more.

== Description ==

**Payment gateway: accept.blue for Formidable** connects your Formidable Forms payment forms to the accept.blue payment processing platform. All card data is handled inside a secure accept.blue Hosted Tokenization iFrame — it never passes through your server, keeping your site PCI-compliant.

This is the **free Lite version**. Pro features are visible in the admin UI but locked behind an upgrade prompt.

= What's Included in Lite =

* **Credit card payments** via accept.blue Hosted Tokenization iFrame — card data never touches your server
* **Test / Sandbox mode** — safely test payments with accept.blue sandbox credentials before going live
* **Test Connection** — verify your API credentials without leaving WordPress
* **Debug logging** — dedicated per-month log file with an in-admin log viewer, gated behind a settings toggle
* **Processing overlay** — animated spinner shown on form submit while payment processes
* **Minified assets** — production-ready JS and CSS; source files served automatically when WP_DEBUG is on

= Pro Version Features =

Upgrade to **Payment gateway: accept.blue for Formidable Pro** to unlock:

* Recurring subscriptions — daily, weekly, bi-weekly, monthly, bi-monthly, quarterly, bi-annually, and annually
* Installment plans — split a total into a fixed number of automatic charges
* 3-D Secure 2 (3DS2) — optional EMV 3DS2 browser-based authentication per Form Action
* Auth-only / Capture — authorise a card without charging; capture manually from the admin panel
* Adjust & Capture — change the amount before capturing an authorisation
* Refunds & Voids — full and partial refunds, voids of unsettled authorisations, all from the admin
* Admin Transactions Panel — filterable list of all payments with 30-day revenue stats and CSV export
* Webhook receiver — HMAC-SHA256 signed REST endpoint keeps payment statuses up to date in real time
* Fraud Shield — IP velocity, email velocity, country block-list, and per-transaction amount threshold
* Per-form API credential override — run different forms against different accept.blue merchant accounts
* Guest subscription cancellation — signed token system lets non-logged-in customers cancel their own subscriptions
* Revenue dashboard — at-a-glance stats and transaction history per Formidable entry

= Requirements =

* WordPress 6.0 or higher
* PHP 7.4 or higher
* Formidable Forms Pro (required for payment fields)
* An active accept.blue merchant account (sign up at https://accept.blue)

== Source code ==

The unminified source files for all JavaScript and CSS are included in the plugin's assets/ directory alongside the minified production files. No separate build step is required to review the source.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/ or install via Plugins > Add New > Upload Plugin.
2. Activate via Plugins > Installed Plugins.
3. Go to Formidable > Global Settings > Accept.Blue.
4. Enter your credentials:
   - API Key — from your accept.blue portal under API Keys
   - PIN — if your API key requires one (optional)
   - Hosted Tokenization Key — from accept.blue Settings > Hosted Tokenization
5. Click Save Changes and use the Test Connection button to confirm your credentials work.
6. Create or open a Formidable Form.
7. Drag the Accept.Blue Card field onto the form canvas.
8. Open Form Actions and add the Accept.Blue Payment action.
9. Set your amount and currency.
10. Click Update and publish your form.

== Frequently Asked Questions ==

= Does this store card numbers on my server? =

No. Card data is tokenised entirely within the accept.blue Hosted Tokenization iFrame. Your server only ever receives a short-lived nonce token. No card numbers, CVVs, or expiry dates are stored on your server or in your database.

= Can I use this in test/sandbox mode? =

Yes. Enable Test / Sandbox Mode in Formidable > Global Settings > Accept.Blue and use your accept.blue sandbox credentials. No real charges are made. Disable the setting and switch to live credentials when you are ready for production.

= Can Lite run alongside the Pro version? =

No. Deactivate Lite before activating Pro. All PHP classes, functions, constants, database tables, and option keys are namespaced with _lite so there are no code-level conflicts, but running both simultaneously is unsupported.

= How is Lite different from Pro? =

Lite handles one-time credit card payments and debug logging. Pro adds recurring billing, installment plans, 3DS2, auth-only/capture, refunds, voids, an admin transactions panel, webhooks, fraud protection, per-form API overrides, and a revenue dashboard.

= Where do I upgrade to Pro? =

Visit https://www.patreon.com/posts/formidable-blue-157799373?source=lite for Pro version details and pricing.

= What statuses does the plugin use? =

complete — charge captured and settled; auth — authorised but not yet captured; failed — charge declined or errored; voided — authorisation cancelled before capture; refunded — charge refunded in full or in part.

= Does debug logging store sensitive data? =

API request and response bodies are logged when debug logging is enabled. Card numbers are never present (they are handled by the accept.blue iFrame and never reach your server), but disable debug logging once you have finished troubleshooting.


== Third-party services ==

This plugin transmits payment data to **accept.blue**, a third-party payment processor. By using this plugin you agree to accept.blue's terms of service and privacy policy.

* **accept.blue Payment API** — used to tokenise and charge credit cards.
  * Service: https://accept.blue
  * Terms of Service: https://accept.blue/terms
  * Privacy Policy: https://accept.blue/privacy

* **accept.blue Hosted Tokenization** — a PCI-compliant iFrame embedded on your form that captures card details without them passing through your server.
  * Documentation: https://docs.accept.blue/tokenization/hosted

* **Paay 3DS (Pro only)** — optional EMV 3-D Secure 2 browser-based authentication service.
  * Service: https://www.paay.co
  * Privacy Policy: https://www.paay.co/privacy-policy

No card data is stored on your server. All sensitive payment data is handled exclusively by accept.blue's PCI-compliant infrastructure.

== Screenshots ==

1. Plugin settings page showing API credentials, test/sandbox mode, and debug logging options.
2. Sandbox/test mode enabled in the Accept.Blue settings.
3. Transaction debug log viewer showing request and response entries.
4. Formidable form with the Accept.Blue card field at checkout.
5. Recurring payment settings panel on the Accept.Blue form action.

== Changelog ==

= 1.0.0 =
* Initial Lite release based on Pro v1.0.0
* Core credit card payments via accept.blue Hosted Tokenization iFrame (PCI-minimised)
* Debug logging with in-admin log viewer and Clear Log function
* Pro features visible in UI with upgrade prompts (recurring, refunds, admin panel, webhooks, fraud shield, dashboard)
* Licensing system fully removed
* All PHP identifiers prefixed with _lite (classes, functions, constants, options, hooks, DB tables)
* Plugin entry file renamed to formidable-acceptblue-lite.php
* All include files renamed to class-frm-ab-lite-*.php

=== HestaHub ===
Contributors: blackbucksoftware
Tags: apartments, availability, booking, holiday rental, clinics
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Shows your HestaHub Apartments, availability and Clinics on your own website.

== Description ==

For HestaHub Tenants. The plugin reads your Apartments, their availability and your Clinics from HestaHub and shows them on your WordPress site. It reads on the server and caches what it reads. It never writes anything back to HestaHub.

You need a HestaHub account and an API key.

What the plugin must do, and how it talks to HestaHub, is defined by the HestaHub Public Tenant API contract: https://docs.hestahub.com/v1/

=== Showing your data ===

Set a base path for Apartments and for Clinics under Settings → HestaHub, and the plugin serves their listing and pages there. Elsewhere on your site, use the shortcode or the template tags. `show` is one of `apartment-listing`, `apartment`, `rate-table`, `calendar`, `clinic-listing` or `clinic`; `slug` limits a listing to some items, or names the one item to show.

* `[hestahub show="apartment-listing" slug="hoettur, katla"]`
* `[hestahub show="rate-table" slug="hoettur"]`
* `<?php hestahub_render( 'calendar', array( 'slug' => 'hoettur' ) ); ?>` prints, like the shortcode.
* `<?php $vars = hestahub_get( 'apartment', array( 'slug' => 'hoettur' ) ); ?>` returns the template's variables and prints nothing.

Without data they show nothing.

=== Your theme ===

To change the markup, copy a template from the plugin's `templates/` folder into `hestahub/` in your (child) theme. The six templates render content only; your theme's header and footer surround them. Their variables are HestaHub's data exactly as received, documented in each template and in the contract. The stylesheet sets no colours: set the `--hestahub-…` custom properties listed in `assets/hestahub.css`, at least the calendar's day states.

== External services ==

This plugin connects to the HestaHub Public Tenant API, a service run by HestaHub. It needs it to show your Apartments, availability and Clinics.

* **Where:** your own Tenant's host, `https://<tenant>.hestahub.com/api/v1/`. No other host is contacted.
* **When:** from your server, when its cached copy is due for a refresh. Visitors' browsers never contact HestaHub through this plugin.
* **What is fetched:** your Tenant's name and details about the API itself (its Revision and which plugin versions it supports), its Apartments with their descriptions, pictures and rates, their availability as anonymous date ranges, and your Clinics. No Guest, booking or personal data is fetched.
* **What is sent:** nothing besides your API key (as `Authorization: Bearer`) and a `User-Agent` header naming the plugin's, WordPress's and PHP's versions. No visitor data and no site URL are sent. HestaHub stores the `User-Agent` against your key, to decide when the plugin's minimum PHP version may rise.

Updates: unless installed from wordpress.org, the plugin also checks GitHub for new versions, like WordPress does for plugins from wordpress.org. Twice a day, and when you look for updates, your server fetches the file `hestahub.json` from this plugin's latest release at `https://github.com/blackbucksoftware/hestahub-wordpress/releases/`, and the release zip when you update. GitHub receives your server's IP address and a `User-Agent` naming WordPress and your site's URL, as with any WordPress update check. Nothing else is sent. GitHub's privacy statement: https://docs.github.com/site-policy/privacy-policies/github-general-privacy-statement

The contract: https://docs.hestahub.com/v1/

== Changelog ==

= 0.1.0 =
* The empty plugin: bootstrap, uninstall and release build.

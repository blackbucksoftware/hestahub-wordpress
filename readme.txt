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

== External services ==

This plugin connects to the HestaHub Public Tenant API, a service run by HestaHub. It needs it to show your Apartments, availability and Clinics.

* **Where:** your own Tenant's host, `https://<tenant>.hestahub.com/api/v1/`. No other host is contacted.
* **When:** from your server, when its cached copy is due for a refresh, and when an administrator tests the connection or refreshes it from the settings screen. Visitors' browsers never contact HestaHub through this plugin.
* **What is fetched:** your Tenant's name, its Apartments with their descriptions, pictures and rates, their availability as anonymous date ranges, and your Clinics. No Guest, booking or personal data is fetched.
* **What is sent:** nothing besides your API key (as `Authorization: Bearer`) and a `User-Agent` header naming the plugin's, WordPress's and PHP's versions. No visitor data and no site URL are sent.

The contract: https://docs.hestahub.com/v1/

== Changelog ==

= 0.1.0 =
* The empty plugin: bootstrap, uninstall and release build.

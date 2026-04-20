=== Purge Everything - Cloudflare ===
Contributors: mindedgedevel, knattk
Tags: cloudflare, cache, purge, performance, admin
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Purge Cloudflare cache (Purge Everything) directly from WordPress admin with one click.

== Description ==

Purge Everything - Cloudflare adds a settings page in WordPress admin where administrators can:

* Save Cloudflare Zone ID and API Token
* Trigger a full Cloudflare cache purge with one click
* View status notices for purge results


== External services ==

This plugin connects to the Cloudflare API to purge cache for your configured zone.

It sends requests only when an administrator clicks the "Purge Cloudflare Cache" button in WordPress admin.

Service used:

* Cloudflare API endpoint: `https://api.cloudflare.com/client/v4/zones/{zone_id}/purge_cache`

Data sent to Cloudflare:

* Zone ID (as part of the API URL)
* API Token (in the `Authorization: Bearer` header)
* Request body: `{"purge_everything": true}`
* User-Agent header that includes this site URL

Cloudflare policies:

* Terms of Use: https://www.cloudflare.com/website-terms/
* Privacy Policy: https://www.cloudflare.com/privacypolicy/

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory, or install it through the WordPress Plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to `Settings > Cloudflare Purge`.
4. Enter your Cloudflare Zone ID and API Token.
5. Save settings.
6. Use the "Purge Cloudflare Cache" button to clear the Cloudflare cache.

== Frequently Asked Questions ==

= What Cloudflare token permissions are required? =

Use an API Token with permission to purge cache for the target zone.

= Where do I find the Zone ID? =

In the Cloudflare dashboard for your site, open the Overview page and copy the Zone ID.

= Why does the purge button show rate-limited? =

The plugin uses a short cooldown/lock window to prevent repeated purge requests in rapid succession.

= Can non-admin users purge cache? =

No. The purge action is restricted to users with `manage_options` capability.

== Changelog ==

= 0.1.0 =
* Initial release
* Admin settings for Zone ID and API Token
* One-click Cloudflare Purge Everything action
* Nonce and capability checks for admin purge action

== Upgrade Notice ==

= 0.1.0 =
Initial release.

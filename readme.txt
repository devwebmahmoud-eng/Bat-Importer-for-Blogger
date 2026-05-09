=== Bat Importer for Blogger – Unlimited & Free Blogger Importer ===
Contributors: devwebmahmoud
Tags: blogger, import, migration, redirects, importer
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import public Blogger blogs into WordPress by Blog ID, with optional image download, page import, and redirect support.

== Description ==

Bat Importer for Blogger imports content from a public Blogger blog using the numeric Blog ID.

Features include:

* Imports posts and optional static pages.
* Downloads Blogger-hosted images into the WordPress media library.
* Removes the duplicated leading image from post content when it is used as the featured image.
* Removes the extra Blogger caption directly under the removed opening image when present.
* Saves old Blogger paths and creates 301 redirects, enabled by default for imported Blogger content.
* Imports Blogger labels as WordPress categories and redirects Blogger label URLs to matching categories when possible.
* Optional fallback to redirect unmatched 404 requests to the homepage, disabled by default until enabled by the site owner.
* Stops import on demand from the admin screen.
* Full plugin-data reset for a fresh migration run.
* Cleans imported Blogger HTML through a WordPress allowlist before saving content.
* WordPress-style admin UI for import management.

This plugin is intended for public Blogger blogs. If a blog is private, feed access will fail.


== External Services ==

This plugin connects to external services to import content from Blogger.

* Service name: Blogger / Google
* What the service is used for: Fetching the public Atom feed for the Blogger blog specified by the site owner, and optionally downloading publicly accessible Blogger-hosted images referenced by imported posts or pages.
* What data is sent: The Blogger Blog ID entered by the site owner is used to request the public feed. Requests for images may be made to the image URLs found in the public feed. No customer lists, passwords, or private WordPress content are sent by the plugin.
* When the service is contacted: Only when the site owner runs an import or when the plugin downloads Blogger-hosted images referenced by the public feed during that import process.
* Service URLs:
  * https://www.blogger.com/
  * https://policies.google.com/privacy
  * https://policies.google.com/terms


== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install the ZIP from the WordPress admin.
2. Activate the plugin.
3. Open **Tools > Bat Importer for Blogger**.
4. Enter the Blogger Blog ID and start the import.

== Frequently Asked Questions ==

= Does it work with private Blogger blogs? =

No. The plugin reads the public Blogger feed, so private blogs cannot be imported.

= Can it create redirects from old Blogger URLs? =

Yes. The plugin stores Blogger paths for imported content and redirects them with HTTP 301 responses by default. The optional homepage fallback for unmatched 404 requests remains disabled unless the site owner enables it.

= Will it delete imported posts if I reset the plugin data? =

No. The full reset clears the plugin's own stored settings, job state, and redirect mappings. Imported WordPress content remains in place.

== Changelog ==

= 1.0.1 =
* Updated the WordPress.org directory display title to Bat Importer for Blogger – Unlimited & Free Blogger Importer.
* Bumped release metadata to 1.0.1.

= 1.0.0 =
* Fixed Plugin Check issues around custom-table queries and uninstall cleanup.
* Kept translation loading aligned with current WordPress.org behavior.
* Synced importer, category re-sync, and redirect table handling in the 1.0.0 release line.
* Enabled Blogger content 301 redirects by default while keeping the optional unmatched-404 homepage fallback disabled by default.
* Added external service disclosure for Blogger / Google in the readme.
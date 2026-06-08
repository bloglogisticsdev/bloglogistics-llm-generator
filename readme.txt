=== BlogLogistics LLMs.txt Generator ===
Contributors: bloglogistics
Tags: llms.txt, ai, robots.txt, seo, discovery
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 1.4.4
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Generates a curated llms.txt file for WordPress sites with exclusions, SEO noindex awareness, regeneration tools, and companion-plugin awareness.

== Description ==

BlogLogistics LLMs.txt Generator creates a curated /llms.txt file for WordPress websites. The file helps AI assistants, AI search systems, and other machine clients discover the site's most useful public pages and posts.

The plugin focuses on llms.txt only. It does not generate ai.txt, does not claim to control AI training, and does not automatically edit robots.txt.

Key features include:

* Curated llms.txt generation for selected public post types.
* Per-post and per-page exclusion controls.
* Optional SEO noindex awareness for common SEO plugin metadata.
* Manual regeneration and scheduled updates.
* Automatic regeneration when selected published content changes.
* Optional XML sitemap and RSS feed discovery links.
* Robots.txt guidance for AI crawler decisions, without automatically modifying robots.txt.
* Detection for BlogLogistics Content Signals for Robots.txt, with notices that robots.txt and Content-Signal preferences should be managed there.
* Detection for BlogLogistics Markdown for Agents, with notices that /index.md and Markdown discovery headers should be managed there.
* Automatic /index.md discovery link in llms.txt when BlogLogistics Markdown for Agents is active and serving the Markdown homepage.
* Cleanup on uninstall.

== BlogLogistics Service Usage Notice ==

This plugin is licensed under GPL-3.0-or-later.

This plugin is provided by BlogLogistics as part of an active hosting, maintenance, or site-management service, unless a separate service arrangement has been granted. If the website is moved to another provider, continued BlogLogistics service use, support, updates, configuration assistance, or replacement work may require a separate agreement.

This notice does not restrict any rights granted under the GPL-3.0-or-later licence.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/.
2. Activate the plugin in WordPress.
3. Go to BlogLogistics > LLMs.txt Generator.
4. Review the site summary, selected post types, and exclusion settings.
5. Save settings, then visit /llms.txt on the site to confirm the file is available.

== Frequently Asked Questions ==

= What does this plugin do? =
It generates a curated llms.txt file that highlights useful public content for AI assistants, AI search tools, and other machine clients.

= Does llms.txt block AI crawlers? =
No. llms.txt is a discovery and context file. Crawler controls belong in robots.txt, server rules, or bot-management tools.

= Does this plugin generate ai.txt? =
No. Older versions included ai.txt support, but this plugin now focuses on llms.txt because ai.txt is not a reliable or broadly adopted control mechanism.

= Does this plugin edit robots.txt? =
No. The plugin includes guidance and example snippets only. It does not automatically modify robots.txt. If BlogLogistics Content Signals for Robots.txt is active, this plugin detects it and points users there for robots.txt and Content-Signal management.

= Can I exclude specific pages or posts? =
Yes. Selected post types receive an editor sidebar checkbox named Exclude this content from llms.txt.

= Does the plugin respect SEO noindex settings? =
It can exclude content marked noindex by common SEO plugin metadata where detectable. This option is enabled by default.

= Does this plugin conflict with BlogLogistics Markdown for Agents? =
No. When BlogLogistics Markdown for Agents is active, this plugin detects it, points users there for /index.md and Markdown discovery-header management, and can include the Markdown homepage link in llms.txt.

= What happens when the plugin is deleted? =
The plugin removes its saved settings, scheduled cron hook, exclusion metadata, and the generated llms.txt file when the file contains the BlogLogistics generator marker.

= Does this plugin continue to be covered by BlogLogistics service terms if the website moves to another provider? =

This plugin is licensed under GPL-3.0-or-later. BlogLogistics service use, support, updates, configuration assistance, or replacement work may require an active BlogLogistics hosting, maintenance, or site-management service, or a separate agreement. This notice does not restrict any rights granted under the GPL-3.0-or-later licence.

== Changelog ==

= 1.4.4 =
* Generate the update manifest Installation section from readme.txt.
* Generate the update manifest FAQ section from readme.txt.
* Remove stale hard-coded Installation and FAQ manifest content from the release workflow.

= 1.4.2 =
* Add companion-plugin detection for BlogLogistics Content Signals for Robots.txt and BlogLogistics Markdown for Agents.
* Add admin notices explaining which companion plugin owns robots.txt, Content-Signal, /index.md, and Markdown discovery-header functions.
* Prevent feature overlap by keeping robots.txt and Markdown endpoint management out of this plugin when the companion plugins are active.
* Add a Markdown homepage link to llms.txt when BlogLogistics Markdown for Agents is active and serving /index.md.
* Add companion plugin status information to the Status tab.

= 1.4.1 =
* Restructure the plugin to follow the standard BlogLogistics repository layout.
* Move the main plugin logic into the includes directory.
* Add the BlogLogistics manifest updater integration.
* Add release ZIP and update manifest GitHub Actions workflow.
* Add standard plugin assets, license file, readme files, language path, and repository metadata.
* Keep the llms.txt-only direction from version 1.4.0.

= 1.4.0 =
* Rename the plugin to BlogLogistics LLMs.txt Generator.
* Remove ai.txt generation and AI policy settings.
* Add robots.txt guidance instead of ai.txt controls.
* Add safer llms.txt defaults, including shorter summaries and fewer items per post type.
* Add site summary, optional sitemap link, optional RSS feed link, and SEO noindex awareness.
* Add content-change regeneration and uninstall cleanup.

= 1.3.1 =
* Legacy version before the llms.txt-only refactor.

=== BlogLogistics LLM Generator ===
Contributors: rogerwheatley  
Tags: llms.txt, ai.txt, large language model, markdown, exclude post, llm, ai, generator, cache, post meta  
Requires at least: 5.5  
Tested up to: 6.5  
Stable tag: 1.2.8  
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html  

Generates `llms.txt` and `ai.txt` files with Markdown formatting to help control what content is indexed by AI systems. Includes exclusion options, content controls, update frequency, and cache management.

== Description ==

The **BlogLogistics LLM Generator** plugin automatically generates `llms.txt` and `ai.txt` files containing structured Markdown summaries and links to selected WordPress content. It is designed to provide machine-readable site content summaries that may be used to assist or inform large language model (LLM) training or indexing processes.

The plugin also allows post-level exclusions and site-wide customisation of what content is included and how it’s structured.

**Key Features:**

- Creates `llms.txt` and `ai.txt` files in the WordPress root and uploads directory.
- Uses Markdown formatting for both summary and detailed content sections.
- Adds a meta box to posts and pages for excluding specific content from generated files.
- Offers configurable options:
  - Post types to include (with ordering support)
  - Max posts per type
  - Max words per post
  - Whether to include excerpts, meta data (date, author), and taxonomies
- Supports scheduled updates (Immediate, Daily, Weekly)
- Admin interface for manually clearing caches and regenerating files

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/bloglogistics-llm-generator` directory, or install via the WordPress Plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to **LLMs & AI txt** in your WordPress admin menu to configure settings.
4. (Optional) Visit individual posts or pages and use the “Exclude from LLM” checkbox if you want to omit specific content.

== Usage ==

Once activated, the plugin will generate two files: `llms.txt` and `ai.txt`. These files include links and optionally excerpts of selected posts and pages, formatted in Markdown. These files may be used for transparency, compliance, or to inform automated systems about your site's content.

Files are automatically regenerated based on the configured frequency or manually via the “Clear caches” button in the admin panel.

== Frequently Asked Questions ==

= What’s the difference between `llms.txt` and `ai.txt`? =

Both files are currently generated with the same content for compatibility and redundancy. They are named differently to match various conventions used by indexing systems.

= Will this plugin affect SEO? =

The plugin does not modify your actual page content or metadata. It only generates separate `.txt` files with summaries and links, which you may opt to reference in `robots.txt` or share as needed.

= How can I control what appears in the files? =

Use the plugin settings page to control post types, word limits, and content elements. You can also exclude individual posts using the meta box.

== Screenshots ==

1. Settings page showing content controls and options.
2. Per-post exclusion checkbox.
3. Sample output in Markdown format.

== Changelog ==

= 1.2.8 =
* Initial public release.
* Generates Markdown files `llms.txt` and `ai.txt`.
* Adds post meta exclusion.
* Provides customisable settings and update frequency.
* Supports manual cache clearing.

== Upgrade Notice ==

= 1.2.8 =
First public release. Adds full Markdown generation and admin options.

== License ==

This plugin is licensed under the GPLv2 or later.


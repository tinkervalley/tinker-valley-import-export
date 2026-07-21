# Tinker Valley Import & Export

WordPress plugin for importing and exporting mapped post JSON files with ACF field mapping.

## Install

Copy the `tinker-valley-import-export` folder into:

```text
wp-content/plugins/
```

Then activate **Tinker Valley Import & Export** in WordPress.

The plugin page is under:

```text
Tools > Tinker Valley Import & Export
```

## What It Imports

Each JSON record can create or update a post of the selected target post type and map:

- post title, slug, status, content, date
- old source ID stored as `_tvpi_old_post_id`
- featured image URL
- taxonomy terms using each category's declared `taxonomy` value, with legacy fallback support for `project_category` and `category`
- any ACF field included in the record's `acf` object

ACF field definitions assigned to the target post are used to preserve their field types. Media URLs in any ACF `image`, `file`, or `gallery` field are sideloaded into the Media Library, including media fields nested inside ACF groups and repeaters. Imported media stores the original URL as `_tvpi_source_url` so repeat imports can reuse the same attachment. The source URL may use the same hostname as the WordPress site.

Declared taxonomy terms are assigned after ACF values so an empty ACF taxonomy field cannot clear the imported term relationships.

During import, you can also choose to reuse existing Media Library files with the same filename before downloading from the source URL.

## What It Exports

The export panel lets you select a post type and download a JSON file in the same shape the importer accepts.

Exports include:

- post title, slug, status, content, date
- featured image URL
- hierarchical taxonomy terms
- actual ACF fields assigned to each post
- optional public custom meta export

## Background Processing

Imports are queued as jobs and processed in small batches through WP-Cron.

The admin page also has a **Process Next Batch** button in case WP-Cron does not run immediately on a local/staging site.

Each job includes a collapsible post log showing which records were created or updated, including the WordPress post ID.

## Notes

- If ACF is active, the plugin uses `update_field()`.
- If ACF is not active, it falls back to `update_post_meta()`.
- Existing posts are matched by `_tvpi_old_post_id` when the update option is enabled.

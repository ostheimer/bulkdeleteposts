# Bulk Delete Custom Posts

Bulk Delete Custom Posts is a WordPress admin tool for safely removing large sets of posts (including custom post types) that share specific taxonomy terms. The plugin focuses on transparency, shared-hosting friendliness, and extensibility for development teams.

## Feature Highlights
- **Targeted selection** – Choose any registered post type and one of its public, UI-enabled taxonomies.
- **Term-based filtering** – Narrow results with a partial match against term names or slugs.
- **Dry-run preview** – Inspect matching content and receive prominent warnings before deleting anything.
- **Batch deletion** – Control batch size and optional pauses to avoid exhausting shared hosting resources.
- **Optional term cleanup** – Remove taxonomy terms that become empty after deletion.
- **Activity logging** – Track who deleted what and when via a dedicated `bdcp_log` post type with optional cron cleanups.

## Requirements
- WordPress 5.0+
- PHP 7.2+
- Administrator capabilities (`manage_options`) to run deletions

## Installation
1. Download the latest release ZIP or clone the repository.
2. Upload the `bulk-delete-custom-posts` directory to `/wp-content/plugins/`.
3. Activate **Bulk Delete Custom Posts** from the **Plugins** screen in the WordPress dashboard.

## Usage
1. Navigate to **Tools → Bulk Delete Custom Posts**.
2. Choose the post type and taxonomy you want to target.
3. (Optional) Provide a search string to match against term names or slugs.
4. Configure batch size, pauses, and empty-term cleanup as required.
5. Run **Find Posts (Dry Run Preview)** to confirm the results.
6. Disable Dry Run and start **Delete Found Posts** to process the batches.

## Developer Notes
The plugin exposes several hooks to tailor behaviour:
- `bdcp_allowed_post_types` – alter available post types.
- `bdcp_pre_get_posts_args` – adjust the `WP_Query` arguments used to discover posts.
- `bdcp_before_batch_delete`, `bdcp_after_batch_delete`, `bdcp_after_all_posts_deleted` – react to different phases of the batch workflow.

Logs are stored in the `bdcp_log` custom post type. Options and transient names are namespaced (`bdcp_…`) to avoid collisions.

## Roadmap & Ideas
- Resume interrupted deletions or auto-adjust batch sizes in low-memory situations.
- Add filters for date ranges, post status, and author targeting.
- Provide CSV/JSON export of matches before deletion.
- Offer email notifications after large deletion jobs.
- Surface REST/AJAX endpoints for headless integrations.

## Contributing
Contributions are welcome via pull requests. Please open an issue first if you plan to introduce major changes.

## License
Released under the [GPL v2 or later](https://www.gnu.org/licenses/gpl-2.0.html).

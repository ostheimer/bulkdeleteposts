# Bulk Delete Custom Posts

A WordPress plugin designed to bulk delete posts of a selected custom post type based on taxonomy terms, with an optional filter for term slugs. It includes features optimized for shared hosting environments.

## Core Features

-   **Selective Post Type**: Choose any registered post type (posts, pages, custom post types).
-   **Taxonomy Filtering**: Select a taxonomy associated with the chosen post type.
-   **Optional Term Name/Slug Filter**: Further refine selection by specifying text to search (case-insensitively) in the taxonomy term's name OR slug.
-   **Dry Run Mode**: Preview posts that match criteria before any actual deletion occurs.
-   **Flexible Deletion**:
    -   Delete all matching posts at once.
    -   Delete posts in manageable batches (e.g., 10, 25, 50, 100 at a time).
-   **Optional Empty Term Deletion**: After deleting posts, optionally remove any terms in the selected taxonomy (matching the slug filter, if used) that are now empty.

## Shared Hosting Optimized Features

### 1. Batch Processing & Performance
-   **Configurable Batch Size**: User can set the number of posts to delete per batch.
-   **AJAX-Powered Deletion**: Smooth, step-by-step deletion process without page reloads, reducing server strain.
-   **Optional Pause Between Batches**: Introduce a configurable delay between batches to prevent server timeouts.
-   **Progress Bar**: Visual feedback on the deletion process.
-   **Optimized Queries**: Initially fetches only post IDs to conserve memory.
-   **Transient Caching**: Caches taxonomy lists for faster loading.
-   **Minimal Database Impact**: Designed for efficient database operations.

### 2. Resource Management
-   **Memory Limit Awareness**: Basic checks or considerations for memory usage.
-   **Execution Time Monitoring**: Helps prevent hitting PHP execution time limits, with potential for graceful pauses or warnings.
-   **Resume Functionality (Future Scope)**: Ability to resume an interrupted deletion process.
-   **Low Memory Mode (Future Scope)**: Automatically adjusts batch sizes or functionality if low memory is detected.

### 3. Safety & Logging
-   **Backup Reminder**: Prominent reminder to back up the database before performing deletions.
-   **Export Option (Future Scope)**: Allow exporting a list (CSV/JSON) of posts marked for deletion.
-   **Confirmation Dialog**: Clear, explicit confirmation required before permanent deletion.
-   **Capability Check**: Ensures only users with appropriate permissions (e.g., administrators) can use the plugin.
-   **Activity Log**: Records deletion operations (timestamp, user, number of posts deleted, criteria used, and optionally, empty terms deleted).
-   **Error Logging**: Logs any errors encountered during the deletion process.
-   **Email Notifications (Optional)**: Notify admin upon completion of large deletion tasks.

### 4. Advanced Filtering (Future Scope)
-   **Date Range Filter**: Delete posts published before, after, or within a specific date range.
-   **Post Status Filter**: Target posts by status (e.g., 'publish', 'draft', 'pending', 'private', 'trash').
-   **Author Filter**: Delete posts by a specific author.
-   **Exclude IDs**: Option to specify post IDs to exclude from deletion.

### 5. Usability
-   **Intuitive Admin Interface**: User-friendly settings page under "Tools".
-   **Clear Instructions & Warnings**: Guidance and warnings are provided throughout the process.

## Requirements

-   WordPress 5.0 or higher
-   PHP 7.2 or higher
-   Administrator privileges for usage

## Installation

1.  Download the plugin ZIP file.
2.  Navigate to **Plugins → Add New** in your WordPress admin panel.
3.  Click **Upload Plugin** and select the ZIP file.
4.  Click **Install Now** and then **Activate**.

Alternatively, install manually:

1.  Unzip the plugin files.
2.  Upload the entire `bulk-delete-custom-posts` folder to `/wp-content/plugins/`.
3.  Navigate to **Plugins** in your WordPress admin panel.
4.  Find "Bulk Delete Custom Posts" and click **Activate**.

## Usage

1.  Navigate to **Tools → Bulk Delete Custom Posts** in your WordPress admin panel.
2.  Select the **Post Type**.
3.  The **Taxonomy** dropdown will populate based on the selected post type. Choose a taxonomy.
4.  (Optional) Enter text into the **Filter by Term Name/Slug** field to filter taxonomy terms. The search is case-insensitive and looks for the entered text in both the term's name and its slug (e.g., enter "archive" to find terms like "Product Archive", "old-archive", or "Archived News").
5.  Configure **Batch Processing** options if deleting in steps (e.g., batch size).
6.  (Optional) Check the **Delete empty terms** box if you want to clean up terms that become empty after post deletion.
7.  Click **Find Posts (Dry Run Preview)** to preview posts that match your criteria. Review the list carefully. The dry run will also indicate which terms *would* be deleted if the option is checked and they become empty.
8.  If satisfied, uncheck "Dry Run" and click **Delete Found Posts**. If batch processing is enabled, this will start the batch deletion process.
9.  Monitor the progress.

## Hooks and Filters

The plugin provides several hooks and filters for developers to extend its functionality:

-   `bdcp_allowed_post_types` (filter): Modify the list of post types available in the selection dropdown.
    ```php
    add_filter( 'bdcp_allowed_post_types', function( $post_types ) {
        // $post_types is an array of post type objects or names
        // Example: Add a specific private post type
        // $post_types['my_private_pt'] = get_post_type_object('my_private_pt');
        return $post_types;
    } );
    ```
-   `bdcp_pre_get_posts_args` (filter): Modify the `WP_Query` arguments used to find posts for deletion.
    ```php
    add_filter( 'bdcp_pre_get_posts_args', function( $query_args, $settings ) {
        // $query_args are the WP_Query args
        // $settings are the plugin's current operation settings (post_type, taxonomy, slug_term, delete_empty_terms, etc.)
        // Example: Only target posts older than 30 days
        // $query_args['date_query'] = array(
        //     array(
        //         'before' => '30 days ago',
        //         'inclusive' => true,
        //     ),
        // );
        return $query_args;
    } );
    ```
-   `bdcp_before_batch_delete` (action): Fires before a batch of posts is deleted.
    ```php
    add_action( 'bdcp_before_batch_delete', function( $post_ids_in_batch, $settings ) {
        // $post_ids_in_batch: Array of post IDs to be deleted in this batch.
        // $settings: The current plugin operation settings.
        // Example: Log to an external service
    }, 10, 2 );
    ```
-   `bdcp_after_batch_delete` (action): Fires after a batch of posts has been processed for deletion.
    ```php
    add_action( 'bdcp_after_batch_delete', function( $deleted_post_ids_in_batch, $attempted_post_ids_in_batch, $settings ) {
        // $deleted_post_ids_in_batch: Array of post IDs successfully deleted in this batch.
        // $attempted_post_ids_in_batch: Array of post IDs attempted in this batch.
        // $settings: The current plugin operation settings.
    }, 10, 3 );
    ```
-   `bdcp_after_all_posts_deleted` (action): Fires after all batches have been processed and the main post deletion process is complete. This hook is also triggered before empty term deletion (if enabled).
    ```php
    add_action( 'bdcp_after_all_posts_deleted', function( $all_found_ids, $settings ) {
        // $all_found_ids: Array of all post IDs initially found and targeted by the operation.
        // $settings: The current plugin operation settings. This includes 'delete_empty_terms' and 'candidate_term_ids_for_cleanup'.
        // This is a good place to trigger actions after all posts are deleted but before terms are (optionally) cleaned up.
        // Or to perform actions if term cleanup is disabled.
    }, 10, 2 );
    ```

### Transients Used

-   `bdcp_operation_settings_{user_id}`: Stores the settings for the current bulk delete operation (post type, taxonomy, slug term, `delete_empty_terms` flag, `candidate_term_ids_for_cleanup`). This transient is set when "Find Posts" is clicked and cleared after the operation (including term cleanup) is complete.
-   `bdcp_all_found_ids_{user_id}`: Stores all post IDs found in the initial "Find Posts" step. Used by the `bdcp_after_all_posts_deleted` hook and then cleared.

## Troubleshooting

-   **No posts in Dry Run**: Double-check post type, taxonomy, and slug term. Ensure posts exist that match the criteria.
-   **Plugin page not appearing**: Ensure the plugin is activated and you have administrator privileges.
-   **Timeouts during deletion**: Reduce batch size and/or enable/increase pause between batches.

## Contributing

Contributions are welcome! Please fork the repository and submit a pull request.

## License

This plugin is licensed under the GPL v2 or later.

```
<?php
/**
 * Plugin Name:       Bulk Delete Custom Posts
 * Plugin URI:        https://www.ostheimer.at
 * Description:       Allows to bulk delete posts of a selected post type based on taxonomy terms, with an optional filter for term slugs. Optimized for shared hosting.
 * Version:           0.1.1
 * Author:            Andreas Ostheimer
 * Author URI:        https://www.ostheimer.at
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bulk-delete-custom-posts
 * Domain Path:       /languages
 */

// Sicherheitsabfrage: Direkten Zugriff verhindern
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Bulk_Delete_Custom_Posts {

    private static $instance;
    private $option_name = 'bdcp_options';
    private $current_operation_settings = array(); // To store settings for current operation for hooks
    const LOG_POST_TYPE = 'bdcp_log'; // Define log CPT name
    const LOG_CLEANUP_CRON_HOOK = 'bdcp_log_cleanup_cron'; // Define cron hook name

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', array( $this, 'register_log_post_type' ) ); // Register CPT
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings_fields' ) );
        add_action( 'admin_post_bdcp_handle_form', array( $this, 'handle_form_submission' ) );
        add_action( 'admin_post_bdcp_cleanup_logs_now', array( $this, 'handle_cleanup_logs_now_action' ) ); // Action for manual cleanup
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_action( 'wp_ajax_bdcp_find_posts', array( $this, 'ajax_find_posts' ) );
        add_action( 'wp_ajax_bdcp_delete_batch', array( $this, 'ajax_delete_batch' ) );

        // Custom columns for bdcp_log CPT
        add_filter( 'manage_' . self::LOG_POST_TYPE . '_posts_columns', array( $this, 'set_log_columns' ) );
        add_action( 'manage_' . self::LOG_POST_TYPE . '_posts_custom_column', array( $this, 'render_log_columns' ), 10, 2 );
        add_filter( 'manage_edit-' . self::LOG_POST_TYPE . '_sortable_columns', array( $this, 'set_log_sortable_columns' ) );

        // Filters for Log CPT admin list
        add_action( 'restrict_manage_posts', array( $this, 'add_log_list_filters' ) );
        add_filter( 'parse_query', array( $this, 'filter_logs_query' ) );

        // Cron action for log cleanup
        add_action( self::LOG_CLEANUP_CRON_HOOK, array( $this, 'run_log_cleanup_cron' ) );
        
        // Hook into options update to reschedule cron if necessary
        add_action( 'update_option_' . $this->option_name, array( $this, 'handle_options_update' ), 10, 3 );

        // Hook for cleaning up empty terms after all posts are deleted
        add_action( 'bdcp_after_all_posts_deleted', array( $this, 'handle_term_cleanup_after_deletion' ), 10, 2 );
    }

    public function enqueue_admin_scripts( $hook_suffix ) {
        if ( 'tools_page_bulk-delete-custom-posts' !== $hook_suffix ) {
            return;
        }

        wp_enqueue_script(
            'bdcp-admin-script',
            plugin_dir_url( __FILE__ ) . 'admin-script.js',
            array( 'jquery' ),
            '0.1.1', // Updated version
            true
        );

        $default_post_types = get_post_types( array( 'public' => true, 'show_ui' => true ), 'objects' );
        /**
         * Filter the list of post types available in the plugin.
         *
         * @param array $post_types Array of post type objects or names.
         */
        $allowed_post_types = apply_filters( 'bdcp_allowed_post_types', $default_post_types );

        $post_type_taxonomies = array();
        if (is_array($allowed_post_types)) {
            foreach ( $allowed_post_types as $pt_key => $pt_obj ) {
                // Ensure we have a post type object, not just a name, for consistency
                $pt = is_object($pt_obj) ? $pt_obj : get_post_type_object($pt_key);
                if (!$pt) continue;

                $taxonomies = get_object_taxonomies( $pt->name, 'objects' );
                $tax_data = array();
                if ( ! empty( $taxonomies ) ) {
                    foreach ( $taxonomies as $tax ) {
                        if ( $tax->show_ui && $tax->public ) {
                             $tax_data[] = array( 'name' => $tax->name, 'label' => $tax->label );
                        }
                    }
                }
                if (!empty($tax_data)) {
                    $post_type_taxonomies[ $pt->name ] = array(
                        'label' => $pt->label,
                        'taxonomies' => $tax_data
                    );
                }
            }
        }
        uasort($post_type_taxonomies, function($a, $b) {
            return strcmp($a['label'], $b['label']);
        });

        wp_localize_script( 'bdcp-admin-script', 'bdcpPluginData', array(
            'postTypeTaxonomies' => $post_type_taxonomies,
            'selectTaxonomyText' => __( '-- Select a Taxonomy --', 'bulk-delete-custom-posts' ),
            'noTaxonomyText'     => __( '-- No public taxonomies found for this post type --', 'bulk-delete-custom-posts' ),
            'ajax_url'           => admin_url( 'admin-ajax.php' ),
            'find_posts_nonce'   => wp_create_nonce( 'bdcp_find_posts_nonce' ),
            'delete_batch_nonce' => wp_create_nonce( 'bdcp_delete_batch_nonce' ),
            'error_text'         => __( 'An error occurred. Please try again.', 'bulk-delete-custom-posts' ),
            'confirm_delete_text'=> __( 'Are you sure you want to delete these posts? This action cannot be undone.', 'bulk-delete-custom-posts' )
        ) );
    }

    public function add_admin_menu() {
        add_management_page(
            __( 'Bulk Delete Custom Posts', 'bulk-delete-custom-posts' ),
            __( 'Bulk Delete Posts', 'bulk-delete-custom-posts' ),
            'manage_options',
            'bulk-delete-custom-posts',
            array( $this, 'create_admin_page' )
        );
    }

    public function register_settings_fields() {
        register_setting( 'bdcp_options_group', $this->option_name, array( $this, 'sanitize_options' ) );
        add_settings_section(
            'bdcp_main_section',
            null,
            null,
            'bulk-delete-custom-posts-page'
        );
    }

    public function sanitize_options( $input ) {
        $sanitized_input = array();
        $current_options = get_option($this->option_name, array());

        if (isset($input['log_retention_days'])) {
            $retention_days = absint($input['log_retention_days']);
            $allowed_retention = array(0, 7, 15, 30, 60, 90, 180, 365); // Define allowed values
            if (in_array($retention_days, $allowed_retention, true)) {
                $sanitized_input['log_retention_days'] = $retention_days;
            } else {
                $sanitized_input['log_retention_days'] = isset($current_options['log_retention_days']) ? $current_options['log_retention_days'] : 30; // Default if invalid
                add_settings_error('bdcp_options', 'invalid_retention', __( 'Invalid log retention period selected. Reverted to previous or default.', 'bulk-delete-custom-posts' ), 'error');
            }
        } else {
            // If not set (e.g. form part not submitted), keep old value or default
            $sanitized_input['log_retention_days'] = isset($current_options['log_retention_days']) ? $current_options['log_retention_days'] : 30;
        }
        
        // Add other options here if needed

        return $sanitized_input;
    }

    /**
     * Handle options update to reschedule cron if retention settings change.
     */
    public function handle_options_update( $old_value, $value, $option_name ) {
        if ( $option_name !== $this->option_name ) {
            return;
        }

        $old_retention = isset( $old_value['log_retention_days'] ) ? absint( $old_value['log_retention_days'] ) : 30;
        $new_retention = isset( $value['log_retention_days'] ) ? absint( $value['log_retention_days'] ) : 30;

        if ( $old_retention === $new_retention ) {
            return; // No change in retention period
        }

        // Clear existing cron
        wp_clear_scheduled_hook( self::LOG_CLEANUP_CRON_HOOK );

        // Schedule new cron if retention is active (> 0)
        if ( $new_retention > 0 ) {
            if ( ! wp_next_scheduled( self::LOG_CLEANUP_CRON_HOOK ) ) {
                wp_schedule_event( time(), 'daily', self::LOG_CLEANUP_CRON_HOOK );
                $this->add_cpt_log_entry(
                    'cron_schedule',
                    'info',
                    sprintf(__( 'Log cleanup cron (re)scheduled for daily execution due to retention period change to %d days.', 'bulk-delete-custom-posts' ), $new_retention),
                    array('new_retention_days' => $new_retention)
                );
            }
        } else {
            $this->add_cpt_log_entry(
                'cron_unschedule',
                'info',
                __( 'Log cleanup cron unscheduled because retention period set to "Keep Logs Forever".', 'bulk-delete-custom-posts' ),
                array('new_retention_days' => $new_retention)
            );
        }
    }

    public function create_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $current_slug_term = isset( $_POST['bdcp_slug_term'] ) ? sanitize_text_field( $_POST['bdcp_slug_term'] ) : '';
        $current_post_type = isset( $_POST['bdcp_post_type'] ) ? sanitize_text_field( $_POST['bdcp_post_type'] ) : '';
        $current_taxonomy = isset( $_POST['bdcp_taxonomy'] ) ? sanitize_text_field( $_POST['bdcp_taxonomy'] ) : '';
        $current_batch_size = isset( $_POST['bdcp_batch_size'] ) ? absint( $_POST['bdcp_batch_size'] ) : 50;
        $current_batch_pause = isset( $_POST['bdcp_batch_pause'] ) ? absint( $_POST['bdcp_batch_pause'] ) : 1;
        $is_dry_run_checked = true;
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <p><?php esc_html_e( 'Select posts to delete based on post type, taxonomy, and an optional term in the taxonomy term slug. Process in batches for better performance on shared hosting.', 'bulk-delete-custom-posts' ); ?></p>
            <p style="color: red; font-weight: bold;"><?php esc_html_e( 'WARNING: This action is destructive and cannot be undone. ALWAYS make a full backup of your site (database and files) before proceeding. Use "Dry Run" first to verify the posts that will be affected.', 'bulk-delete-custom-posts' ); ?></p>
            <form id="bdcp-form" method="post" action="admin-post.php">
                <input type="hidden" name="action" value="bdcp_handle_form">
                <?php wp_nonce_field( 'bdcp_form_action_nonce', 'bdcp_nonce_field' ); ?>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="bdcp_post_type"><?php esc_html_e( 'Post Type', 'bulk-delete-custom-posts' ); ?></label></th>
                            <td>
                                <select id="bdcp_post_type" name="bdcp_post_type" required>
                                    <option value=""><?php esc_html_e( '-- Select a Post Type --', 'bulk-delete-custom-posts' ); ?></option>
                                    <?php
                                    $post_types_obj = get_post_types( array( 'public' => true, 'show_ui' => true ), 'objects' );
                                    $allowed_post_types_for_fallback = apply_filters( 'bdcp_allowed_post_types', $post_types_obj );
                                     if ($allowed_post_types_for_fallback) {
                                         foreach ($allowed_post_types_for_fallback as $pt_key => $pt_obj_fallback) {
                                            $pt_fb = is_object($pt_obj_fallback) ? $pt_obj_fallback : get_post_type_object($pt_key);
                                            if (!$pt_fb) continue;
                                            $taxonomies_for_pt = get_object_taxonomies( $pt_fb->name, 'objects' );
                                            $has_public_tax = false;
                                            foreach($taxonomies_for_pt as $tax) {
                                                 if ($tax->public && $tax->show_ui) { $has_public_tax = true; break; }
                                            }
                                            if ($has_public_tax) {
                                                echo '<option value="' . esc_attr( $pt_fb->name ) . '"' . selected( $current_post_type, $pt_fb->name, false ) . '>' . esc_html( $pt_fb->label ) . '</option>';
                                            }
                                        }
                                    }
                                    ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'Select the post type whose posts you want to delete.', 'bulk-delete-custom-posts' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bdcp_taxonomy"><?php esc_html_e( 'Taxonomy', 'bulk-delete-custom-posts' ); ?></label></th>
                            <td>
                                <select id="bdcp_taxonomy" name="bdcp_taxonomy" required>
                                    <option value=""><?php esc_html_e( '-- Select a Post Type First --', 'bulk-delete-custom-posts' ); ?></option>
                                     <?php
                                        if ( !empty($current_post_type) ) {
                                            $taxonomies_for_current_pt = get_object_taxonomies( $current_post_type, 'objects' );
                                            if ($taxonomies_for_current_pt) {
                                            foreach ( $taxonomies_for_current_pt as $tax ) {
                                                 if ( $tax->show_ui && $tax->public ) {
                                                    echo '<option value="' . esc_attr( $tax->name ) . '"' . selected( $current_taxonomy, $tax->name, false ) . '>' . esc_html( $tax->label ) . '</option>';
                                                     }
                                                 }
                                            }
                                        }
                                     ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'Select the taxonomy. This list updates based on the selected Post Type.', 'bulk-delete-custom-posts' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bdcp_slug_term"><?php esc_html_e( 'Filter by Term Name/Slug (Optional)', 'bulk-delete-custom-posts' ); ?></label></th>
                            <td>
                                <input type="text" id="bdcp_slug_term" name="bdcp_slug_term" value="<?php echo esc_attr( $current_slug_term ); ?>" class="regular-text" />
                                <p class="description"><?php esc_html_e( 'Enter text to search in the term\'s name or slug. E.g., "archive" to find terms like "News Archive" or "news-archive". Case-insensitive. Leave blank to include all terms of the selected taxonomy.', 'bulk-delete-custom-posts' ); ?></p>
                            </td>
                        </tr>
                         <tr>
                            <th scope="row"><label for="bdcp_batch_size"><?php esc_html_e( 'Batch Size', 'bulk-delete-custom-posts' ); ?></label></th>
                            <td>
                                <input type="number" id="bdcp_batch_size" name="bdcp_batch_size" value="<?php echo esc_attr( $current_batch_size ); ?>" min="1" max="1000" class="small-text" required />
                                <p class="description"><?php esc_html_e( 'Number of posts to process in each batch (e.g., 50). Recommended: 25-100 for shared hosting.', 'bulk-delete-custom-posts' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bdcp_batch_pause"><?php esc_html_e( 'Pause Between Batches (seconds)', 'bulk-delete-custom-posts' ); ?></label></th>
                            <td>
                                <input type="number" id="bdcp_batch_pause" name="bdcp_batch_pause" value="<?php echo esc_attr( $current_batch_pause ); ?>" min="0" max="60" class="small-text" required />
                                <p class="description"><?php esc_html_e( 'Seconds to pause between batches to reduce server load (e.g., 1). 0 for no pause.', 'bulk-delete-custom-posts' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Operation Mode', 'bulk-delete-custom-posts' ); ?></th>
                            <td>
                                <input type="checkbox" id="bdcp_dry_run" name="bdcp_dry_run" value="1" <?php checked( 1, $is_dry_run_checked, true ); ?> />
                                <label for="bdcp_dry_run"><?php esc_html_e( 'Dry Run (Only list posts that would be deleted, do not delete yet).', 'bulk-delete-custom-posts' ); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Cleanup Options', 'bulk-delete-custom-posts' ); ?></th>
                            <td>
                                <input type="checkbox" id="bdcp_delete_empty_terms" name="bdcp_delete_empty_terms" value="1" />
                                <label for="bdcp_delete_empty_terms"><?php esc_html_e( 'Delete empty terms: After deleting posts, remove any terms in the selected taxonomy (matching the slug filter, if used) that are now empty.', 'bulk-delete-custom-posts' ); ?></label>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p>
                    <button type="button" id="bdcp-find-posts-button" class="button button-secondary"><?php esc_html_e( 'Find Posts (Dry Run Preview)', 'bulk-delete-custom-posts' ); ?></button>
                    <button type="button" id="bdcp-delete-posts-button" class="button button-primary" style="display:none; background-color: #dc3232; border-color: #dc3232; color: #fff;"><?php esc_html_e( 'Delete Found Posts', 'bulk-delete-custom-posts' ); ?></button>
                </p>
            </form>
            <div id="bdcp-results-area" style="margin-top: 20px; border: 1px solid #ccc; padding: 10px; max-height: 400px; overflow-y: auto; background: #f9f9f9; display:none;">
                <h3><?php esc_html_e( 'Results', 'bulk-delete-custom-posts' ); ?></h3>
                <div id="bdcp-progress-bar-container" style="display:none; margin-bottom:10px;">
                    <div id="bdcp-progress-bar" style="width: 0%; height: 20px; background-color: #4CAF50; text-align: center; line-height: 20px; color: white;">0%</div>
                </div>
                <div id="bdcp-messages">
                    <p><?php esc_html_e( 'Click "Find Posts" to see which posts match your criteria.', 'bulk-delete-custom-posts' ); ?></p>
                </div>
            </div>
             <div id="bdcp-log-area" style="margin-top: 20px; border: 1px solid #ccc; padding: 10px; max-height: 200px; overflow-y: auto; background: #f0f0f0; display:none;">
                <h4><?php esc_html_e( 'Activity Log (Current Session)', 'bulk-delete-custom-posts' ); ?></h4>
                <ul id="bdcp-log-list"></ul>
            </div>

            <hr style="margin: 30px 0;">
            <h2><?php esc_html_e( 'Log Settings', 'bulk-delete-custom-posts' ); ?></h2>
            <form method="post" action="options.php"> 
                <?php settings_fields( 'bdcp_options_group' ); ?>
                <?php $options = get_option( $this->option_name, array('log_retention_days' => 30) ); // Default to 30 days ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><label for="bdcp_log_retention_days"><?php esc_html_e( 'Log Retention Period', 'bulk-delete-custom-posts' ); ?></label></th>
                        <td>
                            <select id="bdcp_log_retention_days" name="<?php echo esc_attr($this->option_name); ?>[log_retention_days]">
                                <?php
                                $retention_options = array(
                                    0 => __( 'Keep Logs Forever', 'bulk-delete-custom-posts' ),
                                    7 => __( '7 Days', 'bulk-delete-custom-posts' ),
                                    15 => __( '15 Days', 'bulk-delete-custom-posts' ),
                                    30 => __( '30 Days', 'bulk-delete-custom-posts' ),
                                    60 => __( '60 Days', 'bulk-delete-custom-posts' ),
                                    90 => __( '90 Days', 'bulk-delete-custom-posts' ),
                                    180 => __( '180 Days', 'bulk-delete-custom-posts' ),
                                    365 => __( '365 Days', 'bulk-delete-custom-posts' ),
                                );
                                $current_retention = isset($options['log_retention_days']) ? $options['log_retention_days'] : 30;
                                foreach ($retention_options as $days => $label) {
                                    echo '<option value="' . esc_attr($days) . '" ' . selected($current_retention, $days, false) . '>' . esc_html($label) . '</option>';
                                }
                                ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'How long to keep deletion logs. Older logs will be automatically deleted by a daily cron job (if configured) or during manual cleanup.', 'bulk-delete-custom-posts' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__( 'Save Log Settings', 'bulk-delete-custom-posts' )); ?>
            </form>
            
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                <input type="hidden" name="action" value="bdcp_cleanup_logs_now">
                <?php wp_nonce_field( 'bdcp_cleanup_logs_now_nonce', 'bdcp_cleanup_nonce_field' ); ?>
                <?php submit_button( __( 'Clean Up Old Logs Now', 'bulk-delete-custom-posts' ), 'delete', 'bdcp_do_cleanup_logs', false, array('onclick' => 'return confirm("' . esc_js(__( 'Are you sure you want to delete old logs now based on the currently saved retention period?', 'bulk-delete-custom-posts' )) . '");') ); ?>
                <p class="description"><?php esc_html_e( 'Manually delete logs older than the saved retention period. This uses the *saved* retention period, not necessarily what is currently selected in the dropdown above if you haven\'t saved yet.', 'bulk-delete-custom-posts' ); ?></p>
            </form>

        </div>
        <?php
    }

    public function handle_form_submission() {
        if ( ! isset( $_POST['bdcp_nonce_field'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bdcp_nonce_field'] ) ), 'bdcp_form_action_nonce' ) ) {
            wp_die( __( 'Security check failed (form submission)! ', 'bulk-delete-custom-posts' ), 'Error', array( 'response' => 403 ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have sufficient permissions to perform this action.', 'bulk-delete-custom-posts' ), 'Error', array( 'response' => 403 ) );
        }
        wp_redirect( admin_url( 'tools.php?page=bulk-delete-custom-posts' ) );
        exit;
    }

    public function handle_cleanup_logs_now_action(){
        if ( ! isset( $_POST['bdcp_cleanup_nonce_field'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bdcp_cleanup_nonce_field'] ) ), 'bdcp_cleanup_logs_now_nonce' ) ) {
            wp_die( __( 'Security check failed!', 'bulk-delete-custom-posts' ), 'Error', array( 'response' => 403 ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have sufficient permissions to perform this action.', 'bulk-delete-custom-posts' ), 'Error', array( 'response' => 403 ) );
        }

        $deleted_count = $this->cleanup_old_logs();

        if (is_wp_error($deleted_count)){
            add_settings_error('bdcp_options', 'log_cleanup_error', sprintf(__( 'Error during log cleanup: %s', 'bulk-delete-custom-posts' ), $deleted_count->get_error_message()), 'error');
        } else {
            add_settings_error('bdcp_options', 'log_cleanup_success', sprintf(__( '%d old log entries deleted.', 'bulk-delete-custom-posts' ), $deleted_count), 'updated');
        }
        
        // Redirect back to the plugin page
        // Pass admin notices via set_transient if not using add_settings_error which works with options.php redirection
        wp_redirect( admin_url( 'tools.php?page=bulk-delete-custom-posts' ) );
        exit;
    }

    /**
     * Deletes old log entries based on the saved retention period.
     * @return int|WP_Error Number of deleted posts or WP_Error on failure.
     */
    public function cleanup_old_logs() {
        $options = get_option($this->option_name, array('log_retention_days' => 30));
        $retention_days = isset($options['log_retention_days']) ? absint($options['log_retention_days']) : 30;

        if ($retention_days <= 0) {
            // 0 means keep forever
            return 0; 
        }

        $cutoff_date = gmdate('Y-m-d H:i:s', strtotime("-$retention_days days"));

        $args = array(
            'post_type' => self::LOG_POST_TYPE,
            'posts_per_page' => -1, // Process all matching logs
            'fields' => 'ids', // We only need IDs to delete
            'date_query' => array(
                array(
                    'before' => $cutoff_date,
                    'inclusive' => true,
                    'column' => 'post_date_gmt' // Ensure we compare with GMT dates
                )
            )
        );

        $old_logs_query = new WP_Query($args);
        $deleted_count = 0;

        if ($old_logs_query->have_posts()) {
            $log_ids_to_delete = $old_logs_query->posts;
            foreach ($log_ids_to_delete as $log_id) {
                $delete_result = wp_delete_post($log_id, true); // true to force delete, bypass trash
                if ($delete_result) {
                    $deleted_count++;
                }
            }
        }
        return $deleted_count;
    }

    public function ajax_find_posts() {
        error_log('[BDCP DEBUG] RAW POST in ajax_find_posts: ' . print_r($_POST, true));
        if (isset($_POST['delete_empty_terms'])) {
            error_log('[BDCP DEBUG] ajax_find_posts - _POST[\'delete_empty_terms\'] IS SET. Value: ' . sanitize_text_field(wp_unslash($_POST['delete_empty_terms'])) . ' (Type: ' . gettype($_POST['delete_empty_terms']) . ')');
        } else {
            error_log('[BDCP DEBUG] ajax_find_posts - _POST[\'delete_empty_terms\'] IS NOT SET.');
        }

        check_ajax_referer( 'bdcp_find_posts_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permissions error.', 'bulk-delete-custom-posts' ) ) );
        }

        $this->current_operation_settings = array(
            'post_type' => isset( $_POST['post_type'] ) ? sanitize_key( $_POST['post_type'] ) : '',
            'taxonomy' => isset( $_POST['taxonomy'] ) ? sanitize_key( $_POST['taxonomy'] ) : '',
            'slug_term' => isset( $_POST['slug_term'] ) ? sanitize_text_field( wp_unslash( $_POST['slug_term'] ) ) : '',
            'delete_empty_terms' => isset( $_POST['delete_empty_terms'] ) && ( $_POST['delete_empty_terms'] === 'true' || $_POST['delete_empty_terms'] === true || $_POST['delete_empty_terms'] === '1' || $_POST['delete_empty_terms'] === 1 ) ,
            'candidate_term_ids_for_cleanup' => array() // Initialize for term cleanup
        );
        error_log('[BDCP DEBUG] ajax_find_posts - current_operation_settings: ' . print_r($this->current_operation_settings, true)); // PHP Error Log

        $post_type = $this->current_operation_settings['post_type'];
        $taxonomy = $this->current_operation_settings['taxonomy'];
        $slug_term_filter = $this->current_operation_settings['slug_term'];
        $delete_empty_terms_flag = $this->current_operation_settings['delete_empty_terms'];

        $response_messages = array();
        $found_posts_data = array();

        // Store current operation settings in a transient for later use by batch processing hooks
        set_transient('bdcp_operation_settings_' . get_current_user_id(), $this->current_operation_settings, HOUR_IN_SECONDS);

        if ( empty( $post_type ) || !post_type_exists($post_type) ) {
            wp_send_json_error( array( 'message' => __( 'Error: Please select a valid Post Type.', 'bulk-delete-custom-posts' ) ) );
        }
        if ( empty( $taxonomy ) || !taxonomy_exists($taxonomy) ) {
             wp_send_json_error( array( 'message' => __( 'Error: Please select a valid Taxonomy.', 'bulk-delete-custom-posts' ) ) );
        }
        if ( !is_object_in_taxonomy( $post_type, $taxonomy ) ) {
             wp_send_json_error( array( 'message' => sprintf(__( 'Error: Taxonomy "%1$s" is not registered for Post Type "%2$s".', 'bulk-delete-custom-posts' ), esc_html($taxonomy), esc_html($post_type)) ) );
        }

        $target_term_ids = array();
        if (!empty($slug_term_filter)) {
            $terms_args = array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'all' );
            $all_terms = get_terms( $terms_args );
            if ( !is_wp_error( $all_terms ) && !empty($all_terms)) {
                foreach ( $all_terms as $term ) {
                    // Search in term name OR slug, case-insensitive
                    if ( stripos( $term->name, $slug_term_filter ) !== false || stripos( $term->slug, $slug_term_filter ) !== false ) {
                        $target_term_ids[] = $term->term_id;
                        $response_messages[] = sprintf(__( 'Matched term by filter "%1$s": %2$s (Slug: %3$s)', 'bulk-delete-custom-posts' ), esc_html($slug_term_filter), esc_html($term->name), esc_html($term->slug));
                    }
                }
            }
            if (empty($target_term_ids)) {
                 $response_messages[] = sprintf(__( 'No terms found in taxonomy "%1$s" with "%2$s" in their name or slug.', 'bulk-delete-custom-posts' ), esc_html($taxonomy), esc_html($slug_term_filter));
                 wp_send_json_success( array( 'message' => implode("<br>", $response_messages), 'posts' => [], 'count' => 0 ) );
                 return; // Important to exit here
            }
            $this->current_operation_settings['candidate_term_ids_for_cleanup'] = $target_term_ids;
        } else {
             $response_messages[] = __( 'No slug term filter applied. Considering all terms in the selected taxonomy.', 'bulk-delete-custom-posts' );
             // If no slug filter, all terms of the taxonomy are candidates if delete_empty_terms is checked
             if ($delete_empty_terms_flag) {
                $all_terms_in_taxonomy = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids'));
                if (!is_wp_error($all_terms_in_taxonomy)) {
                    $this->current_operation_settings['candidate_term_ids_for_cleanup'] = $all_terms_in_taxonomy;
                }
             }
        }

        $query_args = array(
            'post_type'      => $post_type,
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'tax_query'      => array( 'relation' => 'AND' ),
        );
        $tax_query_item = array( 'taxonomy' => $taxonomy, 'field' => 'term_id' );
        if (!empty($target_term_ids)) {
            $tax_query_item['terms'] = $target_term_ids;
            $tax_query_item['operator'] = 'IN';
        } elseif (empty($slug_term_filter)) {
            // No explicit terms needed for tax_query if matching all terms in the taxonomy
        } else { // Slug filter given but no terms matched - already handled by early return
             wp_send_json_success( array( 'message' => implode("<br>", $response_messages), 'posts' => [], 'count' => 0 ) );
             return;
        }
        $query_args['tax_query'][] = $tax_query_item;

        /**
         * Filter the WP_Query arguments before finding posts.
         *
         * @param array $query_args The WP_Query arguments.
         * @param array $settings   The current plugin operation settings (post_type, taxonomy, slug_term).
         */
        $query_args = apply_filters( 'bdcp_pre_get_posts_args', $query_args, $this->current_operation_settings );

        $found_post_ids = get_posts( $query_args );
        $count = count( $found_post_ids );

        if ( $count > 0 ) {
            $response_messages[] = sprintf( _n( 'Found %d post to process.', 'Found %d posts to process.', $count, 'bulk-delete-custom-posts' ), $count );
            foreach ( $found_post_ids as $post_id ) {
                $found_posts_data[] = array( 'id' => $post_id, 'title' => esc_html( get_the_title( $post_id ) ) );
            }
        } else {
            $response_messages[] = __( 'No posts found matching your criteria.', 'bulk-delete-custom-posts' );
        }

        if ($count > 0) {
            // Store all found IDs for reference by bdcp_after_all_posts_deleted hook
            set_transient( 'bdcp_all_found_ids_' . get_current_user_id(), $found_post_ids, HOUR_IN_SECONDS );
            // Store current operation settings for batch processing hooks
            set_transient('bdcp_operation_settings_' . get_current_user_id(), $this->current_operation_settings, HOUR_IN_SECONDS);
            $log_status = 'info';
            $log_summary = sprintf( _n( 'Found %d post.', 'Found %d posts.', $count, 'bulk-delete-custom-posts' ), $count );
        } else {
            delete_transient( 'bdcp_all_found_ids_' . get_current_user_id() );
            delete_transient( 'bdcp_operation_settings_' . get_current_user_id() ); // Clean up if no posts found
            $log_status = 'info';
            $log_summary = __( 'No posts found matching criteria.', 'bulk-delete-custom-posts' );
        }

        $this->add_cpt_log_entry(
            'find_posts',
            $log_status,
            $log_summary,
            array(
                'criteria' => $this->current_operation_settings,
                'found_count' => $count,
                'messages_array' => $response_messages // Add messages from find operation to log content
            )
        );

        wp_send_json_success( array(
            'message' => implode("<br>", $response_messages),
            'posts' => $found_posts_data,
            'count' => $count
        ) );
    }

    public function ajax_delete_batch() {
        check_ajax_referer( 'bdcp_delete_batch_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permissions error.', 'bulk-delete-custom-posts' ) ) );
        }
        error_log('[BDCP DEBUG] ajax_delete_batch called. POST data: ' . print_r($_POST, true)); // PHP Error Log

        $post_ids_in_batch = isset( $_POST['post_ids'] ) && is_array($_POST['post_ids']) ? array_map( 'absint', $_POST['post_ids'] ) : array();
        $force_delete = true;
        $is_last_batch_from_post = isset($_POST['is_last_batch']) && $_POST['is_last_batch'] === 'true';
        error_log('[BDCP DEBUG] ajax_delete_batch - is_last_batch_from_post: ' . ($is_last_batch_from_post ? 'true' : 'false')); // PHP Error Log

        $this->current_operation_settings = get_transient('bdcp_operation_settings_' . get_current_user_id()) ?: array(); 
        error_log('[BDCP DEBUG] ajax_delete_batch - Retrieved transient bdcp_operation_settings: ' . print_r($this->current_operation_settings, true)); // PHP Error Log

        if ( empty( $post_ids_in_batch ) ) {
            wp_send_json_error( array( 
                'message' => __( 'No post IDs provided for deletion in this batch.', 'bulk-delete-custom-posts' )
            ) );
        }

        do_action( 'bdcp_before_batch_delete', $post_ids_in_batch, $this->current_operation_settings );

        $deleted_count_in_batch = 0;
        $error_count_in_batch = 0;
        $results_details = array();
        $deleted_post_ids_in_batch = array();

        foreach ( $post_ids_in_batch as $post_id ) {
            $post_title = get_the_title($post_id);
            $delete_result = wp_delete_post( $post_id, $force_delete );
            if ( $delete_result ) {
                $deleted_count_in_batch++;
                $deleted_post_ids_in_batch[] = $post_id;
                $results_details[] = sprintf(__( 'Successfully deleted: "%1$s" (ID: %2$d)', 'bulk-delete-custom-posts' ), esc_html($post_title), $post_id);
            } else {
                $error_count_in_batch++;
                $results_details[] = sprintf(__( 'Error deleting: "%1$s" (ID: %2$d)', 'bulk-delete-custom-posts' ), esc_html($post_title), $post_id);
            }
        }

        /**
         * Action after a batch of posts has been processed for deletion.
         *
         * @param array $deleted_post_ids_in_batch Array of post IDs successfully deleted in this batch.
         * @param array $post_ids_in_batch         Array of post IDs attempted in this batch.
         * @param array $settings                  The current plugin operation settings.
         */
        do_action( 'bdcp_after_batch_delete', $deleted_post_ids_in_batch, $post_ids_in_batch, $this->current_operation_settings );

        $this->add_cpt_log_entry( 'delete_batch', $deleted_count_in_batch > 0 ? 'success' : 'info', sprintf(__( 'Batch Processed: %d attempted, %d successfully deleted. Batch IDs (sample): %s', 'bulk-delete-custom-posts' ), count($post_ids_in_batch), $deleted_count_in_batch, implode(',', array_slice($post_ids_in_batch, 0, 5)) ), array( 'deleted_count' => $deleted_count_in_batch, 'error_count' => $error_count_in_batch, 'details' => $results_details ) );

        $response_data = array( 
            'message' => sprintf(
                _n( 'Processed 1 post in batch. %d deleted, %d error.', 'Processed %d posts in batch. %d deleted, %d errors.', count($post_ids_in_batch), 'bulk-delete-custom-posts' ),
                count($post_ids_in_batch),
                $deleted_count_in_batch,
                $error_count_in_batch
            ),
            'deleted_count' => $deleted_count_in_batch, 
            'error_count' => $error_count_in_batch, 
            'details' => $results_details
        );

        if ($is_last_batch_from_post) {
            error_log('[BDCP DEBUG] ajax_delete_batch - Inside is_last_batch_from_post condition.'); // PHP Error Log
            $all_found_ids = get_transient( 'bdcp_all_found_ids_' . get_current_user_id() );
            error_log('[BDCP DEBUG] ajax_delete_batch - About to do_action bdcp_after_all_posts_deleted. Settings: ' . print_r($this->current_operation_settings, true) . ' All found IDs count: ' . (is_array($all_found_ids) ? count($all_found_ids) : 'N/A')); // PHP Error Log
            
            do_action( 'bdcp_after_all_posts_deleted', $all_found_ids, $this->current_operation_settings );

            delete_transient( 'bdcp_all_found_ids_' . get_current_user_id() );
            delete_transient( 'bdcp_operation_settings_' . get_current_user_id());
            $response_data['final_operation_message'] = __( 'All batches processed. Term cleanup (if enabled) should have run.', 'bulk-delete-custom-posts' );
        }

        if ($error_count_in_batch > 0) {
            wp_send_json_error( $response_data );
        } else {
            wp_send_json_success( $response_data );
        }
    }
    
    public function register_log_post_type() {
        $labels = array(
            'name'               => _x( 'Deletion Logs', 'post type general name', 'bulk-delete-custom-posts' ),
            'singular_name'      => _x( 'Deletion Log', 'post type singular name', 'bulk-delete-custom-posts' ),
            'menu_name'          => _x( 'Deletion Logs', 'admin menu', 'bulk-delete-custom-posts' ),
            'name_admin_bar'     => _x( 'Deletion Log', 'add new on admin bar', 'bulk-delete-custom-posts' ),
            'add_new'            => _x( 'Add New Log', 'log', 'bulk-delete-custom-posts' ),
            'add_new_item'       => __( 'Add New Log', 'bulk-delete-custom-posts' ),
            'new_item'           => __( 'New Log', 'bulk-delete-custom-posts' ),
            'edit_item'          => __( 'Edit Log', 'bulk-delete-custom-posts' ),
            'view_item'          => __( 'View Log', 'bulk-delete-custom-posts' ),
            'all_items'          => __( 'All Deletion Logs', 'bulk-delete-custom-posts' ),
            'search_items'       => __( 'Search Logs', 'bulk-delete-custom-posts' ),
            'parent_item_colon'  => __( 'Parent Logs:', 'bulk-delete-custom-posts' ),
            'not_found'          => __( 'No logs found.', 'bulk-delete-custom-posts' ),
            'not_found_in_trash' => __( 'No logs found in Trash.', 'bulk-delete-custom-posts' )
        );

        $args = array(
            'labels'             => $labels,
            'public'             => false, // Not public on the front-end
            'publicly_queryable' => false,
            'show_ui'            => true, // Show in admin
            'show_in_menu'       => 'bulk-delete-custom-posts', // New: Submenu of our main plugin page
            'query_var'          => false,
            'rewrite'            => false,
            'capability_type'    => 'post',
            'capabilities' => array(
                'create_posts' => 'do_not_allow', // Disallow direct creation
            ),
            'map_meta_cap'       => true, // Map meta capabilities
            'has_archive'        => false,
            'hierarchical'       => false,
            'menu_position'      => null,
            'supports'           => array( 'title', 'editor' ), // Title for summary, editor for details
            'show_in_rest'       => false, // Not needed for REST API for now
        );

        register_post_type( self::LOG_POST_TYPE, $args );
    }

    /**
     * Enhanced activity logger using a Custom Post Type.
     *
     * @param string $action         Short code for the action (e.g., 'find_posts', 'delete_batch').
     * @param string $status         Status of the action (e.g., 'success', 'warning', 'error', 'info').
     * @param string $summary_message A brief summary for the log title.
     * @param array  $details        Associative array of details to log.
     *                                Keys can be 'criteria', 'deleted_count', 'error_count', 'messages_array'.
     */
    private function add_cpt_log_entry( $action, $status, $summary_message, $details = array() ) {
        if ( ! current_user_can( 'manage_options' ) ) { // Or a more specific capability for logging if needed
            return false;
        }

        $log_post_title = sprintf('[%s] %s: %s', strtoupper($status), $action, $summary_message);
        $log_post_content = '';

        if (isset($details['messages_array']) && is_array($details['messages_array'])) {
            // Sanitize messages before adding to post content
            $log_post_content .= "<p><strong>Operation Messages:</strong></p><ul>";
            foreach ($details['messages_array'] as $msg) {
                // Remove HTML tags for simple list, or use wp_kses_post if HTML is intended and safe
                $log_post_content .= "<li>" . esc_html(strip_tags($msg)) . "</li>";
            }
            $log_post_content .= "</ul>";
            unset($details['messages_array']); // Avoid storing it also as meta if already in content
        }

        if (isset($details['details']) && is_array($details['details'])) {
             $log_post_content .= "<p><strong>Batch Deletion Details:</strong></p><ul>";
            foreach ($details['details'] as $detail_msg) {
                $log_post_content .= "<li>" . esc_html(strip_tags($detail_msg)) . "</li>";
            }
            $log_post_content .= "</ul>";
            unset($details['details']);
        }

        $log_entry_data = array(
            'post_title'   => wp_trim_words($log_post_title, 20, '...'), // Keep title somewhat brief
            'post_content' => $log_post_content,
            'post_status'  => 'publish', // Logs are always published internally
            'post_type'    => self::LOG_POST_TYPE,
            'post_author'  => get_current_user_id(),
        );

        $post_id = wp_insert_post( $log_entry_data );

        if ( $post_id && !is_wp_error( $post_id ) ) {
            // Store structured data as post meta
            update_post_meta( $post_id, '_bdcp_log_action', sanitize_key($action) );
            update_post_meta( $post_id, '_bdcp_log_status', sanitize_key($status) );
            update_post_meta( $post_id, '_bdcp_log_user_id', get_current_user_id() );

            if (isset($details['criteria']) && is_array($details['criteria'])) {
                update_post_meta( $post_id, '_bdcp_log_criteria', $details['criteria'] ); // Already sanitized before usually
            }
            if (isset($details['deleted_count'])) {
                update_post_meta( $post_id, '_bdcp_log_deleted_count', absint($details['deleted_count']) );
            }
            if (isset($details['error_count'])) {
                update_post_meta( $post_id, '_bdcp_log_error_count', absint($details['error_count']) );
            }
             if (isset($details['attempted_count'])) {
                update_post_meta( $post_id, '_bdcp_log_attempted_count', absint($details['attempted_count']) );
            }
            // Add a timestamp for easier sorting if not relying on post_date
            update_post_meta( $post_id, '_bdcp_log_timestamp', time() );
            return $post_id;
        } else {
            // Fallback to old logging method or error log if CPT fails
            error_log('BDCP CPT Logging Error: ' . ($post_id instanceof WP_Error ? $post_id->get_error_message() : 'Unknown error'));
            $this->add_to_activity_log('[CPT_LOG_FAIL] ' . $summary_message); // Old log as fallback
            return false;
        }
    }

    // OLD add_to_activity_log method - keep for now or remove if fully replaced
    // private function add_to_activity_log( $message ) { ... }

    // Custom Columns for Log CPT
    public function set_log_columns($columns) {
        unset($columns['date']); // Use our custom timestamp or formatted date
        // unset($columns['title']); // We might want to keep title for quick glance

        $new_columns = array(
            'cb' => $columns['cb'],
            'title' => __( 'Summary', 'bulk-delete-custom-posts' ),
            'log_action' => __( 'Action', 'bulk-delete-custom-posts' ),
            'log_status' => __( 'Status', 'bulk-delete-custom-posts' ),
            'log_user' => __( 'User', 'bulk-delete-custom-posts' ),
            'log_counts' => __( 'Counts (D/E/A)', 'bulk-delete-custom-posts' ), // Deleted/Error/Attempted or Found
            'log_criteria' => __( 'Criteria', 'bulk-delete-custom-posts' ),
            'log_timestamp' => __( 'Timestamp', 'bulk-delete-custom-posts' ),
        );
        return $new_columns;
    }

    public function render_log_columns($column, $post_id) {
        switch ($column) {
            case 'log_action':
                echo esc_html( get_post_meta( $post_id, '_bdcp_log_action', true ) );
                break;
            case 'log_status':
                $status = get_post_meta( $post_id, '_bdcp_log_status', true );
                echo '<span style="text-transform:capitalize; padding: 2px 5px; background-color:' . ($status === 'error' ? '#fdd' : ($status === 'success' ? '#dfd' : '#ffd')) . ';">' . esc_html($status) . '</span>';
                break;
            case 'log_user':
                $user_id = get_post_meta( $post_id, '_bdcp_log_user_id', true );
                $user = get_userdata($user_id);
                echo $user ? esc_html($user->display_name) : 'N/A';
                break;
            case 'log_counts':
                $deleted = get_post_meta( $post_id, '_bdcp_log_deleted_count', true );
                $errors = get_post_meta( $post_id, '_bdcp_log_error_count', true );
                $attempted = get_post_meta( $post_id, '_bdcp_log_attempted_count', true );
                $found = get_post_meta( $post_id, '_bdcp_log_found_count', true );
                $counts_str = '';
                if ($deleted !== '') $counts_str .= 'D: ' . absint($deleted) . ' ';
                if ($errors !== '') $counts_str .= 'E: ' . absint($errors) . ' ';
                if ($attempted !== '') $counts_str .= 'A: ' . absint($attempted) . ' ';
                if ($found !== '') $counts_str .= 'F: ' . absint($found) . ' ';
                echo esc_html(trim($counts_str)) ?: '-';
                break;
            case 'log_criteria':
                $criteria = get_post_meta( $post_id, '_bdcp_log_criteria', true );
                if (is_array($criteria)) {
                    $output = '';
                    if (!empty($criteria['post_type'])) $output .= 'PT: ' . esc_html($criteria['post_type']) . '<br>';
                    if (!empty($criteria['taxonomy'])) $output .= 'Tax: ' . esc_html($criteria['taxonomy']) . '<br>';
                    if (!empty($criteria['slug_term'])) $output .= 'Slug: ' . esc_html($criteria['slug_term']) . '<br>';
                    echo empty($output) ? 'N/A' : $output;
                } else {
                    echo 'N/A';
                }
                break;
            case 'log_timestamp':
                $timestamp = get_post_meta( $post_id, '_bdcp_log_timestamp', true );
                echo $timestamp ? esc_html(wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp )) : 'N/A';
                break;
        }
    }

    public function set_log_sortable_columns($columns) {
        $columns['log_action'] = '_bdcp_log_action';
        $columns['log_status'] = '_bdcp_log_status';
        $columns['log_user'] = '_bdcp_log_user_id';
        $columns['log_timestamp'] = '_bdcp_log_timestamp';
        // Note: Sorting by counts or criteria directly via meta query can be complex or slow if not numeric.
        // For true numeric sort on counts, the meta value should be stored as numeric.
        return $columns;
    }

    // Filters for Log CPT Admin List
    public function add_log_list_filters($post_type) {
        if (self::LOG_POST_TYPE === $post_type) {
            // Filter by Action
            $current_action = isset($_GET['log_action_filter']) ? sanitize_key($_GET['log_action_filter']) : '';
            // It's better to have predefined actions than querying all distinct meta values for performance
            $actions = array('find_posts' => 'Find Posts', 'delete_batch' => 'Delete Batch', 'after_all_posts_deleted' => 'Operation Complete');
            echo '<select name="log_action_filter">';
            echo '<option value="">' . __( 'All Actions', 'bulk-delete-custom-posts' ) . '</option>';
            foreach ($actions as $action_key => $action_label) {
                printf(
                    '<option value="%s"%s>%s</option>',
                    esc_attr($action_key),
                    selected($current_action, $action_key, false),
                    esc_html($action_label)
                );
            }
            echo '</select>';

            // Filter by Status
            $current_status = isset($_GET['log_status_filter']) ? sanitize_key($_GET['log_status_filter']) : '';
            $stati = array('info' => 'Info', 'success' => 'Success', 'warning' => 'Warning', 'error' => 'Error'); // Predefined stati
            echo '<select name="log_status_filter">';
            echo '<option value="">' . __( 'All Stati', 'bulk-delete-custom-posts' ) . '</option>';
            foreach ($stati as $status_key => $status_label) {
                printf(
                    '<option value="%s"%s>%s</option>',
                    esc_attr($status_key),
                    selected($current_status, $status_key, false),
                    esc_html($status_label)
                );
            }
            echo '</select>';
        }
    }

    public function filter_logs_query($query) {
        global $pagenow;
        // Check if it's the admin edit page, our CPT, and the main query
        if (is_admin() && 'edit.php' === $pagenow && $query->is_main_query() && isset($query->query_vars['post_type']) && $query->query_vars['post_type' ] === self::LOG_POST_TYPE) {
            $meta_query = isset($query->query_vars['meta_query']) ? $query->query_vars['meta_query'] : array();

            if (!empty($_GET['log_action_filter'])) {
                $meta_query[] = array(
                    'key'     => '_bdcp_log_action',
                    'value'   => sanitize_key($_GET['log_action_filter']),
                    'compare' => '=',
                );
            }

            if (!empty($_GET['log_status_filter'])) {
                $meta_query[] = array(
                    'key'     => '_bdcp_log_status',
                    'value'   => sanitize_key($_GET['log_status_filter']),
                    'compare' => '=',
                );
            }
            
            if (!empty($meta_query) && count($meta_query) > (isset($query->query_vars['meta_query']) ? count($query->query_vars['meta_query']) : 0) ) {
                 $query->set('meta_query', $meta_query);
            }
        }
    }

    /**
     * Runs the log cleanup process via WP Cron.
     */
    public function run_log_cleanup_cron() {
        $options = get_option($this->option_name, array('log_retention_days' => 30));
        $retention_days = isset($options['log_retention_days']) ? absint($options['log_retention_days']) : 30;

        if ( $retention_days <= 0 ) {
            $this->add_cpt_log_entry(
                'cron_skip_cleanup',
                'info',
                __( 'Log cleanup cron run: Skipped as retention period is set to "Keep Logs Forever".', 'bulk-delete-custom-posts' )
            );
            return; // Do nothing if retention is 0 (keep forever)
        }

        $deleted_count = $this->cleanup_old_logs();

        if ( is_wp_error( $deleted_count ) ) {
            $this->add_cpt_log_entry(
                'cron_cleanup_error',
                'error',
                sprintf( __( 'Log cleanup cron run: Error during cleanup - %s', 'bulk-delete-custom-posts' ), $deleted_count->get_error_message() )
            );
        } else {
            $this->add_cpt_log_entry(
                'cron_cleanup_success',
                'success',
                sprintf( _n( 'Log cleanup cron run: %d old log entry deleted.', 'Log cleanup cron run: %d old log entries deleted.', $deleted_count, 'bulk-delete-custom-posts' ), $deleted_count ),
                array( 'deleted_count' => $deleted_count )
            );
        }
    }

    /**
     * Handles the cleanup of empty terms after all posts have been deleted in an operation.
     *
     * @param array $all_found_ids Potentially the initial list of all post IDs that were targeted.
     * @param array $settings      The operation settings for the completed deletion process.
     */
    public function handle_term_cleanup_after_deletion( $all_found_ids, $settings ) {
        error_log('[BDCP DEBUG] handle_term_cleanup_after_deletion called. Settings: ' . print_r($settings, true) . ' All found IDs count: ' . (is_array($all_found_ids) ? count($all_found_ids) : 'N/A')); // PHP Error Log

        if ( !isset($settings['delete_empty_terms']) || !$settings['delete_empty_terms'] ) {
            error_log('[BDCP DEBUG] handle_term_cleanup_after_deletion - SKIPPING: delete_empty_terms not set or false. Value: ' . (isset($settings['delete_empty_terms']) ? ($settings['delete_empty_terms'] ? 'true' : 'false') : 'NOT_SET')); // PHP Error Log
            // $this->add_cpt_log_entry(...)
            return; // Option not selected
        }
        if ( empty($settings['taxonomy']) || empty($settings['candidate_term_ids_for_cleanup']) ) {
            error_log('[BDCP DEBUG] handle_term_cleanup_after_deletion - SKIPPING: Missing taxonomy or candidate_term_ids_for_cleanup. Taxonomy: ' . ($settings['taxonomy'] ?? 'NULL') . ' Candidates: ' . print_r($settings['candidate_term_ids_for_cleanup'] ?? [], true)); // PHP Error Log
            // $this->add_cpt_log_entry(...)
            return;
        }

        $taxonomy_name = $settings['taxonomy'];
        $term_ids_to_check = array_map('absint', $settings['candidate_term_ids_for_cleanup']);

        if (empty($term_ids_to_check)){
             error_log('[BDCP DEBUG] handle_term_cleanup_after_deletion - SKIPPING: No candidate term IDs to check after mapping.'); // PHP Error Log
            // $this->add_cpt_log_entry(...)
            return;
        }

        // Ensure term counts are up-to-date for the specified taxonomy and terms.
        // This is crucial as post deletion might not have updated counts immediately in all cases.
        wp_update_term_count_now( $term_ids_to_check, $taxonomy_name );

        $deleted_terms_count = 0;
        $error_terms_count = 0;
        $term_cleanup_details = array();

        foreach ( $term_ids_to_check as $term_id ) {
            $term = get_term( $term_id, $taxonomy_name );
            if ( $term && ! is_wp_error( $term ) ) {
                if ( $term->count == 0 ) {
                    $delete_result = wp_delete_term( $term_id, $taxonomy_name );
                    if ( is_wp_error( $delete_result ) ) {
                        $error_terms_count++;
                        $term_cleanup_details[] = sprintf( __( 'Error deleting term %1$s (ID: %2$d): %3$s', 'bulk-delete-custom-posts' ), esc_html($term->name), $term_id, esc_html($delete_result->get_error_message()) );
                    } elseif ($delete_result) { // $delete_result is true on success
                        $deleted_terms_count++;
                        $term_cleanup_details[] = sprintf( __( 'Successfully deleted empty term: %1$s (ID: %2$d)', 'bulk-delete-custom-posts' ), esc_html($term->name), $term_id );
                    } else {
                        // wp_delete_term returned false, but not WP_Error (should not happen for existing terms if count is 0)
                        $error_terms_count++;
                        $term_cleanup_details[] = sprintf( __( 'Failed to delete term %1$s (ID: %2$d) for an unknown reason.', 'bulk-delete-custom-posts' ), esc_html($term->name), $term_id );
                    }
                }
            } else {
                 $error_terms_count++;
                 $term_cleanup_details[] = sprintf( __( 'Error retrieving term ID %1$d for cleanup.', 'bulk-delete-custom-posts' ), $term_id );
            }
        }

        $log_status = ($error_terms_count > 0) ? 'warning' : 'success';
        $log_summary = sprintf(
            _n( 'Empty term cleanup: %d term deleted.', 'Empty term cleanup: %d terms deleted.', $deleted_terms_count, 'bulk-delete-custom-posts' ) . ' %d errors.',
            $deleted_terms_count,
            $error_terms_count
        );

        $this->add_cpt_log_entry(
            'term_cleanup_result',
            $log_status,
            $log_summary,
            array(
                'deleted_count' => $deleted_terms_count,
                'error_count' => $error_terms_count,
                'taxonomy' => $taxonomy_name,
                'checked_term_ids' => $term_ids_to_check,
                'details_array' => $term_cleanup_details // Storing detailed messages
            )
        );
    }

}

Bulk_Delete_Custom_Posts::get_instance();

/**
 * Plugin activation hook.
 * Schedules the daily cron job for log cleanup if not already scheduled and retention is > 0.
 */
function bdcp_activate_plugin() {
    $options = get_option( 'bdcp_options', array('log_retention_days' => 30) ); // Use the actual option name
    $retention_days = isset($options['log_retention_days']) ? absint($options['log_retention_days']) : 30;

    if ( $retention_days > 0 ) {
        if ( ! wp_next_scheduled( Bulk_Delete_Custom_Posts::LOG_CLEANUP_CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', Bulk_Delete_Custom_Posts::LOG_CLEANUP_CRON_HOOK );
            // Optionally log this event if the plugin is already active and logging CPT is available
            // For a fresh install, this might be too early.
        }
    }
}
register_activation_hook( __FILE__, 'bdcp_activate_plugin' );

/**
 * Plugin deactivation hook.
 * Clears the scheduled cron job.
 */
function bdcp_deactivate_plugin() {
    wp_clear_scheduled_hook( Bulk_Delete_Custom_Posts::LOG_CLEANUP_CRON_HOOK );
    // Optionally log this event
}
register_deactivation_hook( __FILE__, 'bdcp_deactivate_plugin' ); 
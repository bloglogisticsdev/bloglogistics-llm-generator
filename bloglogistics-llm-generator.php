<?php
/**
 * Plugin Name:     BlogLogistics LLM Generator
 * Plugin URI:      https://www.bloglogistics.com
 * Description:     Generates llms.txt and ai.txt files. llms.txt is Markdown formatted for LLM understanding. ai.txt declares content usage policies for AI. Adds a per-post/page "Exclude from LLM" checkbox. Configurable Content Settings, Update Frequency, and Cache Management.
 * Version:         1.3.1
 * Author:          Roger Wheatley
 * Author URI:      https://www.bloglogistics.com
 * License:         GPLv2 or later
 * Text Domain:     bloglogistics-llm-generator
 */

// Ensure no whitespace or characters before this line

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class BlogLogistics_LLM_Generator {
    const OPTION_NAME      = 'bloglogistics_llm_settings';
    const CRON_HOOK        = 'bloglogistics_llm_cron';
    const EXCLUDE_META_KEY = '_ble_exclude_llm';

    /** Initialize plugin hooks */
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_admin_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_post_bl_clear_caches', [ __CLASS__, 'handle_clear_caches' ] );
        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_schedules' ] );
        add_action( self::CRON_HOOK, [ __CLASS__, 'generate_files' ] );
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_exclude_meta_box' ] );
        add_action( 'save_post', [ __CLASS__, 'save_exclude_meta' ], 10, 2 ); // Priority 10, accepts 2 arguments
        register_activation_hook( __FILE__, [ __CLASS__, 'activate' ] );
        register_deactivation_hook( __FILE__, [ __CLASS__, 'deactivate' ] );
    }

    /** Activation: schedule cron and generate initial files */
    public static function activate() {
        // Ensure default settings are available if the option doesn't exist yet
        $settings = get_option( self::OPTION_NAME );
        if ( false === $settings ) {
            $settings = self::default_settings();
            update_option( self::OPTION_NAME, $settings );
        } elseif ( !isset($settings['update_frequency'])) { // Check if a crucial key is missing (e.g. from older version)
             $settings = array_merge(self::default_settings(), $settings); // Merge to add new keys
        }

        self::reschedule_cron( $settings['update_frequency'] );
        self::generate_files(); // Generate files on activation
    }

    /** Deactivation: clear scheduled cron */
    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /** Add "Exclude from LLM" meta box */
    public static function add_exclude_meta_box() {
        $settings = get_option( self::OPTION_NAME, self::default_settings() );
        // Ensure 'post_types' is an array and not empty
        $post_types_for_metabox = !empty($settings['post_types']) && is_array($settings['post_types']) ? $settings['post_types'] : ['post', 'page'];

        foreach ( $post_types_for_metabox as $post_type ) {
            add_meta_box(
                'ble_exclude_llm_metabox', // Changed ID to be more specific
                __( 'LLM & AI Content Control', 'bloglogistics-llm-generator' ),
                [ __CLASS__, 'render_exclude_meta_box' ],
                $post_type,
                'side',
                'default' // Changed priority to default, high can sometimes cause issues
            );
        }
    }

    /** Render the meta box content */
    public static function render_exclude_meta_box( $post ) {
        // Add a nonce field for security
        wp_nonce_field( 'ble_exclude_llm_action', 'ble_exclude_llm_nonce_field' );
        $value = get_post_meta( $post->ID, self::EXCLUDE_META_KEY, true );
        echo '<label for="ble_exclude_llm_checkbox">';
        echo '<input type="checkbox" id="ble_exclude_llm_checkbox" name="ble_exclude_llm" value="1" ' . checked( $value, '1', false ) . '> ';
        esc_html_e( 'Exclude this content from llms.txt and ai.txt', 'bloglogistics-llm-generator' );
        echo '</label>';
    }

    /** Save meta box data */
    public static function save_exclude_meta( $post_id, $post ) { // $post object is passed
        // Check if our nonce is set and verify it.
        if ( ! isset( $_POST['ble_exclude_llm_nonce_field'] ) || ! wp_verify_nonce( sanitize_key($_POST['ble_exclude_llm_nonce_field']), 'ble_exclude_llm_action' ) ) {
            return;
        }

        // If this is an autosave, our form has not been submitted, so we don't want to do anything.
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        // Check the user's permissions.
        $post_type = isset($_POST['post_type']) ? sanitize_key($_POST['post_type']) : $post->post_type; // Get post_type safely
        if ( 'page' == $post_type ) {
            if ( ! current_user_can( 'edit_page', $post_id ) ) {
                return;
            }
        } else {
            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                return;
            }
        }

        // Sanitize user input.
        $exclude_value = isset( $_POST['ble_exclude_llm'] ) ? '1' : '0';

        // Update the meta field in the database.
        update_post_meta( $post_id, self::EXCLUDE_META_KEY, $exclude_value );
    }

    /** Add plugin settings page to admin menu */
    public static function add_admin_menu() {
        add_menu_page(
            __( 'LLMs.txt & AI.txt Generator Settings', 'bloglogistics-llm-generator' ),
            __( 'LLMs & AI .txt', 'bloglogistics-llm-generator' ),
            'manage_options', // Capability
            'bloglogistics-llm-settings', // Menu slug
            [ __CLASS__, 'settings_page' ], // Function to display page
            'dashicons-media-text' // Icon
        );
    }

    /** Register plugin settings */
    public static function register_settings() {
        register_setting(
            'bloglogistics_llm_group', // Option group
            self::OPTION_NAME,         // Option name
            [ __CLASS__, 'sanitize_settings' ] // Sanitize callback
        );
    }

    /** Sanitize settings input */
    public static function sanitize_settings( $input ) {
        $defaults = self::default_settings();
        // Ensure $input is an array, if not, use defaults (prevents errors if form submits unexpectedly)
        $input = is_array($input) ? $input : [];
        $settings = wp_parse_args( $input, $defaults );

        // Sanitize llms.txt settings
        $settings['max_posts_per_type'] = isset($settings['max_posts_per_type']) ? absint( $settings['max_posts_per_type'] ) : $defaults['max_posts_per_type'];
        $settings['max_words']          = isset($settings['max_words']) ? absint( $settings['max_words'] ) : $defaults['max_words'];
        $settings['post_types'] = isset($settings['post_types']) && is_array($settings['post_types']) ? array_map('sanitize_text_field', $settings['post_types']) : $defaults['post_types'];

        // Sanitize ai.txt settings
        $settings['ai_txt_author'] = isset($settings['ai_txt_author']) ? sanitize_text_field( $settings['ai_txt_author'] ) : $defaults['ai_txt_author'];
        $settings['ai_txt_description'] = isset($settings['ai_txt_description']) ? sanitize_text_field( $settings['ai_txt_description'] ) : $defaults['ai_txt_description'];
        $settings['ai_txt_license'] = isset($settings['ai_txt_license']) ? sanitize_text_field( $settings['ai_txt_license'] ) : $defaults['ai_txt_license'];
        $settings['ai_txt_allow_training'] = isset( $settings['ai_txt_allow_training'] ) && $settings['ai_txt_allow_training'] === 'true' ? 'true' : 'false';
        $settings['ai_txt_contact'] = isset($settings['ai_txt_contact']) ? sanitize_email( $settings['ai_txt_contact'] ) : $defaults['ai_txt_contact'];

        // Sanitize update frequency
        $allowed_frequencies = [ 'disabled', 'immediate', 'hourly', 'twicedaily', 'daily', 'weekly' ];
        if ( !isset($settings['update_frequency']) || ! in_array( $settings['update_frequency'], $allowed_frequencies, true ) ) {
            $settings['update_frequency'] = $defaults['update_frequency'];
        }

        // Reschedule cron job if frequency changed
        // Get old frequency to compare, to avoid rescheduling if not changed
        $old_settings = get_option(self::OPTION_NAME, $defaults);
        if ($old_settings['update_frequency'] !== $settings['update_frequency']) {
            self::reschedule_cron( $settings['update_frequency'] );
        }

        return $settings;
    }

    /** Default plugin settings */
    public static function default_settings() {
        return [
            // llms.txt specific
            'post_types'         => [ 'post', 'page' ],
            'max_posts_per_type' => 100,
            'max_words'          => 250,

            // ai.txt specific
            'ai_txt_author'           => '',
            'ai_txt_description'      => get_bloginfo( 'description' ),
            'ai_txt_license'          => 'CC BY-SA 4.0',
            'ai_txt_allow_training'   => 'true',
            'ai_txt_contact'          => '',

            // General
            'update_frequency'   => 'daily',
        ];
    }

    /** Reschedule cron based on frequency */
    protected static function reschedule_cron( $frequency ) {
        // Clear any existing hook
        if ( wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_clear_scheduled_hook( self::CRON_HOOK );
        }
        // Schedule new hook if frequency is not 'disabled'
        if ( $frequency && $frequency !== 'disabled' ) {
            wp_schedule_event( time(), $frequency, self::CRON_HOOK );
        }
    }

    /** Add custom cron schedules */
    public static function add_cron_schedules( $schedules ) {
        $schedules['immediate'] = [
            'interval' => 60,
            'display'  => __( 'Every Minute (for testing)', 'bloglogistics-llm-generator' ),
        ];
        $schedules['weekly'] = [
            'interval' => WEEK_IN_SECONDS,
            'display'  => __( 'Weekly', 'bloglogistics-llm-generator' ),
        ];
        return $schedules;
    }

    /** Handle manual cache clearing and file regeneration */
    public static function handle_clear_caches() {
        // Verify nonce for security
        if ( ! isset( $_POST['bl_clear_caches_nonce_field'] ) || ! wp_verify_nonce( sanitize_key($_POST['bl_clear_caches_nonce_field']), 'bl_clear_caches_nonce_action' ) ) {
            wp_die( esc_html__( 'Nonce verification failed!', 'bloglogistics-llm-generator' ) );
        }
        // Check user capability
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'bloglogistics-llm-generator' ) );
        }

        self::generate_files();
        // Redirect back to settings page with a success message (optional)
        wp_safe_redirect( add_query_arg( 'settings-updated', 'true', wp_get_referer() ) );
        exit;
    }

    /** Generate llms.txt and ai.txt files */
    public static function generate_files() {
        $settings = get_option( self::OPTION_NAME, self::default_settings() );
        // Ensure settings are fully populated with defaults if some are missing
        $settings = array_merge(self::default_settings(), $settings);

        $root_path = untrailingslashit( ABSPATH );

        // Generate llms.txt
        $llms_txt_path = $root_path . '/llms.txt';
        $llms_content  = self::build_llms_txt_content( $settings );
        // Using WP_Filesystem for better compatibility and security if available
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once (ABSPATH . '/wp-admin/includes/file.php');
            WP_Filesystem();
        }
        if ($wp_filesystem) {
            $wp_filesystem->put_contents($llms_txt_path, $llms_content, FS_CHMOD_FILE); // FS_CHMOD_FILE for default permissions
        } else {
             @file_put_contents( $llms_txt_path, $llms_content, LOCK_EX ); // Fallback
        }


        // Generate ai.txt
        $ai_txt_path = $root_path . '/ai.txt';
        $ai_content  = self::build_ai_txt_content( $settings );
        if ($wp_filesystem) {
            $wp_filesystem->put_contents($ai_txt_path, $ai_content, FS_CHMOD_FILE);
        } else {
            @file_put_contents( $ai_txt_path, $ai_content, LOCK_EX ); // Fallback
        }
    }

    /** Build content for llms.txt */
    protected static function build_llms_txt_content( $settings ) {
        $out       = [];
        $site_name = get_bloginfo( 'name' );
        $site_desc = get_bloginfo( 'description' );

        $out[] = "# " . esc_html($site_name);
        $out[] = "> " . esc_html($site_desc);
        $out[] = "";
        $out[] = "## Content Index";
        $out[] = "";

        $selected_post_types = !empty($settings['post_types']) && is_array($settings['post_types']) ? $settings['post_types'] : [];

        foreach ( $selected_post_types as $pt ) {
            $query_args = [
                'post_type'      => $pt,
                'posts_per_page' => isset($settings['max_posts_per_type']) ? intval($settings['max_posts_per_type']) : 100,
                'post_status'    => 'publish',
                'orderby'        => 'date',
                'order'          => 'DESC',
                'meta_query'     => [
                    'relation' => 'OR',
                    [
                        'key'     => self::EXCLUDE_META_KEY,
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => self::EXCLUDE_META_KEY,
                        'value'   => '0',
                        'compare' => '=',
                    ]
                ],
                'suppress_filters' => true, // Good practice for performance in admin context queries
            ];
            $query = new WP_Query( $query_args );

            if ( $query->have_posts() ) {
                $post_type_object = get_post_type_object( $pt );
                $label = $post_type_object ? $post_type_object->labels->name : ucwords( str_replace( ['-', '_'], ' ', $pt ) );
                $out[] = "### " . esc_html( $label );
                while ( $query->have_posts() ) {
                    $query->the_post();
                    $title = get_the_title();
                    // Strip shortcodes and then trim words
                    $content_full = get_the_content();
                    $content_no_shortcodes = strip_shortcodes($content_full);
                    $content_summary = wp_trim_words( $content_no_shortcodes, isset($settings['max_words']) ? intval($settings['max_words']) : 250, '...' );
                    $out[] = "- [" . esc_html($title) . "](" . esc_url(get_permalink()) . "): " . esc_html(trim($content_summary));
                }
                $out[] = ""; // Add a blank line after each post type section
            }
            wp_reset_postdata(); // Important to reset post data
        }
        return implode( "\n", $out );
    }

    /** Build content for ai.txt */
    protected static function build_ai_txt_content( $settings ) {
        $out = [];
        $out[] = "site: " . esc_url( home_url( '/' ) );

        if ( ! empty( $settings['ai_txt_author'] ) ) {
            $out[] = "author: " . esc_html( $settings['ai_txt_author'] );
        }
        if ( ! empty( $settings['ai_txt_description'] ) ) {
            $out[] = "description: " . esc_html( $settings['ai_txt_description'] );
        }
        if ( ! empty( $settings['ai_txt_license'] ) ) {
            $out[] = "license: " . esc_html( $settings['ai_txt_license'] );
        }

        $out[] = "allow-ai-training: " . esc_html( $settings['ai_txt_allow_training'] === 'true' ? 'true' : 'false' );

        if ( ! empty( $settings['ai_txt_contact'] ) && is_email( $settings['ai_txt_contact'] ) ) {
            $out[] = "contact: " . esc_html( $settings['ai_txt_contact'] );
        }

        $out[] = ""; // Blank line for separation
        $out[] = "# AI Crawl Directives";
        $out[] = "User-agent: *";

        // Get all public post types to check for exclusions
        $all_public_post_types = get_post_types(['public' => true]);
        $excluded_paths = [];

        foreach ($all_public_post_types as $pt_slug => $pt_name) { // get_post_types returns slug => name when 'objects' is false
            if ($pt_slug === 'attachment') continue; // Skip attachments usually

            $query_args_excluded = [
                'post_type'      => $pt_slug,
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'meta_query'     => [
                    [
                        'key'     => self::EXCLUDE_META_KEY,
                        'value'   => '1',
                        'compare' => '=',
                    ]
                ],
                'fields' => 'ids', // Only fetch post IDs
                'suppress_filters' => true,
            ];
            $excluded_query = new WP_Query( $query_args_excluded );

            if ( $excluded_query->have_posts() ) {
                foreach ( $excluded_query->posts as $post_id ) {
                    $permalink = get_permalink($post_id);
                    if ($permalink) { // Ensure permalink was retrieved
                        $path = wp_make_link_relative($permalink);
                        if ($path) { // Ensure path is not empty
                             $excluded_paths[] = "Disallow: " . trailingslashit($path); // Add trailing slash for directories/clean URLs
                        }
                    }
                }
            }
        }
        wp_reset_postdata();

        if (!empty($excluded_paths)) {
            // Remove duplicates and add to output
            $unique_excluded_paths = array_unique($excluded_paths);
            foreach ($unique_excluded_paths as $path_rule) {
                $out[] = $path_rule;
            }
        } else {
            $out[] = "# No content explicitly disallowed for AI training via per-post/page settings.";
        }

        return implode( "\n", $out );
    }

    /** Render settings page HTML */
    public static function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        // Get saved settings, merge with defaults to ensure all keys exist
        $current_settings = get_option( self::OPTION_NAME, [] ); // Get empty array if not exists
        $settings = array_merge(self::default_settings(), $current_settings);


        // For jQuery UI sortable if we re-introduce it for post types
        // wp_enqueue_script( 'jquery-ui-sortable' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'BlogLogistics LLMs.txt & AI.txt Generator Settings', 'bloglogistics-llm-generator' ); ?></h1>

            <?php if ( isset( $_GET['settings-updated'] ) ) : ?>
                <div id="message" class="updated notice is-dismissible"><p><?php esc_html_e( 'Settings saved and files regenerated.', 'bloglogistics-llm-generator' ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'bloglogistics_llm_group' ); ?>

                <h2 class="nav-tab-wrapper">
                    <a href="#llms-settings" class="nav-tab nav-tab-active"><?php esc_html_e( 'LLMs.txt Settings', 'bloglogistics-llm-generator' ); ?></a>
                    <a href="#ai-settings" class="nav-tab"><?php esc_html_e( 'AI.txt Settings', 'bloglogistics-llm-generator' ); ?></a>
                    <a href="#general-settings" class="nav-tab"><?php esc_html_e( 'General Settings', 'bloglogistics-llm-generator' ); ?></a>
                </h2>

                <div id="llms-settings" class="tab-content">
                    <h3><?php esc_html_e( 'LLMs.txt Content Settings', 'bloglogistics-llm-generator' ); ?></h3>
                    <p><?php esc_html_e( 'These settings control the content generated for the llms.txt file, which is aimed at LLM understanding.', 'bloglogistics-llm-generator' ); ?></p>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label><?php esc_html_e( 'Post Types for LLMs.txt', 'bloglogistics-llm-generator' ); ?></label></th>
                            <td>
                                <fieldset>
                                    <legend class="screen-reader-text"><span><?php esc_html_e( 'Post Types for LLMs.txt', 'bloglogistics-llm-generator' ); ?></span></legend>
                                    <?php
                                    $post_types = get_post_types( [ 'public' => true ], 'objects' );
                                    $selected_post_types = isset($settings['post_types']) && is_array($settings['post_types']) ? $settings['post_types'] : [];
                                    foreach ( $post_types as $post_type_obj ) { // Renamed variable for clarity
                                        if ( $post_type_obj->name === 'attachment' ) continue;
                                        echo '<label style="margin-right: 15px; display: block;">'; // Display block for better layout
                                        echo '<input type="checkbox" name="' . esc_attr( self::OPTION_NAME ) . '[post_types][]" value="' . esc_attr( $post_type_obj->name ) . '" ' . checked( in_array( $post_type_obj->name, $selected_post_types, true ), true, false ) . '> ';
                                        echo esc_html( $post_type_obj->labels->name );
                                        echo '</label>';
                                    }
                                    ?>
                                    <p class="description"><?php esc_html_e( 'Select the post types to include in llms.txt. The "Exclude from LLM" checkbox on individual posts/pages will also apply.', 'bloglogistics-llm-generator' ); ?></p>
                                </fieldset>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="max_posts_per_type"><?php esc_html_e( 'Max Posts per Type (LLMs.txt)', 'bloglogistics-llm-generator' ); ?></label></th>
                            <td><input type="number" id="max_posts_per_type" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[max_posts_per_type]" value="<?php echo esc_attr( $settings['max_posts_per_type'] ); ?>" min="0" class="small-text"> <p class="description"><?php esc_html_e( 'Maximum number of posts/pages of each selected type to include in llms.txt (0 for all).', 'bloglogistics-llm-generator' ); ?></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="max_words"><?php esc_html_e( 'Max Words per Entry (LLMs.txt)', 'bloglogistics-llm-generator' ); ?></label></th>
                            <td><input type="number" id="max_words" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[max_words]" value="<?php echo esc_attr( $settings['max_words'] ); ?>" min="0" class="small-text"> <p class="description"><?php esc_html_e( 'Maximum words from content for each entry in llms.txt (0 for no summary, just link and title).', 'bloglogistics-llm-generator' ); ?></p></td>
                        </tr>
                    </table>
                </div>

                <div id="ai-settings" class="tab-content" style="display:none;">
                    <h3><?php esc_html_e( 'AI.txt Policy Settings', 'bloglogistics-llm-generator' ); ?></h3>
                    <p><?php esc_html_e( 'These settings control the content generated for the ai.txt file, which declares your content usage policies to AI agents.', 'bloglogistics-llm-generator' ); ?></p>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="ai_txt_author"><?php esc_html_e( 'Author (ai.txt)', 'bloglogistics-llm-generator' ); ?></label></th>
                            <td><input type="text" id="ai_txt_author" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[ai_txt_author]" value="<?php echo esc_attr( $settings['ai_txt_author'] ); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e( 'First and last name of the author or organization for ai.txt.', 'bloglogistics-llm-generator' ); ?></p></td>
                        </tr>
                         <tr>
                            <th scope="row"><label for="ai_txt_description"><?php esc_html_e( 'Site Description (ai.txt)', 'bloglogistics-llm-generator' ); ?></label></th>
                            <td><input type="text" id="ai_txt_description" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[ai_txt_description]" value="<?php echo esc_attr( $settings['ai_txt_description'] ); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e( 'A brief description of your website for ai.txt. Defaults to site tagline.', 'bloglogistics-llm-generator' ); ?></p></td>
                        </tr>
                         <tr>
                            <th scope="row"><label for="ai_txt_license"><?php esc_html_e( 'License (ai.txt)', 'bloglogistics-llm-generator' ); ?></label></th>
                            <td><input type="text" id="ai_txt_license" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[ai_txt_license]" value="<?php echo esc_attr( $settings['ai_txt_license'] ); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e( 'e.g., CC BY-SA 4.0, All Rights Reserved. Defaults to CC BY-SA 4.0.', 'bloglogistics-llm-generator' ); ?></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Allow AI Training (ai.txt)', 'bloglogistics-llm-generator' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[ai_txt_allow_training]" value="true" <?php checked( $settings['ai_txt_allow_training'], 'true' ); ?>>
                                    <?php esc_html_e( 'Allow AI models to use this site\'s content for training purposes.', 'bloglogistics-llm-generator' ); ?>
                                </label>
                                <p class="description"><?php esc_html_e( 'If unchecked, will set "allow-ai-training: false". Individual post/page exclusions will still generate "Disallow" rules for those specific URLs.', 'bloglogistics-llm-generator' ); ?></p>
                            </td>
                        </tr>
                         <tr>
                            <th scope="row"><label for="ai_txt_contact"><?php esc_html_e( 'Contact Email (ai.txt)', 'bloglogistics-llm-generator' ); ?></label></th>
                            <td><input type="email" id="ai_txt_contact" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[ai_txt_contact]" value="<?php echo esc_attr( $settings['ai_txt_contact'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e('e.g., admin@example.com', 'bloglogistics-llm-generator'); ?>">
                            <p class="description"><?php esc_html_e( 'An email address for AI-related inquiries.', 'bloglogistics-llm-generator' ); ?></p></td>
                        </tr>
                    </table>
                </div>

                <div id="general-settings" class="tab-content" style="display:none;">
                    <h3><?php esc_html_e( 'General Settings', 'bloglogistics-llm-generator' ); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="update_frequency"><?php esc_html_e( 'File Update Frequency', 'bloglogistics-llm-generator' ); ?></label></th>
                            <td>
                                <select id="update_frequency" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[update_frequency]">
                                    <option value="disabled" <?php selected( $settings['update_frequency'], 'disabled' ); ?>><?php esc_html_e( 'Disabled (Manual Only)', 'bloglogistics-llm-generator' ); ?></option>
                                    <option value="immediate" <?php selected( $settings['update_frequency'], 'immediate' ); ?>><?php esc_html_e( 'Every Minute (for testing)', 'bloglogistics-llm-generator' ); ?></option>
                                    <option value="hourly" <?php selected( $settings['update_frequency'], 'hourly' ); ?>><?php esc_html_e( 'Hourly' ); ?></option>
                                    <option value="twicedaily" <?php selected( $settings['update_frequency'], 'twicedaily' ); ?>><?php esc_html_e( 'Twice Daily' ); ?></option>
                                    <option value="daily" <?php selected( $settings['update_frequency'], 'daily' ); ?>><?php esc_html_e( 'Daily' ); ?></option>
                                    <option value="weekly" <?php selected( $settings['update_frequency'], 'weekly' ); ?>><?php esc_html_e( 'Weekly' ); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e( 'How often to automatically regenerate llms.txt and ai.txt. "Disabled" means you must regenerate manually.', 'bloglogistics-llm-generator' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                <?php submit_button( __( 'Save Settings & Regenerate Files', 'bloglogistics-llm-generator' ) ); // Changed button text for clarity ?>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:20px;">
                <input type="hidden" name="action" value="bl_clear_caches">
                <?php wp_nonce_field( 'bl_clear_caches_nonce_action', 'bl_clear_caches_nonce_field' ); // Action name for nonce should be specific ?>
                <?php submit_button( __( 'Regenerate Files Now', 'bloglogistics-llm-generator' ), 'secondary' ); ?>
            </form>

             <div style="margin-top: 30px; padding: 15px; border: 1px solid #ccd0d4; background-color: #f8f9fa; border-radius: 4px;">
                <h3><?php esc_html_e( 'File Locations & Status', 'bloglogistics-llm-generator' ); ?></h3>
                <?php
                $root_path_display = untrailingslashit( ABSPATH ); // Use a different variable for display if needed
                $llms_txt_file_path = $root_path_display . '/llms.txt'; // Renamed for clarity
                $ai_txt_file_path = $root_path_display . '/ai.txt';   // Renamed for clarity
                $llms_txt_url = home_url( '/llms.txt' );
                $ai_txt_url = home_url( '/ai.txt' );

                echo '<p>';
                if ( file_exists( $llms_txt_file_path ) ) {
                    echo '<strong>llms.txt:</strong> <a href="' . esc_url( $llms_txt_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_url( $llms_txt_url ) . '</a>';
                    echo ' <small>(Last modified: ' . esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), filemtime( $llms_txt_file_path ) ) ) . ')</small>';
                } else {
                    echo '<strong>llms.txt:</strong> ' . esc_html__( 'Not yet generated.', 'bloglogistics-llm-generator' );
                }
                echo '</p>';

                echo '<p>';
                if ( file_exists( $ai_txt_file_path ) ) {
                    echo '<strong>ai.txt:</strong> <a href="' . esc_url( $ai_txt_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_url( $ai_txt_url ) . '</a>';
                    echo ' <small>(Last modified: ' . esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), filemtime( $ai_txt_file_path ) ) ) . ')</small>';
                } else {
                    echo '<strong>ai.txt:</strong> ' . esc_html__( 'Not yet generated.', 'bloglogistics-llm-generator' );
                }
                echo '</p>';
                ?>
                <p class="description">
                    <?php esc_html_e( 'These files are generated in the root directory of your WordPress installation: ', 'bloglogistics-llm-generator' ); ?>
                    <code><?php echo esc_html($root_path_display); ?></code><br>
                    <?php esc_html_e( 'If they are not appearing or updating, check file permissions for your WordPress root directory. The web server needs to be able to write files there.', 'bloglogistics-llm-generator' ); ?>
                </p>
            </div>
        </div>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('.nav-tab-wrapper a').click(function(event) {
                    event.preventDefault();
                    $('.nav-tab-wrapper a').removeClass('nav-tab-active');
                    $(this).addClass('nav-tab-active');
                    $('.tab-content').hide();
                    var activeTab = $(this).attr('href');
                    $(activeTab).show();
                });
                 // Show the first tab by default
                $('.nav-tab-wrapper a:first').click();
            });
        </script>
        <style>
            .tab-content { margin-top: 1em; }
        </style>
        <?php
    }
}

// Initialize plugin
add_action( 'plugins_loaded', [ 'BlogLogistics_LLM_Generator', 'init' ] );

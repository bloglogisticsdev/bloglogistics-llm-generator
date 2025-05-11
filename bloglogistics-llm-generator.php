<?php
/**
 * Plugin Name:     BlogLogistics LLM Generator
 * Plugin URI:      https://www.bloglogistics.com
 * GitHub Plugin URI: bloglogisticsdev/bloglogistics-llm-generator
 * GitHub Branch: main
 * Description:     Generates llms.txt and ai.txt files with Markdown formatting. Adds a per-post/page “Exclude from LLM” checkbox for use in posts and pages. Includes configurable Content Settings, Content Options, Update Frequency, and Cache Management.
 * Version:         1.2.8
 * Author:          Roger Wheatley
 * Author URI:      https://www.bloglogistics.com
 * License:         GPLv2 or later
 * Text Domain:     bloglogistics-llm-generator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BlogLogistics_LLM_Generator {
    const OPTION_NAME      = 'bloglogistics_llm_settings';
    const CRON_HOOK        = 'bloglogistics_llm_cron';
    const EXCLUDE_META_KEY = '_ble_exclude_llm';

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_admin_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_post_bl_clear_caches', [ __CLASS__, 'handle_clear_caches' ] );
        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_schedules' ] );
        add_action( self::CRON_HOOK, [ __CLASS__, 'generate_files' ] );
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_exclude_meta_box' ] );
        add_action( 'save_post', [ __CLASS__, 'save_exclude_meta' ], 10, 2 );
        register_activation_hook( __FILE__, [ __CLASS__, 'activate' ] );
        register_deactivation_hook( __FILE__, [ __CLASS__, 'deactivate' ] );
    }

    public static function add_exclude_meta_box() {
        foreach ( [ 'post', 'page' ] as $post_type ) {
            add_meta_box(
                'ble_exclude_llm',
                __( 'Exclude from LLM', 'bloglogistics-llm-generator' ),
                [ __CLASS__, 'render_exclude_meta_box' ],
                $post_type,
                'side',
                'high'
            );
        }
    }

    public static function render_exclude_meta_box( $post ) {
        wp_nonce_field( 'ble_exclude_llm_nonce', 'ble_exclude_llm_nonce' );
        $value = get_post_meta( $post->ID, self::EXCLUDE_META_KEY, true );
        echo '<label><input type="checkbox" name="ble_exclude_llm" value="1" ' . checked( $value, '1', false ) . '> ';
        esc_html_e( 'Exclude this content from llms.txt and ai.txt', 'bloglogistics-llm-generator' );
        echo '</label>';
    }

    public static function save_exclude_meta( $post_id, $post ) {
        if ( ! isset( $_POST['ble_exclude_llm_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['ble_exclude_llm_nonce'] ), 'ble_exclude_llm_nonce' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        $exclude = isset( $_POST['ble_exclude_llm'] ) ? '1' : '0';
        update_post_meta( $post_id, self::EXCLUDE_META_KEY, $exclude );
    }

    public static function add_admin_menu() {
        add_menu_page(
            __( 'LLMs.txt  AI.txt Generator', 'bloglogistics-llm-generator' ),
            __( 'LLMs & AI txt', 'bloglogistics-llm-generator' ),
            'manage_options',
            'bloglogistics-llm-settings',
            [ __CLASS__, 'settings_page' ],
            'dashicons-media-text'
        );
    }

    public static function register_settings() {
        register_setting( 'bloglogistics_llm_group', self::OPTION_NAME, [ __CLASS__, 'sanitize_settings' ] );
    }

    public static function sanitize_settings( $input ) {
        $defaults = self::default_settings();
        $settings = wp_parse_args( $input, $defaults );
        if ( ! is_array( $settings['post_types'] ) ) {
            $settings['post_types'] = $defaults['post_types'];
        }
        $settings['max_posts_per_type'] = absint( $settings['max_posts_per_type'] );
        $settings['max_words']          = absint( $settings['max_words'] );
        $settings['include_meta']       = ! empty( $settings['include_meta'] );
        $settings['include_excerpts']   = ! empty( $settings['include_excerpts'] );
        $settings['include_taxonomies'] = ! empty( $settings['include_taxonomies'] );
        $allowed = [ 'immediate', 'daily', 'weekly' ];
        if ( ! in_array( $settings['update_frequency'], $allowed, true ) ) {
            $settings['update_frequency'] = $defaults['update_frequency'];
        }
        self::reschedule_cron( $settings['update_frequency'] );
        return $settings;
    }

    public static function default_settings() {
        return [
            'post_types'         => [ 'page', 'post', 'structured_data', 'schema_template', 'review', 'collection' ],
            'max_posts_per_type' => 100,
            'max_words'          => 250,
            'include_meta'       => false,
            'include_excerpts'   => false,
            'include_taxonomies' => false,
            'update_frequency'   => 'daily',
        ];
    }

    public static function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $settings = get_option( self::OPTION_NAME, self::default_settings() );
        wp_enqueue_script( 'jquery-ui-sortable' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'BlogLogistics LLMs.txt & AI.txt Generator Settings', 'bloglogistics-llm-generator' ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'bloglogistics_llm_group' ); ?>
                <?php do_settings_sections( 'bloglogistics_llm_group' ); ?>
                <!-- Content Settings -->
                <h2><?php esc_html_e( 'Content Settings', 'bloglogistics-llm-generator' ); ?></h2>
                <p><?php esc_html_e( 'Select and order the post types to include:', 'bloglogistics-llm-generator' ); ?></p>
                <ul id="bl_post_types_list" style="list-style:none;padding:0;">
                <?php foreach ( $settings['post_types'] as $pt ) : ?>
                    <li style="padding:8px;border:1px solid #ddd;margin:4px 0;cursor:move;">
                        <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[post_types][]" value="<?php echo esc_attr($pt); ?>" <?php checked( in_array($pt,$settings['post_types'],true) ); ?>> <?php echo esc_html( ucwords(str_replace('_',' ',$pt)) ); ?></label>
                    </li>
                <?php endforeach; ?>
                </ul>
                <script>jQuery(function($){$('#bl_post_types_list').sortable();});</script>
                <!-- Content Options -->
                <h2><?php esc_html_e( 'Content Options', 'bloglogistics-llm-generator' ); ?></h2>
                <table class="form-table">
                    <tr><th><label for="max_posts_per_type"><?php esc_html_e('Maximum posts per type','bloglogistics-llm-generator');?></label></th><td><input type="number" id="max_posts_per_type" name="<?php echo esc_attr(self::OPTION_NAME); ?>[max_posts_per_type]" value="<?php echo esc_attr($settings['max_posts_per_type']); ?>" min="1"></td></tr>
                    <tr><th><label for="max_words"><?php esc_html_e('Maximum words','bloglogistics-llm-generator');?></label></th><td><input type="number" id="max_words" name="<?php echo esc_attr(self::OPTION_NAME); ?>[max_words]" value="<?php echo esc_attr($settings['max_words']); ?>" min="1"></td></tr>
                    <tr><th><?php esc_html_e('Include meta information','bloglogistics-llm-generator');?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[include_meta]" <?php checked($settings['include_meta']); ?>> <?php esc_html_e('Publish date, author, etc.','bloglogistics-llm-generator');?></label></td></tr>
                    <tr><th><?php esc_html_e('Include post excerpts','bloglogistics-llm-generator');?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[include_excerpts]" <?php checked($settings['include_excerpts']); ?>> <?php esc_html_e('Summaries','bloglogistics-llm-generator');?></label></td></tr>
                    <tr><th><?php esc_html_e('Include taxonomies','bloglogistics-llm-generator');?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[include_taxonomies]" <?php checked($settings['include_taxonomies']); ?>> <?php esc_html_e('Categories, tags, etc.','bloglogistics-llm-generator');?></label></td></tr>
                    <tr><th><label for="update_frequency"><?php esc_html_e('Update Frequency','bloglogistics-llm-generator');?></label></th><td><select id="update_frequency" name="<?php echo esc_attr(self::OPTION_NAME); ?>[update_frequency]"><?php foreach(['immediate'=>__('Immediate'),'daily'=>__('Daily'),'weekly'=>__('Weekly')] as $val=>$lab):?><option value="<?php echo esc_attr($val);?>" <?php selected($settings['update_frequency'],$val);?>><?php echo esc_html($lab);?></option><?php endforeach;?></select></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <!-- Cache Management -->
            <h2><?php esc_html_e('Cache Management','bloglogistics-llm-generator');?></h2>
            <p><?php esc_html_e('Clear rewrite rules and regenerate llms.txt & ai.txt immediately.','bloglogistics-llm-generator');?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('bl_clear_caches_action','bl_clear_caches_nonce'); ?><input type="hidden" name="action" value="bl_clear_caches"><?php submit_button(__('Clear caches','bloglogistics-llm-generator'),'secondary'); ?>
            </form>
        </div>
        <?php
    }

    public static function handle_clear_caches() {
        if ( ! current_user_can('manage_options') || ! check_admin_referer('bl_clear_caches_action','bl_clear_caches_nonce') ) {
            wp_die('Unauthorized');
        }
        flush_rewrite_rules();
        self::generate_files();
        wp_redirect( wp_get_referer() ); exit;
    }

    public static function add_cron_schedules( $schedules ) {
        $schedules['immediate'] = [ 'interval' => 300, 'display' => 'Every 5 minutes' ];
        return $schedules;
    }

    public static function reschedule_cron( $freq ) {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        if ( $freq === 'immediate' ) {
            wp_schedule_event( time(), 'immediate', self::CRON_HOOK );
        } elseif ( $freq === 'daily' ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        } elseif ( $freq === 'weekly' ) {
            wp_schedule_event( time(), 'weekly', self::CRON_HOOK );
        }
    }

    public static function activate() {
        add_option( self::OPTION_NAME, self::default_settings() );
        self::reschedule_cron( get_option(self::OPTION_NAME)['update_frequency'] );
        self::generate_files();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    public static function generate_files() {
        $settings   = get_option( self::OPTION_NAME, self::default_settings() );
        $upload_dir = wp_upload_dir();
        $base_path  = untrailingslashit( $upload_dir['basedir'] );
        $targets    = [ ABSPATH . 'llms.txt', ABSPATH . 'ai.txt', "$base_path/llms.txt", "$base_path/ai.txt" ];
        $content    = self::build_content( $settings );
        foreach ( $targets as $file ) {
            @file_put_contents( $file, $content, LOCK_EX );
        }
    }

    protected static function build_content( $settings ) {
        $out       = [];
        $site_name = get_bloginfo('name');
        $site_desc = get_bloginfo('description');

        // Header
        $out[] = "# {$site_name}";
        if ( $site_desc ) {
            $out[] = "\n> {$site_desc}";
        }
        $out[] = "\n---\n";

        // Summaries
        foreach ( $settings['post_types'] as $pt ) {
            $label = ucwords(str_replace('_',' ',$pt));
            $out[] = "## {$label}\n";
            $query = new WP_Query([
                'post_type'      => $pt,
                'posts_per_page' => $settings['max_posts_per_type'],
                'orderby'        => 'date',
                'order'          => 'DESC',
                'post_status'    => 'publish',
            ]);
            foreach ( $query->posts as $post ) {
                if ( get_post_meta($post->ID,self::EXCLUDE_META_KEY,true)==='1' ) {
                    continue;
                }
                $title = get_the_title($post);
                $url   = get_permalink($post);
                $line  = "- [{$title}]({$url})";
                if ( $settings['include_excerpts'] ) {
                    $excerpt = wp_trim_words(
                        wp_strip_all_tags( apply_filters('the_content', $post->post_content) ),
                        $settings['max_words'],
                        '...'
                    );
                    $line .= ": {$excerpt}";
                }
                $out[] = $line;
            }
            wp_reset_postdata();
            $out[] = "\n---\n";
        }

        // Detailed section (trimmed)
        $out[] = "#\n# Detailed Content\n";
        foreach ( $settings['post_types'] as $pt ) {
            $label = ucwords(str_replace('_',' ',$pt));
            $out[] = "## {$label}\n";
            $query = new WP_Query([
                'post_type'      => $pt,
                'posts_per_page' => $settings['max_posts_per_type'],
                'orderby'        => 'date',
                'order'          => 'DESC',
                'post_status'    => 'publish',
            ]);
            foreach ( $query->posts as $post ) {
                if ( get_post_meta($post->ID,self::EXCLUDE_META_KEY,true)==='1' ) {
                    continue;
                }
                $out[] = "### " . get_the_title($post) . "\n";
                $out[] = "- Published: " . get_the_date('Y-m-d',$post);
                $out[] = "- Modified: " . get_the_modified_date('Y-m-d',$post);
                $out[] = "- URL: " . get_permalink($post) . "\n";

                $plain   = wp_strip_all_tags( apply_filters('the_content', $post->post_content) );
                $trimmed = wp_trim_words( $plain, $settings['max_words'], '...' );
                $out[]   = $trimmed;
                $out[]   = "\n---\n";
            }
            wp_reset_postdata();
        }

        return implode("\n", $out);
    }
}

BlogLogistics_LLM_Generator::init();

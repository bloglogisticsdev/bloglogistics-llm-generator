<?php
/**
 * Main plugin class for BlogLogistics LLMs.txt Generator.
 *
 * @package BlogLogistics_LLM_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class BL_LLMs_Txt_Generator {
    const OPTION_NAME      = BLOGLOGISTICS_LLMG_SETTINGS_OPTION;
    const CRON_HOOK        = 'bloglogistics_llm_cron';
    const EXCLUDE_META_KEY = '_ble_exclude_llm';
    const MENU_SLUG        = 'bloglogistics-llm-settings';
    const PARENT_SLUG      = 'bloglogistics';
    const VERSION          = BLOGLOGISTICS_LLMG_VERSION;
    const VERSION_OPTION   = BLOGLOGISTICS_LLMG_VERSION_OPTION;

    public static function init() {
        self::load_textdomain();

        add_action( 'admin_menu', [ __CLASS__, 'add_admin_menu' ], 20 );
        add_action( 'admin_post_bloglogistics_llmg_save_settings', [ __CLASS__, 'handle_save_settings' ] );
        add_action( 'admin_post_bloglogistics_llmg_regenerate', [ __CLASS__, 'handle_regenerate' ] );
        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_schedules' ] );
        add_action( self::CRON_HOOK, [ __CLASS__, 'generate_files' ] );
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_exclude_meta_box' ] );
        add_action( 'save_post', [ __CLASS__, 'save_exclude_meta' ], 10, 2 );
        add_action( 'save_post', [ __CLASS__, 'maybe_regenerate_on_post_save' ], 20, 2 );
        add_action( 'deleted_post', [ __CLASS__, 'generate_files' ] );
        add_action( 'trashed_post', [ __CLASS__, 'generate_files' ] );

    }

    private static function load_textdomain() {
        load_plugin_textdomain(
            'bloglogistics-llm-generator',
            false,
            dirname( plugin_basename( BLOGLOGISTICS_LLMG_FILE ) ) . '/languages/'
        );
    }

    public static function activate() {
        $settings = get_option( self::OPTION_NAME );
        if ( false === $settings || ! is_array( $settings ) ) {
            $settings = self::default_settings();
            update_option( self::OPTION_NAME, $settings );
        } else {
            $settings = self::normalise_settings( $settings );
            update_option( self::OPTION_NAME, $settings );
        }

        update_option( self::VERSION_OPTION, self::VERSION, false );

        self::reschedule_cron( $settings['update_frequency'] );
        self::generate_files();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    public static function add_admin_menu() {
        if ( ! self::admin_menu_slug_exists( self::PARENT_SLUG ) ) {
            add_menu_page(
                __( 'BlogLogistics', 'bloglogistics-llm-generator' ),
                __( 'BlogLogistics', 'bloglogistics-llm-generator' ),
                'manage_options',
                self::PARENT_SLUG,
                [ __CLASS__, 'settings_page' ],
                'dashicons-admin-generic',
                58
            );
        }

        add_submenu_page(
            self::PARENT_SLUG,
            __( 'LLMs.txt Generator', 'bloglogistics-llm-generator' ),
            __( 'LLMs.txt Generator', 'bloglogistics-llm-generator' ),
            'manage_options',
            self::MENU_SLUG,
            [ __CLASS__, 'settings_page' ]
        );
    }

    protected static function admin_menu_slug_exists( $slug ) {
        global $menu;
        if ( empty( $menu ) || ! is_array( $menu ) ) {
            return false;
        }
        foreach ( $menu as $item ) {
            if ( isset( $item[2] ) && $item[2] === $slug ) {
                return true;
            }
        }
        return false;
    }


    protected static function normalise_settings( $input ) {
        $defaults = self::default_settings();
        $settings = wp_parse_args( $input, $defaults );

        $settings['site_summary'] = isset( $settings['site_summary'] ) ? sanitize_textarea_field( $settings['site_summary'] ) : $defaults['site_summary'];
        $settings['post_types'] = isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ? array_values( array_map( 'sanitize_key', $settings['post_types'] ) ) : $defaults['post_types'];
        $settings['max_posts_per_type'] = isset( $settings['max_posts_per_type'] ) ? absint( $settings['max_posts_per_type'] ) : $defaults['max_posts_per_type'];
        $settings['max_words'] = isset( $settings['max_words'] ) ? absint( $settings['max_words'] ) : $defaults['max_words'];
        $settings['include_sitemap'] = ! empty( $settings['include_sitemap'] ) ? '1' : '0';
        $settings['include_feed'] = ! empty( $settings['include_feed'] ) ? '1' : '0';
        $settings['respect_noindex'] = ! empty( $settings['respect_noindex'] ) ? '1' : '0';
        $settings['delete_legacy_ai_txt'] = ! empty( $settings['delete_legacy_ai_txt'] ) ? '1' : '0';

        $allowed_frequencies = [ 'disabled', 'hourly', 'twicedaily', 'daily', 'weekly' ];
        if ( ! in_array( $settings['update_frequency'], $allowed_frequencies, true ) ) {
            $settings['update_frequency'] = $defaults['update_frequency'];
        }

        return $settings;
    }


    public static function default_settings() {
        return [
            'site_summary'         => get_bloginfo( 'description' ),
            'post_types'           => [ 'page', 'post' ],
            'max_posts_per_type'   => 20,
            'max_words'            => 40,
            'include_sitemap'      => '1',
            'include_feed'         => '1',
            'respect_noindex'      => '1',
            'delete_legacy_ai_txt' => '0',
            'update_frequency'     => 'daily',
        ];
    }

    protected static function reschedule_cron( $frequency ) {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        if ( $frequency && 'disabled' !== $frequency ) {
            wp_schedule_event( time(), $frequency, self::CRON_HOOK );
        }
    }

    public static function add_cron_schedules( $schedules ) {
        $schedules['weekly'] = [
            'interval' => WEEK_IN_SECONDS,
            'display'  => __( 'Weekly', 'bloglogistics-llm-generator' ),
        ];
        return $schedules;
    }

    public static function add_exclude_meta_box() {
        $settings = get_option( self::OPTION_NAME, self::default_settings() );
        $settings = self::normalise_settings( $settings );
        $post_types_for_metabox = ! empty( $settings['post_types'] ) ? $settings['post_types'] : [ 'post', 'page' ];

        foreach ( $post_types_for_metabox as $post_type ) {
            add_meta_box(
                'ble_exclude_llm_metabox',
                __( 'LLMs.txt Content Control', 'bloglogistics-llm-generator' ),
                [ __CLASS__, 'render_exclude_meta_box' ],
                $post_type,
                'side',
                'default'
            );
        }
    }

    public static function render_exclude_meta_box( $post ) {
        wp_nonce_field( 'ble_exclude_llm_action', 'ble_exclude_llm_nonce_field' );
        $value = get_post_meta( $post->ID, self::EXCLUDE_META_KEY, true );
        echo '<label for="ble_exclude_llm_checkbox">';
        echo '<input type="checkbox" id="ble_exclude_llm_checkbox" name="ble_exclude_llm" value="1" ' . checked( $value, '1', false ) . '> ';
        esc_html_e( 'Exclude this content from llms.txt', 'bloglogistics-llm-generator' );
        echo '</label>';
        echo '<p class="description">' . esc_html__( 'Use this for private, thin, duplicated, outdated, or otherwise unsuitable public content.', 'bloglogistics-llm-generator' ) . '</p>';
    }

    public static function save_exclude_meta( $post_id, $post ) {
        if ( ! isset( $_POST['ble_exclude_llm_nonce_field'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ble_exclude_llm_nonce_field'] ) ), 'ble_exclude_llm_action' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        $post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : $post->post_type;
        if ( 'page' === $post_type ) {
            if ( ! current_user_can( 'edit_page', $post_id ) ) {
                return;
            }
        } elseif ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $new_value = isset( $_POST['ble_exclude_llm'] ) ? '1' : '0';
        $old_value = get_post_meta( $post_id, self::EXCLUDE_META_KEY, true );

        if ( $old_value !== $new_value ) {
            update_post_meta( $post_id, self::EXCLUDE_META_KEY, $new_value );
        }
    }

    public static function maybe_regenerate_on_post_save( $post_id, $post ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        if ( ! $post || 'publish' !== $post->post_status ) {
            return;
        }

        $settings = self::normalise_settings( get_option( self::OPTION_NAME, self::default_settings() ) );
        if ( in_array( $post->post_type, $settings['post_types'], true ) ) {
            self::generate_files( $settings );
        }
    }

    public static function handle_save_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'bloglogistics-llm-generator' ) );
        }

        if ( ! isset( $_POST['bloglogistics_llmg_settings_nonce_field'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['bloglogistics_llmg_settings_nonce_field'] ) ), 'bloglogistics_llmg_settings_nonce_action' ) ) {
            wp_die( esc_html__( 'Nonce verification failed.', 'bloglogistics-llm-generator' ) );
        }

        $input = [];
        if ( isset( $_POST[ self::OPTION_NAME ] ) && is_array( $_POST[ self::OPTION_NAME ] ) ) {
            $input = wp_unslash( $_POST[ self::OPTION_NAME ] );
        }

        $settings = self::normalise_settings( $input );
        update_option( self::OPTION_NAME, $settings, false );
        update_option( self::VERSION_OPTION, self::VERSION, false );
        self::reschedule_cron( $settings['update_frequency'] );
        self::generate_files( $settings );

        wp_safe_redirect( add_query_arg( 'settings-updated', '1', self::settings_page_url() ) );
        exit;
    }

    public static function handle_regenerate() {
        if ( ! isset( $_POST['bloglogistics_llmg_regenerate_nonce_field'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['bloglogistics_llmg_regenerate_nonce_field'] ) ), 'bloglogistics_llmg_regenerate_nonce_action' ) ) {
            wp_die( esc_html__( 'Nonce verification failed.', 'bloglogistics-llm-generator' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'bloglogistics-llm-generator' ) );
        }

        self::generate_files();
        wp_safe_redirect( add_query_arg( 'bl-llms-regenerated', '1', wp_get_referer() ) );
        exit;
    }

    public static function generate_files( $settings = null ) {
        $settings = is_array( $settings ) ? $settings : get_option( self::OPTION_NAME, self::default_settings() );
        $settings = self::normalise_settings( $settings );
        $root_path = untrailingslashit( ABSPATH );
        $llms_txt_path = $root_path . '/llms.txt';
        $llms_content = self::build_llms_txt_content( $settings );

        self::write_file( $llms_txt_path, $llms_content );

        if ( '1' === $settings['delete_legacy_ai_txt'] ) {
            self::delete_legacy_ai_txt();
        }
    }

    protected static function write_file( $path, $content ) {
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if ( $wp_filesystem ) {
            return $wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE );
        }

        return false !== @file_put_contents( $path, $content, LOCK_EX );
    }

    protected static function delete_legacy_ai_txt() {
        $path = untrailingslashit( ABSPATH ) . '/ai.txt';
        if ( is_file( $path ) && is_writable( $path ) ) {
            @unlink( $path );
        }
    }

    protected static function build_llms_txt_content( $settings ) {
        $out = [];
        $site_name = self::markdown_text( get_bloginfo( 'name' ) );
        $site_desc = self::markdown_text( ! empty( $settings['site_summary'] ) ? $settings['site_summary'] : get_bloginfo( 'description' ) );

        $out[] = '# ' . $site_name;
        if ( ! empty( $site_desc ) ) {
            $out[] = '';
            $out[] = '> ' . $site_desc;
        }
        $out[] = '';
        $out[] = 'This file highlights important public pages and posts for AI assistants and AI search systems. It is a curated discovery file, not a crawler blocklist or AI training permission file.';
        $out[] = '';

        $selected_post_types = ! empty( $settings['post_types'] ) && is_array( $settings['post_types'] ) ? $settings['post_types'] : [];

        foreach ( $selected_post_types as $pt ) {
            $query_args = [
                'post_type'              => $pt,
                'posts_per_page'         => max( 1, absint( $settings['max_posts_per_type'] ) ),
                'post_status'            => 'publish',
                'orderby'                => [ 'menu_order' => 'ASC', 'date' => 'DESC' ],
                'order'                  => 'DESC',
                'ignore_sticky_posts'    => true,
                'no_found_rows'          => true,
                'update_post_meta_cache' => true,
                'update_post_term_cache' => false,
                'meta_query'             => [
                    'relation' => 'OR',
                    [
                        'key'     => self::EXCLUDE_META_KEY,
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => self::EXCLUDE_META_KEY,
                        'value'   => '0',
                        'compare' => '=',
                    ],
                ],
            ];

            $query = new WP_Query( $query_args );

            if ( $query->have_posts() ) {
                $post_type_object = get_post_type_object( $pt );
                $label = $post_type_object ? $post_type_object->labels->name : ucwords( str_replace( [ '-', '_' ], ' ', $pt ) );
                $out[] = '## ' . self::markdown_text( $label );

                while ( $query->have_posts() ) {
                    $query->the_post();
                    $post_id = get_the_ID();

                    if ( '1' === $settings['respect_noindex'] && self::is_noindex( $post_id ) ) {
                        continue;
                    }

                    $title = self::markdown_link_text( get_the_title( $post_id ) );
                    $url = esc_url_raw( get_permalink( $post_id ) );
                    $summary = self::post_summary( $post_id, absint( $settings['max_words'] ) );
                    $line = '- [' . $title . '](' . $url . ')';
                    if ( '' !== $summary ) {
                        $line .= ': ' . $summary;
                    }
                    $out[] = $line;
                }

                $out[] = '';
            }
            wp_reset_postdata();
        }

        $optional = [];
        if ( '1' === $settings['include_sitemap'] ) {
            $optional[] = '- [XML Sitemap](' . esc_url_raw( home_url( '/wp-sitemap.xml' ) ) . '): Full WordPress sitemap for broader discovery.';
        }
        if ( '1' === $settings['include_feed'] ) {
            $optional[] = '- [RSS Feed](' . esc_url_raw( get_feed_link() ) . '): Latest published posts.';
        }
        if ( ! empty( $optional ) ) {
            $out[] = '## Optional';
            $out = array_merge( $out, $optional );
            $out[] = '';
        }

        $out[] = 'Generated by BlogLogistics LLMs.txt Generator ' . self::VERSION . '.';

        return trim( implode( "\n", $out ) ) . "\n";
    }

    protected static function post_summary( $post_id, $max_words ) {
        if ( 0 === $max_words ) {
            return '';
        }

        $excerpt = has_excerpt( $post_id ) ? get_the_excerpt( $post_id ) : get_post_field( 'post_content', $post_id );
        $excerpt = strip_shortcodes( $excerpt );
        $excerpt = wp_strip_all_tags( $excerpt, true );
        $excerpt = preg_replace( '/\s+/', ' ', $excerpt );
        $excerpt = wp_trim_words( trim( $excerpt ), $max_words, '...' );

        return self::markdown_text( $excerpt );
    }

    protected static function markdown_text( $text ) {
        $text = wp_strip_all_tags( (string) $text, true );
        $text = html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) );
        $text = preg_replace( '/\s+/', ' ', $text );
        return trim( $text );
    }

    protected static function markdown_link_text( $text ) {
        $text = self::markdown_text( $text );
        $text = str_replace( [ '[', ']' ], [ '\\[', '\\]' ], $text );
        return $text;
    }

    protected static function is_noindex( $post_id ) {
        $checks = [
            '_yoast_wpseo_meta-robots-noindex' => [ '1', 'true', 'yes' ],
            'rank_math_robots'                 => [ 'noindex' ],
            '_seopress_robots_index'           => [ 'yes', '1', 'true' ],
            '_aioseo_robots_default'           => [ '0' ],
            '_aioseo_robots_noindex'           => [ '1', 'true', 'yes' ],
        ];

        foreach ( $checks as $meta_key => $noindex_values ) {
            $value = get_post_meta( $post_id, $meta_key, true );
            if ( '' === $value || null === $value ) {
                continue;
            }

            if ( is_array( $value ) ) {
                $flattened = array_map( 'strval', $value );
                foreach ( $flattened as $item ) {
                    if ( in_array( strtolower( $item ), $noindex_values, true ) ) {
                        return true;
                    }
                }
            } elseif ( in_array( strtolower( (string) $value ), $noindex_values, true ) ) {
                return true;
            }
        }

        return false;
    }

    private static function settings_page_url() {
        return admin_url( 'admin.php?page=' . self::MENU_SLUG );
    }

    public static function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = self::normalise_settings( get_option( self::OPTION_NAME, self::default_settings() ) );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'BlogLogistics LLMs.txt Generator', 'bloglogistics-llm-generator' ); ?></h1>

            <?php if ( isset( $_GET['settings-updated'] ) ) : ?>
                <div id="message" class="updated notice is-dismissible"><p><?php esc_html_e( 'Settings saved and llms.txt regenerated.', 'bloglogistics-llm-generator' ); ?></p></div>
            <?php endif; ?>

            <?php if ( isset( $_GET['bl-llms-regenerated'] ) ) : ?>
                <div id="message" class="updated notice is-dismissible"><p><?php esc_html_e( 'llms.txt regenerated.', 'bloglogistics-llm-generator' ); ?></p></div>
            <?php endif; ?>

            <div class="notice notice-info">
                <p><?php esc_html_e( 'llms.txt is a curated discovery file for AI assistants and AI search systems. It does not block crawlers, control AI training, or replace robots.txt.', 'bloglogistics-llm-generator' ); ?></p>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="bloglogistics_llmg_save_settings">
                <?php wp_nonce_field( 'bloglogistics_llmg_settings_nonce_action', 'bloglogistics_llmg_settings_nonce_field' ); ?>

                <h2 class="nav-tab-wrapper">
                    <a href="#llms-settings" class="nav-tab nav-tab-active"><?php esc_html_e( 'LLMs.txt Settings', 'bloglogistics-llm-generator' ); ?></a>
                    <a href="#guidance-settings" class="nav-tab"><?php esc_html_e( 'Robots.txt Guidance', 'bloglogistics-llm-generator' ); ?></a>
                    <a href="#status-settings" class="nav-tab"><?php esc_html_e( 'Status', 'bloglogistics-llm-generator' ); ?></a>
                </h2>

                <div id="llms-settings" class="tab-content">
                    <h2><?php esc_html_e( 'LLMs.txt Content Settings', 'bloglogistics-llm-generator' ); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="site_summary"><?php esc_html_e( 'Site Summary', 'bloglogistics-llm-generator' ); ?></label></th>
                            <td>
                                <textarea id="site_summary" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[site_summary]" rows="3" class="large-text"><?php echo esc_textarea( $settings['site_summary'] ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'A short, factual summary shown near the top of llms.txt.', 'bloglogistics-llm-generator' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Post Types', 'bloglogistics-llm-generator' ); ?></th>
                            <td>
                                <fieldset>
                                    <?php
                                    $post_types = get_post_types( [ 'public' => true ], 'objects' );
                                    foreach ( $post_types as $post_type_obj ) {
                                        if ( 'attachment' === $post_type_obj->name ) {
                                            continue;
                                        }
                                        echo '<label style="display:block;margin-bottom:6px;">';
                                        echo '<input type="checkbox" name="' . esc_attr( self::OPTION_NAME ) . '[post_types][]" value="' . esc_attr( $post_type_obj->name ) . '" ' . checked( in_array( $post_type_obj->name, $settings['post_types'], true ), true, false ) . '> ';
                                        echo esc_html( $post_type_obj->labels->name );
                                        echo '</label>';
                                    }
                                    ?>
                                    <p class="description"><?php esc_html_e( 'Select public post types to include. Individual content can still be excluded with the editor sidebar checkbox.', 'bloglogistics-llm-generator' ); ?></p>
                                </fieldset>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="max_posts_per_type"><?php esc_html_e( 'Maximum Items per Type', 'bloglogistics-llm-generator' ); ?></label></th>
                            <td><input type="number" id="max_posts_per_type" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[max_posts_per_type]" value="<?php echo esc_attr( $settings['max_posts_per_type'] ); ?>" min="1" max="500" class="small-text"> <p class="description"><?php esc_html_e( 'Keep this curated. A smaller llms.txt is usually better than a large sitemap-style dump.', 'bloglogistics-llm-generator' ); ?></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="max_words"><?php esc_html_e( 'Maximum Summary Words', 'bloglogistics-llm-generator' ); ?></label></th>
                            <td><input type="number" id="max_words" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[max_words]" value="<?php echo esc_attr( $settings['max_words'] ); ?>" min="0" max="250" class="small-text"> <p class="description"><?php esc_html_e( 'Use concise summaries. Set to 0 for links only.', 'bloglogistics-llm-generator' ); ?></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Discovery Links', 'bloglogistics-llm-generator' ); ?></th>
                            <td>
                                <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[include_sitemap]" value="1" <?php checked( $settings['include_sitemap'], '1' ); ?>> <?php esc_html_e( 'Include WordPress XML sitemap link', 'bloglogistics-llm-generator' ); ?></label>
                                <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[include_feed]" value="1" <?php checked( $settings['include_feed'], '1' ); ?>> <?php esc_html_e( 'Include RSS feed link', 'bloglogistics-llm-generator' ); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'SEO Awareness', 'bloglogistics-llm-generator' ); ?></th>
                            <td>
                                <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[respect_noindex]" value="1" <?php checked( $settings['respect_noindex'], '1' ); ?>> <?php esc_html_e( 'Exclude content marked noindex by common SEO plugins where detectable', 'bloglogistics-llm-generator' ); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="update_frequency"><?php esc_html_e( 'Update Frequency', 'bloglogistics-llm-generator' ); ?></label></th>
                            <td>
                                <select id="update_frequency" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[update_frequency]">
                                    <option value="disabled" <?php selected( $settings['update_frequency'], 'disabled' ); ?>><?php esc_html_e( 'Disabled, manual only', 'bloglogistics-llm-generator' ); ?></option>
                                    <option value="hourly" <?php selected( $settings['update_frequency'], 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'bloglogistics-llm-generator' ); ?></option>
                                    <option value="twicedaily" <?php selected( $settings['update_frequency'], 'twicedaily' ); ?>><?php esc_html_e( 'Twice Daily', 'bloglogistics-llm-generator' ); ?></option>
                                    <option value="daily" <?php selected( $settings['update_frequency'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'bloglogistics-llm-generator' ); ?></option>
                                    <option value="weekly" <?php selected( $settings['update_frequency'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'bloglogistics-llm-generator' ); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e( 'The file also regenerates after selected published content is updated, trashed, or deleted.', 'bloglogistics-llm-generator' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Legacy ai.txt', 'bloglogistics-llm-generator' ); ?></th>
                            <td>
                                <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[delete_legacy_ai_txt]" value="1" <?php checked( $settings['delete_legacy_ai_txt'], '1' ); ?>> <?php esc_html_e( 'Delete legacy ai.txt from the WordPress root during regeneration', 'bloglogistics-llm-generator' ); ?></label>
                                <p class="description"><?php esc_html_e( 'This plugin no longer generates ai.txt. Use this only if the existing ai.txt was created by the old version of this plugin.', 'bloglogistics-llm-generator' ); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( __( 'Save Settings & Regenerate llms.txt', 'bloglogistics-llm-generator' ) ); ?>
                </div>

                <div id="guidance-settings" class="tab-content" style="display:none;">
                    <h2><?php esc_html_e( 'Robots.txt Guidance', 'bloglogistics-llm-generator' ); ?></h2>
                    <p><?php esc_html_e( 'Crawler controls belong in robots.txt, server rules, or a bot-management tool, not in llms.txt or ai.txt.', 'bloglogistics-llm-generator' ); ?></p>
                    <p><?php esc_html_e( 'Common AI crawler examples include GPTBot for OpenAI training access, OAI-SearchBot for OpenAI search visibility, ChatGPT-User for user-triggered requests, and Google-Extended for Google AI-related controls. Review each provider’s current documentation before blocking or allowing crawlers.', 'bloglogistics-llm-generator' ); ?></p>
                    <h3><?php esc_html_e( 'Example: allow AI search-style discovery but discourage OpenAI training crawler access', 'bloglogistics-llm-generator' ); ?></h3>
                    <textarea readonly class="large-text code" rows="8">User-agent: GPTBot
Disallow: /

User-agent: OAI-SearchBot
Allow: /

User-agent: ChatGPT-User
Allow: /</textarea>
                    <p class="description"><?php esc_html_e( 'This is guidance only. The plugin does not edit robots.txt automatically.', 'bloglogistics-llm-generator' ); ?></p>
                </div>

                <div id="status-settings" class="tab-content" style="display:none;">
                    <?php self::render_status_panel(); ?>
                </div>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:20px;">
                <input type="hidden" name="action" value="bloglogistics_llmg_regenerate">
                <?php wp_nonce_field( 'bloglogistics_llmg_regenerate_nonce_action', 'bloglogistics_llmg_regenerate_nonce_field' ); ?>
                <?php submit_button( __( 'Regenerate llms.txt Now', 'bloglogistics-llm-generator' ), 'secondary' ); ?>
            </form>
        </div>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('.nav-tab-wrapper a').on('click', function(event) {
                    event.preventDefault();
                    $('.nav-tab-wrapper a').removeClass('nav-tab-active');
                    $(this).addClass('nav-tab-active');
                    $('.tab-content').hide();
                    $($(this).attr('href')).show();
                });
            });
        </script>
        <style>
            .tab-content { margin-top: 1em; }
            .bloglogistics-status-box { margin-top: 15px; padding: 15px; border: 1px solid #ccd0d4; background: #fff; border-radius: 4px; }
        </style>
        <?php
    }

    protected static function render_status_panel() {
        $root_path = untrailingslashit( ABSPATH );
        $llms_txt_file_path = $root_path . '/llms.txt';
        $llms_txt_url = home_url( '/llms.txt' );
        $ai_txt_file_path = $root_path . '/ai.txt';
        ?>
        <div class="bloglogistics-status-box">
            <h2><?php esc_html_e( 'File Status', 'bloglogistics-llm-generator' ); ?></h2>
            <?php if ( file_exists( $llms_txt_file_path ) ) : ?>
                <p><strong>llms.txt:</strong> <a href="<?php echo esc_url( $llms_txt_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $llms_txt_url ); ?></a></p>
                <p><small><?php echo esc_html( sprintf( __( 'Last modified: %s', 'bloglogistics-llm-generator' ), date_i18n( 'F d, Y H:i', filemtime( $llms_txt_file_path ) ) ) ); ?></small></p>
            <?php else : ?>
                <p><strong>llms.txt:</strong> <?php esc_html_e( 'Not yet generated.', 'bloglogistics-llm-generator' ); ?></p>
            <?php endif; ?>
            <p><?php esc_html_e( 'Generated file location:', 'bloglogistics-llm-generator' ); ?> <code><?php echo esc_html( $root_path . '/llms.txt' ); ?></code></p>
            <?php if ( file_exists( $ai_txt_file_path ) ) : ?>
                <p><strong>ai.txt:</strong> <?php esc_html_e( 'A legacy ai.txt file exists. This plugin no longer generates ai.txt.', 'bloglogistics-llm-generator' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }
}

<?php
/**
 * Plugin Name:       BlogLogistics LLMs.txt Generator
 * Plugin URI:        https://github.com/bloglogisticsdev/bloglogistics-llm-generator
 * Description:       Generates a curated llms.txt file for WordPress sites with per-content exclusions, SEO noindex awareness, companion-plugin awareness, manual regeneration, and scheduled updates.
 * Version:           1.4.2
 * Requires at least: 7.0
 * Requires PHP:      8.3
 * Author:            BlogLogistics
 * Author URI:        https://www.bloglogistics.com/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Update URI:        https://github.com/bloglogisticsdev/bloglogistics-llm-generator
 * Text Domain:       bloglogistics-llm-generator
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BLOGLOGISTICS_LLMG_VERSION', '1.4.2' );
define( 'BLOGLOGISTICS_LLMG_SLUG', 'bloglogistics-llm-generator' );
define( 'BLOGLOGISTICS_LLMG_FILE', __FILE__ );
define( 'BLOGLOGISTICS_LLMG_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLOGLOGISTICS_LLMG_REPO_URL', 'https://github.com/bloglogisticsdev/bloglogistics-llm-generator/' );
define( 'BLOGLOGISTICS_LLMG_UPDATE_MANIFEST_URL', 'https://updates.bloglogistics.com/plugins/bloglogistics-llm-generator.json' );

define( 'BLOGLOGISTICS_LLMG_SETTINGS_OPTION', 'bloglogistics_llm_settings' );
define( 'BLOGLOGISTICS_LLMG_VERSION_OPTION', 'bloglogistics_llm_version' );

$bloglogistics_llmg_puc = BLOGLOGISTICS_LLMG_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

if ( file_exists( $bloglogistics_llmg_puc ) ) {
    if ( ! class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class, false ) ) {
        require_once $bloglogistics_llmg_puc;
    }

    require_once BLOGLOGISTICS_LLMG_DIR . 'includes/class-bloglogistics-llm-generator-updater.php';

    if ( class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class, false ) && class_exists( 'BlogLogistics_LLM_Generator_Updater', false ) ) {
        BlogLogistics_LLM_Generator_Updater::init( [
            'repo_url'    => BLOGLOGISTICS_LLMG_UPDATE_MANIFEST_URL,
            'plugin_file' => BLOGLOGISTICS_LLMG_FILE,
            'slug'        => BLOGLOGISTICS_LLMG_SLUG,
        ] );
    }
}

require_once BLOGLOGISTICS_LLMG_DIR . 'includes/class-bloglogistics-llm-generator.php';

register_activation_hook( __FILE__, [ 'BL_LLMs_Txt_Generator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'BL_LLMs_Txt_Generator', 'deactivate' ] );

BL_LLMs_Txt_Generator::init();

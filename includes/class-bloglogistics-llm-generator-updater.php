<?php
/**
 * Manifest updater for BlogLogistics LLMs.txt Generator.
 *
 * @package BlogLogistics_LLM_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if ( ! class_exists( 'BlogLogistics_LLM_Generator_Updater', false ) ) {

    final class BlogLogistics_LLM_Generator_Updater {

        /**
         * Initialise manifest-based plugin updates.
         *
         * @param array<string, string> $args Updater arguments.
         */
        public static function init( array $args ): void {
            if (
                empty( $args['repo_url'] ) ||
                empty( $args['plugin_file'] ) ||
                empty( $args['slug'] )
            ) {
                return;
            }

            if ( ! class_exists( PucFactory::class ) ) {
                return;
            }

            PucFactory::buildUpdateChecker(
                $args['repo_url'],
                $args['plugin_file'],
                $args['slug']
            );
        }
    }
}

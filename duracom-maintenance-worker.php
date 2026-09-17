<?php
/*
Plugin Name: Duracom maintenance worker
Plugin URI: https://duracom.nl/
Description: Call maintenance hooks in duracom backoffice
Version: 2.0.1
Author: Wiebe-Jan Valkema
Author URI: https://valkemedia.nl/
Gemaakt door: WJ Valkema
License: GPLv2 or later
Text Domain: duracom
*/

const DURACOM_TOKEN = 'lacFB%@6qH42#dK#2mrnQopf!BX67&#m';

if (!defined('ABSPATH')) {
    exit;
} // Exit if accessed directly

class duracom_maintenance_worker
{
    public function __construct()
    {
        /**
         * Fires after all automatic updates have run.
         * Completes the update scheduled in background.
         *
         * @param array $results The results of all attempted updates.
         *
         * @since  3.8.0
         */
        add_action('automatic_updates_complete', function ($results) {
            $results = json_decode(json_encode($results), true);
            $this->callback(serialize($results), 'automatic');
        });

        /**
         * hook for manual updates callbacks
         */
        add_action('upgrader_process_complete', function ($upgrader_object, $options) {
//            if ( $options['action'] !== 'update' || $options['type'] !== 'plugin' ) {
//                return;
//            }

            $results = json_decode(json_encode($options), true);
            $this->callback(serialize($results), 'manual');
        }, 10, 2);
    }

    /**
     * @param string $data
     * @param string $mode
     *
     * @return mixed
     */
    private function callback($data, $mode)
    {
        return wp_remote_get(sprintf(
            'https://bo.duracom.nl/hook/domain/updated?key=%s&domain_url=%s&ver=%s&type=%s&msg=%s',
            md5(DURACOM_TOKEN),
            get_site_url(),
            wp_get_wp_version(),
            $mode,
            serialize($data)
        ), [
            'headers' => [
                'Accept-Language' => 'en-US'
            ],
            'timeout' => 30,
            'user-agent' => 'Duracom maintenance worker',
            'redirection' => 5,
            'httpversion' => '1.1',
            'sslverify' => false
        ]);
    }
}

$init = new duracom_maintenance_worker();
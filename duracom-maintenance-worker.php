<?php
/*
Plugin Name: Duracom maintenance worker
Plugin URI: https://duracom.nl/
Description: Call maintenance hooks in duracom backoffice
Version: 1.0
Author: Wiebe-Jan Valkema
Author URI: https://valkemedia.nl/
Gemaakt door: WJ Valkema
License: GPLv2 or later
Text Domain: duracom
*/

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
        add_action('automatic_updates_complete', function($results ){
            $results = json_decode(json_encode($results), true);

            $auth_key = md5('lacFB%@6qH42#dK#2mrnQopf!BX67&#m');
            $hook = 'https://bo.duracom.nl/hook/domain/updated';
            $url = sprintf($hook . '?key=%s&domain_url=%s&msg=%s', $auth_key, get_site_url(), serialize($results));
            
            $response = wp_remote_get($url, [
                'headers' => [
                    'Accept-Language' => 'en-US'
                ],
                'timeout' => 30,
                'redirection' => 5,
                'httpversion' => '1.1',
                'sslverify' => false
            ]);
        });
    }
}

$init = new duracom_maintenance_worker();
<?php

namespace DuracomMaintenanceWorker;

/*
Plugin Name: Duracom maintenance worker
Plugin URI: https://duracom.nl/
Description: Call maintenance hooks in duracom backoffice
Version: 2.0.8
Update URI: https://github.com/wimwam/duracom-maintenance-worker
Author: Wiebe-Jan Valkema
Author URI: https://valkemedia.nl/
Gemaakt door: WJ Valkema
License: GPLv2 or later
Text Domain: duracom
*/

if (!defined('ABSPATH')) {
    exit;
}

class duracom_maintenance_worker
{
    public function __construct()
    {
        require_once __DIR__ . '/includes/class-github-updater.php';
        require_once __DIR__ . '/includes/class-settings.php';

        new GitHubUpdater(
            'wimwam/duracom-maintenance-worker',
            plugin_basename(__FILE__),
            '2.0.8',
            'duracom-maintenance-worker'
        );

        new Settings();

        /**
         * Fires after all automatic updates have run.
         *
         * @param array $results The results of all attempted updates.
         */
        add_action('automatic_updates_complete', function ($results) {
            $results = json_decode(json_encode($results), true);

            $this->callback(
                serialize($results),
                'automatic'
            );
        });

        /**
         * Hook for manual updates callbacks.
         */
        add_action('upgrader_process_complete', function ($upgrader_object, $options) {
            $results = json_decode(json_encode($options), true);

            $this->callback(
                serialize($results),
                'manual'
            );
        }, 10, 2);
    }

    /**
     * Verstuur callback naar Duracom.
     *
     * @param string $data
     * @param string $mode
     *
     * @return array|\WP_Error
     */
    private function callback($data, $mode)
    {
        $token = get_option('duracom_token', '');

        /*
         * Zonder token heeft het geen zin om de callback
         * naar de backoffice te sturen.
         */
        if (empty($token)) {
            return new \WP_Error(
                'duracom_missing_token',
                'Duracom token is niet ingesteld.'
            );
        }

        return wp_remote_get(
            add_query_arg(
                [
                    'key'        => md5($token),
                    'domain_url' => get_site_url(),
                    'ver'        => wp_get_wp_version(),
                    'ver1'       => PHP_VERSION,
                    'type'       => $mode,
                    'msg'        => serialize($data),
                ],
                'https://bo.duracom.nl/hook/domain/updated'
            ),
            [
                'headers' => [
                    'Accept-Language' => 'en-US',
                ],
                'timeout' => 30,
                'user-agent' => 'Duracom maintenance worker',
                'redirection' => 5,
                'httpversion' => '1.1',

                /*
                 * Dit zou ik eigenlijk op true zetten.
                 * Zie toelichting hieronder.
                 */
                'sslverify' => true,
            ]
        );
    }
}

new duracom_maintenance_worker();
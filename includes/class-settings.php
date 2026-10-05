<?php

namespace DuracomMaintenanceWorker;

class Settings
{
    private const OPTION_NAME = 'duracom_token';

    public function __construct()
    {
        add_action(
            'admin_menu',
            [$this, 'add_settings_page']
        );

        add_action(
            'admin_init',
            [$this, 'register_settings']
        );

        add_action(
            'admin_post_duracom_test_settings',
            [$this, 'test_settings']
        );
    }

    /**
     * Voeg de instellingenpagina toe onder Instellingen.
     */
    public function add_settings_page(): void
    {
        add_options_page(
            'Duracom Maintenance Worker',
            'Duracom Maintenance Worker',
            'manage_options',
            'duracom-maintenance-worker',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Registreer instellingen.
     */
    public function register_settings(): void
    {
        register_setting(
            'duracom_maintenance_worker',
            self::OPTION_NAME,
            [
                'type'              => 'string',
                'sanitize_callback' => [$this, 'sanitize_token'],
                'default'           => '',
            ]
        );

        add_settings_section(
            'duracom_main',
            'Duracom instellingen',
            [$this, 'render_section'],
            'duracom-maintenance-worker'
        );

        add_settings_field(
            'duracom_token',
            'Duracom token',
            [$this, 'render_token_field'],
            'duracom-maintenance-worker',
            'duracom_main'
        );
    }

    /**
     * Beschrijving van de instellingen.
     */
    public function render_section(): void
    {
        echo '<p>';
        echo esc_html(
            'Stel hier het token in waarmee deze website communiceert met de Duracom backoffice.'
        );
        echo '</p>';
    }

    /**
     * Token invoerveld.
     */
    public function render_token_field(): void
    {
        $token = get_option(
            self::OPTION_NAME,
            ''
        );
        ?>

        <input
            type="password"
            name="<?php echo esc_attr(self::OPTION_NAME); ?>"
            value="<?php echo esc_attr($token); ?>"
            class="regular-text"
            autocomplete="new-password"
        />

        <?php if (!empty($token)) : ?>

        <p class="description">
            Er is momenteel een Duracom token ingesteld.
            Laat het veld ongewijzigd om het huidige token te behouden.
        </p>

    <?php else : ?>

        <p class="description">
            Vul hier het Duracom token in.
        </p>

    <?php endif; ?>

        <?php
    }

    /**
     * Sanitize het token.
     *
     * @param mixed $value
     *
     * @return string
     */
    public function sanitize_token($value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return trim($value);
    }

    /**
     * Test de verbinding met Duracom.
     */
    public function test_settings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Je hebt geen toestemming om deze actie uit te voeren.');
        }

        check_admin_referer('duracom_test_settings');

        $token = get_option('duracom_token', '');

        if (empty($token)) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'page' => 'duracom-maintenance-worker',
                        'duracom_test' => 'missing_token',
                    ],
                    admin_url('options-general.php')
                )
            );
            exit;
        }

        $response = wp_remote_get(
            add_query_arg(
                [
                    'key'        => md5($token),
                    'domain_url' => get_site_url(),
                    'ver'        => wp_get_wp_version(),
                    'ver1'       => PHP_VERSION,
                    'type'       => 'test',
                    'msg'        => 'Duracom Maintenance Worker test',
                ],
                'https://webhooks.duracom.nl/hook/domain/updated'
            ),
            [
                'headers' => [
                    'Accept-Language' => 'en-US',
                ],
                'timeout' => 30,
                'user-agent' => 'Duracom maintenance worker',
                'redirection' => 5,
                'httpversion' => '1.1',
                'sslverify' => true,
            ]
        );

        if (is_wp_error($response)) {
            $redirect_args = [
                'page' => 'duracom-maintenance-worker',
                'duracom_test' => 'error',
                'message' => $response->get_error_message(),
            ];
        } else {
            $status_code = wp_remote_retrieve_response_code($response);

            if ($status_code >= 200 && $status_code < 300) {
                $redirect_args = [
                    'page' => 'duracom-maintenance-worker',
                    'duracom_test' => 'success',
                ];
            } else {
                $redirect_args = [
                    'page' => 'duracom-maintenance-worker',
                    'duracom_test' => 'http_error',
                    'status' => $status_code,
                ];
            }
        }

        wp_safe_redirect(
            add_query_arg(
                $redirect_args,
                admin_url('options-general.php')
            )
        );

        exit;
    }

    /**
     * Render instellingenpagina.
     */
    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>

        <div class="wrap">

            <h1>
                Duracom Maintenance Worker
            </h1>

            <form method="post" action="options.php">

                <?php

                settings_fields(
                    'duracom_maintenance_worker'
                );

                do_settings_sections(
                    'duracom-maintenance-worker'
                );

                submit_button(
                    'Instellingen opslaan'
                );

                ?>

            </form>

            <?php
            if (isset($_GET['duracom_test'])) {
                $test_result = sanitize_key($_GET['duracom_test']);

                if ($test_result === 'success') {
                    echo '<div class="notice notice-success is-dismissible">';
                    echo '<p><strong>Verbinding geslaagd.</strong> De Duracom webhook heeft succesvol gereageerd.</p>';
                    echo '</div>';
                }

                if ($test_result === 'missing_token') {
                    echo '<div class="notice notice-error is-dismissible">';
                    echo '<p><strong>Test mislukt.</strong> Er is geen Duracom token ingesteld.</p>';
                    echo '</div>';
                }

                if ($test_result === 'error') {
                    $message = isset($_GET['message'])
                        ? sanitize_text_field(wp_unslash($_GET['message']))
                        : 'Onbekende fout.';

                    echo '<div class="notice notice-error is-dismissible">';
                    echo '<p><strong>Test mislukt.</strong> ' . esc_html($message) . '</p>';
                    echo '</div>';
                }

                if ($test_result === 'http_error') {
                    $status = isset($_GET['status'])
                        ? absint($_GET['status'])
                        : 0;

                    echo '<div class="notice notice-error is-dismissible">';
                    echo '<p><strong>Test mislukt.</strong> De Duracom webhook gaf HTTP-status ' . esc_html($status) . ' terug.</p>';
                    echo '</div>';
                }
            }
            ?>

            <hr>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="duracom_test_settings">

                <?php
                wp_nonce_field('duracom_test_settings');
                ?>

                <?php
                submit_button(
                    'Instellingen testen',
                    'secondary',
                    'submit',
                    false
                );
                ?>
            </form>

        </div>

        <?php
    }
}
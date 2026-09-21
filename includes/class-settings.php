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

        </div>

        <?php
    }
}
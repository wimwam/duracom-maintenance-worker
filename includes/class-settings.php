<?php

namespace DuracomMaintenanceWorker;

class Settings
{
    /**
     * Naam van de option in wp_options.
     */
    private const OPTION_NAME = 'duracom_token';

    /**
     * Constructor.
     */
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
     * Voeg de instellingenpagina toe aan het WordPress-menu.
     */
    public function add_settings_page(): void
    {
        add_options_page(
            'Duracom Maintenance worker instellingen',
            'Duracom Maintenance worker',
            'manage_options',
            'duracom-maintenance-worker-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Registreer de setting.
     */
    public function register_settings(): void
    {
        register_setting(
            'duracom_maintenance_worker_settings',
            self::OPTION_NAME,
            [
                'type'              => 'string',
                'sanitize_callback' => [$this, 'sanitize_token'],
                'default'           => '',
            ]
        );

        add_settings_section(
            'duracom_maintenance_worker_main_section',
            'Instellingen',
            [$this, 'render_section_description'],
            'duracom-maintenance-worker-settings'
        );

        add_settings_field(
            'duracom_token',
            'Duracom token',
            [$this, 'render_token_field'],
            'duracom-maintenance-worker-settings',
            'duracom_maintenance_worker_main_section'
        );
    }

    /**
     * Beschrijving boven de instellingen.
     */
    public function render_section_description(): void
    {
        echo '<p>Hier kun je de instellingen van de plugin beheren.</p>';
    }

    /**
     * Render het tokenveld.
     */
    public function render_token_field(): void
    {
        $value = get_option(
            self::OPTION_NAME,
            ''
        );
        ?>

        <input
            type="password"
            name="<?php echo esc_attr(self::OPTION_NAME); ?>"
            value="<?php echo esc_attr($value); ?>"
            class="regular-text"
            autocomplete="new-password"
        />

        <p class="description">
            Vul hier het Duracom token in.
        </p>

        <?php
    }

    /**
     * Sanitize het token voordat het wordt opgeslagen.
     */
    public function sanitize_token($value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return trim($value);
    }

    /**
     * Render de instellingenpagina.
     */
    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>

        <div class="wrap">

            <h1>
                Mijn Plugin instellingen
            </h1>

            <form method="post" action="options.php">

                <?php

                settings_fields(
                    'duracom_maintenance_worker_settings'
                );

                do_settings_sections(
                    'duracom-maintenance-worker-plugin-settings'
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

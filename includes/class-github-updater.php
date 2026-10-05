<?php

namespace DuracomMaintenanceWorker;

class GitHubUpdater
{
    /**
     * GitHub repository, bijvoorbeeld:
     * https://github.com/username/my-plugin
     */
    private string $repository;

    /**
     * Plugin basename:
     * my-plugin/my-plugin.php
     */
    private string $plugin_file;

    /**
     * Plugin slug:
     * my-plugin
     */
    private string $slug;

    /**
     * Huidige pluginversie.
     */
    private string $current_version;

    /**
     * URL naar de GitHub repository.
     */
    private string $repository_url;

    /**
     * GitHub Personal Access Token.
     *
     * Een leeg token betekent dat de repository als public wordt behandeld.
     */
    private string $token;

    /**
     * Cache duur in seconden.
     */
    private int $cache_time = 43200; // 12 uur

    /**
     * Constructor.
     *
     * @param string      $repository     GitHub repository, bijvoorbeeld username/repository
     * @param string      $plugin_file    Plugin basename
     * @param string      $current_version Huidige pluginversie
     * @param string|null $slug           Plugin slug
     */
    public function __construct(
        string $repository,
        string $plugin_file,
        string $current_version,
        ?string $slug = null
    ) {
        $this->repository = trim($repository, '/');
        $this->plugin_file = $plugin_file;
        $this->current_version = ltrim(trim($current_version), 'v');
        $this->slug = $slug ?: dirname($plugin_file);
        $this->repository_url = 'https://github.com/' . $this->repository;
        $this->token = (string) get_option('duracom_token', '');

        /*
         * WordPress moet weten dat dit geen WordPress.org-plugin is.
         */
        add_filter(
            'site_transient_update_plugins',
            [$this, 'check_for_update']
        );

        /*
         * Informatie tonen wanneer iemand op
         * "Bekijk versie X" klikt.
         */
        add_filter(
            'plugins_api',
            [$this, 'plugin_information'],
            20,
            3
        );

        /*
         * Voeg onze eigen cache-clearing mogelijkheid toe.
         */
        add_action(
            'upgrader_process_complete',
            [$this, 'clear_cache_after_update'],
            10,
            2
        );

        /*
         * Private GitHub repositories kunnen hun ZIP niet rechtstreeks
         * door WordPress laten downloaden. Onderschep daarom de download
         * en voeg de GitHub-authenticatie toe.
         */
        add_filter(
            'upgrader_pre_download',
            [$this, 'download_package'],
            10,
            4
        );
    }

    /**
     * Controleer GitHub op een nieuwe release.
     *
     * @param object $transient
     *
     * @return object
     */
    public function check_for_update($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }

        /*
         * WordPress kan dit filter aanroepen voordat checked
         * beschikbaar is.
         */
        if (empty($transient->checked)) {
            return $transient;
        }

        /*
         * Als onze plugin niet in de lijst staat,
         * hoeven we niets te doen.
         */
        if (!array_key_exists($this->plugin_file, $transient->checked)) {
            return $transient;
        }

        $release = $this->get_latest_release();

        if (!$release) {
            return $transient;
        }

        $latest_version = $release['version'];

        /*
         * Alleen een update aanbieden als de GitHub-versie
         * daadwerkelijk nieuwer is.
         */
        if (version_compare(
            $latest_version,
            $this->current_version,
            '>'
        )) {
            $update = new \stdClass();

            $update->slug = $this->slug;
            $update->plugin = $this->plugin_file;
            $update->new_version = $latest_version;
            $update->url = $release['html_url'];

            /*
             * Dit is de daadwerkelijke ZIP die WordPress
             * moet downloaden.
             */
            $update->package = $release['package'];

            /*
             * Optionele metadata.
             */
            $update->tested = $this->get_tested_version();
            $update->requires = $this->get_requires_version();
            $update->requires_php = $this->get_requires_php();

            $transient->response[$this->plugin_file] = $update;
        }

        return $transient;
    }

    /**
     * Geef plugininformatie terug voor de WordPress plugin
     * information popup.
     *
     * @param false|object $result
     * @param string       $action
     * @param object       $args
     *
     * @return false|object
     */
    public function plugin_information($result, $action, $args)
    {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (empty($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }

        $release = $this->get_latest_release();

        if (!$release) {
            return $result;
        }

        $info = new \stdClass();

        $info->name = $this->get_plugin_name();
        $info->slug = $this->slug;
        $info->version = $release['version'];
        $info->author = $this->get_plugin_author();
        $info->homepage = $this->repository_url;
        $info->download_link = $release['package'];
        $info->last_updated = $release['published_at'];

        $info->sections = [
            'description' => $this->get_plugin_description(),
            'changelog' => $this->format_changelog(
                $release['body']
            ),
        ];

        $info->banners = [
            'low'  => '',
            'high' => '',
        ];

        return $info;
    }

    /**
     * Haal de laatste GitHub release op.
     *
     * @return array|null
     */
    private function get_latest_release(): ?array
    {
        $cache_key = $this->get_cache_key();

        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/releases/latest',
            $this->repository
        );

        $response = $this->github_request(
            $url,
            [
                'timeout' => 10,
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);

        if (!$body) {
            return null;
        }

        $data = json_decode($body, true);

        if (
            !is_array($data) ||
            empty($data['tag_name'])
        ) {
            return null;
        }

        /*
         * GitHub tag:
         *
         * v1.2.3
         *
         * wordt:
         *
         * 1.2.3
         */
        $version = ltrim(
            trim($data['tag_name']),
            'v'
        );

        /*
         * Zoek de ZIP tussen de release assets.
         */
        $package = $this->find_release_asset($data);

        /*
         * Als er geen eigen ZIP-release asset is,
         * gebruik de GitHub zipball als fallback.
         */
        if (!$package && !empty($data['zipball_url'])) {
            $package = $data['zipball_url'];
        }

        if (!$package) {
            return null;
        }

        $release = [
            'version' => $version,
            'tag_name' => $data['tag_name'],
            'html_url' => $data['html_url'] ?? $this->repository_url,
            'package' => $package,
            'body' => $data['body'] ?? '',
            'published_at' => $data['published_at'] ?? '',
        ];

        /*
         * Cache de release.
         */
        set_transient(
            $cache_key,
            $release,
            $this->cache_time
        );

        return $release;
    }

    /**
     * Maak een request naar de GitHub API.
     *
     * Een token wordt alleen toegevoegd als er een token is ingesteld.
     * Hierdoor blijven publieke repositories gewoon werken.
     *
     * @param string $url
     * @param array  $args
     *
     * @return array|\WP_Error
     */
    private function github_request(string $url, array $args = [])
    {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'WordPress/' . get_bloginfo('url'),
        ];

        if (!empty($this->token)) {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }

        $args['headers'] = array_merge(
            $headers,
            $args['headers'] ?? []
        );

        return wp_remote_get($url, $args);
    }

    /**
     * Download een GitHub package.
     *
     * Voor publieke repositories kan WordPress de URL normaal downloaden.
     * Voor private repositories gebruiken we de GitHub-token.
     *
     * Bij een GitHub download-URL kan GitHub een redirect geven naar een
     * tijdelijke download-URL. De Authorization-header wordt bewust niet
     * meegestuurd naar die externe downloadhost.
     *
     * @param bool|\WP_Error $reply
     * @param string         $package
     * @param object         $upgrader
     * @param array          $hook_extra
     *
     * @return bool|\WP_Error|string
     */
    public function download_package(
        $reply,
        string $package,
        $upgrader,
        array $hook_extra
    ) {
        /*
         * Als er geen token is, is er geen reden om een public package
         * over te nemen. WordPress handelt de download zelf af.
         */
        if (empty($this->token)) {
            return $reply;
        }

        /*
         * Alleen GitHub-URLs van onze eigen repository onderscheppen.
         */
        if (!$this->is_github_package_url($package)) {
            return $reply;
        }

        $tmp_file = $this->download_github_package($package);

        if (is_wp_error($tmp_file)) {
            return $tmp_file;
        }

        return $tmp_file;
    }

    /**
     * Controleer of een package-URL bij onze repository hoort.
     *
     * @param string $url
     *
     * @return bool
     */
    private function is_github_package_url(string $url): bool
    {
        $parts = wp_parse_url($url);

        if (empty($parts['host']) || empty($parts['path'])) {
            return false;
        }

        $host = strtolower($parts['host']);
        $path = trim($parts['path'], '/');
        $repository_path = trim($this->repository, '/');

        if ($host === 'github.com') {
            return strpos($path, $repository_path . '/') === 0
                || $path === $repository_path;
        }

        if ($host === 'api.github.com') {
            return strpos($path, 'repos/' . $repository_path . '/') === 0;
        }

        return false;
    }

    /**
     * Download een private GitHub package naar een tijdelijk bestand.
     *
     * Eerst wordt de GitHub URL met Authorization aangeroepen zonder
     * redirects te volgen. Wanneer GitHub een tijdelijke download-URL
     * teruggeeft, wordt die URL daarna zonder GitHub-token gedownload.
     *
     * @param string $url
     *
     * @return string|\WP_Error
     */
    private function download_github_package(string $url)
    {
        $tmp_file = wp_tempnam($url);

        if (!$tmp_file) {
            return new \WP_Error(
                'github_download_temp',
                'Er kon geen tijdelijk bestand voor de GitHub-update worden aangemaakt.'
            );
        }

        /*
         * Gebruik eerst een authenticated request en volg redirects niet.
         * Zo lekken we de Authorization-header niet naar de uiteindelijke
         * downloadhost van GitHub.
         */
        $response = wp_remote_get(
            $url,
            [
                'timeout' => 60,
                'redirection' => 0,
                'headers' => [
                    'Accept' => 'application/octet-stream',
                    'Authorization' => 'Bearer ' . $this->token,
                    'User-Agent' => 'WordPress/' . get_bloginfo('url'),
                ],
            ]
        );

        if (is_wp_error($response)) {
            @unlink($tmp_file);
            return new \WP_Error(
                'github_download_failed',
                'GitHub package kon niet worden gedownload: ' . $response->get_error_message()
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);

        /*
         * GitHub release downloads geven normaal een 302/301 naar een
         * tijdelijke URL. Download die URL vervolgens zonder token.
         */
        if (in_array($status_code, [301, 302, 303, 307, 308], true)) {
            $location = wp_remote_retrieve_header($response, 'location');

            if (empty($location)) {
                @unlink($tmp_file);

                return new \WP_Error(
                    'github_download_redirect',
                    'GitHub gaf een redirect terug zonder download-URL.'
                );
            }

            $response = wp_remote_get(
                $location,
                [
                    'timeout' => 60,
                    'redirection' => 5,
                    'stream' => true,
                    'filename' => $tmp_file,
                ]
            );

            if (is_wp_error($response)) {
                @unlink($tmp_file);

                return new \WP_Error(
                    'github_download_failed',
                    'GitHub package kon niet worden gedownload: ' . $response->get_error_message()
                );
            }
        } else {
            /*
             * Sommige GitHub endpoints kunnen de inhoud direct teruggeven.
             * Schrijf die response naar het tijdelijke bestand.
             */
            $body = wp_remote_retrieve_body($response);

            if ($status_code !== 200 || $body === '') {
                @unlink($tmp_file);

                return new \WP_Error(
                    'github_download_failed',
                    sprintf(
                        'GitHub package kon niet worden gedownload. HTTP-status: %d',
                        $status_code
                    )
                );
            }

            if (file_put_contents($tmp_file, $body) === false) {
                @unlink($tmp_file);

                return new \WP_Error(
                    'github_download_write',
                    'GitHub package kon niet naar het tijdelijke bestand worden geschreven.'
                );
            }

            return $tmp_file;
        }

        $download_status = wp_remote_retrieve_response_code($response);

        if ($download_status !== 200) {
            @unlink($tmp_file);

            return new \WP_Error(
                'github_download_failed',
                sprintf(
                    'GitHub package kon niet worden gedownload. HTTP-status: %d',
                    $download_status
                )
            );
        }

        if (!file_exists($tmp_file) || filesize($tmp_file) === 0) {
            @unlink($tmp_file);

            return new \WP_Error(
                'github_download_empty',
                'GitHub heeft een leeg package teruggegeven.'
            );
        }

        return $tmp_file;
    }

    /**
     * Zoek een ZIP tussen de GitHub release-assets.
     *
     * Bijvoorbeeld:
     *
     * my-plugin-1.2.3.zip
     *
     * @param array $release
     *
     * @return string|null
     */
    private function find_release_asset(array $release): ?string
    {
        if (
            empty($release['assets']) ||
            !is_array($release['assets'])
        ) {
            return null;
        }

        $assets = $release['assets'];

        /*
         * Eerst zoeken naar een ZIP waarin de versie voorkomt.
         */
        foreach ($assets as $asset) {
            if (
                empty($asset['browser_download_url']) ||
                empty($asset['name'])
            ) {
                continue;
            }

            $name = strtolower($asset['name']);

            if (
                substr($name, -4) === '.zip' &&
                strpos($name, strtolower($release['tag_name'])) !== false
            ) {
                return $asset['browser_download_url'];
            }
        }

        /*
         * Anders de eerste ZIP gebruiken.
         */
        foreach ($assets as $asset) {
            if (
                empty($asset['browser_download_url']) ||
                empty($asset['name'])
            ) {
                continue;
            }

            if (substr(strtolower($asset['name']), -4) === '.zip') {
                return $asset['browser_download_url'];
            }
        }

        return null;
    }

    /**
     * Cache key.
     *
     * @return string
     */
    private function get_cache_key(): string
    {
        return 'github_updater_' . md5(
                $this->repository
            );
    }

    /**
     * Cache verwijderen nadat WordPress een update
     * heeft uitgevoerd.
     *
     * @param \WP_Upgrader $upgrader
     * @param array        $options
     */
    public function clear_cache_after_update($upgrader, $options): void
    {
        if (
            empty($options['action']) ||
            $options['action'] !== 'update'
        ) {
            return;
        }

        if (
            empty($options['type']) ||
            $options['type'] !== 'plugin'
        ) {
            return;
        }

        delete_transient(
            $this->get_cache_key()
        );
    }

    /**
     * Pluginnaam uit de plugin header.
     *
     * @return string
     */
    private function get_plugin_name(): string
    {
        $data = $this->get_plugin_data();

        return $data['Name'] ?: $this->slug;
    }

    /**
     * Auteur.
     *
     * @return string
     */
    private function get_plugin_author(): string
    {
        $data = $this->get_plugin_data();

        return $data['Author'] ?? '';
    }

    /**
     * Beschrijving.
     *
     * @return string
     */
    private function get_plugin_description(): string
    {
        $data = $this->get_plugin_data();

        return $data['Description'] ?? '';
    }

    /**
     * WordPress-versie waarvoor getest is.
     *
     * @return string
     */
    private function get_tested_version(): string
    {
        $data = $this->get_plugin_data();

        return $data['Tested WP'] ?? '';
    }

    /**
     * Minimale WordPress-versie.
     *
     * @return string
     */
    private function get_requires_version(): string
    {
        $data = $this->get_plugin_data();

        return $data['RequiresWP'] ?? '';
    }

    /**
     * Minimale PHP-versie.
     *
     * @return string
     */
    private function get_requires_php(): string
    {
        $data = $this->get_plugin_data();

        return $data['RequiresPHP'] ?? '';
    }

    /**
     * Plugin header uitlezen.
     *
     * @return array
     */
    private function get_plugin_data(): array
    {
        static $data = null;

        if ($data !== null) {
            return $data;
        }

        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_path = WP_PLUGIN_DIR . '/' . $this->plugin_file;

        if (!file_exists($plugin_path)) {
            return [];
        }

        $data = get_plugin_data(
            $plugin_path,
            false,
            false
        );

        return $data;
    }

    /**
     * GitHub Markdown changelog omzetten naar simpele HTML.
     *
     * @param string $body
     *
     * @return string
     */
    private function format_changelog(string $body): string
    {
        if (!$body) {
            return '<p>Geen changelog beschikbaar.</p>';
        }

        /*
         * wpautop zorgt in ieder geval voor nette paragrafen.
         *
         * We voeren hier bewust geen willekeurige Markdown
         * rechtstreeks als HTML uit.
         */
        return wpautop(
            esc_html($body)
        );
    }
}

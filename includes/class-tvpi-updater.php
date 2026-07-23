<?php

defined('ABSPATH') || exit;

final class TVPI_Updater
{
    const REPOSITORY = 'tinkervalley/tinker-valley-import-export';
    const ASSET_NAME = 'tinker-valley-import-export.zip';
    const AUTO_UPDATE_OPTION = 'tvpi_auto_updates';
    const RELEASE_CACHE = 'tvpi_github_release';

    private static $instance;

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_filter('update_plugins_github.com', [$this, 'check_update'], 10, 4);
        add_filter('plugins_api', [$this, 'plugin_information'], 20, 3);
        add_filter('auto_update_plugin', [$this, 'allow_auto_update'], 10, 2);
        add_filter('plugin_action_links_' . plugin_basename(TVPI_FILE), [$this, 'plugin_action_links']);
        add_action('admin_action_tvpi_toggle_auto_updates', [$this, 'toggle_auto_updates']);
        add_action('admin_action_tvpi_check_updates', [$this, 'check_updates_now']);
        add_action('delete_site_transient_update_plugins', [$this, 'clear_release_cache']);
    }

    public function allow_auto_update($update, $item)
    {
        if (!empty($item->slug) && 'tinker-valley-import-export' === $item->slug) {
            return $this->automatic_updates_enabled();
        }
        return $update;
    }

    public function plugin_action_links($links)
    {
        if (!current_user_can('update_plugins')) {
            return $links;
        }

        $enabled = $this->automatic_updates_enabled();
        $toggle_url = wp_nonce_url(
            add_query_arg(
                [
                    'action' => 'tvpi_toggle_auto_updates',
                    'enabled' => $enabled ? 0 : 1,
                ],
                admin_url('admin.php')
            ),
            'tvpi_toggle_auto_updates'
        );
        $links['tvpi_auto_updates'] = sprintf(
            '<a href="%1$s">%2$s</a>',
            esc_url($toggle_url),
            esc_html($enabled ? __('Disable automatic updates', 'tinker-valley-import-export') : __('Enable automatic updates', 'tinker-valley-import-export'))
        );

        $check_url = wp_nonce_url(
            add_query_arg('action', 'tvpi_check_updates', admin_url('admin.php')),
            'tvpi_check_updates'
        );
        $links['tvpi_check_updates'] = sprintf(
            '<a href="%1$s">%2$s</a>',
            esc_url($check_url),
            esc_html__('Check for updates', 'tinker-valley-import-export')
        );

        return $links;
    }

    public function toggle_auto_updates()
    {
        if (!current_user_can('update_plugins')) {
            wp_die(esc_html__('You do not have permission to change automatic updates.', 'tinker-valley-import-export'), 403);
        }
        check_admin_referer('tvpi_toggle_auto_updates');

        $enabled = isset($_GET['enabled']) && '1' === sanitize_text_field(wp_unslash($_GET['enabled']));
        update_site_option(self::AUTO_UPDATE_OPTION, $enabled);
        $this->sync_core_auto_updates($enabled);

        wp_safe_redirect(add_query_arg('tvpi-auto-updates', $enabled ? 'enabled' : 'disabled', admin_url('plugins.php')));
        exit;
    }

    public function check_updates_now()
    {
        if (!current_user_can('update_plugins')) {
            wp_die(esc_html__('You do not have permission to check for updates.', 'tinker-valley-import-export'), 403);
        }
        check_admin_referer('tvpi_check_updates');

        $this->clear_release_cache();
        delete_site_transient('update_plugins');
        wp_update_plugins();
        wp_safe_redirect(admin_url('plugins.php'));
        exit;
    }

    public function clear_release_cache()
    {
        delete_site_transient(self::RELEASE_CACHE);
    }

    public function check_update($update, $plugin_data, $plugin_file, $locales)
    {
        if (empty($plugin_data['UpdateURI']) || 'https://github.com/' . self::REPOSITORY !== untrailingslashit($plugin_data['UpdateURI'])) {
            return $update;
        }

        $release = $this->get_release();
        if (!$release || empty($release['version']) || version_compare($release['version'], TVPI_VERSION, '<=')) {
            return false;
        }

        return [
            'id' => 'https://github.com/' . self::REPOSITORY,
            'slug' => 'tinker-valley-import-export',
            'plugin' => $plugin_file,
            'version' => $release['version'],
            'url' => 'https://github.com/' . self::REPOSITORY,
            'package' => $release['package'],
            'requires_php' => '7.4',
            'requires' => '6.4',
            'tested' => get_bloginfo('version'),
        ];
    }

    public function plugin_information($result, $action, $args)
    {
        if ('plugin_information' !== $action || empty($args->slug) || 'tinker-valley-import-export' !== $args->slug) {
            return $result;
        }

        $release = $this->get_release();
        if (!$release) {
            return $result;
        }

        return (object) [
            'name' => 'Tinker Valley Import & Export',
            'slug' => 'tinker-valley-import-export',
            'version' => $release['version'],
            'author' => '<a href="https://tinkervalley.ca">Tinker Valley</a>',
            'homepage' => 'https://github.com/' . self::REPOSITORY,
            'requires' => '6.4',
            'requires_php' => '7.4',
            'download_link' => $release['package'],
            'last_updated' => $release['published_at'],
            'sections' => [
                'description' => 'Import and export WordPress posts, ACF fields, taxonomy terms, and media using mapped JSON files.',
                'changelog' => wp_kses_post(nl2br(esc_html($release['notes']))),
            ],
        ];
    }

    private function automatic_updates_enabled()
    {
        return (bool) get_site_option(self::AUTO_UPDATE_OPTION, false);
    }

    private function sync_core_auto_updates($enabled)
    {
        $plugin = plugin_basename(TVPI_FILE);
        $plugins = (array) get_site_option('auto_update_plugins', []);
        $plugins = array_values(array_diff($plugins, [$plugin]));
        if ($enabled) {
            $plugins[] = $plugin;
        }
        update_site_option('auto_update_plugins', array_values(array_unique($plugins)));
    }

    private function get_release()
    {
        $cached = get_site_transient(self::RELEASE_CACHE);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest',
            [
                'timeout' => 10,
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'Tinker-Valley-Import-Export/' . TVPI_VERSION,
                ],
            ]
        );

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            set_site_transient(self::RELEASE_CACHE, [], 2 * MINUTE_IN_SECONDS);
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['tag_name']) || !empty($data['draft']) || !empty($data['prerelease'])) {
            return false;
        }

        $package = '';
        foreach ((array) ($data['assets'] ?? []) as $asset) {
            if (self::ASSET_NAME === ($asset['name'] ?? '')) {
                $package = esc_url_raw($asset['browser_download_url']);
                break;
            }
        }
        if (!$package) {
            return false;
        }

        $release = [
            'version' => ltrim(sanitize_text_field($data['tag_name']), 'v'),
            'package' => $package,
            'notes' => (string) ($data['body'] ?? ''),
            'published_at' => sanitize_text_field($data['published_at'] ?? ''),
        ];
        set_site_transient(self::RELEASE_CACHE, $release, 10 * MINUTE_IN_SECONDS);
        return $release;
    }
}

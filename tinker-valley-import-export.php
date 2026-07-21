<?php
/**
 * Plugin Name: Tinker Valley Import & Export
 * Description: Imports and exports mapped post JSON files with ACF field mapping and background media sideloading.
 * Version: 0.2.1
 * Author: Tinker Valley
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Tinker_Valley_Import_Export
{
    const JOBS_OPTION = 'tinker_valley_post_import_jobs';
    const CRON_HOOK = 'tinker_valley_post_import_process_batch';
    const NONCE_ACTION = 'tinker_valley_post_importer';
    const OLD_ID_META = '_tvpi_old_post_id';
    const SOURCE_URL_META = '_tvpi_source_url';

    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'register_admin_page']);
        add_action('admin_post_tvpi_upload', [__CLASS__, 'handle_upload']);
        add_action('admin_post_tvpi_export', [__CLASS__, 'handle_export']);
        add_action('admin_post_tvpi_process_now', [__CLASS__, 'handle_process_now']);
        add_action('admin_post_tvpi_cancel', [__CLASS__, 'handle_cancel']);
        add_action(self::CRON_HOOK, [__CLASS__, 'process_batch']);
    }

    public static function activate()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 30, self::CRON_HOOK);
        }
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function register_admin_page()
    {
        add_management_page(
            'Tinker Valley Import & Export',
            'Tinker Valley Import & Export',
            'manage_options',
            'tinker-valley-import-export',
            [__CLASS__, 'render_admin_page']
        );
    }

    public static function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $jobs = self::get_jobs();
        $notice = isset($_GET['tvpi_notice']) ? sanitize_text_field(wp_unslash($_GET['tvpi_notice'])) : '';
        ?>
        <div class="wrap">
            <h1>Tinker Valley Import & Export</h1>

            <?php if ($notice) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <p>Upload a mapped post migration JSON file. Imports run in small background batches to avoid request timeouts.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="action" value="tvpi_upload">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="tvpi_json_file">JSON file</label></th>
                        <td><input type="file" id="tvpi_json_file" name="tvpi_json_file" accept="application/json,.json" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="target_post_type">Target post type</label></th>
                        <td><input type="text" id="target_post_type" name="target_post_type" value="project" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="batch_size">Batch size</label></th>
                        <td><input type="number" id="batch_size" name="batch_size" value="5" min="1" max="25" class="small-text"> records per batch</td>
                    </tr>
                    <tr>
                        <th scope="row">Import mode</th>
                        <td>
                            <label>
                                <input type="checkbox" name="update_existing" value="1" checked>
                                Update existing posts matched by old source ID
                            </label>
                            <br>
                            <label>
                                <input type="checkbox" name="reuse_media_by_filename" value="1" checked>
                                Reuse existing Media Library files with the same filename before downloading
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Upload and Queue Import'); ?>
            </form>

            <hr>

            <h2>Export Posts</h2>
            <p>Export a selected post type into the same JSON shape used by this importer.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="action" value="tvpi_export">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="export_post_type">Post type</label></th>
                        <td>
                            <select id="export_post_type" name="export_post_type">
                                <?php foreach (self::exportable_post_types() as $post_type => $label) : ?>
                                    <option value="<?php echo esc_attr($post_type); ?>"><?php echo esc_html($label . ' (' . $post_type . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Post status</th>
                        <td>
                            <label><input type="checkbox" name="export_status[]" value="publish" checked> Published</label><br>
                            <label><input type="checkbox" name="export_status[]" value="draft"> Drafts</label><br>
                            <label><input type="checkbox" name="export_status[]" value="private"> Private</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">ACF/meta fields</th>
                        <td>
                            <label>
                                <input type="checkbox" name="export_all_meta" value="1">
                                Export all public custom meta instead of actual ACF fields only
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Download JSON Export', 'secondary'); ?>
            </form>

            <hr>

            <h2>Import Jobs</h2>
            <?php self::render_jobs_table($jobs); ?>

            <hr>

            <h2>Sample JSON Format</h2>
            <p>This is a shortened example of the expected structure.</p>
            <textarea readonly style="width:100%;min-height:420px;font-family:monospace;"><?php echo esc_textarea(wp_json_encode(self::sample_json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></textarea>
        </div>
        <?php
    }

    private static function render_jobs_table($jobs)
    {
        if (!$jobs) {
            echo '<p>No import jobs yet.</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Job</th><th>Status</th><th>Progress</th><th>Created</th><th>Last Message</th><th>Actions</th></tr></thead><tbody>';
        foreach (array_reverse($jobs) as $job_id => $job) {
            $total = count($job['records']);
            $done = (int) $job['offset'];
            $status = $job['status'];
            $message = $job['messages'] ? end($job['messages']) : '';
            echo '<tr>';
            echo '<td><code>' . esc_html($job_id) . '</code></td>';
            echo '<td>' . esc_html($status) . '</td>';
            echo '<td>' . esc_html($done . ' / ' . $total) . '</td>';
            echo '<td>' . esc_html($job['created_at']) . '</td>';
            echo '<td>' . esc_html($message);
            if (!empty($job['log'])) {
                $log = array_slice(array_reverse($job['log']), 0, 12);
                echo '<details style="margin-top:8px;"><summary>View post log</summary><ol style="margin:8px 0 0 20px;">';
                foreach ($log as $entry) {
                    $line = sprintf(
                        '%s: %s (#%s)',
                        $entry['action'] ?? 'Imported',
                        $entry['title'] ?? 'Untitled',
                        $entry['post_id'] ?? ''
                    );
                    echo '<li>' . esc_html($line) . '</li>';
                }
                echo '</ol></details>';
            }
            if (!empty($job['errors'])) {
                $errors = array_slice(array_reverse($job['errors']), 0, 8);
                echo '<details style="margin-top:8px;color:#b32d2e;"><summary>View errors</summary><ol style="margin:8px 0 0 20px;">';
                foreach ($errors as $error) {
                    $line = sprintf(
                        '%s: %s',
                        $error['record'] ?? 'Unknown record',
                        $error['message'] ?? 'Unknown error'
                    );
                    echo '<li>' . esc_html($line) . '</li>';
                }
                echo '</ol></details>';
            }
            echo '</td>';
            echo '<td>';
            if (in_array($status, ['queued', 'running'], true)) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px;">';
                wp_nonce_field(self::NONCE_ACTION);
                echo '<input type="hidden" name="action" value="tvpi_process_now">';
                echo '<input type="hidden" name="job_id" value="' . esc_attr($job_id) . '">';
                submit_button('Process Next Batch', 'secondary small', 'submit', false);
                echo '</form>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;">';
                wp_nonce_field(self::NONCE_ACTION);
                echo '<input type="hidden" name="action" value="tvpi_cancel">';
                echo '<input type="hidden" name="job_id" value="' . esc_attr($job_id) . '">';
                submit_button('Cancel', 'delete small', 'submit', false);
                echo '</form>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function exportable_post_types()
    {
        $post_types = get_post_types([
            'show_ui' => true,
        ], 'objects');

        $excluded = [
            'attachment',
            'acf-field',
            'acf-field-group',
            'wp_block',
            'wp_navigation',
            'wp_template',
            'wp_template_part',
            'wp_global_styles',
            'custom_css',
            'customize_changeset',
            'oembed_cache',
            'revision',
            'nav_menu_item',
        ];

        $out = [];
        foreach ($post_types as $post_type => $object) {
            if (in_array($post_type, $excluded, true)) {
                continue;
            }
            $out[$post_type] = $object->labels->singular_name ?: $object->label ?: $post_type;
        }

        if (!$out) {
            $out = [
                'post' => 'Post',
                'page' => 'Page',
            ];
        }

        natcasesort($out);
        return $out;
    }

    public static function handle_upload()
    {
        self::verify_request();

        if (empty($_FILES['tvpi_json_file']['tmp_name'])) {
            self::redirect('No JSON file was uploaded.');
        }

        $raw = file_get_contents($_FILES['tvpi_json_file']['tmp_name']);
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            self::redirect('Uploaded file is not valid JSON.');
        }

        $records = self::extract_records($payload);
        if (!$records) {
            self::redirect('No importable records were found in the JSON.');
        }

        $target_post_type = sanitize_key($_POST['target_post_type'] ?? 'project');
        $batch_size = max(1, min(25, absint($_POST['batch_size'] ?? 5)));
        $job_id = 'job_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false, false);
        $jobs = self::get_jobs();
        $jobs[$job_id] = [
            'status' => 'queued',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
            'offset' => 0,
            'batch_size' => $batch_size,
            'target_post_type' => $target_post_type ?: 'project',
            'update_existing' => !empty($_POST['update_existing']),
            'reuse_media_by_filename' => !empty($_POST['reuse_media_by_filename']),
            'records' => array_values($records),
            'excluded' => $payload['excluded'] ?? [],
            'messages' => ['Queued ' . count($records) . ' records.'],
            'log' => [],
            'errors' => [],
        ];
        self::save_jobs($jobs);
        self::schedule_processing();

        self::redirect('Import queued.');
    }

    public static function handle_export()
    {
        self::verify_request();

        $post_type = sanitize_key($_POST['export_post_type'] ?? 'post');
        if (!post_type_exists($post_type)) {
            wp_die('Invalid post type.');
        }

        $statuses = isset($_POST['export_status']) && is_array($_POST['export_status'])
            ? array_map('sanitize_key', wp_unslash($_POST['export_status']))
            : ['publish'];
        $statuses = array_values(array_intersect($statuses, ['publish', 'draft', 'pending', 'private', 'future']));
        if (!$statuses) {
            $statuses = ['publish'];
        }

        $export_all_meta = !empty($_POST['export_all_meta']);
        $payload = self::build_export_payload($post_type, $statuses, $export_all_meta);
        $filename = sprintf('tv-post-export-%s-%s.json', $post_type, gmdate('Y-m-d-His'));

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function handle_process_now()
    {
        self::verify_request();
        $job_id = sanitize_text_field(wp_unslash($_POST['job_id'] ?? ''));
        self::process_batch($job_id);
        self::redirect('Processed next batch.');
    }

    public static function handle_cancel()
    {
        self::verify_request();
        $job_id = sanitize_text_field(wp_unslash($_POST['job_id'] ?? ''));
        $jobs = self::get_jobs();
        if (isset($jobs[$job_id])) {
            $jobs[$job_id]['status'] = 'cancelled';
            $jobs[$job_id]['updated_at'] = current_time('mysql');
            $jobs[$job_id]['messages'][] = 'Cancelled by user.';
            self::save_jobs($jobs);
        }
        self::redirect('Import cancelled.');
    }

    public static function process_batch($job_id = '')
    {
        $jobs = self::get_jobs();
        if (!$job_id) {
            foreach ($jobs as $id => $job) {
                if (in_array($job['status'], ['queued', 'running'], true)) {
                    $job_id = $id;
                    break;
                }
            }
        }

        if (!$job_id || empty($jobs[$job_id])) {
            return;
        }

        $job = $jobs[$job_id];
        if (!in_array($job['status'], ['queued', 'running'], true)) {
            return;
        }

        $job['status'] = 'running';
        $records = $job['records'];
        $start = (int) $job['offset'];
        $batch_size = (int) $job['batch_size'];
        $batch = array_slice($records, $start, $batch_size);
        $imported = 0;
        $created = 0;
        $updated = 0;

        foreach ($batch as $record) {
            $result = self::import_record($record, $job);
            if (is_wp_error($result)) {
                $job['errors'][] = [
                    'record' => $record['title'] ?? $record['old_id'] ?? 'Unknown record',
                    'message' => $result->get_error_message(),
                ];
            } else {
                $job['log'][] = $result;
                if (($result['action'] ?? '') === 'Updated') {
                    $updated++;
                } else {
                    $created++;
                }
                $imported++;
            }
            $job['offset']++;
        }

        $total = count($records);
        $job['updated_at'] = current_time('mysql');
        $job['messages'][] = sprintf(
            'Imported %d record(s): %d created, %d updated. Progress: %d / %d.',
            $imported,
            $created,
            $updated,
            min($job['offset'], $total),
            $total
        );

        if ($job['offset'] >= $total) {
            $job['status'] = 'complete';
            $job['messages'][] = 'Import complete.';
        }

        $jobs[$job_id] = $job;
        self::save_jobs($jobs);

        if ($job['status'] !== 'complete') {
            self::schedule_processing();
        }
    }

    private static function import_record($record, $job)
    {
        $old_id = isset($record['old_id']) ? (string) $record['old_id'] : '';
        $post_id = 0;

        if ($old_id && !empty($job['update_existing'])) {
            $existing = get_posts([
                'post_type' => $job['target_post_type'],
                'post_status' => 'any',
                'meta_key' => self::OLD_ID_META,
                'meta_value' => $old_id,
                'fields' => 'ids',
                'posts_per_page' => 1,
            ]);
            $post_id = $existing ? (int) $existing[0] : 0;
        }
        $was_update = $post_id > 0;

        $postarr = [
            'post_type' => $job['target_post_type'],
            'post_title' => wp_strip_all_tags($record['title'] ?? ''),
            'post_name' => sanitize_title($record['slug'] ?? $record['title'] ?? ''),
            'post_status' => self::valid_status($record['status'] ?? 'publish'),
            'post_content' => $record['content'] ?? '',
            'post_date' => !empty($record['post_date']) ? $record['post_date'] : current_time('mysql'),
        ];

        if ($post_id) {
            $postarr['ID'] = $post_id;
            $post_id = wp_update_post(wp_slash($postarr), true);
        } else {
            $post_id = wp_insert_post(wp_slash($postarr), true);
        }

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        if ($old_id) {
            update_post_meta($post_id, self::OLD_ID_META, $old_id);
        }

        self::assign_categories($post_id, $record['categories'] ?? [], $job['target_post_type']);
        self::import_media_fields($post_id, $record, $job);
        self::import_acf_fields($post_id, $record['acf'] ?? [], $job);

        return [
            'action' => $was_update ? 'Updated' : 'Created',
            'post_id' => (int) $post_id,
            'old_id' => $old_id,
            'title' => get_the_title($post_id),
            'slug' => get_post_field('post_name', $post_id),
            'time' => current_time('mysql'),
        ];
    }

    private static function build_export_payload($post_type, $statuses, $export_all_meta)
    {
        $posts = get_posts([
            'post_type' => $post_type,
            'post_status' => $statuses,
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'ASC',
        ]);

        $records = [];
        foreach ($posts as $post) {
            $records[] = self::export_post_record($post, $export_all_meta);
        }

        return [
            'source_site' => home_url('/'),
            'target_post_type' => $post_type,
            'field_mapping_version' => gmdate('Y-m-d'),
            'exported_at' => current_time('mysql'),
            'counts' => [
                'records' => count($records),
            ],
            'records' => $records,
            'excluded' => [],
        ];
    }

    private static function export_post_record($post, $export_all_meta)
    {
        $post_id = (int) $post->ID;
        $old_id = get_post_meta($post_id, self::OLD_ID_META, true);
        $featured_image_id = get_post_thumbnail_id($post_id);

        return [
            'old_id' => $old_id !== '' ? $old_id : $post_id,
            'title' => get_the_title($post_id),
            'slug' => $post->post_name,
            'status' => $post->post_status,
            'post_date' => $post->post_date,
            'categories' => self::export_post_terms($post_id, $post->post_type),
            'content' => $post->post_content,
            'content_text' => wp_strip_all_tags($post->post_content),
            'featured_image_url' => $featured_image_id ? wp_get_attachment_url($featured_image_id) : '',
            'acf' => $export_all_meta ? self::export_public_meta($post_id) : self::export_actual_acf($post_id),
        ];
    }

    private static function export_actual_acf($post_id)
    {
        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return self::export_public_meta($post_id);
        }

        $field_groups = acf_get_field_groups(['post_id' => $post_id]);
        $out = [];

        foreach ($field_groups as $field_group) {
            $fields = acf_get_fields($field_group);
            if (!$fields) {
                continue;
            }

            foreach ($fields as $field) {
                if (empty($field['name'])) {
                    continue;
                }
                $out[$field['name']] = self::export_acf_field_value($field, $post_id);
            }
        }

        return $out;
    }

    private static function export_acf_field_value($field, $post_id, $raw_value = null, $is_top_level = true)
    {
        $value = $raw_value;
        if ($value === null && $is_top_level && function_exists('get_field')) {
            $value = get_field($field['name'], $post_id, false);
        }

        $type = $field['type'] ?? '';

        if ($type === 'group') {
            $group = [];
            foreach (($field['sub_fields'] ?? []) as $sub_field) {
                if (empty($sub_field['name'])) {
                    continue;
                }
                $sub_value = is_array($value) && array_key_exists($sub_field['name'], $value)
                    ? $value[$sub_field['name']]
                    : get_post_meta($post_id, $field['name'] . '_' . $sub_field['name'], true);
                $group[$sub_field['name']] = self::export_acf_field_value($sub_field, $post_id, $sub_value, false);
            }
            return $group;
        }

        if ($type === 'repeater') {
            if (!is_array($value)) {
                return [];
            }
            $rows = [];
            foreach ($value as $row) {
                $exported_row = [];
                foreach (($field['sub_fields'] ?? []) as $sub_field) {
                    if (empty($sub_field['name'])) {
                        continue;
                    }
                    $sub_value = is_array($row) && array_key_exists($sub_field['name'], $row) ? $row[$sub_field['name']] : null;
                    $exported_row[$sub_field['name']] = self::export_acf_field_value($sub_field, $post_id, $sub_value, false);
                }
                $rows[] = $exported_row;
            }
            return $rows;
        }

        if (in_array($type, ['image', 'file'], true)) {
            return self::media_value_to_url($value);
        }

        if ($type === 'gallery') {
            $urls = [];
            foreach ((array) $value as $item) {
                $url = self::media_value_to_url($item);
                if ($url) {
                    $urls[] = $url;
                }
            }
            return $urls;
        }

        if (in_array($type, ['checkbox', 'select', 'relationship', 'post_object', 'taxonomy'], true) && is_array($value)) {
            return array_values(self::normalize_export_value($value));
        }

        return self::normalize_export_value($value);
    }

    private static function export_public_meta($post_id)
    {
        $meta = get_post_meta($post_id);
        $out = [];
        foreach ($meta as $key => $values) {
            if (strpos($key, '_') === 0) {
                continue;
            }
            $value = count($values) === 1 ? maybe_unserialize($values[0]) : array_map('maybe_unserialize', $values);
            $out[$key] = self::normalize_export_value($value);
        }
        return $out;
    }

    private static function export_post_terms($post_id, $post_type)
    {
        $taxonomies = get_object_taxonomies($post_type, 'names');
        $out = [];
        foreach ($taxonomies as $taxonomy) {
            if (!is_taxonomy_hierarchical($taxonomy)) {
                continue;
            }
            $terms = get_the_terms($post_id, $taxonomy);
            if (!$terms || is_wp_error($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                $out[] = [
                    'taxonomy' => $taxonomy,
                    'slug' => $term->slug,
                    'name' => $term->name,
                ];
            }
        }
        return $out;
    }

    private static function media_value_to_url($value)
    {
        if (!$value) {
            return '';
        }
        if (is_numeric($value)) {
            return wp_get_attachment_url((int) $value) ?: '';
        }
        if (is_array($value)) {
            if (!empty($value['url'])) {
                return $value['url'];
            }
            if (!empty($value['ID'])) {
                return wp_get_attachment_url((int) $value['ID']) ?: '';
            }
            if (!empty($value['id'])) {
                return wp_get_attachment_url((int) $value['id']) ?: '';
            }
        }
        return is_string($value) ? $value : '';
    }

    private static function normalize_export_value($value)
    {
        if (is_array($value)) {
            return array_map([__CLASS__, 'normalize_export_value'], $value);
        }
        if (is_numeric($value)) {
            return strpos((string) $value, '.') !== false ? (float) $value : (int) $value;
        }
        return $value === false || $value === null ? '' : $value;
    }

    private static function import_media_fields($post_id, $record, $job)
    {
        if (!empty($record['featured_image_url'])) {
            $attachment_id = self::sideload_media($record['featured_image_url'], $post_id, $job);
            if ($attachment_id) {
                set_post_thumbnail($post_id, $attachment_id);
            }
        }
    }

    private static function import_acf_fields($post_id, $acf, $job)
    {
        if (!$acf || !is_array($acf)) {
            return;
        }

        $acf = self::prepare_acf_media($acf, $post_id, $job);

        foreach ($acf as $field_name => $value) {
            self::update_field_value($field_name, $value, $post_id);
        }
    }

    private static function prepare_acf_media($acf, $post_id, $job)
    {
        $field_definitions = self::get_acf_field_definitions($post_id);

        foreach ($acf as $field_name => $value) {
            $field = $field_definitions[$field_name] ?? null;

            // Keep support for the original project-specific fields when ACF is
            // unavailable or a field group is not active for the target post.
            if (!$field) {
                if (in_array($field_name, ['floorplan_pdf', 'floorplan_image', 'video'], true)) {
                    $field = ['type' => 'file'];
                } elseif ($field_name === 'project_gallery') {
                    $field = ['type' => 'gallery'];
                }
            }

            if ($field) {
                $acf[$field_name] = self::prepare_acf_field_media($value, $field, $post_id, $job);
            }
        }

        return $acf;
    }

    private static function get_acf_field_definitions($post_id)
    {
        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return [];
        }

        $definitions = [];
        foreach ((array) acf_get_field_groups(['post_id' => $post_id]) as $field_group) {
            foreach ((array) acf_get_fields($field_group) as $field) {
                if (!empty($field['name'])) {
                    $definitions[$field['name']] = $field;
                }
            }
        }

        return $definitions;
    }

    private static function prepare_acf_field_media($value, $field, $post_id, $job)
    {
        $type = $field['type'] ?? '';

        if (in_array($type, ['image', 'file'], true)) {
            if (is_string($value) && self::is_remote_media_url($value)) {
                $attachment_id = self::sideload_media($value, $post_id, $job);
                return $attachment_id ?: $value;
            }
            return $value;
        }

        if ($type === 'gallery' && is_array($value)) {
            $prepared = [];
            foreach ($value as $item) {
                if (is_string($item) && self::is_remote_media_url($item)) {
                    $attachment_id = self::sideload_media($item, $post_id, $job);
                    $prepared[] = $attachment_id ?: $item;
                } else {
                    $prepared[] = $item;
                }
            }
            return $prepared;
        }

        if ($type === 'group' && is_array($value)) {
            return self::prepare_acf_sub_fields($value, $field['sub_fields'] ?? [], $post_id, $job);
        }

        if ($type === 'repeater' && is_array($value)) {
            foreach ($value as $row_index => $row) {
                if (is_array($row)) {
                    $value[$row_index] = self::prepare_acf_sub_fields($row, $field['sub_fields'] ?? [], $post_id, $job);
                }
            }
            return $value;
        }

        return $value;
    }

    private static function prepare_acf_sub_fields($value, $sub_fields, $post_id, $job)
    {
        foreach ($sub_fields as $sub_field) {
            $name = $sub_field['name'] ?? '';
            if ($name !== '' && array_key_exists($name, $value)) {
                $value[$name] = self::prepare_acf_field_media($value[$name], $sub_field, $post_id, $job);
            }
        }
        return $value;
    }

    private static function is_remote_media_url($value)
    {
        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    private static function update_field_value($field_name, $value, $post_id)
    {
        if (function_exists('update_field')) {
            update_field($field_name, $value, $post_id);
            return;
        }

        update_post_meta($post_id, $field_name, $value);

        if (is_array($value)) {
            foreach ($value as $sub_key => $sub_value) {
                if (is_array($sub_value)) {
                    continue;
                }
                update_post_meta($post_id, $field_name . '_' . $sub_key, $sub_value);
            }
        }
    }

    private static function sideload_media($url, $post_id, $job = [])
    {
        $url = esc_url_raw($url);
        if (!$url) {
            return 0;
        }

        $existing = self::find_attachment_by_source_url($url);
        if ($existing) {
            return $existing;
        }

        if (!empty($job['reuse_media_by_filename'])) {
            $existing = self::find_attachment_by_filename(basename(parse_url($url, PHP_URL_PATH)));
            if ($existing) {
                update_post_meta($existing, self::SOURCE_URL_META, $url);
                return $existing;
            }
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($url, 60);
        if (is_wp_error($tmp)) {
            return 0;
        }

        $file_array = [
            'name' => basename(parse_url($url, PHP_URL_PATH)),
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload($file_array, $post_id);
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return 0;
        }

        update_post_meta($attachment_id, self::SOURCE_URL_META, $url);
        return (int) $attachment_id;
    }

    private static function find_attachment_by_source_url($url)
    {
        $existing = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'meta_key' => self::SOURCE_URL_META,
            'meta_value' => $url,
            'fields' => 'ids',
            'posts_per_page' => 1,
        ]);

        return $existing ? (int) $existing[0] : 0;
    }

    private static function find_attachment_by_filename($filename)
    {
        $filename = sanitize_file_name(wp_basename((string) $filename));
        if (!$filename) {
            return 0;
        }

        $attachments = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_wp_attached_file',
                    'value' => '/' . $filename,
                    'compare' => 'LIKE',
                ],
            ],
        ]);

        if ($attachments) {
            return (int) $attachments[0];
        }

        $attachments = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'fields' => 'ids',
            's' => pathinfo($filename, PATHINFO_FILENAME),
        ]);

        foreach ($attachments as $attachment_id) {
            $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
            if ($attached_file && wp_basename($attached_file) === $filename) {
                return (int) $attachment_id;
            }
        }

        return 0;
    }

    private static function assign_categories($post_id, $categories, $post_type)
    {
        if (!$categories || !is_array($categories)) {
            return;
        }

        $term_ids_by_taxonomy = [];
        foreach ($categories as $category) {
            if (!is_array($category)) {
                continue;
            }

            $taxonomy = self::resolve_category_taxonomy($category, $post_type);
            if (!$taxonomy) {
                continue;
            }

            $name = sanitize_text_field($category['name'] ?? '');
            $slug = sanitize_title($category['slug'] ?? $name);
            if (!$name) {
                continue;
            }

            $term = term_exists($slug, $taxonomy);
            if (!$term) {
                $term = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
            }
            if (!is_wp_error($term)) {
                $term_ids_by_taxonomy[$taxonomy][] = (int) (is_array($term) ? $term['term_id'] : $term);
            }
        }

        foreach ($term_ids_by_taxonomy as $taxonomy => $term_ids) {
            wp_set_object_terms($post_id, array_values(array_unique($term_ids)), $taxonomy, false);
        }
    }

    private static function resolve_category_taxonomy($category, $post_type)
    {
        $declared_taxonomy = sanitize_key($category['taxonomy'] ?? '');
        if ($declared_taxonomy) {
            return taxonomy_exists($declared_taxonomy) && is_object_in_taxonomy($post_type, $declared_taxonomy)
                ? $declared_taxonomy
                : '';
        }

        foreach (['project_category', 'category'] as $fallback_taxonomy) {
            if (taxonomy_exists($fallback_taxonomy) && is_object_in_taxonomy($post_type, $fallback_taxonomy)) {
                return $fallback_taxonomy;
            }
        }

        return '';
    }

    private static function extract_records($payload)
    {
        if (!empty($payload['records']) && is_array($payload['records'])) {
            return $payload['records'];
        }

        if (self::is_list_array($payload)) {
            return $payload;
        }

        return [];
    }

    private static function is_list_array($value)
    {
        if (!is_array($value)) {
            return false;
        }

        $index = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $index) {
                return false;
            }
            $index++;
        }

        return true;
    }

    private static function valid_status($status)
    {
        $status = sanitize_key($status);
        return in_array($status, ['publish', 'draft', 'pending', 'private', 'future'], true) ? $status : 'publish';
    }

    private static function verify_request()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }
        check_admin_referer(self::NONCE_ACTION);
    }

    private static function redirect($message)
    {
        wp_safe_redirect(add_query_arg(
            ['page' => 'tinker-valley-import-export', 'tvpi_notice' => rawurlencode($message)],
            admin_url('tools.php')
        ));
        exit;
    }

    private static function schedule_processing()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 15, self::CRON_HOOK);
        }
    }

    private static function get_jobs()
    {
        $jobs = get_option(self::JOBS_OPTION, []);
        return is_array($jobs) ? $jobs : [];
    }

    private static function save_jobs($jobs)
    {
        update_option(self::JOBS_OPTION, $jobs, false);
    }

    private static function sample_json()
    {
        return [
            'source_file' => 'posts-export.xml',
            'target_post_type' => 'project',
            'field_mapping_version' => '2026-06-28',
            'records' => [
                [
                    'old_id' => 291,
                    'title' => 'Sample Project',
                    'slug' => 'sample-project',
                    'status' => 'publish',
                    'post_date' => '2014-01-01 12:00:00',
                    'categories' => [
                        ['slug' => 'cottage', 'name' => 'Cottage'],
                    ],
                    'content' => '<p>Original post content can go here.</p>',
                    'featured_image_url' => 'https://example.com/uploads/sample-project.jpg',
                    'acf' => [
                        'designer' => 'OS1 DESIGN',
                        'specifications' => [
                            'sqft' => 1183,
                            'bedrooms' => 2,
                            'bathrooms' => 1,
                            'stories' => null,
                        ],
                        'floorplan_pdf' => '',
                        'floorplan_image' => '',
                        'project_gallery' => [
                            'https://example.com/uploads/sample-project-gallery.jpg',
                        ],
                        'video' => '',
                        'features' => ['fireplace'],
                        'additional_features' => [
                            ['feature_text' => 'Custom tile surround'],
                        ],
                        'interior_finishes' => [
                            'flooring' => 'Varnished Plywood',
                            'fireplace' => 'Morso Fireplace',
                            'kitchen_fixtures' => 'Kindred sink, Delta faucet',
                            'kitchen_cabinetry' => 'Birch plywood custom cabinetry',
                            'kitchen_countertops' => '',
                            'kitchen_backsplash' => 'Black Glass',
                            'bath_fixtures' => 'Delta faucets and shower fixtures',
                            'bath_cabinetry' => '',
                            'bath_tile' => '',
                            'bath_countertops' => '',
                            'cabinet_hardware' => '',
                        ],
                        'exterior_build' => [
                            'exterior_siding' => 'Flat Sheet Cor-Ten Steel',
                            'roofing' => '',
                            'foundation_notes' => '',
                        ],
                        'mechanical' => [
                            'heating' => '',
                            'cooling' => '',
                            'ventilation' => '',
                            'hot_water' => '',
                            'insulation' => '',
                            'windows' => '',
                        ],
                        'additional_specs' => [
                            ['spec_label' => 'Ceiling Height', 'spec_value' => '9 ft'],
                        ],
                    ],
                ],
            ],
        ];
    }
}

Tinker_Valley_Import_Export::init();
register_activation_hook(__FILE__, ['Tinker_Valley_Import_Export', 'activate']);
register_deactivation_hook(__FILE__, ['Tinker_Valley_Import_Export', 'deactivate']);

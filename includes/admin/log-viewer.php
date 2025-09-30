<?php declare(strict_types=1);
/**
 * Administrative log viewer for Hotel in Cloud plugin.
 */

use function FpHic\Helpers\hic_ensure_log_directory_security;
use function FpHic\Helpers\hic_get_log_directory;
use function FpHic\Helpers\hic_require_cap;

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('hic_register_log_viewer_page')) {
    add_action('admin_menu', 'hic_register_log_viewer_page', 45);

    /**
     * Register the read-only log viewer admin page.
     */
    function hic_register_log_viewer_page(): void {
        add_submenu_page(
            'hic-monitoring',
            __('Log viewer', 'hotel-in-cloud'),
            __('Log viewer', 'hotel-in-cloud'),
            'hic_view_logs',
            'hic-log-viewer',
            'hic_log_viewer_page'
        );
    }
}

if (!function_exists('hic_log_viewer_page')) {
    /**
     * Render the paginated log viewer.
     */
    function hic_log_viewer_page(): void {
        hic_require_cap('hic_view_logs');

        $security_status = hic_ensure_log_directory_security();
        $log_manager     = function_exists('hic_get_log_manager') ? hic_get_log_manager() : null;

        $active_log_file = '';
        if ($log_manager && method_exists($log_manager, 'get_log_file_path')) {
            $active_log_file = (string) $log_manager->get_log_file_path();
        }

        $active_basename = $active_log_file !== '' ? basename($active_log_file) : '';
        $available_files = hic_log_viewer_collect_files($log_manager, $active_basename);

        $selected_file = $active_basename;
        if (isset($_GET['hic_log_file'])) {
            $candidate = wp_unslash((string) $_GET['hic_log_file']);
            $candidate = sanitize_file_name($candidate);
            if ($candidate !== '' && isset($available_files[$candidate])) {
                $selected_file = $candidate;
            }
        }

        $per_page = isset($_GET['per_page']) ? absint($_GET['per_page']) : 100;
        if ($per_page <= 0) {
            $per_page = 100;
        } elseif ($per_page > 500) {
            $per_page = 500;
        }

        $current_page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;

        $resolved_path = hic_log_viewer_resolve_path($selected_file, $available_files);
        $entries       = hic_log_viewer_fetch_entries($resolved_path, $current_page, $per_page);

        $total_pages = $entries['total_lines'] > 0
            ? (int) ceil($entries['total_lines'] / $per_page)
            : 1;

        $pagination_links = paginate_links(
            array(
                'base'      => add_query_arg(
                    array(
                        'page'         => 'hic-log-viewer',
                        'hic_log_file' => $selected_file,
                        'per_page'     => $per_page,
                        'paged'        => '%#%',
                    )
                ),
                'format'    => '',
                'current'   => $current_page,
                'total'     => $total_pages,
                'prev_text' => __('« Precedente', 'hotel-in-cloud'),
                'next_text' => __('Successivo »', 'hotel-in-cloud'),
            )
        );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Registro eventi', 'hotel-in-cloud'); ?></h1>
            <p class="description">
                <?php
                echo esc_html__(
                    'Visualizza in sola lettura gli eventi del plugin. I log ruotano in automatico in base alla dimensione e i file compressi rimangono disponibili nel medesimo percorso protetto.',
                    'hotel-in-cloud'
                );
                ?>
            </p>
            <?php if (!empty($security_status['errors'])) : ?>
                <div class="notice notice-error">
                    <p>
                        <?php echo esc_html__('Impossibile mettere in sicurezza completamente la directory dei log. Verifica i permessi e riprova.', 'hotel-in-cloud'); ?>
                    </p>
                    <ul>
                        <?php foreach ($security_status['errors'] as $area => $message) : ?>
                            <li><strong><?php echo esc_html($area); ?>:</strong> <?php echo esc_html($message); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php else : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        printf(
                            /* translators: %s is the absolute path to the secured directory */
                            esc_html__('Directory log protetta in %s. Accessi diretti sono bloccati da .htaccess/web.config.', 'hotel-in-cloud'),
                            esc_html($security_status['path'])
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <form method="get" class="hic-log-viewer-filters" style="margin-bottom: 1em;">
                <input type="hidden" name="page" value="hic-log-viewer" />
                <label for="hic-log-file">
                    <?php echo esc_html__('File log', 'hotel-in-cloud'); ?>
                </label>
                <select name="hic_log_file" id="hic-log-file">
                    <?php foreach ($available_files as $basename => $file_meta) : ?>
                        <option value="<?php echo esc_attr($basename); ?>"<?php selected($selected_file, $basename); ?>>
                            <?php echo esc_html($file_meta['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="hic-log-per-page" style="margin-left:1em;">
                    <?php echo esc_html__('Righe per pagina', 'hotel-in-cloud'); ?>
                </label>
                <input type="number" name="per_page" id="hic-log-per-page" min="10" max="500" value="<?php echo esc_attr($per_page); ?>" />
                <button type="submit" class="button">
                    <?php echo esc_html__('Aggiorna', 'hotel-in-cloud'); ?>
                </button>
            </form>

            <?php if (!empty($entries['error'])) : ?>
                <div class="notice notice-error">
                    <p><?php echo esc_html($entries['error']); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($entries['is_compressed']) : ?>
                <div class="notice notice-warning">
                    <p>
                        <?php echo esc_html__('Il file selezionato è compresso (.gz). Scaricalo via FTP/SFTP per analizzarlo; non è possibile visualizzarlo dal browser.', 'hotel-in-cloud'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <table class="widefat fixed striped">
                <thead>
                <tr>
                    <th scope="col" style="width: 180px;">
                        <?php echo esc_html__('Data', 'hotel-in-cloud'); ?>
                    </th>
                    <th scope="col" style="width: 100px;">
                        <?php echo esc_html__('Livello', 'hotel-in-cloud'); ?>
                    </th>
                    <th scope="col" style="width: 120px;">
                        <?php echo esc_html__('Memoria', 'hotel-in-cloud'); ?>
                    </th>
                    <th scope="col">
                        <?php echo esc_html__('Messaggio', 'hotel-in-cloud'); ?>
                    </th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($entries['entries'])) : ?>
                    <tr>
                        <td colspan="4" style="text-align:center;">
                            <?php echo esc_html__('Nessun evento da mostrare.', 'hotel-in-cloud'); ?>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($entries['entries'] as $entry) : ?>
                        <tr>
                            <td><?php echo esc_html($entry['timestamp']); ?></td>
                            <td><?php echo esc_html($entry['level']); ?></td>
                            <td><?php echo esc_html($entry['memory']); ?></td>
                            <td><code style="white-space: pre-wrap; display: block;">
                                <?php echo esc_html($entry['message']); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ($pagination_links) : ?>
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <?php echo wp_kses_post($pagination_links); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

if (!function_exists('hic_log_viewer_collect_files')) {
    /**
     * Build a list of available log files keyed by their basename.
     *
     * @param object|null $log_manager Instance of the log manager, if available.
     * @param string      $active_log  Basename for the active log file.
     * @return array<string,array{label:string,path:string,size:int,modified:?string}>
     */
    function hic_log_viewer_collect_files($log_manager, string $active_log): array {
        $files = array();

        if ($log_manager && method_exists($log_manager, 'get_log_stats')) {
            $stats = $log_manager->get_log_stats();

            if (!empty($stats['exists']) && $active_log !== '') {
                $label = sprintf(
                    /* translators: 1: log filename, 2: size in MB, 3: number of lines */
                    __('%1$s (%.2f MB, %3$d righe)', 'hotel-in-cloud'),
                    $active_log,
                    isset($stats['size_mb']) ? (float) $stats['size_mb'] : 0,
                    isset($stats['lines']) ? (int) $stats['lines'] : 0
                );
                $files[$active_log] = array(
                    'label'    => $label,
                    'path'     => $log_manager->get_log_file_path(),
                    'size'     => isset($stats['size']) ? (int) $stats['size'] : 0,
                    'modified' => isset($stats['last_modified']) ? (string) $stats['last_modified'] : null,
                );
            }

            if (!empty($stats['rotated_files']) && is_array($stats['rotated_files'])) {
                $log_dir = hic_get_log_directory();
                foreach ($stats['rotated_files'] as $rotated) {
                    if (empty($rotated['file'])) {
                        continue;
                    }

                    $basename = sanitize_file_name((string) $rotated['file']);
                    if ($basename === '') {
                        continue;
                    }

                    $absolute_path = isset($rotated['path']) ? (string) $rotated['path'] : '';
                    if ($absolute_path === '') {
                        $absolute_path = rtrim($log_dir, "/\\") . DIRECTORY_SEPARATOR . $basename;
                    }

                    $files[$basename] = array(
                        'label'    => sprintf(
                            /* translators: 1: rotated filename, 2: size in MB, 3: last modified date */
                            __('%1$s (%.2f MB, modificato %3$s)', 'hotel-in-cloud'),
                            $basename,
                            isset($rotated['size_mb']) ? (float) $rotated['size_mb'] : 0,
                            isset($rotated['modified']) ? (string) $rotated['modified'] : __('data sconosciuta', 'hotel-in-cloud')
                        ),
                        'path'     => $absolute_path,
                        'size'     => isset($rotated['size']) ? (int) $rotated['size'] : 0,
                        'modified' => isset($rotated['modified']) ? (string) $rotated['modified'] : null,
                    );
                }
            }
        }

        if (empty($files) && $active_log !== '') {
            $files[$active_log] = array(
                'label'    => $active_log,
                'path'     => $active_log,
                'size'     => 0,
                'modified' => null,
            );
        }

        return $files;
    }
}

if (!function_exists('hic_log_viewer_resolve_path')) {
    /**
     * Validate that the requested log file lives inside the logging directory.
     *
     * @param string $selected_file Basename requested by the administrator.
     * @param array  $available     Allowed files keyed by basename.
     * @return string Absolute path if valid, empty string otherwise.
     */
    function hic_log_viewer_resolve_path(string $selected_file, array $available): string {
        if ($selected_file === '' || empty($available[$selected_file]['path'])) {
            return '';
        }

        $candidate_path = $available[$selected_file]['path'];
        $candidate_path = wp_normalize_path($candidate_path);

        $log_dir = wp_normalize_path(hic_get_log_directory());
        if ($log_dir === '') {
            return '';
        }

        if (!file_exists($candidate_path)) {
            return '';
        }

        $real_candidate = realpath($candidate_path);
        $real_dir       = realpath($log_dir);

        if (false === $real_candidate || false === $real_dir) {
            return '';
        }

        if (strpos($real_candidate, $real_dir) !== 0) {
            return '';
        }

        return $real_candidate;
    }
}

if (!function_exists('hic_log_viewer_fetch_entries')) {
    /**
     * Read the requested log file slice.
     *
     * @param string $path         Log file absolute path.
     * @param int    $current_page Page requested (1-indexed).
     * @param int    $per_page     Number of lines per page.
     * @return array{entries:array<int,array{timestamp:string,level:string,memory:string,message:string}>,total_lines:int,error:string,is_compressed:bool}
     */
    function hic_log_viewer_fetch_entries(string $path, int $current_page, int $per_page): array {
        $result = array(
            'entries'       => array(),
            'total_lines'   => 0,
            'error'         => '',
            'is_compressed' => false,
        );

        if ($path === '' || !file_exists($path)) {
            $result['error'] = __('Il file di log selezionato non esiste.', 'hotel-in-cloud');

            return $result;
        }

        if (substr($path, -3) === '.gz') {
            $result['is_compressed'] = true;

            return $result;
        }

        if (!is_readable($path)) {
            $result['error'] = __('Impossibile leggere il file di log selezionato.', 'hotel-in-cloud');

            return $result;
        }

        try {
            $file = new \SplFileObject($path, 'r');
        } catch (\RuntimeException $exception) {
            $result['error'] = __("Errore durante l'apertura del file di log.", 'hotel-in-cloud');

            return $result;
        }

        $file->seek(PHP_INT_MAX);
        $total_lines = (int) $file->key() + 1;
        $result['total_lines'] = $total_lines;

        if (0 === $total_lines) {
            return $result;
        }

        $offset = ($current_page - 1) * $per_page;
        if ($offset >= $total_lines) {
            $offset = max(0, $total_lines - $per_page);
        }

        $start = max(0, $total_lines - ($offset + $per_page));
        $end   = max(0, $total_lines - $offset);

        $entries = array();
        $file->seek($start);

        while (!$file->eof() && $file->key() < $end) {
            $line   = (string) $file->current();
            $parsed = hic_log_viewer_parse_line($line);
            if (!empty($parsed)) {
                $entries[] = $parsed;
            }
            $file->next();
        }

        $result['entries'] = array_reverse($entries);

        return $result;
    }
}

if (!function_exists('hic_log_viewer_parse_line')) {
    /**
     * Parse a log line into a structured array compatible with the viewer table.
     *
     * @param string $line Raw log line from the file.
     * @return array{timestamp:string,level:string,memory:string,message:string}|array<string,never>
     */
    function hic_log_viewer_parse_line(string $line): array {
        $pattern = '/^\[([^\]]+)\] \[([^\]]+)\] \[([^\]]+)\] (.+)$/';
        if (preg_match($pattern, trim($line), $matches)) {
            return array(
                'timestamp' => $matches[1],
                'level'     => strtoupper($matches[2]),
                'memory'    => $matches[3],
                'message'   => $matches[4],
            );
        }

        return array();
    }
}

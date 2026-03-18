<?php
namespace WC_HS_Sync\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the HubSpot Sync log viewer tab in WP Admin.
 * Reads WC Logger files matching hubspot-sync-*.log
 */
class Log_Viewer {

    private string $log_dir;
    private string $log_prefix = 'hubspot-sync';

    public function __construct() {
        $upload_dir    = wp_upload_dir();
        $this->log_dir = $upload_dir['basedir'] . '/wc-logs/';
    }

    /**
     * Renders the full log viewer tab content.
     */
    public function render(): void {
        $files      = $this->get_log_files();
        $selected   = isset( $_GET['log_file'] )
            ? sanitize_file_name( $_GET['log_file'] )
            : ( $files[0] ?? '' );

        $lines      = $selected ? $this->read_log( $selected ) : [];
        $level_filter = isset( $_GET['log_level'] ) ? sanitize_text_field( $_GET['log_level'] ) : 'all';

        // Handle clear action
        if (
            isset( $_POST['wc_hs_clear_log'], $_POST['wc_hs_clear_log_nonce'] ) &&
            wp_verify_nonce( $_POST['wc_hs_clear_log_nonce'], 'wc_hs_clear_log' ) &&
            current_user_can( 'manage_woocommerce' )
        ) {
            $this->clear_log( sanitize_file_name( $_POST['wc_hs_clear_log'] ) );
            echo '<div class="notice notice-success"><p>Log file cleared.</p></div>';
            $lines = [];
        }

        // Count by level
        $counts = [ 'all' => count( $lines ), 'INFO' => 0, 'WARNING' => 0, 'ERROR' => 0 ];
        foreach ( $lines as $line ) {
            $lvl = $line['level'] ?? 'INFO';
            if ( isset( $counts[ $lvl ] ) ) $counts[ $lvl ]++;
        }

        // Apply filter
        if ( $level_filter !== 'all' ) {
            $lines = array_filter( $lines, fn( $l ) => ( $l['level'] ?? 'INFO' ) === strtoupper( $level_filter ) );
        }

        ?>
        <style>
            /* ── Log Viewer Styles ── */
            #wc-hs-log-viewer {
                font-family: 'SF Mono', 'Fira Code', 'Consolas', monospace;
                margin-top: 16px;
            }

            .wc-hs-log-toolbar {
                display: flex;
                align-items: center;
                gap: 12px;
                flex-wrap: wrap;
                background: #1a1a2e;
                border-radius: 8px 8px 0 0;
                padding: 12px 16px;
            }

            .wc-hs-log-toolbar select {
                background: #16213e;
                color: #e2e8f0;
                border: 1px solid #334155;
                border-radius: 5px;
                padding: 6px 10px;
                font-size: 13px;
                cursor: pointer;
            }

            .wc-hs-log-toolbar select:focus {
                outline: none;
                border-color: #6366f1;
            }

            .wc-hs-log-level-btns {
                display: flex;
                gap: 6px;
                margin-left: auto;
                flex-wrap: wrap;
            }

            .wc-hs-level-btn {
                padding: 5px 12px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 600;
                cursor: pointer;
                border: 1px solid transparent;
                transition: all 0.15s ease;
                text-decoration: none;
                letter-spacing: 0.03em;
            }

            .wc-hs-level-btn.all        { background: #334155; color: #cbd5e1; border-color: #475569; }
            .wc-hs-level-btn.info       { background: #1e3a5f; color: #60a5fa; border-color: #3b82f6; }
            .wc-hs-level-btn.warning    { background: #422006; color: #fb923c; border-color: #f97316; }
            .wc-hs-level-btn.error      { background: #450a0a; color: #f87171; border-color: #ef4444; }

            .wc-hs-level-btn.active.all      { background: #475569; color: #f1f5f9; }
            .wc-hs-level-btn.active.info     { background: #3b82f6; color: #fff; }
            .wc-hs-level-btn.active.warning  { background: #f97316; color: #fff; }
            .wc-hs-level-btn.active.error    { background: #ef4444; color: #fff; }

            .wc-hs-log-level-btns .count {
                display: inline-block;
                margin-left: 5px;
                background: rgba(255,255,255,0.15);
                border-radius: 10px;
                padding: 0 6px;
                font-size: 11px;
            }

            .wc-hs-log-table-wrap {
                background: #0f172a;
                border-radius: 0 0 8px 8px;
                overflow: auto;
                max-height: 600px;
                border: 1px solid #1e293b;
                border-top: none;
            }

            .wc-hs-log-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 12.5px;
                line-height: 1.6;
            }

            .wc-hs-log-table thead th {
                background: #0f172a;
                color: #64748b;
                text-transform: uppercase;
                font-size: 11px;
                letter-spacing: 0.07em;
                padding: 10px 14px;
                text-align: left;
                border-bottom: 1px solid #1e293b;
                position: sticky;
                top: 0;
                z-index: 2;
            }

            .wc-hs-log-table tbody tr {
                border-bottom: 1px solid #1a2540;
                transition: background 0.1s;
            }

            .wc-hs-log-table tbody tr:hover {
                background: #1e293b;
            }

            .wc-hs-log-table td {
                padding: 8px 14px;
                vertical-align: top;
                color: #94a3b8;
            }

            .wc-hs-log-table td.ts {
                color: #fff;
                white-space: nowrap;
                font-size: 11.5px;
            }

            .wc-hs-log-table td.msg {
                color: #fff;
                word-break: break-word;
                max-width: 700px;
            }

            .wc-hs-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 4px;
                font-size: 11px;
                font-weight: 700;
                letter-spacing: 0.05em;
                text-transform: uppercase;
            }

            .wc-hs-badge-INFO    { background: #1e3a5f; color: #60a5fa; }
            .wc-hs-badge-WARNING { background: #422006; color: #fb923c; }
            .wc-hs-badge-ERROR   { background: #450a0a; color: #f87171; }
            .wc-hs-badge-DEBUG   { background: #1e293b; color: #64748b; }

            .wc-hs-log-empty {
                text-align: center;
                padding: 60px 20px;
                color: #475569;
                font-size: 14px;
            }

            .wc-hs-log-empty .icon {
                font-size: 36px;
                display: block;
                margin-bottom: 10px;
                opacity: 0.4;
            }

            .wc-hs-clear-form {
                display: inline;
            }

            .wc-hs-btn-clear {
                background: transparent;
                border: 1px solid #ef4444;
                color: #f87171;
                border-radius: 5px;
                padding: 5px 12px;
                font-size: 12px;
                cursor: pointer;
                transition: all 0.15s;
            }

            .wc-hs-btn-clear:hover {
                background: #ef4444;
                color: #fff;
            }

            .wc-hs-log-meta {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 8px 16px;
                background: #0d1117;
                border-radius: 0 0 8px 8px;
                font-size: 12px;
                color: #475569;
                border: 1px solid #1e293b;
                border-top: none;
                margin-top: -1px;
            }
        </style>

        <div id="wc-hs-log-viewer">

            <?php if ( empty( $files ) ) : ?>
                <div class="wc-hs-log-empty">
                    <span class="icon">📭</span>
                    No log files found yet. Logs will appear here after the first sync runs.
                </div>
            <?php else : ?>

                <?php
                // Build base URL for filter links
                $base_url = admin_url( 'admin.php?page=wc-hubspot-sync&tab=logs&log_file=' . urlencode( $selected ) );
                ?>

                <div class="wc-hs-log-toolbar">

                    <!-- File picker -->
                    <form method="get" style="display:inline-flex;align-items:center;gap:8px;">
                        <input type="hidden" name="page" value="wc-hubspot-sync">
                        <input type="hidden" name="tab" value="logs">
                        <input type="hidden" name="log_level" value="<?php echo esc_attr( $level_filter ); ?>">
                        <select name="log_file" onchange="this.form.submit()">
                            <?php foreach ( $files as $file ) : ?>
                                <option value="<?php echo esc_attr( $file ); ?>"
                                    <?php selected( $file, $selected ); ?>>
                                    <?php echo esc_html( $this->friendly_filename( $file ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>

                    <!-- Level filter buttons -->
                    <div class="wc-hs-log-level-btns">
                        <?php
                        $levels = [
                            'all'     => 'All',
                            'info'    => 'Info',
                            'warning' => 'Warning',
                            'error'   => 'Error',
                        ];
                        foreach ( $levels as $slug => $label ) :
                            $active = ( $level_filter === $slug ) ? 'active' : '';
                            $count  = $counts[ strtoupper( $slug ) ] ?? $counts['all'];
                            $url    = $base_url . '&log_level=' . $slug;
                        ?>
                            <a href="<?php echo esc_url( $url ); ?>"
                               class="wc-hs-level-btn <?php echo $slug . ' ' . $active; ?>">
                                <?php echo esc_html( $label ); ?>
                                <span class="count"><?php echo esc_html( $count ); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <!-- Clear button -->
                    <?php if ( $selected ) : ?>
                    <form method="post" class="wc-hs-clear-form"
                          onsubmit="return confirm('Delete this log file?');">
                        <?php wp_nonce_field( 'wc_hs_clear_log', 'wc_hs_clear_log_nonce' ); ?>
                        <input type="hidden" name="wc_hs_clear_log" value="<?php echo esc_attr( $selected ); ?>">
                        <input type="hidden" name="page" value="wc-hubspot-sync">
                        <input type="hidden" name="tab" value="logs">
                        <button type="submit" class="wc-hs-btn-clear">🗑 Clear</button>
                    </form>
                    <?php endif; ?>

                </div>

                <div class="wc-hs-log-table-wrap">
                    <?php if ( empty( $lines ) ) : ?>
                        <div class="wc-hs-log-empty">
                            <span class="icon">✅</span>
                            No <?php echo $level_filter !== 'all' ? esc_html( strtolower( $level_filter ) ) . ' ' : ''; ?>log entries found.
                        </div>
                    <?php else : ?>
                        <table class="wc-hs-log-table">
                            <thead>
                                <tr>
                                    <th style="width:170px;">Timestamp</th>
                                    <th style="width:90px;">Level</th>
                                    <th>Message</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( array_reverse( $lines ) as $line ) : ?>
                                    <tr>
                                        <td class="ts"><?php echo esc_html( $line['timestamp'] ?? '' ); ?></td>
                                        <td>
                                            <?php
                                            $lvl = $line['level'] ?? 'INFO';
                                            printf(
                                                '<span class="wc-hs-badge wc-hs-badge-%s">%s</span>',
                                                esc_attr( $lvl ),
                                                esc_html( $lvl )
                                            );
                                            ?>
                                        </td>
                                        <td class="msg"><?php echo esc_html( $line['message'] ?? '' ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="wc-hs-log-meta">
                    <span>📄 <?php echo esc_html( $selected ); ?></span>
                    <span>·</span>
                    <span><?php echo count( $lines ); ?> entries shown</span>
                    <?php
                    $filepath = $this->log_dir . $selected;
                    if ( file_exists( $filepath ) ) :
                        echo '<span>·</span>';
                        echo '<span>Size: ' . esc_html( size_format( filesize( $filepath ) ) ) . '</span>';
                    endif;
                    ?>
                </div>

            <?php endif; ?>

        </div>
        <?php
    }

    // ──────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────

    /**
     * Returns sorted list of hubspot-sync log filenames (newest first).
     */
    private function get_log_files(): array {
        if ( ! is_dir( $this->log_dir ) ) return [];

        $files = glob( $this->log_dir . $this->log_prefix . '-*.log' );
        if ( ! $files ) return [];

        // Sort newest first
        usort( $files, fn( $a, $b ) => filemtime( $b ) - filemtime( $a ) );

        return array_map( 'basename', $files );
    }

    /**
     * Parse log file into structured line arrays.
     * WC Logger format: YYYY-MM-DD HH:MM:SS LEVEL MESSAGE
     * e.g. 2025-03-15 10:22:01 INFO [HS Sync] Order 123 synced.
     */
    private function read_log( string $filename ): array {
        $path = $this->log_dir . $filename;
        if ( ! file_exists( $path ) ) return [];

        $raw   = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        $lines = [];

        foreach ( $raw as $raw_line ) {
            // Pattern: 2025-03-15T10:22:01+00:00 LEVEL  CONTEXT MESSAGE
            if ( preg_match(
                '/^(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}[^\s]*)\s+(DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY)\s+(.*)$/',
                $raw_line,
                $m
            ) ) {
                $lines[] = [
                    'timestamp' => str_replace( 'T', ' ', substr( $m[1], 0, 19 ) ),
                    'level'     => strtoupper( $m[2] ) === 'NOTICE' ? 'INFO' : strtoupper( $m[2] ),
                    'message'   => trim( $m[3] ),
                ];
            } else {
                // Fallback — unstructured line
                $lines[] = [
                    'timestamp' => '',
                    'level'     => 'INFO',
                    'message'   => $raw_line,
                ];
            }
        }

        return $lines;
    }

    /**
     * Makes the filename human-readable in the dropdown.
     * hubspot-sync-2025-03-15-abc123.log → 2025-03-15
     */
    private function friendly_filename( string $filename ): string {
        if ( preg_match( '/hubspot-sync-(\d{4}-\d{2}-\d{2})/', $filename, $m ) ) {
            return $m[1];
        }
        return $filename;
    }

    /**
     * Delete a log file by name.
     */
    private function clear_log( string $filename ): void {
        $path = $this->log_dir . $filename;
        if ( file_exists( $path ) && strpos( realpath( $path ), realpath( $this->log_dir ) ) === 0 ) {
            wp_delete_file( $path );
        }
    }
}
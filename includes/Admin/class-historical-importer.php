<?php
namespace WC_HS_Sync\Admin;

defined( 'ABSPATH' ) || exit;

use WC_HS_Sync\Sync_Manager;
use WC_HS_Sync\CRM\HubSpot_Adapter;
use WC_HS_Sync\Order\Order_Extractor;
use WC_HS_Sync\Settings;

/**
 * Historical order importer — batches all WooCommerce orders into HubSpot.
 *
 * Architecture note: This class only calls Sync_Manager::sync_order().
 * It has zero direct CRM knowledge — fully adapter-agnostic.
 */
class Historical_Importer {

	private int $batch_size = 5;

	public function __construct() {
		add_action( 'wp_ajax_wc_hs_importer_start',         [ $this, 'ajax_start'          ] );
		add_action( 'wp_ajax_wc_hs_importer_process_batch', [ $this, 'ajax_process_batch'  ] );
		add_action( 'wp_ajax_wc_hs_importer_status',        [ $this, 'ajax_status'         ] );
		add_action( 'wp_ajax_wc_hs_importer_reset',         [ $this, 'ajax_reset'          ] );
		add_action( 'wp_ajax_wc_hs_importer_sync_single',   [ $this, 'ajax_sync_single'    ] );
	}

	// ──────────────────────────────────────────────
	// Render (called from Settings tab)
	// ──────────────────────────────────────────────

	public function render(): void {
		$status = $this->get_status();
		$nonce  = wp_create_nonce( 'wc_hs_importer' );
		?>
		<div id="wc-hs-importer" style="max-width:860px;">

			<p style="color:#50575e;margin-bottom:20px;">
				Import all historical WooCommerce orders into HubSpot as deals.
				Existing deals are updated; new deals are created. Runs 5 orders per batch.
			</p>

			<!-- Controls -->
			<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:20px;">
				<button id="hs-imp-start" class="button button-primary">▶ Start Import</button>
				<button id="hs-imp-reset" class="button">↺ Reset</button>
			</div>

			<!-- Single / multiple orders -->
			<div style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:16px;margin-bottom:20px;">
				<strong style="display:block;margin-bottom:8px;">Sync specific order(s)</strong>
				<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
					<input type="text" id="hs-imp-order-ids"
						   placeholder="e.g. 1234 or 1234,1235,1236"
						   style="width:280px;" />
					<button id="hs-imp-sync-single" class="button button-secondary">Sync Order(s)</button>
				</div>
				<div id="hs-imp-single-result" style="margin-top:8px;font-size:13px;"></div>
			</div>

			<!-- Progress -->
			<div style="background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:16px;margin-bottom:16px;">
				<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;margin-bottom:14px;">
					<?php foreach ( [
						'Status'       => '<span id="hs-imp-status-text">Idle</span>',
						'Progress'     => '<span id="hs-imp-progress-text">0 / 0</span>',
						'Created'      => '<span id="hs-imp-synced-text">0</span>',
						'Updated'      => '<span id="hs-imp-skipped-text">0</span>',
						'Failed'       => '<span id="hs-imp-failed-text">0</span>',
						'Last Order'   => '<span id="hs-imp-last-id">—</span>',
						'Elapsed'      => '<span id="hs-imp-elapsed">00:00:00</span>',
					] as $label => $value ) : ?>
					<div style="background:#f6f7f7;padding:10px 12px;border-radius:3px;">
						<div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#8c8f94;margin-bottom:3px;">
							<?php echo esc_html( $label ); ?>
						</div>
						<div style="font-size:15px;font-weight:600;color:#1d2327;"><?php echo $value; // pre-escaped ?></div>
					</div>
					<?php endforeach; ?>
				</div>

				<!-- Progress bar -->
				<div style="background:#f0f0f1;border-radius:3px;height:8px;overflow:hidden;">
					<div id="hs-imp-bar"
						 style="height:100%;width:0%;background:#2271b1;border-radius:3px;transition:width .4s ease;"></div>
				</div>
			</div>

			<!-- Error log -->
			<div style="background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:16px;">
				<strong style="display:block;margin-bottom:8px;">Error log</strong>
				<div id="hs-imp-errors"
					 style="max-height:260px;overflow-y:auto;font-size:12px;font-family:monospace;
					        background:#f6f7f7;padding:10px;border-radius:3px;color:#50575e;">
					No errors.
				</div>
			</div>
		</div>

		<script>
		(function($){
			const nonce   = <?php echo wp_json_encode( $nonce ); ?>;
			const ajaxurl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			let pollTimer  = null;
			let startedAt  = null;
			let clockTimer = null;

			// ── Boot ──────────────────────────────
			refreshStatus();

			// ── Button handlers ───────────────────

			$('#hs-imp-start').on('click', function(){
				if ( ! confirm('Import all WooCommerce orders into HubSpot? This may take several minutes.') ) return;
				$(this).prop('disabled', true).text('Starting…');

				$.post( ajaxurl, {
					action : 'wc_hs_importer_start',
					nonce  : nonce,
				}).done(function(r){
					if ( r.success ) {
						startedAt = new Date();
						startClock();
						poll();
					} else {
						alert('Could not start import: ' + r.data );
						$('#hs-imp-start').prop('disabled', false).text('▶ Start Import');
					}
				});
			});

			$('#hs-imp-reset').on('click', function(){
				if ( ! confirm('Reset import progress?') ) return;
				$.post( ajaxurl, { action: 'wc_hs_importer_reset', nonce } )
				 .done(() => { stopPolling(); refreshStatus(); });
			});

			$('#hs-imp-sync-single').on('click', function(){
				const ids = $('#hs-imp-order-ids').val().trim();
				if ( ! ids ) { alert('Enter at least one order ID.'); return; }

				$(this).prop('disabled', true).text('Syncing…');
				$('#hs-imp-single-result').html('');

				$.post( ajaxurl, {
					action    : 'wc_hs_importer_sync_single',
					nonce     : nonce,
					order_ids : ids,
				}).done(function(r){
					$('#hs-imp-sync-single').prop('disabled', false).text('Sync Order(s)');
					if ( r.success ) {
						// Multiple orders queued as batch
						if ( r.data.use_batch ) {
							startedAt = new Date();
							startClock();
							poll();
							$('#hs-imp-single-result').html('<span style="color:#2271b1">Processing ' + r.data.total + ' orders…</span>');
						} else {
							$('#hs-imp-single-result').html('<span style="color:#00a32a">✓ ' + r.data.message + '</span>');
						}
					} else {
						$('#hs-imp-single-result').html('<span style="color:#d63638">✗ ' + r.data + '</span>');
					}
				});
			});

			// ── Batch polling ─────────────────────

			function poll() {
				$.post( ajaxurl, { action: 'wc_hs_importer_process_batch', nonce } )
				 .done(function(r){
					refreshStatus();
					if ( r.success && r.data.continue ) {
						pollTimer = setTimeout( poll, 1500 );
					} else {
						stopPolling();
						if ( r.success ) {
							$('#hs-imp-start').prop('disabled', false).text('▶ Start Import');
							refreshStatus();
						} else {
							alert('Batch error: ' + r.data );
							$('#hs-imp-start').prop('disabled', false).text('▶ Start Import');
						}
					}
				});
			}

			function stopPolling() {
				clearTimeout( pollTimer );
				clearInterval( clockTimer );
				$('#hs-imp-start').prop('disabled', false).text('▶ Start Import');
			}

			// ── Status display ────────────────────

			function refreshStatus() {
				$.post( ajaxurl, { action: 'wc_hs_importer_status', nonce } )
				 .done(function(r){
					if ( ! r.success ) return;
					const s = r.data;
					$('#hs-imp-status-text').text( s.status );
					$('#hs-imp-progress-text').text( s.processed + ' / ' + s.total );
					$('#hs-imp-synced-text').text( s.synced );
					$('#hs-imp-skipped-text').text( s.updated || 0 );
					$('#hs-imp-failed-text').text( s.failed );
					$('#hs-imp-last-id').text( s.last_order_id || '—' );

					const pct = s.total > 0 ? ( s.processed / s.total * 100 ) : 0;
					$('#hs-imp-bar').css('width', pct + '%');

					if ( s.errors && s.errors.length ) {
						let html = '';
						s.errors.forEach(function(e){
							html += '[Order #' + e.order_id + '] ' + e.message + '\n';
						});
						$('#hs-imp-errors').text( html );
					} else {
						$('#hs-imp-errors').text('No errors.');
					}

					// If already running when page loads, resume polling
					if ( s.status === 'processing' && ! pollTimer ) {
						if ( ! startedAt ) startedAt = new Date();
						startClock();
						poll();
					}
				});
			}

			// ── Clock ─────────────────────────────

			function startClock() {
				clearInterval( clockTimer );
				clockTimer = setInterval(function(){
					if ( ! startedAt ) return;
					const secs = Math.floor( ( new Date() - startedAt ) / 1000 );
					$('#hs-imp-elapsed').text( fmt(secs) );
				}, 1000 );
			}

			function fmt(s) {
				return [ Math.floor(s/3600), Math.floor((s%3600)/60), s%60 ]
					.map(v => String(v).padStart(2,'0')).join(':');
			}

		})(jQuery);
		</script>
		<?php
	}

	// ──────────────────────────────────────────────
	// AJAX handlers
	// ──────────────────────────────────────────────

	public function ajax_start(): void {
		$this->check_nonce();

		$order_ids = $this->fetch_all_order_ids();

		$this->save_status( [
			'status'        => 'processing',
			'total'         => count( $order_ids ),
			'processed'     => 0,
			'synced'        => 0,
			'updated'       => 0,
			'failed'        => 0,
			'batch'         => 0,
			'order_ids'     => $order_ids,
			'last_order_id' => null,
			'errors'        => [],
			'started_at'    => current_time( 'mysql' ),
		] );

		wp_send_json_success( [ 'total' => count( $order_ids ) ] );
	}

	public function ajax_process_batch(): void {
		$this->check_nonce();

		$status = $this->get_status();

		if ( ( $status['status'] ?? '' ) !== 'processing' ) {
			wp_send_json_error( 'Not in processing state.' );
		}

		$start  = $status['batch'] * $this->batch_size;
		$slice  = array_slice( $status['order_ids'], $start, $this->batch_size );

		if ( empty( $slice ) ) {
			$status['status'] = 'completed';
			$this->save_status( $status );
			wp_send_json_success( [ 'continue' => false ] );
		}

		$sync = $this->make_sync_manager();

		foreach ( $slice as $order_id ) {
			$order_id = (int) $order_id;

			try {
				$sync->sync_order( $order_id );
				$status['synced']++;
			} catch ( \Throwable $e ) {
				$status['failed']++;
				$status['errors'][] = [
					'order_id' => $order_id,
					'message'  => $e->getMessage(),
				];
				// Keep last 100 errors only
				if ( count( $status['errors'] ) > 100 ) {
					$status['errors'] = array_slice( $status['errors'], -100 );
				}
			}

			$status['processed']++;
			$status['last_order_id'] = $order_id;
		}

		$status['batch']++;
		$this->save_status( $status );

		$has_more = ( $start + $this->batch_size ) < $status['total'];
		wp_send_json_success( [ 'continue' => $has_more ] );
	}

	public function ajax_status(): void {
		$this->check_nonce();
		$status = $this->get_status();
		unset( $status['order_ids'] ); // never send large array to browser
		wp_send_json_success( $status );
	}

	public function ajax_reset(): void {
		$this->check_nonce();
		$this->save_status( $this->default_status() );
		wp_send_json_success();
	}

	public function ajax_sync_single(): void {
		$this->check_nonce();

		$raw = sanitize_text_field( $_POST['order_ids'] ?? '' );
		$ids = array_filter( array_map( 'intval', array_map( 'trim', explode( ',', $raw ) ) ) );

		if ( empty( $ids ) ) {
			wp_send_json_error( 'No valid order IDs provided.' );
		}

		// More than one order → use the batch system
		if ( count( $ids ) > 1 ) {
			$this->save_status( [
				'status'        => 'processing',
				'total'         => count( $ids ),
				'processed'     => 0,
				'synced'        => 0,
				'updated'       => 0,
				'failed'        => 0,
				'batch'         => 0,
				'order_ids'     => array_values( $ids ),
				'last_order_id' => null,
				'errors'        => [],
				'started_at'    => current_time( 'mysql' ),
			] );
			wp_send_json_success( [ 'use_batch' => true, 'total' => count( $ids ) ] );
		}

		// Single order — run immediately
		$order_id = $ids[0];
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error( "Order #{$order_id} not found." );
		}

		try {
			$this->make_sync_manager()->sync_order( $order_id );
			wp_send_json_success( [ 'use_batch' => false, 'message' => "Order #{$order_id} synced successfully." ] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	// ──────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────

	/**
	 * Build a Sync_Manager using current plugin settings.
	 * CRM adapter injected here — swap adapter class to change CRM.
	 */
	private function make_sync_manager(): Sync_Manager {
		return new Sync_Manager(
			new HubSpot_Adapter( Settings::get_token() ),
			new Order_Extractor()
		);
	}

	private function fetch_all_order_ids(): array {
		return wc_get_orders( [
			'limit'   => -1,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'return'  => 'ids',
			'type'    => 'shop_order',
			'status'  => 'any',
		] );
	}

	private function get_status(): array {
		return get_option( 'wc_hs_importer_status', $this->default_status() );
	}

	private function save_status( array $status ): void {
		update_option( 'wc_hs_importer_status', $status, false );
	}

	private function default_status(): array {
		return [
			'status'        => 'idle',
			'total'         => 0,
			'processed'     => 0,
			'synced'        => 0,
			'updated'       => 0,
			'failed'        => 0,
			'batch'         => 0,
			'order_ids'     => [],
			'last_order_id' => null,
			'errors'        => [],
			'started_at'    => null,
		];
	}

	private function check_nonce(): void {
		check_ajax_referer( 'wc_hs_importer', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized', 403 );
		}
	}
}
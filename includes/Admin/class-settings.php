<?php
namespace WC_HS_Sync\Admin;

defined( 'ABSPATH' ) || exit;

class Settings {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function add_menu(): void {
        add_submenu_page(
            'woocommerce',
            'HubSpot Sync',
            'HubSpot Sync',
            'manage_woocommerce',
            'wc-hubspot-sync',
            [ $this, 'render_page' ]
        );
    }

    public function register_settings(): void {
        register_setting( 'wc_hs_sync', 'wc_hs_sync_token',    [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'wc_hs_sync', 'wc_hs_sync_pipeline', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'wc_hs_sync', 'wc_hs_sync_notify_email', [ 'sanitize_callback' => 'sanitize_email' ] );
        register_setting( 'wc_hs_sync', 'wc_hs_sync_stage_map' );
    }

    public function render_page(): void {
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
        ?>
        <div class="wrap">
            <h1 style="display:flex;align-items:center;gap:10px;">
                 WooCommerce → HubSpot Sync
            </h1>

            <!-- Tab navigation -->
            <nav class="nav-tab-wrapper woo-nav-tab-wrapper" style="margin-bottom:0;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-hubspot-sync&tab=settings' ) ); ?>"
                   class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    Settings
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-hubspot-sync&tab=import' ) ); ?>"
                   class="nav-tab <?php echo $active_tab === 'import' ? 'nav-tab-active' : ''; ?>">
                    Import Orders
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-hubspot-sync&tab=logs' ) ); ?>"
                   class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    Logs
                </a>
            </nav>

            <div style="background:#fff;border:1px solid #c3c4c7;border-top:none;padding:20px 24px;border-radius:0 0 4px 4px;">
                <?php
                if ( $active_tab === 'logs' ) {
                    ( new Log_Viewer() )->render();
                } elseif ( $active_tab === 'import' ) {
                    ( new Historical_Importer() )->render();
                } else {
                    $this->render_settings_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function render_settings_tab(): void {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'wc_hs_sync' ); ?>
			
            <table class="form-table">
                <tr>
                    <th>HubSpot Private App Token</th>
                    <td>
                        <input type="password" name="wc_hs_sync_token"
                               value="<?php echo esc_attr( get_option( 'wc_hs_sync_token' ) ); ?>"
                               class="regular-text" autocomplete="off" />
                        <p class="description">
                            Generate at HubSpot → Development → Legacy apps → Inside legacy apps → Auth → Access token
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>Pipeline ID</th>
                    <td>
                        <input type="text" name="wc_hs_sync_pipeline"
                               value="<?php echo esc_attr( get_option( 'wc_hs_sync_pipeline', 'default' ) ); ?>"
                               class="regular-text" />
                        <p class="description">
                            The internal HubSpot pipeline ID (e.g. <code>ecommerce</code>).
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th>Failure Notification Email</th>
                    <td>
                        <input type="email" name="wc_hs_sync_notify_email"
                               value="<?php echo esc_attr( get_option( 'wc_hs_sync_notify_email' ) ); ?>"
                               class="regular-text" placeholder="e.g. board+card@trello.com" />
                        <p class="description">
                            Sync failures will be emailed here. Leave blank to disable. 
                            Historical import errors are never emailed.
                        </p>
                    </td>
                </tr>

                <tr>
                    <th>Order Status → Deal Stage Mapping</th>
                    <td>
                        <?php
                        $stage_map = \WC_HS_Sync\Settings::get_stage_map();
                        $statuses  = wc_get_order_statuses();
                        foreach ( $statuses as $slug => $label ) :
                            $key = str_replace( 'wc-', '', $slug );
                            $val = $stage_map[ $key ] ?? '';
                        ?>
                        <div style="margin-bottom:10px;display:flex;align-items:center;gap:10px;">
                            <label style="width:160px;color:#50575e;">
                                <?php echo esc_html( $label ); ?>
                            </label>
                            <input type="text"
                                   name="wc_hs_sync_stage_map[<?php echo esc_attr( $key ); ?>]"
                                   value="<?php echo esc_attr( $val ); ?>"
                                   placeholder="HubSpot stage ID"
                                   style="width:260px;" />
                        </div>
                        <?php endforeach; ?>
                        <p class="description">Enter the HubSpot internal stage ID for each WooCommerce order status.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>		
						
			 <!-- ── Required HubSpot Properties Note ── -->
			<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:14px 18px;margin-bottom:20px;border-radius:0 4px 4px 0;max-width:700px;">
				<strong style="display:block;margin-bottom:10px;font-size:13px;color:#1d2327;">
					Required HubSpot Properties
				</strong>
				<p style="margin:0 0 10px;color:#50575e;font-size:13px;">
					Before using this plugin, create the following custom properties in HubSpot:
				</p>

				<strong style="font-size:12px;text-transform:uppercase;color:#2271b1;letter-spacing:.04em;">Deal Properties</strong>
				<ul style="margin:4px 0 10px 16px;font-size:13px;color:#50575e;">
					<li><code>wc_order_id</code> — Single-line text</li>
					<li><code>order_items</code> — Multi-line text</li>
				</ul>

				<strong style="font-size:12px;text-transform:uppercase;color:#2271b1;letter-spacing:.04em;">Contact Properties</strong>
				<ul style="margin:4px 0 10px 16px;font-size:13px;color:#50575e;">
					<li><code>shipping_phone</code> — Phone</li>
				</ul>

				<strong style="font-size:12px;text-transform:uppercase;color:#2271b1;letter-spacing:.04em;">Company Properties</strong>
				<ul style="margin:4px 0 0 16px;font-size:13px;color:#50575e;">
					<li><code>box_size</code> — Single-line text</li>
					<li><code>mehrwegkiste</code> "permanent_box" — Boolean checkbox</li>
					<li><code>internal_note</code> — Multi-line text</li>
					<li><code>billing_email</code> — Email</li>
					<li><code>billing_address</code> — Single-line text</li>
					<li><code>billing_address2</code> — Single-line text</li>
					<li><code>billing_city</code> — Single-line text</li>
					<li><code>billing_zip</code> — Single-line text</li>
				</ul>
				
				<strong style="margin-top: 10px; font-size:12px;text-transform:uppercase;color:#2271b1;letter-spacing:.04em;"> Scopes Required in legacy app</strong>

	<p> HubSpot → Development → Legacy apps → Create Legacy app → private → Give any name → Under scope choose the following scopes → click create app.</p>
				<ul style="margin:4px 0 0 16px;font-size:13px;color:#50575e;">
					<li><code>crm.objects.contacts.write</code> </li>
					<li><code>crm.objects.companies.write</code></li>
					<li><code>crm.objects.companies.read </code></li>
					<li><code>crm.objects.deals.read</code></li>
					<li><code>crm.objects.deals.write</code></li>
					<li><code>crm.objects.contacts.read</code></li>
				</ul>
				
			</div>
			
        </form>
        <?php
    }
}
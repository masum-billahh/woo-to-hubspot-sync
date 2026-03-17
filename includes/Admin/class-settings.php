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
		register_setting( 'wc_hs_sync', 'wc_hs_sync_stage_map' );
    }

    public function render_page(): void {
        ?>
        <div class="wrap">
            <h1>WooCommerce → HubSpot Sync</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'wc_hs_sync' ); ?>
                <table class="form-table">
                    <tr>
                        <th>HubSpot Private App Token</th>
                        <td>
                            <input type="password" name="wc_hs_sync_token"
                                   value="<?php echo esc_attr( get_option( 'wc_hs_sync_token' ) ); ?>"
                                   class="regular-text" autocomplete="off" />
                        </td>
                    </tr>
                    <tr>
                        <th>Pipeline ID</th>
                        <td>
                            <input type="text" name="wc_hs_sync_pipeline"
                                   value="<?php echo esc_attr( get_option( 'wc_hs_sync_pipeline', 'default' ) ); ?>"
                                   class="regular-text" />
                        </td>
                    </tr>
					
					<tr>
						<th>Order Status → Deal Stage Mapping</th>
						<td>
							<?php
							$stage_map = \WC_HS_Sync\Settings::get_stage_map();
							$statuses  = wc_get_order_statuses(); 
							foreach ( $statuses as $slug => $label ) :
								$key = str_replace( 'wc-', '', $slug ); // strip 'wc-' prefix
								$val = $stage_map[ $key ] ?? '';
							?>
							<div style="margin-bottom:6px;">
								<label style="display:inline-block;width:160px;"><?php echo esc_html( $label ); ?></label>
								<input type="text"
									   name="wc_hs_sync_stage_map[<?php echo esc_attr( $key ); ?>]"
									   value="<?php echo esc_attr( $val ); ?>"
									   placeholder="HubSpot stage ID"
									   style="width:260px;" />
							</div>
							<?php endforeach; ?>
						</td>
					</tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

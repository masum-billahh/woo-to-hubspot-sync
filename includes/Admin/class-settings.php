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
                    ⚙️ Settings
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-hubspot-sync&tab=logs' ) ); ?>"
                   class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    📋 Logs
                </a>
            </nav>

            <div style="background:#fff;border:1px solid #c3c4c7;border-top:none;padding:20px 24px;border-radius:0 0 4px 4px;">
                <?php
                if ( $active_tab === 'logs' ) {
                    ( new Log_Viewer() )->render();
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
                            Generate at HubSpot → Settings → Integrations → Private Apps.
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
        </form>
        <?php
    }
}
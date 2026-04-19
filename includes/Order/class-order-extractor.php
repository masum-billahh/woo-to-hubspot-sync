<?php
namespace WC_HS_Sync\Order;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts all order data into a plain DTO array.
 * No CRM logic here — only WooCommerce data access.
 */
class Order_Extractor {

    /**
     * @param int|\WC_Order $order_or_id
     * @return array|null  Null when order not found.
     */
    public function extract( $order_or_id ): ?array {
        $order = $order_or_id instanceof \WC_Order
            ? $order_or_id
            : wc_get_order( $order_or_id );

        if ( ! $order ) return null;

        $shipping_email = $order->get_meta( '_shipping_email', true );
        $billing_email  = $order->get_billing_email();
        $billing_email  = ( $billing_email && strtolower( $billing_email ) !== strtolower( $shipping_email ) )
            ? $billing_email
            : null; // ignore if empty or same as shipping

        // Determine deal name priority:
        // 1. Shipping company  2. Shipping name  3. Billing name
        $shipping_company = $order->get_shipping_company();
        $shipping_name    = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
        $billing_name     = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

        $deal_name_prefix = $shipping_company
            ?: ( $shipping_name ?: $billing_name );

        $deal_name = sprintf( '%s - #%d', $deal_name_prefix, $order->get_id() );

        // Items for the structured order text field
        $items = [];
        foreach ( $order->get_items() as $item ) {
            /** @var \WC_Order_Item_Product $item */
            $product = $item->get_product();
            $items[] = [
                'name'     => $item->get_name(),
                'sku'      => $product ? $product->get_sku() : '',
                'qty'      => $item->get_quantity(),
                'price'    => $item->get_total(),
            ];
        }

        $order_text = self::build_order_text( $order, $items );

        // Date fields
        $created_at   = $order->get_date_created();
        $completed_at = $order->get_date_completed();

        return [
            // Contact
            'contact_email'    => $shipping_email ?: $billing_email ?: $order->get_billing_email(),
            'contact_first'    => $order->get_billing_first_name(),
            'contact_phone'    => $order->get_billing_phone(),

            // Billing email (only when different from shipping)
            'billing_email'    => $billing_email,

            // Company
            'shipping_company' => $shipping_company,

            // Deal
            'deal_name'        => $deal_name,
            'order_id'         => $order->get_id(),
            'order_status'     => $order->get_status(),
            'order_total'      => $order->get_total(),
            'order_text'       => $order_text,
            'created_timestamp'   => $created_at  ? $created_at->getTimestamp()  * 1000 : null,
            'completed_timestamp' => $completed_at ? $completed_at->getTimestamp() * 1000 : null,

            // Company properties from order meta
            'box_size'         => $order->get_meta( '_shipping_shipping_box', true ),
            'permanent_box'    => $order->get_meta( '_shipping_send_collection_box', true ),
            'internal_note'    => $order->get_meta( '_shipping_interne_notiz', true ),

            // Language
            'language'         => $order->get_meta( 'wpml_language', true )
                                   ?: get_bloginfo( 'language' ),

            // Batch number
            'batch_number'     => $order->get_meta( '_batch_number', true ),
			
			//shipping address
			'shipping_address_1' => $order->get_shipping_address_1(),
			'shipping_address_2' => $order->get_shipping_address_2(),
			'shipping_city'      => $order->get_shipping_city(),
			'shipping_postcode'  => $order->get_shipping_postcode(),
			'shipping_state'     => $order->get_shipping_state(),
			'shipping_country'   => $order->get_shipping_country(),
			'shipping_phone' 	 => $order->get_shipping_phone(),
			
			//billing address
			'billing_address_1'  => $order->get_billing_address_1(),
			'billing_address_2'  => $order->get_billing_address_2(),
			'billing_city'       => $order->get_billing_city(),
			'billing_postcode'   => $order->get_billing_postcode(),
        ];
    }

    private static function build_order_text( \WC_Order $order, array $items ): string {
        $lines = [];
		$i = 1;

		foreach ( $items as $item ) {
			$lines[] = sprintf(
				"Product %d:\nName: %s\nSKU: %s\nQty: %d\nPrice: %s",
				$i++,
				$item['name'],
				$item['sku'] ?: 'N/A',
				$item['qty'],
				html_entity_decode( wp_strip_all_tags( wc_price( $item['price'] ) ) )
			);
		}
		
		$shipping_items = $order->get_shipping_methods();
		if ( ! empty( $shipping_items ) ) {
			$shipping_total = $order->get_shipping_total();

			$lines[] = sprintf(
				"Shipping:\nMethod: %s\nCost: %s",
				reset( $shipping_items )->get_name(),
				html_entity_decode( wp_strip_all_tags( wc_price( $shipping_total ) ) )
			);
		}

        $completed_at = $order->get_date_completed();
        $shipping_name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
        $language      = $order->get_meta( 'wpml_language', true ) ?: get_bloginfo( 'language' );
        $batch_number  = $order->get_meta( '_batch_number', true );

        return implode( "\n", [
            '=== Order #' . $order->get_id() . ' ===',
            'Shipping To: ' . ( $shipping_name ?: $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'Language:    ' . $language,
            'Batch:       ' . ( $batch_number ?: 'N/A' ),
            '',
            'Ordered Items:',
            implode( "\n\n", $lines ),
            '',
            'Order Total: ' . html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total() ) ) ),
        ] );
    }
}

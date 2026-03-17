<?php
namespace WC_HS_Sync\CRM;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for any CRM adapter.
 * Swap HubSpot for another CRM by implementing this interface.
 */
interface CRM_Adapter_Interface {

    /**
     * Find a contact by email; returns CRM contact ID or null.
     */
    public function find_contact( string $email ): ?string;

    /**
     * Get the primary company associated with a contact.
     * Returns [ 'id' => '...', 'name' => '...', 'owner_id' => '...' ] or null.
     */
    public function get_primary_company( string $contact_id ): ?array;

    /**
     * Update properties on a company (must already exist).
     */
    public function update_company( string $company_id, array $properties ): bool;

    /**
     * Find an existing deal by WooCommerce order ID meta.
     * Returns deal CRM ID or null.
     */
    public function find_deal_by_order_id( int $order_id ): ?string;

    /**
     * Create a deal; returns its CRM ID or null on failure.
     */
    public function create_deal( array $properties ): ?string;

    /**
     * Update an existing deal's properties.
     */
    public function update_deal( string $deal_id, array $properties ): bool;

    /**
     * Delete a deal permanently.
     */
    public function delete_deal( string $deal_id ): bool;

    /**
     * Associate a deal with a contact.
     */
    public function associate_deal_contact( string $deal_id, string $contact_id ): bool;

    /**
     * Associate a deal with a company.
     */
    public function associate_deal_company( string $deal_id, string $company_id ): bool;

    /**
     * Associate a deal with an owner (HubSpot user id).
     */
    public function set_deal_owner( string $deal_id, string $owner_id ): bool;
}

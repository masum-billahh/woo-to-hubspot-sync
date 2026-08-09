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
	 * Create a contact; returns CRM contact ID or null.
	 */
	public function create_contact( array $properties ): ?string;

	/**
	 * Update properties on an existing contact.
	 */
	public function update_contact( string $contact_id, array $properties ): bool;

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

    public function get_contact_from_deal( string $deal_id ): ?string;

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
    
    /**
     * Tickets
     */
    public function get_ticket_subject( string $ticket_id ): ?string;
    public function ticket_has_deal_association( string $ticket_id ): bool;
    public function associate_ticket_deal( string $ticket_id, string $deal_id ): bool;
    public function add_ticket_note( string $ticket_id, string $note_body ): bool;
    
    public function add_deal_note( string $deal_id, string $note_body ): bool;
    
    /**
     * Find a HubSpot owner ID by their user email. Returns null if no match.
     */
    public function find_owner_by_email( string $email ): ?string;
    
    //Notion
    public function find_ticket_by_notion_page( string $notion_page_id ): ?string;
    public function create_ticket( array $properties ): ?string;
    public function update_ticket( string $ticket_id, array $properties ): bool;
    public function delete_ticket( string $ticket_id ): bool;   // ← add this line
    public function associate_ticket_contact( string $ticket_id, string $contact_id ): bool;
    public function associate_ticket_company( string $ticket_id, string $company_id ): bool;
}
<?php
namespace WC_HS_Sync\CRM;

defined( 'ABSPATH' ) || exit;

/**
 * All HubSpot API calls live here.
 * Uses CRM v3 + Associations v4.
 */
class HubSpot_Adapter implements CRM_Adapter_Interface {

    private string $token;
    private string $base = 'https://api.hubapi.com';
	private \WC_Logger_Interface $logger;

    public function __construct( string $token ) {
        $this->token = $token;
		$this->logger = wc_get_logger();
    }

    // ──────────────────────────────────────────────
    // Contact
    // ──────────────────────────────────────────────

    public function find_contact( string $email ): ?string {
        $res = $this->post( '/crm/v3/objects/contacts/search', [
            'filterGroups' => [ [
                'filters' => [ [
                    'propertyName' => 'email',
                    'operator'     => 'EQ',
                    'value'        => $email,
                ] ],
            ] ],
            'properties' => [ 'email' ],
            'limit'      => 1,
        ] );

        return $res['results'][0]['id'] ?? null;
    }
    
    public function create_contact( array $properties ): ?string {
		$res = $this->post( '/crm/v3/objects/contacts', [
			'properties' => $properties,
		] );
		return $res['id'] ?? null;
	}

	public function update_contact( string $contact_id, array $properties ): bool {
		$res = $this->patch( "/crm/v3/objects/contacts/{$contact_id}", [
			'properties' => $properties,
		] );
		return ! empty( $res['id'] );
	}

    // ──────────────────────────────────────────────
    // Company
    // ──────────────────────────────────────────────

    public function get_primary_company( string $contact_id ): ?array {
		$res = $this->get(
			"/crm/v3/objects/contacts/{$contact_id}/associations/companies",
			[ 'limit' => 10 ]
		);

		$this->logger->info(
			'[HS Sync] Company assoc raw: ' . wp_json_encode( $res ), 
			[ 'source' => 'hubspot-sync' ] 
		);

		$results = $res['results'] ?? [];
		if ( empty( $results ) ) return null;

		// Handle both v3 flat format and v4 nested format
		$primary_id = null;
		foreach ( $results as $assoc ) {
			// New v4 format
			if ( isset( $assoc['toObjectId'] ) ) {
				foreach ( $assoc['associationTypes'] ?? [] as $type ) {
					if (
						( $type['category'] ?? '' ) === 'HUBSPOT_DEFINED' &&
						( $type['typeId']   ?? 0  ) === 1
					) {
						$primary_id = $assoc['toObjectId'];
						break 2;
					}
				}
				// Fallback for v4 without matching typeId
				if ( ! $primary_id ) {
					$primary_id = $assoc['toObjectId'];
				}
			}
			// Old v3 flat format — just grab the id directly
			elseif ( isset( $assoc['id'] ) ) {
				$primary_id = $assoc['id'];
				break;
			}
		}

		if ( ! $primary_id ) return null;

		$company = $this->get( "/crm/v3/objects/companies/{$primary_id}", [
			'properties' => 'hubspot_owner_id,name',
		] );

		if ( empty( $company['id'] ) ) return null;

		return [
			'id'       => $company['id'],
			'name'     => $company['properties']['name'] ?? '',
			'owner_id' => $company['properties']['hubspot_owner_id'] ?? null,
		];
	}

    public function update_company( string $company_id, array $properties ): bool {
        $res = $this->patch( "/crm/v3/objects/companies/{$company_id}", [
            'properties' => $properties,
        ] );
        return ! empty( $res['id'] );
    }

    // ──────────────────────────────────────────────
    // Deals
    // ──────────────────────────────────────────────

    public function find_deal_by_order_id( int $order_id ): ?string {
        $res = $this->post( '/crm/v3/objects/deals/search', [
            'filterGroups' => [ [
                'filters' => [ [
                    'propertyName' => 'wc_order_id',
                    'operator'     => 'EQ',
                    'value'        => (string) $order_id,
                ] ],
            ] ],
            'properties' => [ 'wc_order_id' ],
            'limit'      => 1,
        ] );
        return $res['results'][0]['id'] ?? null;
    }
    
    public function get_contact_from_deal( string $deal_id ): ?string {
		$response = $this->get(
			"/crm/v3/objects/deals/{$deal_id}/associations/contacts"
		);

		return $response['results'][0]['id'] ?? null;
	}

    public function create_deal( array $properties ): ?string {
        $res = $this->post( '/crm/v3/objects/deals', [
            'properties' => $properties,
        ] );
        return $res['id'] ?? null;
    }

    public function update_deal( string $deal_id, array $properties ): bool {
        $res = $this->patch( "/crm/v3/objects/deals/{$deal_id}", [
            'properties' => $properties,
        ] );
        return ! empty( $res['id'] );
    }

    public function delete_deal( string $deal_id ): bool {
        $res = $this->request( 'DELETE', "/crm/v3/objects/deals/{$deal_id}" );
        return $res === null || $res === true; // 204 No Content = success
    }

    // ──────────────────────────────────────────────
    // Associations
    // ──────────────────────────────────────────────

    public function associate_deal_contact( string $deal_id, string $contact_id ): bool {
        // v4 associations API — typeId 3 = deal→contact (standard)
        $res = $this->put(
            "/crm/v4/objects/deals/{$deal_id}/associations/contacts/{$contact_id}",
            [ [ 'associationCategory' => 'HUBSPOT_DEFINED', 'associationTypeId' => 3 ] ]
        );
        return ! isset( $res['status'] ) || $res['status'] !== 'error';
    }

    public function associate_deal_company( string $deal_id, string $company_id ): bool {
        // typeId 5 = deal→company (standard)
        $res = $this->put(
            "/crm/v4/objects/deals/{$deal_id}/associations/companies/{$company_id}",
            [ [ 'associationCategory' => 'HUBSPOT_DEFINED', 'associationTypeId' => 5 ] ]
        );
        return ! isset( $res['status'] ) || $res['status'] !== 'error';
    }

    public function set_deal_owner( string $deal_id, string $owner_id ): bool {
        return $this->update_deal( $deal_id, [ 'hubspot_owner_id' => $owner_id ] );
    }

    // ──────────────────────────────────────────────
    // HTTP helpers
    // ──────────────────────────────────────────────

    private function headers(): array {
        return [
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type'  => 'application/json',
        ];
    }

    private function get( string $path, array $query = [] ): ?array {
        $url = $this->base . $path;
        if ( $query ) $url = add_query_arg( $query, $url );
        $res = wp_remote_get( $url, [ 'headers' => $this->headers(), 'timeout' => 15 ] );
        return $this->decode( $res );
    }

    private function post( string $path, array $body ): ?array {
        $res = wp_remote_post( $this->base . $path, [
            'headers' => $this->headers(),
            'body'    => wp_json_encode( $body ),
            'timeout' => 15,
        ] );
        return $this->decode( $res );
    }

    private function patch( string $path, array $body ): ?array {
        return $this->request( 'PATCH', $path, $body );
    }

    private function put( string $path, array $body ): ?array {
        return $this->request( 'PUT', $path, $body );
    }

    private function request( string $method, string $path, array $body = [] ): ?array {
        $args = [
            'method'  => $method,
            'headers' => $this->headers(),
            'timeout' => 15,
        ];
        if ( $body ) $args['body'] = wp_json_encode( $body );

        $res = wp_remote_request( $this->base . $path, $args );
        return $this->decode( $res );
    }

    private function decode( $response ): ?array {
        if ( is_wp_error( $response ) ) {
            wc_get_logger()->error(
                '[HS Sync] HTTP error: ' . $response->get_error_message(),
                [ 'source' => 'hubspot-sync' ]
            );
            return null;
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code === 204 ) return []; // No Content — success with no body

        $decoded = json_decode( $body, true );

        if ( $code >= 400 ) {
            wc_get_logger()->error(
                "[HS Sync] API error {$code}: " . $body,
                [ 'source' => 'hubspot-sync' ]
            );
            return null;
        }
        return $decoded ?? [];
    }
}
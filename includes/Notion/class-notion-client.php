<?php
namespace WC_HS_Sync\Notion;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal Notion API client — reads a page's properties and a comment's
 * text for the Notion → HubSpot ticket sync.
 *
 */
class Notion_Client {

    private string $token;
    private string $base = 'https://api.notion.com/v1';
    private \WC_Logger_Interface $logger;

    public function __construct( string $token ) {
        $this->token  = $token;
        $this->logger = wc_get_logger();
    }

    public function get_page( string $page_id ): ?array {
        return $this->get( "/pages/{$page_id}" );
    }

    public function get_comment( string $comment_id ): ?array {
        return $this->get( "/comments/{$comment_id}" );
    }

    private function get( string $path ): ?array {
        $res = wp_remote_get( $this->base . $path, [
            'headers' => [
                'Authorization'  => 'Bearer ' . $this->token,
                'Notion-Version' => '2026-03-11',
            ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $res ) ) {
            $this->logger->error( '[Notion Sync] HTTP error: ' . $res->get_error_message(), [ 'source' => 'notion-sync' ] );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $res );
        $body = json_decode( wp_remote_retrieve_body( $res ), true );

        if ( $code >= 400 ) {
            $this->logger->error( "[Notion Sync] API error {$code}: " . wp_remote_retrieve_body( $res ), [ 'source' => 'notion-sync' ] );
            return null;
        }

        return $body ?? [];
    }

    /** Extract readable text from a Notion property regardless of its type. */
    public static function property_to_text( ?array $property ): string {
        if ( ! $property ) return '';

        switch ( $property['type'] ?? '' ) {
            case 'title':     return self::rich_text_to_plain( $property['title'] ?? [] );
            case 'rich_text': return self::rich_text_to_plain( $property['rich_text'] ?? [] );
            case 'number':    return $property['number'] !== null ? (string) $property['number'] : '';
            case 'url':       return (string) ( $property['url'] ?? '' );
            case 'select':    return (string) ( $property['select']['name'] ?? '' );
            case 'status':    return (string) ( $property['status']['name'] ?? '' );
            default:          return '';
        }
    }

    private static function rich_text_to_plain( array $rich_text ): string {
        return implode( '', array_map( fn( $t ) => $t['plain_text'] ?? '', $rich_text ) );
    }

    /** Finds the property whose type is "title" — the key name varies per database. */
    public static function get_title_property( array $properties ): string {
        foreach ( $properties as $prop ) {
            if ( ( $prop['type'] ?? '' ) === 'title' ) {
                return self::property_to_text( $prop );
            }
        }
        return '';
    }
}
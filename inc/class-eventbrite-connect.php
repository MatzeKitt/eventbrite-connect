<?php
namespace KittMedia\Eventbrite_Connect;

/**
 * Get Events from Eventbrite via API to a custom post type.
 * 
 * @author		KittMedia
 * @license		GPL2
 * @package		KittMedia\Eventbrite_Connect;
 * @version		0.1
 */
class Eventbrite_Connect {
	/**
	 * @var		string The full path to the main plugin file
	 */
	public $plugin_file = '';
	
	/**
	 * Eventbrite_Connect constructor.
	 * 
	 * @param	string		$plugin_file The path of the main plugin file
	 */
	public function __construct( $plugin_file ) {
		// assign variables
		$this->plugin_file = $plugin_file;
	}
	
	/**
	 * Initial load of the class.
	 */
	public final function load() {
		// hook into cron
		\add_action( 'eventbrite_connect_hourly_cron_hook', [ $this, 'event_hourly_cron' ] );
		// activate|deactivate cron
		\register_activation_hook( $this->plugin_file, [ $this, 'hourly_cron_activation' ] );
		\register_deactivation_hook( $this->plugin_file, [ $this, 'hourly_cron_deactivation' ] );
		// set textdomain
		\add_action( 'init', [ $this, 'load_textdomain' ] );
		// register post type
		\add_action( 'init', [ $this, 'register_post_type' ] );
		\add_action( 'init', [ $this, 'register_post_meta' ] );
	}
	
	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		\load_plugin_textdomain( 'eventbrite-connect', false, \dirname( \plugin_basename( $this->plugin_file ) ) . '/languages' );
	}
	
	/**
	 * Activate the hourly cron.
	 */
	public function hourly_cron_activation() {
		if ( ! \wp_next_scheduled( 'eventbrite_connect_hourly_cron_hook' ) ) {
			\wp_schedule_event( time(), 'hourly', 'eventbrite_connect_hourly_cron_hook' );
		}
	}
	
	/**
	 * Deactivate the hourly cron.
	 */
	public function hourly_cron_deactivation() {
		if ( \wp_next_scheduled( 'eventbrite_connect_hourly_cron_hook' ) ) {
			\wp_clear_scheduled_hook( 'eventbrite_connect_hourly_cron_hook' );
		}
	}
	
	/**
	 * Delete all Eventbrite events including their post meta.
	 * 
	 * Run this only in cron mode as it may take a while.
	 */
	private function delete_events() {
		global $wpdb;
		
		// delete all posts by post type
		$sql = "DELETE		post,
							meta
				FROM		" . $wpdb->prefix . "posts AS post
				LEFT JOIN	" . $wpdb->prefix . "postmeta AS meta
				ON			meta.post_id = post.ID
				WHERE		post.post_type = 'events'";
		$result = $wpdb->query( $sql );
	}
	
	/**
	 * Get new events regularly.
	 */
	public function event_hourly_cron() {
		// delete old events
		$this->delete_events();
		
		// get new events
		$events = $this->get_events();
		
		// stop if there are no events
		if ( $events === false ) return;
		
		foreach ( $events->events as &$event ) {
			// get ticket information
			$event = $this->get_event_information( $event, 'ticket_availability' );
			$post_args = [
				'post_title' => $event->name->text,
				'post_status' => 'publish',
				// TODO: Enable
				// 'post_status' => ( $event->status !== 'draft' ? 'publish' : 'draft' ),
				'post_type' => 'events',
				'meta_input' => [
					// TODO: Store locally
					'eventbrite_event_cover' => $event->logo->url,
					'eventbrite_event_datetime_end' => \date( 'd.m.Y H:i', \strtotime( $event->end->local ) ),
					'eventbrite_event_datetime_start' => \date( 'd.m.Y H:i', \strtotime( $event->start->local ) ),
					'eventbrite_event_is_free' => $event->is_free,
					'eventbrite_event_price_max' => $event->ticket_availability->maximum_ticket_price->major_value,
					'eventbrite_event_price_min' => $event->ticket_availability->minimum_ticket_price->major_value,
					'eventbrite_event_price_currency' => $event->currency,
					'eventbrite_event_timestamp' => \strtotime( $event->start->local ),
					'eventbrite_event_url' => $event->url,
				],
			];
			
			// add post
			$post_id = \wp_insert_post( $post_args );
		}
		
		die( '<pre>' . print_r( $events, true ) );
	}
	
	private function get_event_information( $event, $information = '' ) {
		$url = 'https://www.eventbriteapi.com/v3/events/' . $event->id . '/' . ( $information ? '?expand=' . $information : '' );
		$event_information = $this->request( $url );
		
		// stop here if there is no additional event information
		if ( $event_information === false ) return $event;
		
		// the API contains the full event object with the additional information
		return $event_information;
	}
	
	/**
	 * Get events from the Eventbrite API.
	 * 
	 * @return	array|false
	 */
	private function get_events() {
		$url = 'https://www.eventbriteapi.com/v3/users/me/events/';
		
		return $this->request( $url );
	}
	
	/**
	 * Request the Eventbrite API.
	 * 
	 * @param	string		$url The URL to request
	 * @return	array|false
	 */
	private function request( $url ) {
		$request = \wp_remote_get( $url, [
			'headers' => [
				'Authorization' => 'Bearer ' . \EVENTBRITE_CONNECT_TOKEN,
			],
		] );
		$response = \wp_remote_retrieve_body( $request );
		
		// return if response is no valid JSON
		if ( ! self::is_json( $response ) ) return false;
		
		$json = \json_decode( $response );
		
		// return if response contains errors
		if ( ! empty( $json->errors ) ) {
			\error_log( 'Request: ' . $url . \PHP_EOL );
			\error_log( print_r( $json, true ) );
			
			return false;
		}
		
		return $json;
	}
	
	/**
	 * Check if given string is a valid JSON.
	 * 
	 * @param	string		$string
	 * @return	bool
	 */
	protected static function is_json( string $string ) {
		if ( ! \is_string( $string ) ) return false;
		
		\json_decode( $string );
		
		return ( \json_last_error() === JSON_ERROR_NONE );
	}
	/**
	 * Register the needed post meta.
	 */
	public function register_post_meta() {
		\register_post_meta(
			'events',
			'eventbrite_event_cover',
			[
				'type' => 'string',
				'description' => __( '', 'eventbrite-connect' ), // TODO: Add description
				'single' => true,
				'show_in_rest' => true,
			]
		);
		\register_post_meta(
			'events',
			'eventbrite_event_datetime_end',
			[
				'type' => 'string',
				'description' => __( '', 'eventbrite-connect' ), // TODO: Add description
				'single' => true,
				'show_in_rest' => true,
			]
		);
		\register_post_meta(
			'events',
			'eventbrite_event_datetime_start',
			[
				'type' => 'string',
				'description' => __( '', 'eventbrite-connect' ), // TODO: Add description
				'single' => true,
				'show_in_rest' => true,
			]
		);
		\register_post_meta(
			'events',
			'eventbrite_event_is_free',
			[
				'type' => 'boolean',
				'description' => __( '', 'eventbrite-connect' ), // TODO: Add description
				'single' => true,
				'show_in_rest' => true,
			]
		);
		\register_post_meta(
			'events',
			'eventbrite_event_price_max',
			[
				'type' => 'float',
				'description' => __( '', 'eventbrite-connect' ), // TODO: Add description
				'single' => true,
				'show_in_rest' => true,
			]
		);
		\register_post_meta(
			'events',
			'eventbrite_event_price_min',
			[
				'type' => 'float',
				'description' => __( '', 'eventbrite-connect' ), // TODO: Add description
				'single' => true,
				'show_in_rest' => true,
			]
		);
		\register_post_meta(
			'events',
			'eventbrite_event_price_currency',
			[
				'type' => 'string',
				'description' => __( '', 'eventbrite-connect' ), // TODO: Add description
				'single' => true,
				'show_in_rest' => true,
			]
		);
		\register_post_meta(
			'events',
			'eventbrite_event_timestamp',
			[
				'type' => 'integer',
				'description' => __( '', 'eventbrite-connect' ), // TODO: Add description
				'single' => true,
				'show_in_rest' => true,
			]
		);
		\register_post_meta(
			'events',
			'eventbrite_event_url',
			[
				'type' => 'string',
				'description' => __( '', 'eventbrite-connect' ), // TODO: Add description
				'single' => true,
				'show_in_rest' => true,
			]
		);
	}
	
	/**
	 * Register the post type.
	 */
	public function register_post_type() {
		$post_type_args = [
			'labels' => [
				'name' => \esc_html__( 'Eventbrite events', 'eventbrite-connect' ),
				'singular_name' => \esc_html__( 'Eventbrite event', 'eventbrite-connect' ),
				'menu_name' => \esc_html__( 'Eventbrite events', 'eventbrite-connect' ),
			],
			'public' => true,
			'show_ui' => false,
			'publicly_queryable' => true,
			'show_in_rest' => true,
			'has_archive' => true,
			'supports' => [
				'title',
				'custom-fields',
			],
			'rest_base' => 'events',
			'capability_type' => 'page',
		];
		
		\register_post_type( 'events', $post_type_args );
	}
}
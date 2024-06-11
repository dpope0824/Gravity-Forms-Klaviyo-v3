<?php

GFForms::include_feed_addon_framework();

class GFKlaviyoAPI extends GFFeedAddOn {

	protected $_version = GF_KLAVIYO_API_VERSION;
	protected $_min_gravityforms_version = '1.9.16';
	protected $_slug = 'klaviyoaddon';
	protected $_path = 'klaviyoaddon/klaviyoaddon.php';
	protected $_full_path = __FILE__;
	protected $_title = 'Gravity Forms Klaviyo Feed Add-On';
	protected $_short_title = 'Klaviyo';

	private static $_instance = null;

	/**
	 * Get an instance of this class.
	 * 
	 * @return GFKlaviyoAPI
	 */
	public static function get_instance() {
		if ( self::$_instance == null ) {
			self::$_instance = new GFKlaviyoAPI();
		}

		return self::$_instance;
	}

	/**
	 * Plugin starting point. Handles hooks, loading of language files and PayPal delayed payment support.
	 */
	public function init() {

		parent::init();

		$this->add_delayed_payment_support (
			array(
				'option_label' => esc_html__( 'Subscribe contact to service x only when payment is received.', 'klaviyoaddon' )
			)
		);

	}


	// # FEED PROCESSING -----------------------------------------------------------------------------------------------

	/**
	 * Process the feed e.g. subscribe the user to a list.
	 *
	 * @param array $feed The feed object to be processed.
	 * @param array $entry The entry object currently being processed.
	 * @param array $form The form object currently being processed.
	 *
	 * @return bool|void
	 */
	public function process_feed( $feed, $entry, $form ) {
		$feedName  = $feed['meta']['feedName'];
		$list_id = $feed['meta']['list'];

		// Retrieve the name => value pairs for all fields mapped in the 'mappedFields' field map.
		$field_map = $this->get_field_map_fields( $feed, 'mappedFields' );

		// Loop through the fields from the field map setting building an array of values to be passed to the third-party service.
		$merge_vars = array();
		foreach ( $field_map as $name => $field_id ) {

			// Get the field value for the specified field id
			$merge_vars[ $name ] = $this->get_field_value( $form, $entry, $field_id );

		}

		
		// Send the values to the third-party service.
        if ($this->get_plugin_setting('api_key')) {
            $tracker = new Klaviyo($this->get_plugin_setting('api_key'));

            $properties=array('$email' => $merge_vars['email'], '$first_name' => $merge_vars['first_name'], '$last_name' => $merge_vars['last_name'], '$organization' => $merge_vars['organization']);
            
            $properties=array_merge($properties, $merge_vars);
         
            $tracker->track (
                'Active on Site',
                $properties
				
			
                
            // array('Item SKU' => 'ABC123', 'Payment Method' => 'Credit Card'),
            // 1354913220
            );
        }
        
        if ($this->get_plugin_setting('private_api_key')) {
        	
        	$url = 'https://a.klaviyo.com/api/profile-import/';

			$api_klaviyo_key = $this->get_plugin_setting('api_key');
			$api_private_key = $this->get_plugin_setting('private_api_key');
			
			$post_data = [
					"data" => [
						"type" => "profile",
						"attributes" => [
							"email" => $merge_vars['email'],
							"first_name" => $merge_vars['first_name'],
							"last_name" => $merge_vars['last_name'],
							"organization" => $merge_vars['organization'],
						],
					],
				];
			
			$post_data_encoded = json_encode($post_data);
			
			$postArgs = array(
				'method' => 'POST',
				'headers' => [
					'Authorization' => 'Klaviyo-API-Key '.$api_private_key,
					'accept' => 'application/json',
					'content-type' => 'application/json',
					'revision' => '2024-05-15',
				],
				'body' => $post_data_encoded,
			);
			
        	$response = wp_safe_remote_post($url, $postArgs);
			
			//If the Klaviyo API returns a code anything other than OK, log it!
			if(!in_array($response['response']['code'], [200, 201], true) ) {
				$this->log_error( __METHOD__ . '(): Could not add user to mailing list not 200' );
				//$this->log_error( __METHOD__ . '(): response => ' . print_r( $response, true ) );
			}else{
				$this->log_error( __METHOD__ . '(): something else' );
				$responseIneed = json_decode($response['body'], true);
				//$this->log_error( __METHOD__ . '(): response => ' . print_r( $responseIneed, true ) );
				
				
				//
				//Run this to subscribe after creating or updating the profile
				//
				
				$url_subscribe = 'https://a.klaviyo.com/api/profile-subscription-bulk-create-jobs/';
			
						$post_data_subscribe = [
							"data" => [
								"type" => "profile-subscription-bulk-create-job",
								"attributes" => [
									"custom_source" => "GravityForms: " . $form['title'],
									"profiles" => [
										"data" => [
											[
												"type" => "profile",
												"attributes" => [
													"email" => $responseIneed['data']['attributes']['email'],
													'id'  => $responseIneed['data']['id'],
													"subscriptions" => [
														"email" => [
															"marketing" => ["consent" => "SUBSCRIBED"],
														],
													],
												],
											],
										],
									],
								],
								"relationships" => [
									"list" => ["data" => ["type" => "list", "id" => $list_id]],
								],
							],
						];


						$post_data_subscribe_encoded = json_encode($post_data_subscribe);

						$postArgsSubscribe = array(
							'method' => 'POST',
							'headers' => [
								'Authorization' => 'Klaviyo-API-Key '.$api_private_key,
								'accept' => 'application/json',
								'content-type' => 'application/json',
								'revision' => '2024-05-15',
							],
							'body' => $post_data_subscribe_encoded,
						);
						
					$this->log_error( __METHOD__ . '(): response => ' . print_r( $post_data_subscribe, true ) );
				
				
						$responseDeux = wp_safe_remote_post($url_subscribe, $postArgsSubscribe);

						//If the Klaviyo API returns a code anything other than OK, log it!
						if($responseDeux['response']['code'] != 202) {
							$this->log_error( __METHOD__ . '(): Could not add user to mailing list' );
							$this->log_error( __METHOD__ . '(): response => ' . print_r( $responseDeux, true ) );
							$this->log_error( __METHOD__ . '(): post_data => ' . print_r( $postArgsSubscribe, true ) );
						}


						}
        	
        }
	}

	/**
	 * Custom format the phone type field values before they are returned by $this->get_field_value().
	 *
	 * @param array $entry The Entry currently being processed.
	 * @param string $field_id The ID of the Field currently being processed.
	 * @param GF_Field_Phone $field The Field currently being processed.
	 *
	 * @return string
	 */
	public function get_phone_field_value( $entry, $field_id, $field ) {

		// Get the field value from the Entry Object.
		$field_value = rgar( $entry, $field_id );

		// If there is a value and the field phoneFormat setting is set to standard reformat the value.
		if ( ! empty( $field_value ) && $field->phoneFormat == 'standard' && preg_match( '/^\D?(\d{3})\D?\D?(\d{3})\D?(\d{4})$/', $field_value, $matches ) ) {
			$field_value = sprintf( '%s-%s-%s', $matches[1], $matches[2], $matches[3] );
		}

		return $field_value;
	}

	// # ADMIN FUNCTIONS -----------------------------------------------------------------------------------------------


	/**
	 * Configures the settings which should be rendered on the add-on settings tab.
	 *
	 * @return array
	 */
	 public function plugin_settings_fields() {
	 	return array(
	 		array(
	 			'title'  => esc_html__( 'Insert your Klaviyo API keys below to connect. You can find them on your Klaviyo account page.', 'klaviyoaddon' ),
	 			'fields' => array(
	 				array(
	 					'name'    => 'api_key',
	 					'label'   => esc_html__( 'Public API Key', 'klaviyoaddon' ),
	 					'type'    => 'text',
	 					'class'   => 'small',
	 				),
	 				array(
	 					'name'    => 'private_api_key',
	 					'label'   => esc_html__( 'Private API Key', 'klaviyoaddon' ),
	 					'type'    => 'text',
	 					'class'   => 'medium',
	 				),
	 			),
	 		),
	 	);
	 }

	/**
	 * Configures the settings which should be rendered on the feed edit page in the Form Settings > Klaviyo area.
	 *
	 * @return array
	 */
	public function feed_settings_fields() {
		return array(
			array(
				'title'  => esc_html__( 'Klaviyo Feed Settings', 'klaviyoaddon' ),
				'fields' => array(
					array(
						'label'   => esc_html__( 'Feed name', 'klaviyoaddon' ),
						'type'    => 'text',
						'name'    => 'feedName',
						'class'   => 'small',
						'tooltip'  => '<h6>' . esc_html__( 'Name', 'klaviyoaddon' ) . '</h6>' . esc_html__( 'Enter a feed name to uniquely identify this setup.', 'klaviyoaddon' )
					),
					 array(
					 	'name'     => 'list',
					 	'label'    => esc_html__('Klaviyo List', 'klaviyoaddon' ),
					 	'type'     => 'select',
					 	'required' => true,
					 	'choices'  => $this->lists_for_feed_setting(),
					 	'tooltip'  => '<h6>' . esc_html__( 'Klaviyo List', 'klaviyoaddon' ) . '</h6>' . esc_html__( 'Select which Klaviyo list this feed will add contacts to.', 'klaviyoaddon' )
				 	),
					array(
						'name'      => 'mappedFields',
						'label'     => esc_html__( 'Map Fields', 'klaviyoaddon' ),
						'type'      => 'field_map',
						'field_map' => array(
							array(
								'name'       => 'email',
								'label'      => esc_html__( 'Email', 'klaviyoaddon' ),
								'required'   => true,
								'field_type' => array( 'email', 'hidden' ),
							),
							array(
                                'name'     => 'first_name',
                                'label'    => esc_html__( 'First Name', 'klaviyoaddon' ),
                                'required' => true
                            ),
                            array(
                                'name'     => 'last_name',
                                'label'    => esc_html__( 'Last Name', 'klaviyoaddon' ),
                                'required' => true
                            ),
							array(
                                'name'     => 'organization',
                                'label'    => esc_html__( 'Organization', 'klaviyoaddon' ),
                                'required' => false
                            ),
                            
						),
					),
					array(
						'name'           => 'condition',
						'label'          => esc_html__( 'Condition', 'klaviyoaddon' ),
						'type'           => 'feed_condition',
						'checkbox_label' => esc_html__( 'Enable Condition', 'klaviyoaddon' ),
						'instructions'   => esc_html__( 'Process this feed if', 'klaviyoaddon' ),
					),
				),
			),
		);
	}

	/**
	 * Configures which columns should be displayed on the feed list page.
	 *
	 * @return array
	 */
	public function feed_list_columns() {
		return array(
			'feedName'  => esc_html__( 'Name', 'klaviyoaddon' ),
			 'list' => esc_html__( 'Klaviyo List', 'klaviyoaddon' ),
		);
	}

	/**
	 * Format the value to be displayed in the mytextbox column.
	 *
	 * @param array $feed The feed being included in the feed list.
	 *
	 * @return string
	 */
	public function get_column_value_mytextbox( $feed ) {
		return '<b>' . rgars( $feed, 'meta/mytextbox' ) . '</b>';
	}

	/**
	 * Prevent feeds being listed or created if an api key isn't valid.
	 *
	 * @return bool
	 */
	public function can_create_feed() {

		// Get the plugin settings.
		$settings = $this->get_plugin_settings();

		// Access a specific setting e.g. an api key
		$key = rgar( $settings, 'apiKey' );

		return true;
	}

	public function lists_for_feed_setting() {
        $lists = array(
            array(
                'label' => '',
                'value' => ''
            )
        );
		
		
		/* If Klaviyo API credentials are invalid, return the lists array. */
        //        if ( ! $this->initialize_api() ) {
        //            return $lists;
        //        }

        $private_key = $this->get_plugin_setting('private_api_key');
		
		if ($private_key) {

			
			$args =  array(
				'headers'  => [
					'Authorization' => 'Klaviyo-API-Key '. $private_key,
					'accept' => 'application/json',
					'revision' => '2024-05-15',
				],
			
			);
			
			
			$url = 'https://a.klaviyo.com/api/lists/';
		
$results = array();
$url = 'https://a.klaviyo.com/api/lists/?page[cursor]=';
$keep_going = true;
while ( $keep_going ) {
    $request = wp_remote_get( $url, $args ); // This assumes you've set $args previously
	
	//print_r($request);

    if ( is_wp_error( $request ) ) {
        // Error out.
        $keep_going = false;
    }
    if ( $keep_going ) {
        $status = wp_remote_retrieve_response_code($request);
        if ( 200 != $status ) {
            // Not a valid response.
            $keep_going = false;
        }
    }
    if ( $keep_going ) {
        $data = wp_remote_retrieve_body($request);
        $body = json_decode($request['body'], true);
		
		//print_r($body);
		
		$ac_lists = $body["data"];

        foreach ($ac_lists as $datapoint) {
            array_push($results, $datapoint);
			
        }
		//print_r($body["data"]);
        // URL for the next pass through the while() loop
        $url = $body['links']['next'];
		

    }
}	//print_r($results);
			
			$lists = array();
			$i = 0;
			foreach ( $results as $list ) {
			
             //print_r($list[$i]);
				
	            $lists[$i] = array(
	                'label' => $list["attributes"]["name"],
	                'value' => $list["id"]
	            );
                $i++;
				//print_r($i);
            }
        }
       return $lists;
    }
}

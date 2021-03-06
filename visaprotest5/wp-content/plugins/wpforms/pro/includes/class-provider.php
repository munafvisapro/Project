<?php
/**
 * Provider class.
 *
 * @package    WPForms
 * @author     WPForms
 * @since      1.0.0
 * @license    GPL-2.0+
 * @copyright  Copyright (c) 2016, WPForms LLC
*/
abstract class WPForms_Provider {
	
	/**
	 * Provider add-on version.
	 *
	 * @since 1.0.0
	 * @var sting
	 */
	private $version;

	/**
	 * Provider name.
	 *
	 * @since 1.0.0
	 * @var sting
	 */
	public $name;

	/**
	 * Provider name in slug format.
	 *
	 * @since 1.0.0
	 * @var sting
	 */
	public $slug;

	/**
	 * Load priority.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $priority = 10;

	/**
	 * Holds the API connections.
	 *
	 * @since 1.0.0
	 * @var mixed
	 */
	public $api = false;

	/**
	 * Service icon.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $icon;

	/**
	 * Service icon.
	 *
	 * @since 1.2.3
	 * @var string
	 */
	public $type;

	/**
	 * Form data.
	 *
	 * @since 1.2.3
	 * @var array
	 */
	public $form_data;
  
	/**
	 * Primary class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		$this->type = __( 'Connection', 'wpforms' );

		$this->init();

		// Add to list of available providers
		add_filter( 'wpforms_providers_available', array( $this, 'register_provider' ), $this->priority, 1 );

		// Process builder AJAX requests
		add_action( "wp_ajax_wpforms_provider_ajax_{$this->slug}", array( $this, 'process_ajax' ) );
	
		// Process entry
		add_action( 'wpforms_process_complete', array( $this, 'process_entry' ), 5, 4 );

		// Fetch and store the current form data when in the builder
		add_action( 'wpforms_builder_init', array( $this, 'builder_form_data' ) );

		// Output builder sidebar
		add_action( 'wpforms_providers_panel_sidebar', array( $this, 'builder_sidebar' ), $this->priority );
		
		// Output builder content
		add_action( 'wpforms_providers_panel_content', array( $this, 'builder_output' ), $this->priority );

		// Remove provider from Settings Integrations tab
		add_action( "wp_ajax_wpforms_settings_provider_disconnect", array( $this, 'integrations_tab_disconnect' ) );

		// Add new provider from Settings Integrations tab
		add_action( "wp_ajax_wpforms_settings_provider_add", array( $this, 'integrations_tab_add' ) );

		// Add providers sections to the Settings Integrations tab
		add_action( 'wpforms_settings_providers', array( $this, 'integrations_tab_options' ), $this->priority, 2 );
	}

	/**
	 * All systems go. Used by subclasses.
	 *
	 * @since 1.0.0
	 */
	public function init() {
	}

	/**
	 * Add to list of registered providers.
	 *
	 * @since 1.0.0
	 * @param array $providers
	 * @return array
	 */
	function register_provider( $providers = array() ) {

		$providers[$this->slug] = $this->name;
		return $providers;
	} 

	/**
	 * Process the Builder AJAX requests.
	 *
	 * @since 1.0.0
	 */
	public function process_ajax() {

		// Run a security check
		check_ajax_referer( 'wpforms-builder', 'nonce' );

		// Check for permissions
		if ( !current_user_can( apply_filters( 'wpforms_manage_cap', 'manage_options' ) ) ) {
			wp_send_json_error( array( 'error' => __( 'You do not have permission', 'wpforms' ) ) );
		}

		//--------------------------------------------------------------------//
		// Create new connection
		//--------------------------------------------------------------------//
		
		if ( $_POST['task'] == 'new_connection' ) {
			
			$connection = $this->output_connection( '', array( 'connection_name' => $_POST['name'] ), $_POST['id'] );
			wp_send_json_success( array( 'html' => $connection ) );
		}

		//--------------------------------------------------------------------//
		// Create new Provider account
		//--------------------------------------------------------------------//
		
		if ( $_POST['task'] == 'new_account' ) {
			
			$auth = $this->api_auth( wp_parse_args( $_POST['data'], array() ), $_POST['id'] );

			if ( is_wp_error( $auth ) ) {
	
				wp_send_json_error( array( 'error' => $auth->get_error_message() ) );

			} else {

				$accounts = $this->output_accounts( $_POST['connection_id'], array( 'account_id' => $auth ) );
				wp_send_json_success( array( 'html' => $accounts ) );
			}
		}

		//--------------------------------------------------------------------//
		// Select/Toggle Provider accounts
		//--------------------------------------------------------------------//
		
		if ( $_POST['task'] == 'select_account' ) {

			$lists = $this->output_lists( $_POST['connection_id'], array( 'account_id' => $_POST['account_id'] ) );

			if ( is_wp_error( $lists ) ) {
	
				wp_send_json_error( array( 'error' => $lists->get_error_message() ) );

			} else {

				wp_send_json_success( array( 'html' => $lists ) );
			}
		}

		//--------------------------------------------------------------------//
		// Select/Toggle Provider account lists
		//--------------------------------------------------------------------//

		if ( $_POST['task'] == 'select_list' ) {

			$fields  = $this->output_fields( $_POST['connection_id'], array( 'account_id' => $_POST['account_id'], 'list_id' => $_POST['list_id'] ), $_POST['id'] );

			if ( is_wp_error( $fields ) ) {

				wp_send_json_error( array( 'error' => $fields->get_error_message() ) );

			} else {

				$groups       = $this->output_groups( $_POST['connection_id'],  array( 'account_id' => $_POST['account_id'], 'list_id' => $_POST['list_id'] ) );
				$conditionals = $this->output_conditionals( $_POST['connection_id'], array( 'account_id' => $_POST['account_id'], 'list_id' => $_POST['list_id'] ), array( 'id' => absint( $_POST['form_id'] ) ) );
				$options      = $this->output_options( $_POST['connection_id'], array( 'account_id' => $_POST['account_id'], 'list_id' => $_POST['list_id'] ) );
				
				wp_send_json_success( array( 'html' => $groups . $fields . $conditionals . $options ) );
			}
		}

		die();
	}

	/**
	 * Process and submit entry to provider.
	 *
	 * @since 1.0.0
	 * @param array $fields
	 * @param array $entry
	 * @param array $form_data
	 * @param int $entry_id
	 */
	public function process_entry( $fields, $entry, $form_data, $entry_id ) {
	}

	/**
	 * Process conditional fields.
	 *
	 * @since 1.0.0
	 * @param array $fields
	 * @param array $entry
	 * @param array $form_data
	 * @param array $connection
	 */
	public function process_conditionals( $fields, $entry, $form_data, $connection ) {

		if ( empty( $connection['conditional_logic'] ) || empty( $connection['conditionals'] ) ) {
			return true;
		}

		$pass = false;

		foreach ( $connection['conditionals'] as $group_id => $group ) {

			$pass_group = true;

			if ( !empty( $group ) ) {

				foreach( $group as $rule_id => $rule ) {

					if (  in_array( $fields[$rule['field']]['type'], array( 'text', 'textarea' ) ) ) {

						if ( $rule['operator'] == "==" ) {
							
							$pass_rule = ( $fields[$rule['field']]['value'] === $rule['value'] );

						} elseif( $rule['operator'] == "!=" ) {
							
							$pass_rule = ( $fields[$rule['field']]['value'] !== $rule['value'] );
					
						}

					} elseif ( in_array( $fields[$rule['field']]['type'], array( 'checkbox' ) ) ) {

						$provided_id = false;

						if ( !empty( $fields[$rule['field']]['value'] ) ) {

							$provided_id = array();
							$values      = explode( "\n", $fields[$rule['field']]['value'] );

							foreach( $form_data['fields'][$rule['field']]['choices'] as $key => $choice ) {

								foreach( $values as $value ) {

									if ( in_array( $value, $choice ) ) {
										$provided_id[] = $key;
									}
								}
							}			
						}

						if ( $rule['operator'] == "==" ) {
							
							$pass_rule = in_array( (int) $rule['value'], (array) $provided_id );

						} elseif( $rule['operator'] == "!=" ) {
					
							$pass_rule = ! in_array( (int) $rule['value'], (array) $provided_id );
						}

					} else {

						$provided_id = false;

						if ( !empty( $fields[$rule['field']]['value'] ) ) {

							foreach( $form_data['fields'][$rule['field']]['choices'] as $key => $choice ) {

								if ( in_array( $fields[$rule['field']]['value'], $choice ) ) {

									$provided_id = (int) $key;
								}
							}
						}

						if ( $rule['operator'] == "==" ) {
							
							$pass_rule = ( $provided_id === (int) $rule['value'] );

						} elseif( $rule['operator'] == "!=" ) {
							
							$pass_rule = ( $provided_id !== (int) $rule['value'] );
					
						}
					}

					if ( !$pass_rule ) {
						$pass_group = false;
						break;
					}
				}
			}

			if ( $pass_group ) {
				$pass = true;
			}
		}

		return $pass;
	}

	/**
	 * Retrieve all available forms in a field.
	 *
	 * Not all fields should be available for merge tags so we compare against a
	 * white-list. Also some fields, such as Name, should have additional 
	 * variations.
	 *
	 * @since 1.0.0
	 * @param mixed $form_id
	 * @return mixed boolean or array
	 */
	public function get_form_fields( $form = false, $whitelist = array() ) {

		// Accept form (post) object or form ID
		if ( is_object( $form ) ) {
			$form = wpforms_decode( $form->post_content );
		} elseif ( is_numeric( $form ) ) {
			$form = wpforms()->form->get( $form, array( 'content_only' => true ) );
		}

		if ( !is_array( $form ) || empty( $form['fields'] ) )
			return false;

		// White list of field types to allow
		$allowed_form_fields = array(
			'text',
			'textarea',
			'select',
			'radio',
			'checkbox',
			'email',
			'address',
			'url',
			'name',
			'hidden',
			'date-time',
			'phone',
			'number'
		);
		$allowed_form_fields = apply_filters( 'wpforms_providers_fields', $allowed_form_fields );

		$whitelist = !empty( $whitelist ) ? $whitelist : $allowed_form_fields;

		$form_fields = $form['fields'];

		foreach( $form_fields as $id => $form_field ) {
			if ( !in_array( $form_field['type'], $whitelist ) ) {
				unset( $form_fields[$id] );
			}
		}

		return $form_fields;
	}

	/**
	 * Get form fields ready for select list options.
	 *
	 * In this function we also do the logic to limit certain fields to certain
	 * provider field types.
	 *
	 * @since 1.0.0
	 * @param array $form_fields
	 * @param string $form_field_type
	 */
	public function get_form_field_select( $form_fields = array(), $form_field_type = '' ) {

		if ( empty( $form_fields ) || empty( $form_field_type ) )
			return array();

		$formatted = array();

		// Include only specific field types
		foreach ( $form_fields as $id => $form_field ) {
			
			// Email
			if ( 'email' == $form_field_type && !in_array( $form_field['type'], array( 'text', 'email' ) ) ) {
				unset( $form_fields[$id] );
			}
		}

		// Format
		foreach ( $form_fields as $id => $form_field ) {

			// Complex Name field
			if ( $form_field['type'] == 'name' ) {

				// Full Name
				$formatted[] = array(
					'id'            => $form_field['id'],
					'key'           => 'value',
					'type'          => $form_field['type'],
					'subtype'       => '',
					'provider_type' => $form_field_type,
					'label'         => $form_field['label'] . sprintf( ' (%s)', __( 'Full', 'wpforms' ) ),
				);
				
				// First Name 
				if ( strpos( $form_field['format'], 'first' ) !== false ) {
					$formatted[] = array(
						'id'            => $form_field['id'],
						'key'           => 'first',
						'type'          => $form_field['type'],
						'subtype'       => 'first',
						'provider_type' => $form_field_type,
						'label'         => $form_field['label'] . sprintf( ' (%s)', __( 'First', 'wpforms' ) ),
					);
				}

				// Middle Name 
				if ( strpos( $form_field['format'], 'middle' ) !== false ) {
					$formatted[] = array(
						'id'            => $form_field['id'],
						'key'           => 'middle',
						'type'          => $form_field['type'],
						'subtype'       => 'middle',
						'provider_type' => $form_field_type,
						'label'         => $form_field['label'] . sprintf( ' (%s)', __( 'Middle', 'wpforms' ) ),
					);
				}

				// Middle Name 
				if ( strpos( $form_field['format'], 'last' ) !== false ) {
					$formatted[] = array(
						'id'            => $form_field['id'],
						'key'           => 'last',
						'type'          => $form_field['type'],
						'subtype'       => 'last',
						'provider_type' => $form_field_type,
						'label'         => $form_field['label'] . sprintf( ' (%s)', __( 'Last', 'wpforms' ) ),
					);
				}

			// All other fields
			} else {

				$formatted[] = array(
					'id'            => $form_field['id'],
					'key'           => 'value',
					'type'          => $form_field['type'],
					'subtype'       => '',
					'provider_type' => $form_field_type,
					'label'         => $form_field['label'],
				);
			}
		}

		return $formatted;
	}

	//************************************************************************//
	//
	//	API methods - these methods interact directly with the provider API.
	//
	//************************************************************************//

	/**
	 * Authenticate with the provider API.
	 * 
	 * @param array $data
	 * @param string $form_id
	 * @return mixed id or error object
	 */
	public function api_auth( $data = array(), $form_id = '' ) {
	}

	/**
	 * Establish connection object to provider API.
	 *
	 * @since 1.0.0
	 * @param string $account_id
	 * @return mixed array or error object
	 */
	public function api_connect( $account_id ) {
	}

	/**
	 * Retrieve provider account lists.
	 *
	 * @since 1.0.0
	 * @param string $connection_id
	 * @param string $account_id
	 * @return mixed array or error object
	 */
	public function api_lists( $connection_id = '', $account_id = '' ) {
	}

	/**
	 * Retrieve provider account list groups.
	 *
	 * @since 1.0.0
	 * @param string $connection_id
	 * @param string $account_id
	 * @return mixed array or error object
	 */
	public function api_groups( $connection_id = '', $account_id = '', $list_id = '' ) {
	}

	/**
	 * Retrieve provider account list fields.
	 *
	 * @since 1.0.0
	 * @param string $connection_id
	 * @param string $account_id
	 * @return mixed array or error object
	 */
	public function api_fields( $connection_id = '', $account_id = '', $list_id = '' ) {
	}


	//************************************************************************//
	//
	//	Output methods - these methods generally return HTML for the builder.
	//
	//************************************************************************//

	/**
	 * Connection HTML.
	 *
	 * This method compiles all the HTML necessary for a connection to a provider.
	 *
	 * @since 1.0.0
	 * @param string $connection_id
	 * @param array $connection
	 * @param mixed $form form id or form data
	 * @return string
	 */
	public function output_connection( $connection_id = '', $connection = array(), $form = '' ) {

		if ( empty( $connection_id ) ) {
			$connection_id = 'connection_' . uniqid();
		}

		if ( empty( $connection ) || empty( $form ) )
			return;

		$output = sprintf( '<div class="wpforms-provider-connection" data-provider="%s" data-connection_id="%s">', $this->slug, $connection_id );
			
			$output .= $this->output_connection_header( $connection_id, $connection );
			
			$output .= $this->output_auth();

			$output .= $this->output_accounts( $connection_id, $connection );

			$lists   = $this->output_lists( $connection_id, $connection );
			$output .= !is_wp_error( $lists ) ? $lists : '';

			$output .= $this->output_groups( $connection_id, $connection );

			$fields  = $this->output_fields( $connection_id, $connection, $form );
			$output .= !is_wp_error( $fields ) ? $fields : '';

			$output .= $this->output_conditionals( $connection_id, $connection, $form );

			$output .= $this->output_options( $connection_id, $connection );

		$output .= '</div>';

		return $output;
	}

	/**
	 * Connection header HTML.
	 *
	 * @since 1.0.0
	 * @param string $connection_id
	 * @param array $connection
	 * @return string
	 */
	public function output_connection_header( $connection_id = '', $connection = array() ) {

		if ( empty( $connection_id ) || empty( $connection ) )
			return;

		$output = '<div class="wpforms-provider-connection-header">';
			
			$output .= sprintf( '<span>%s</span>', sanitize_text_field( $connection['connection_name'] ) );
			
			$output .= '<button class="wpforms-provider-connection-delete"><i class="fa fa-times-circle"></i></button>';
			
			$output .= sprintf( '<input type="hidden" name="providers[%s][%s][connection_name]" value="%s">', $this->slug, $connection_id, esc_attr( $connection['connection_name'] ) );
	
		$output .= '</div>';

		return $output;
	}

	/**
	 * Provider account authorize fields HTML.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function output_auth() {
	}

	/**
	 * Provider account select HTML.
	 *
	 * @since 1.0.0	
	 * @param string $connection_id
	 * @param array $connection
	 * @return string
	 */
	public function output_accounts( $connection_id = '', $connection = array() ) {

		if ( empty( $connection_id ) || empty( $connection ) )
			return;

		$providers = get_option( 'wpforms_providers' );

		if ( empty( $providers[$this->slug] ) )
			return;

		$output = '<div class="wpforms-provider-accounts wpforms-connection-block">';

			$output .= sprintf( '<h4>%s</h4>', __( 'Select Account', 'wpforms' ) );
			
			$output .= sprintf( '<select name="providers[%s][%s][account_id]">', $this->slug, $connection_id );
				foreach ( $providers[$this->slug] as $key => $provider_details ) {
					$selected = !empty( $connection['account_id'] ) ? $connection['account_id'] : '';
					$output .= sprintf( 
						'<option value="%s" %s>%s</option>', 
						$key,
						selected( $selected, $key, false ),
						esc_html( $provider_details['label'] ) 
					);
				}
				$output .= sprintf( '<option value="">%s</a>', __( 'Add New Account', 'wpforms' ) );
			$output .= '</select>';

		$output .= '</div>';

		return $output;
	}

	/**
	 * Provider account lists HTML.
	 *
	 * @since 1.0.0
	 * @param string $connection_id
	 * @param array $connection
	 * @return string
	 */
	public function output_lists( $connection_id ='', $connection = array() ) {

		if ( empty( $connection_id ) || empty( $connection['account_id'] ) )
			return;

		$lists    = $this->api_lists( $connection_id, $connection['account_id'] );
		$selected = !empty( $connection['list_id'] ) ? $connection['list_id'] : '';

		if ( is_wp_error( $lists ) ) {
			return $lists;
		}

		$output = '<div class="wpforms-provider-lists wpforms-connection-block">';

			$output .= sprintf( '<h4>%s</h4>', __( 'Select List', 'wpforms' ) );
			
			$output .= sprintf( '<select name="providers[%s][%s][list_id]">', $this->slug, $connection_id );

				foreach ( $lists as $list ) {
					$output .= sprintf( 
						'<option value="%s" %s>%s</option>', 
						esc_attr( $list['id'] ),
						selected( $selected, $list['id'], false ),
						esc_attr( $list['name'] )
					);
				}

			$output .= '</select>';

		$output .='</div>';

		return $output;
	}

	/**
	 * Provider account list groups HTML.
	 *
	 * @since 1.0.0
	 * @param string $connection_id
	 * @param array $connection
	 * @return string
	 */
	public function output_groups( $connection_id = '', $connection = array() ) {

		if ( empty( $connection_id ) || empty( $connection['account_id'] ) || empty( $connection['list_id'] ) )
			return;

		$groupsets = $this->api_groups( $connection_id, $connection['account_id'], $connection['list_id'] );

		if ( is_wp_error( $groupsets ) )
			return;

		$output = '<div class="wpforms-provider-groups wpforms-connection-block">';

			$output .= sprintf( '<h4>%s</h4>', __( 'Select Groups', 'wpforms' ) );

			$output .= sprintf( '<p>%s</p>', __( 'We also noticed that you have some segments in your list. You can select specific list segments below if needed. This is optional.', 'wpforms' ) );

			$output .= '<div class="wpforms-provider-groups-list">';

				foreach( $groupsets as $groupset ) {

					$output .= sprintf( '<p>%s</p>', esc_html( $groupset['name'] ) );

					foreach( $groupset['groups'] as $group ) {
					
						$selected = !empty( $connection['groups'] ) && !empty( $connection['groups'][$groupset['id']] ) ? in_array( $group['name'], $connection['groups'][$groupset['id']] ) : false;

						$output .= sprintf(
							'<span><input id="group_%s" type="checkbox" value="%s" name="providers[%s][%s][groups][%s][%s]" %s><label for="group_%s">%s</label></span>',
							esc_attr( $group['id'] ),
							esc_attr( $group['name'] ),
							$this->slug,
							$connection_id,
							$groupset['id'],
							$group['id'],
							checked( $selected, true, false ),
							esc_attr( $group['id'] ),
							esc_attr( $group['name'] )
						);
					}
				}

			$output .= '</div>';

		$output .= '</div>';

		return $output;
	}

	/**
	 * Provider account list fields HTML.
	 *
	 * @since 1.0.0
	 * @param string $connection_id
	 * @param array $connection
	 * @param mixed $form
	 * @return string
	 */
	public function output_fields( $connection_id = '', $connection = array(), $form = '' ) {

		if ( empty( $connection_id ) || empty( $connection['account_id'] ) || empty( $connection['list_id'] ) || empty( $form ) )
			return;

		$provider_fields = $this->api_fields( $connection_id, $connection['account_id'], $connection['list_id'] );
		$form_fields     = $this->get_form_fields( $form );

		if ( is_wp_error( $provider_fields ) )
			return $provider_fields;

		$output = '<div class="wpforms-provider-fields wpforms-connection-block">';

			$output .= sprintf( '<h4>%s</h4>', __( 'List Fields', 'wpforms' ) );

			// Table with all the fields
			$output .= '<table>';

				$output .= sprintf( '<thead><tr><th>%s</th><th>%s</th></thead>', __( 'List Fields', 'wpforms'), __( 'Available Form Fields', 'wpforms' ) );
				
				$output .= '<tbody>';

				foreach( $provider_fields as $provider_field ) :

					$output .= '<tr>';
					
						$output .= '<td>';
							
							$output .= esc_html( $provider_field['name'] );
							if ( !empty( $provider_field['req']) && $provider_field['req'] == '1' ) {
								$output .= '<span class="required">*</span>';
							}
					
						$output .= '<td>';

							$output .= sprintf( '<select name="providers[%s][%s][fields][%s]">', $this->slug, $connection_id, esc_attr( $provider_field['tag'] ) );
								
								$output .= '<option value=""></option>';

								$options = $this->get_form_field_select( $form_fields, $provider_field['field_type'] );

								foreach( $options as $option ) {
									$value    = sprintf( '%d.%s.%s', $option['id'], $option['key'], $option['provider_type'] );
									$selected = !empty( $connection['fields'][$provider_field['tag']] ) ? selected( $connection['fields'][$provider_field['tag']], $value, false ) : '';
									$output  .= sprintf( '<option value="%s" %s>%s</option>', esc_attr( $value ), $selected, esc_html( $option['label'] ) );
								}

							$output .= '</select>';
					
						$output .= '</td>';
					
					$output .= '</tr>';
				
				endforeach;

				$output .= '</tbody>';

			$output .= '</table>';

		$output .= '</div>';

		return $output;
	}

	/**
	 * Provider connection conditional options HTML
	 *
	 * @since 1.0.0
	 * @return [type]
	 */
	public function output_conditionals( $connection_id = '', $connection = array(), $form = '' ) {

		if ( empty( $connection['account_id'] ) )
			return;

		$form_fields = wpforms_get_form_fields( $form['id'], array( 'text', 'textarea', 'select', 'radio', 'checkbox', 'number' ) );

		$output = '<div class="wpforms-provider-conditionals wpforms-connection-block">';

			$output .= sprintf( '<h4>%s</h4>', __( 'Conditional Logic', 'wpforms' ) );

			$output .= sprintf(
							'<p><input id="%s_conditional_logic" class="toggle" type="checkbox" value="1" name="providers[%s][%s][conditional_logic]" %s><label for="%s_conditional_logic">%s</label></p>',
							$connection_id,
							$this->slug, 
							$connection_id,
							checked( !empty( $connection['conditional_logic'] ) , true , false ),
							$connection_id,
							__( 'Enable conditional logic', 'wpforms' )
						);

			$style = empty( $connection['conditional_logic'] ) ? 'display:none' : '';

			$output .= sprintf( '<div class="wpforms-provider-conditionals-groups" style="%s">', $style );

				$output .= '<h4>' . __( 'Process this form if', 'wpforms' ) . '</h4>';

				if ( empty( $connection['conditionals'] ) ){
					$connection['conditionals'][0][0] = array();
				}

				foreach ( $connection['conditionals'] as $group_id => $group ) :

					$output .= '<div class="wpforms-provider-conditionals-group">';

						$output .='<table><tbody>';

							foreach ( $group as $rule_id => $rule ) :

								$output .= '<tr class="wpforms-provider-conditionals-group-row">';

									// Fields
									$output .= '<td class="field">';

										$output .= sprintf( 
											'<select name="providers[%s][%s][conditionals][%d][%d][field]" data-provider="%s" data-connectionid="%s" data-groupid="%d" data-ruleid="%d" class="wpforms-provider-conditionals-field">', 
											$this->slug, 
											$connection_id,
											$group_id,
											$rule_id,
											$this->slug,
											$connection_id,
											$group_id,
											$rule_id
										);
											
											$output .= '<option value="">' . __( '-- Select field --', 'wpforms' ) . '</option>';

											foreach( $form_fields as $form_field ) {
												$selected = !empty( $rule['field'] ) ? $rule['field'] : false;
												$selected = selected( $selected, $form_field['id'], false );
												$output  .= sprintf( '<option value="%s" %s>%s</option>', absint( $form_field['id'] ), $selected, esc_html( $form_field['label'] ) );
											}

										$output .= '</select>';

									$output .= '</td>';

									// Operator
									$output .= '<td class="operator">';

										$output .= sprintf( '<select name="providers[%s][%s][conditionals][%s][%s][operator]" class="wpforms-provider-conditionals-operator">', $this->slug, $connection_id, $group_id, $rule_id );
											$operator = !empty( $rule['operator'] ) ? $rule['operator'] : false;
											$output .= sprintf( '<option value="==" %s>%s</option>', selected( $operator, '==', false ), __( 'is', 'wpforms' ) );
											$output .= sprintf( '<option value="!=" %s>%s</option>', selected( $operator, '!=', false ), __( 'is not', 'wpforms' ) );
										$output .= '</select>';

									$output .= '</td>';

									// Values
									$output .= '<td class="value">';

										if ( !empty( $rule['field'] ) ) {

											if ( in_array( $form_fields[$rule['field']]['type'], array( 'text', 'textarea') ) ) {
											
												$output .= sprintf( '<input type="text" name="providers[%s][%s][conditionals][%s][%s][value]" value="%s" class="wpforms-provider-conditionals-value">', $this->slug, $connection_id, $group_id, $rule_id, esc_attr($rule['value'] ) );
											
											} else {
												$output .= sprintf( '<select name="providers[%s][%s][conditionals][%s][%s][value]" class="wpforms-provider-conditionals-value">', $this->slug, $connection_id, $group_id, $rule_id );
												$output .= '<option value="">' . __( '-- Select Choice --', 'wpforms' ) . '</option>';
												foreach( $form_fields[$rule['field']]['choices'] as $option_id => $option ) {
													$value    = !empty( $rule['value'] ) ? $rule['value'] : '';
													$selected = selected( $option_id, $rule['value'], false );
													$output  .= sprintf( '<option value="%s" %s>%s</option>', $option_id, $selected, esc_html( $option['label'] ) );
												}
												$output .= '</select>';
											}

										} else {
											$output .= '<select></select>';
										}

									$output .= '</td>';

									// Actions
									$output .= '<td class="actions">';

										$output .= sprintf( '<button class="wpforms-provider-conditionals-rule-add">%s</button>', __( 'AND', 'wpforms') );
										$output .= '<button class="wpforms-provider-conditionals-rule-delete"><i class="fa fa-minus-circle"></i></button>';

									$output .= '</td>';

								$output .= '</tr>';

							endforeach;

						$output .= '</tbody></table>';

						$output .= '<h5>or</h5>';
							
					$output .= '</div>';

				endforeach;

				$output .= '<button class="wpforms-provider-conditionals-groups-add">' . __( 'Add rule group', 'wpforms' ) . '</button>';

			$output .= '</div>';

		$output .= '</div>';

		return $output;
	}


	/**
	 * Provider account list options HTML.
	 *
	 * @since 1.0.0
	 * @param string $connection_id
	 * @param array $connection
	 * @param mixed $form
	 * @return string
	 */
	public function output_options( $connection_id = '', $connection = array()  ) {
	}

	//************************************************************************//
	//
	//	Builder methods - these methods _build_ the Builder.
	//
	//************************************************************************//

	/**
	 * Fetch and store the current form data when in the builder.
	 *
	 * @since 1.2.3
	 */
	public function builder_form_data() {

		if ( !empty( $_GET['form_id'] ) ) {
			$this->form_data  = wpforms()->form->get( absint( $_GET['form_id'] ), array( 'content_only' => true ) );
		}
	}

	/**
	 * Display content inside the panel content area.
	 * 
	 * @since 1.0.0
	 */
	public function builder_content() {

		$form_data = $this->form_data;
		$providers = get_option( 'wpforms_providers' );

		if ( !empty( $form_data['providers'][$this->slug] ) && !empty( $providers[$this->slug] ) ) {

			foreach( $form_data['providers'][$this->slug] as $connection_id => $connection ) {

				foreach( $providers[$this->slug] as $account_id => $connections ) {

					if ( !empty( $connection['account_id'] ) && $connection['account_id'] == $account_id ) {
						
						echo $this->output_connection( $connection_id, $connection, $form_data );
					}
				}
			}
		}
	}

	/**
	 * Display content inside the panel sidebar area.
	 * 
	 * @since 1.0.0
	 */
	public function builder_sidebar() {

		$form_data  = $this->form_data;
		$configured = !empty( $form_data['providers'][$this->slug] ) ? 'configured' : '';
		$configured = apply_filters( 'wpforms_providers_' . $this->slug . '_configured', $configured );

		echo '<a href="#" class="wpforms-panel-sidebar-section icon ' . $configured . ' wpforms-panel-sidebar-section-' . esc_attr( $this->slug ) . '" data-section="' . esc_attr( $this->slug ) . '">';
			
			echo '<img src="' . esc_url( $this->icon ) . '">';

			echo esc_html( $this->name );

			echo '<i class="fa fa-angle-right wpforms-toggle-arrow"></i>';

			if ( !empty( $configured ) ) {
				echo '<i class="fa fa-check-circle-o"></i>';
			}

		echo '</a>';
	}

	/**
	 * Wraps the builder content with the required markup.
	 * 
	 * @since 1.0.0
	 */
	public function builder_output() {
		?>
		<div class="wpforms-panel-content-section wpforms-panel-content-section-<?php echo $this->slug; ?>" id="<?php echo $this->slug; ?>-provider">
			
			<div class="wpforms-panel-content-section-title">

				<?php echo $this->name; ?>

				<button class="wpforms-provider-connections-add" data-form_id="<?php echo absint( $_GET['form_id'] ); ?>" data-provider="<?php echo $this->slug; ?>" data-type="<?php echo esc_attr( strtolower( $this->type ) ); ?>"><?php _e( 'Add New', 'wpforms' ); ?> <?php echo esc_html( $this->type ); ?></button>

			</div>
		
			<div class="wpforms-provider-connections-wrap wpforms-clear">

				<div class="wpforms-provider-connections">

					<?php $this->builder_content(); ?>

				</div>

			</div>

		</div>
		<?php
	}

	//************************************************************************//
	//
	//	Integrations tab methods - these methods relate to the settings page.
	//
	//************************************************************************//

	/**
	 * Form fields to add a new provider account.
	 *
	 * @since 1.0.0
	 */
	public function integrations_tab_new_form() {

	}

	/**
	 * AJAX to disconnect a provider from the settings integrations tab.
	 *
	 * @since 1.0.0
	 */
	public function integrations_tab_disconnect() {

		// Run a security check
		check_ajax_referer( 'wpforms-settings', 'nonce' );

		// Check for permissions
		if ( !current_user_can( apply_filters( 'wpforms_manage_cap', 'manage_options' ) ) ) {
			wp_send_json_error( array( 'error' => __( 'You do not have permission', 'wpforms' ) ) );
		}

		if ( empty( $_POST['provider'] ) || empty( $_POST['key'] ) ) {
			wp_send_json_error( array( 'error' => __( 'Missing data', 'wpforms' ) ) );
		}

		$providers = get_option( 'wpforms_providers', false );

		if ( !empty( $providers[$_POST['provider']][$_POST['key']] ) ) {

			unset( $providers[$_POST['provider']][$_POST['key']] );
			update_option( 'wpforms_providers', $providers );
			wp_send_json_success();

		} else {
			wp_send_json_error( array( 'error' => __( 'Connection missing', 'wpforms' ) ) );
		}
	}

	/**
	 * AJAX to add a provider from the settings integrations tab.
	 *
	 * @since 1.0.0
	 */
	public function integrations_tab_add() {

		if ( $_POST['provider'] != $this->slug )
			return;

		// Run a security check
		check_ajax_referer( 'wpforms-settings', 'nonce' );

		// Check for permissions
		if ( !current_user_can( apply_filters( 'wpforms_manage_cap', 'manage_options' ) ) ) {
			wp_send_json_error( array( 'error' => __( 'You do not have permission', 'wpforms' ) ) );
		}

		if ( empty( $_POST['data'] ) ) {
			wp_send_json_error( array( 'error' => __( 'Missing data', 'wpforms' ) ) );
		}

		$data = wp_parse_args( $_POST['data'], array() );
		$auth = $this->api_auth( $data, '' );

		if ( is_wp_error( $auth ) ) {

			wp_send_json_error( array( 'error' => 'Could not connect to the provider.' ) );

		} else {

			$account = '<li>';
				$account .= '<span class="label">' . sanitize_text_field( $data['label'] ) . '</span>';
				$account .= '<span class="date">' . __( 'Connected on: ', 'wpforms' ) . date( get_option( 'date_format', time() ) ) . '</span>';
				$account .= '<a href="#" data-provider="' . $this->slug . '" data-key="' . esc_attr( $auth ) . '">' . __( 'Disconnect ', 'wpforms' ) . '</a>';
			$account .= '</li>';

			wp_send_json_success( array( 'html' => $account ) );
		}
	}


	/**
	 * Add provider to the Settings Integrations tab
	 *
	 * @since 1.0.0
	 * @param array $active
	 * @param array $settings
	 */
	public function integrations_tab_options( $active, $settings ) {

		$slug      = esc_html( $this->slug );
		$name      = esc_html( $this->name );
		$connected = !empty( $active[$this->slug] );
		$accounts  = !empty( $settings[$this->slug] ) ? $settings[$this->slug] : '';
		$class     = $connected && $accounts ? 'connected' : '';
		?>

		<div class="wpforms-settings-provider wpforms-clear <?php echo $slug; ?> <?php echo $class; ?>">

			<div class="wpforms-settings-provider-header wpforms-clear" data-provider="<?php echo $slug; ?>">

				<div class="wpforms-settings-provider-logo">
					<i title="Show Accounts" class="fa fa-chevron-right"></i>
					<img src="<?php echo $this->icon; ?>">
				</div>

				<div class="wpforms-settings-provider-info">
					<h3><?php echo $name; ?></h3>
					<p><?php printf( __( 'Integrate %s with WPForms', 'wpforms' ), $name ); ?></p>
					<span class="connected-indicator green"><i class="fa fa-check-circle-o"></i> <?php _e( 'Connected', 'wpforms' ); ?></span>
				</div>

			</div>

			<div class="wpforms-settings-provider-accounts" id="provider-<?php echo $slug; ?>">
				
				<div class="wpforms-settings-provider-accounts-list">
					<ul>
						<?php 
						if ( !empty( $accounts ) ) {
							foreach ( $accounts as $key => $account ) {
								echo '<li>';
									echo '<span class="label">' . esc_html( $account['label'] ) . '</span>';
									echo '<span class="date">' . __( 'Connected on: ', 'wpforms' ) . date( get_option( 'date_format', $account['date'] ) ) . '</span>';
									echo '<a href="#" data-provider="' . $slug . '" data-key="' . $key . '">' . __( 'Disconnect ', 'wpforms' ) . '</a>';
								echo '</li>';
							}
						}
						?>
					</ul>
				</div>

				<p class="wpforms-settings-provider-accounts-toggle">
					<a class="button button-secondary" href="#" data-provider="<?php echo $slug; ?>"><i class="fa fa-plus"></i> <?php _e( 'Add New Account', 'wpforms' ); ?></a>
				</p>	

				<div class="wpforms-settings-provider-accounts-connect">
						
					<form>
					<p><?php _e( 'Please fill out all of the fields below to add your new provider account.', 'wpforms' ); ?></span></p>

					<p class="wpforms-settings-provider-accounts-connect-fields">
						<?php $this->integrations_tab_new_form(); ?>
					</p>
					
					<p><button type="submit" class="button button-primary wpforms-settings-provider-connect" data-provider="<?php echo $slug; ?>" title="Connect to <?php echo $name; ?>"><?php _e( 'Connect to', 'wpforms' ); ?> <?php echo $name; ?></button> <i class="fa fa-cog fa-spin"></i></p>
					</form>
				</div>

			</div>

		</div>
		<?php
	}

	/**
	 * Error wrapper for WP_Error.
	 *
	 * @since 1.0.0
	 * @param string $id
	 * @param string $message
	 * @return object
	 */
	public function error( $message = '', $parent = '0' ) {

		return new WP_Error( $this->slug . '-error', $message );
	}
}
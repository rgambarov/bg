<?php
/**
 * The shipping method class.
 *
 * This is used to define shipping method.
 *
 *
 * @since       1.0.0
 * @package     Woocommerce BorderGuru Shipping
 * @author     	W4PRO
 */
class Border_Guru_Shipping_Method extends WC_Shipping_Method {
	
	protected $api_url;	
	protected $taxes;	
	protected $quoteIdentifier;	
	protected $_handler;
	
	/**
	 * Constructor for your shipping class
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {
		$this->id                 = 'border_guru'; // Shipping method ID.
		$this->method_title       = __( 'Border Guru', 'wbgs');  // Title shown in admin
		$this->method_description = __( 'BorderGuru offers an international shipping fulfillment service to merchants.', 'wbgs' ); // Description shown in admin
		$this->enabled            = 'yes';
		$this->title              = __('Border Guru', 'wbgs');

		$this->init();
					
	}
	
	/**
	 * Init BorderGuru shipping settings
	 *
	 * @access public
	 * @return void
	 */
	public function init() {
		// Load the settings API
		$this->init_form_fields();
		$this->init_settings();
		$this->api_url = 'https://sandbox1.borderguru.com/';
		$this->get_shipment_lable();				
		$this->init_hooks();
	}
	
	private function init_hooks() {
		// Save settings in admin
		add_action('woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );	
		add_action('woocommerce_checkout_order_processed', array($this, 'checkout_order_processed'));		
		add_action('woocommerce_email_after_order_table', array(&$this, 'add_payment_link_to_order_email'), 10, 2 );			
		add_action('woocommerce_admin_order_data_after_shipping_address', array($this, 'display_bg_shipping_data'));	
	}
		
	public function init_form_fields() {
		include plugin_dir_path( dirname( __FILE__ ) ) . 'includes/wbgs-fields.php';
		$this->form_fields = $wbgs_fields;			
	}
		
	/**
	 * calculate_shipping function.
	 *
	 * @access public
	 * @param mixed $package
	 * @return void
	 */
	public function calculate_shipping( $package ) {
		global $woocommerce;
		/*check countries restriction*/
		$shipping_packages = $woocommerce->cart->get_shipping_packages();
		if($this->settings['bg_availability'] == 'specific' && !in_array($shipping_packages[0]['destination']['country'], $this->settings['bg_countries'])){
			return false;
		}
		$resp = $this->get_quote_data();
		$data = json_decode($resp);
		if(!$data || !is_object($data) || $data->response->success !== TRUE){
			$bg_log = new WC_Logger();
			$bg_log->add( 'border-guru', $resp );
			return false;
		}
		$shipping_cost = floatval($data->response->result->shippingCost);
		if(!empty($data->response->result->quoteIdentifier)){
			$this->quoteIdentifier = $data->response->result->quoteIdentifier;
		}
		
		$this->taxes = $data->response->result->taxAndDutyCost;
		WC()->session->set('bg_shipping', array('bg_split_checkout' => $this->settings['bg_split_checkout'], 
												'taxes' => $this->taxes,
												'quoteIdentifier' => $this->quoteIdentifier
												));
		
		if(!empty($this->settings['bg_handling_fee']) && is_numeric($this->settings['bg_handling_fee'])){
			$shipping_cost += floatval($this->settings['bg_handling_fee']);
		}
				
		$rate = array(
		'id'        => $this->id,
		'label'     => $this->settings['bg_title'] . ' - ' . $this->settings['bg_method_name'],
		'cost'      => $shipping_cost,
		'taxes'     => '',
		'calc_tax'  => 'per_order'
		);
		
		$this->add_rate( $rate );
		
	}
	
	public function checkout_order_processed( $order_id ){
		if(!isset(WC()->session)){
			return false;
		}
		$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
		if($chosen_methods[0] != 'border_guru'){
			return false;
		} 		
		$resp = $this->get_shipping_data($order_id);
		$data = json_decode($resp);
	
		if(!$data || !is_object($data) || $data->response->success !== TRUE){
			$bg_log = new WC_Logger();
			$bg_log->add( 'border-guru', $resp );
			return false;
		}
		
		if(isset($data->response->linkPayment)){
			WC()->session->set('bg_shipping', array('linkPayment' => $data->response->linkPayment));
		}
		update_post_meta( $order_id, 'bg_shipment_id', $data->response->result);
		update_post_meta( $order_id, 'bg_tracking_link', $data->response->linkTracking);
	}
	
	/**
	 * Display BorderGuru shipping data
	 *
	 * @access public
	 * @return void
	 */
	public function display_bg_shipping_data($order){
		if(empty($order)){
			return;
		}
		if($bg_shipment_id = get_post_meta( $order->id, 'bg_shipment_id', true)){
			echo '<p><strong>' . __('Shippment ID', 'wbgs') . ': </strong><br/>' . $bg_shipment_id . '</p>';
		}
		if($bg_payment_link = get_post_meta( $order->id, 'bg_payment_link', true)){
			echo '<p><strong>' . __('Payment link', 'wbgs') . ': </strong><br/><a href="' . $bg_payment_link . '" target="_blank">' . $bg_payment_link . '</a></p>';
		}
		if($bg_tracking_link = get_post_meta( $order->id, 'bg_tracking_link', true)){
			echo '<p><a href="' . $bg_tracking_link . '" target="_blank">' . __('Track this shipment', 'wbgs') . '</a></p>';
		}
		if(!empty($bg_shipment_id)){
			$dir = wp_upload_dir();
			$dir_path = $dir['basedir'] . '/border_guru/labels';
			$dir_url = $dir['baseurl'] . '/border_guru/labels';
			$filepath = $dir_path . '/label_' . $bg_shipment_id . '.pdf';
			$fileurl = $dir_url . '/label_' . $bg_shipment_id . '.pdf';
		
			?>			
			 <?php if (file_exists($filepath)): ?>
				<a class="button-primary" href="javascript: w=window.open('<?php echo $fileurl; ?>'); w.print(); w.close(); "><?php _e('Print Shipping Label', 'wbgs'); ?></a> 
			<?php else: ?>
				<a class="button-primary" href="<?php echo esc_url( add_query_arg(array('bg_action' => 'create_label', 'sid' => $bg_shipment_id)) ); ?>"><?php _e('Create Shipping Label...', 'wbgs'); ?></a>
			<?php endif;		
		}
	}
	
	//Quote API
	/**
	 * Get BorderGuru quote data by
	 *
	 * @access public	 
	 * @return string
	 */
	public function get_quote_data(){									
		$key = $this->settings['bg_key'];
		$secret = $this->settings['bg_secret'];

		$requestHandler = new RequestHandler($key, $secret, $this->api_url);
		$data = $requestHandler->request('POST', 'api/quotes/calculate', $this->get_quote_params());
		return $data;
	}
	/**
	 * Get BorderGuru quote parameters
	 *
	 * @access public	 
	 * @return array
	 */
	public function get_quote_params(){
		global $woocommerce;
		$shipping_packages = $woocommerce->cart->get_shipping_packages();
		$quoteParams = Array(
			'totalWeight'=> floatval($woocommerce->cart->cart_contents_weight),
			'totalWeightScale'=> get_option('woocommerce_weight_unit'),				
			'countryOfOrigin'=> $woocommerce->countries->get_base_country(),
			'countryOfDestination'=> $shipping_packages[0]['destination']['country'],
			'currency'=> get_woocommerce_currency(),
			'lineItems'=> $this->get_cart_items(),
			'merchantIdentifier'=> $this->settings['bg_id'],
			'subtotal' => floatval($woocommerce->cart->cart_contents_total),
		);
		return $quoteParams;
	}

	//Shipping API
	/**
	 * Get BorderGuru shipping data by order ID
	 *
	 * @access public
	 * @param int $order_id
	 * @return string
	 */
	public function get_shipping_data($order_id){
		$key = $this->settings['bg_key'];
		$secret = $this->settings['bg_secret'];
		$requestHandler = new RequestHandler($key, $secret, $this->api_url);
		$data = $requestHandler->request('POST', 'api/shipping', $this->get_shipping_params($order_id));
		return $data;
	}
	
	/**
	 * Get BorderGuru shipping parameters
	 *
	 * @access public
	 * @return array
	 */
	public function get_shipping_params($order_id){
		if(!isset(WC()->session)){
			return false;
		}
		global $woocommerce;
		$order = new WC_Order( $order_id );
		$bg_shipp_data = WC()->session->get('bg_shipping');
		$shipping_packages = $woocommerce->cart->get_shipping_packages();
		$shippingAddress = Array(
			Array(
				'firstName'=> $order->shipping_first_name,
				'lastName'=> $order->shipping_last_name,
				'streetName'=> $order->shipping_address_1,
				'houseNo'=> !empty($order->shipping_address_2) ? $order->shipping_address_2 : $order->shipping_address_1,
				'additionalInfo'=> $order->customer_note,
				'postcode'=>$order->shipping_postcode,
				'city'=> $order->shipping_city,
				'country'=> WC()->countries->countries[ $order->shipping_country ],
				'telephone'=> $order->billing_phone,
				'email'=> $order->billing_email,
				'countryCode'=> $order->shipping_country,
			)
		);

		$billingAddress = Array(
			Array(
				'firstName'=> $order->billing_first_name,
				'lastName'=> $order->billing_last_name,
				'streetName'=> $order->billing_address_1,
				'houseNo'=> !empty($order->billing_address_2) ? $order->billing_address_2 : $order->billing_address_1,
				'additionalInfo'=> $order->customer_note,
				'postcode'=>$order->billing_postcode,
				'city'=> $order->billing_city,
				'country'=> WC()->countries->countries[ $order->billing_country ],
				'telephone'=> $order->billing_phone,
				'email'=> $order->billing_email,
				'countryCode'=> $order->billing_country,
			)
		);
				
		$shippingParams = array(
			'merchantIdentifier'=> $this->settings['bg_id'],
			'quoteIdentifier' => $bg_shipp_data['quoteIdentifier'] ,
			'merchantOrderId' => $order_id,
			'storeName' =>  bloginfo('name'),
			'subtotal' => $woocommerce->cart->cart_contents_total,
			'totalWeightScale'=> get_option('woocommerce_weight_unit'),	
			'countryOfOrigin'=> $woocommerce->countries->get_base_country(),
			'currency'=> get_woocommerce_currency(),
			'countryOfDestination'=> $shipping_packages[0]['destination']['country'],
			'shippingAddress' => $shippingAddress,
			'billingAddress' => $billingAddress,
			'lineItems'=> $this->get_cart_items(),
			'totalWeight'=> $woocommerce->cart->cart_contents_weight,
		);
		
		return $shippingParams;
	}
	
	/**
	 * Get BorderGuru shipment label by shipment ID
	 *
	 * @access public
	 * @param int $shipment_id
	 * @return boolean
	 */
	public function get_shipment_lable(){
		if(	!isset($_GET['sid']) || empty($_GET['sid']) ||
			!isset($_GET['bg_action']) || $_GET['bg_action'] != 'create_label' ){
				return false;
		}
		global $woocommerce;
		$shipment_id = $_GET['sid'];
		$key = $this->settings['bg_key'];
		$secret = $this->settings['bg_secret'];
		$dir_path = wp_upload_dir();
		$dir_path = $dir_path['basedir'] . '/border_guru/labels';
		if (!is_dir($dir_path)){
			if(!@mkdir($dir_path, 0755, true)){
				$mess = __('Error: can\'t create', 'wbgs') . ' ' . $dir_path . ' ' . __('directory', 'wbgs');
				$bg_log = new WC_Logger();
				$bg_log->add( 'border-guru', $mess );
				return false;
			}
		}
		$requestHandler = new RequestHandler($key, $secret, $this->api_url);
		$fileName = $dir_path . '/label_' . $shipment_id . '.pdf';
		
		if($requestHandler->downloadRequest('GET','api/orders/label/' . $shipment_id, $fileName)){
			return true;
		}
		
		return false;
	}
	
	/**
	 * Adds Payment Link to the order email.
	 *
	 * @access public
	 */
	public function add_payment_link_to_order_email( $order, $sent_to_admin ) {   
		if($this->settings['bg_split_checkout'] == 'no' || $sent_to_admin || !isset(WC()->session)){
			return;
		}
		$bg_shipp_data = WC()->session->get('bg_shipping');
		if(isset($bg_shipp_data['linkPayment']) && !empty($bg_shipp_data['linkPayment'])){
			update_post_meta( $order->id, 'bg_payment_link', $bg_shipp_data['linkPayment']);
			echo '<p>' . sprintf( __('Please pay tax and duties by', 'wbgs') . ' <a href="%s" target="_blank">' . __('this link', 'wbgs') . '</a>', $bg_shipp_data['linkPayment'] ) . '</p>';
		}	  
	}
						
	/**
	 * get_cart_items function.
	 *
	 * @access public
	 * 
	 * @return array
	 */
	public function get_cart_items() {
		global $woocommerce;
		$items =array();
		foreach($woocommerce->cart->cart_contents as $iid => $item){
			$categories = array();
			if($terms = get_the_terms( $item['data']->post->ID, 'product_cat' )){	
				foreach($terms as $key => $term){
					if($term->parent == 0){
						$categories[] = $term->name;
					}
				}
			}
			$items[] = array(
				'sku' => $item['data']->get_sku(),
				'shortDescription' => $item['data']->post->post_title,
				'category' => mb_strtolower($categories[0]),
				'price' => $item['data']->price,
				'weight' => $item['data']->weight,
				'weightScale' => get_option('woocommerce_weight_unit'),
				'quantity' => $item['quantity'],
			);
		}
		return $items;
	}

  /**
   * get response of request.
   *
   * @access public
   *
   * @return array
   */
  public function request($action, $route, $params){
      $res = $this->_handler->request($action,$route,$params);

      return $res;
  }

}
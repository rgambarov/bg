<?php

/**
 * The core class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package     Woocommerce BorderGuru Shipping
 * @author     	W4PRO
 */
class Wbgs {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Plugin_Name_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		$this->plugin_name = 'wbgs';
		$this->version = '1.0.0';		
		$this->define_constants();
		$this->includes();
		$this->set_locale();
		$this->init_hooks();
	}
	
	/**
	 * Define Constants
	 */
	private function define_constants() {
		$upload_dir = wp_upload_dir();
		/** Plugin directory */
		define( 'WBGS_DIR_PATH', plugin_dir_path( dirname( __FILE__ ) ));		
	}

	/**
	 * Define constant if not already set
	 * @param  string $name
	 * @param  string|bool $value
	 */
	private function define( $name, $value ) {
		if ( ! defined( $name ) ) {
			define( $name, $value );
		}
	}

	/**
	 * Load the required dependencies
	 *
	 * 
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function includes() {

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wbgs-loader.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wbgs-i18n.php';
		
		$this->loader = new Plugin_Name_Loader();

	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Plugin_Name_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {

		$Wbgs_i18n = new Wbgs_i18n();
		$Wbgs_i18n->set_domain( $this->get_plugin_name() );

		$this->loader->add_action( 'plugins_loaded', $Wbgs_i18n, 'load_plugin_textdomain' );

	}
	
	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function init_hooks() {
		add_action('woocommerce_shipping_init', array($this, 'border_guru_shipping_method_init'));				
	}
			
	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Plugin_Name_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}
	
	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function border_guru_shipping_method_init() {
		if ( ! class_exists( 'WC_Shipping_Method' ) ){
      return;
    }
		require_once (WBGS_DIR_PATH . '/includes/library/OAuthSimple.php');
		require_once (WBGS_DIR_PATH . '/includes/library/RequestHandler.php');		
		require_once (WBGS_DIR_PATH . '/includes/border-guru-shipping-method.php');		
		add_filter('woocommerce_shipping_methods', array($this, 'add_border_guru_shipping_method'));
		add_action( 'woocommerce_cart_calculate_fees', array($this, 'woo_add_cart_fee') );
	}
	
	/**
	 * Adds dutes and taxes to the cart.
	 *
	 * @since     1.0.0
	 * @return    void.
	 */
	public function woo_add_cart_fee() {
		if(!isset(WC()->session)){
			return false;
		}
		$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
		if($chosen_methods[0] != 'border_guru'){
			return false;
		}		
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ){
      return;
    }
		global $woocommerce;
		$shipping_data = WC()->session->get('bg_shipping');
		if($shipping_data['bg_split_checkout'] == 'no'){
			$woocommerce->cart->add_fee( __('Duty and Tax', 'woocommerce'), $shipping_data['taxes'], true, 'standard' );			
		}
		else{
			$woocommerce->cart->cart_contents_total = $woocommerce->cart->cart_contents_total - $shipping_data['taxes'];
			$woocommerce->cart->add_fee( __('Duty and Tax (Payable to BorderGuru after checkout)', 'woocommerce'), $shipping_data['taxes'], true, 'standard' );
		}			
	}
	
	/**
	 * Add BorderGuru shipping method.
	 *
	 * @since     1.0.0
	 * @return    array.
	 */
	public function add_border_guru_shipping_method($methods) {
		$methods[] = 'Border_Guru_Shipping_Method';
		return $methods;
	}
		
}

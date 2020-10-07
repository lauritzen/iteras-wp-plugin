<?php
/**
 * ITERAS
 *
 * @package   Iteras
 * @author    ITERAS Team <team@iteras.dk>
 * @license   GPL-2.0+
 * @link      http://www.iteras.dk
 * @copyright 2015 ITERAS ApS
 */

/**
 * @package Iteras
 * @author  ITERAS Team <team@iteras.dk>
 */
class Iteras {

  const VERSION = '1.3.5';

  const SETTINGS_KEY = "iteras_settings";
  const POST_META_KEY = "iteras_paywall";
  const DEFAULT_ARTICLE_SNIPPET_SIZE = 300;

  protected $plugin_slug = 'iteras';

  protected static $instance = null;

  public $settings = null;


  private function __construct() {
    // run migrations if needed
    self::migrate();

    // Load plugin text domain
    add_action( 'init', array( $this, 'load_settings' ) );
    add_action( 'init', array( $this, 'load_plugin_textdomain' ) );

    // Activate plugin when new blog is added
    add_action( 'wpmu_new_blog', array( $this, 'activate_new_site' ) );

    // Load public-facing style sheet and JavaScript.
    add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
    add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

    add_filter( 'the_content', array( $this, 'potentially_paywall_content_filter' ), 99 );

    add_shortcode( 'iteras-ordering', array( $this, 'ordering_shortcode') );
    add_shortcode( 'iteras-paywall-login', array( $this, 'paywall_shortcode') );
    add_shortcode( 'iteras-selfservice', array( $this, 'selfservice_shortcode') );

    add_shortcode( 'iteras-paywall-content', array( $this, 'paywall_content_shortcode') );

    add_shortcode( 'iteras-return-to-page', array( $this, 'return_to_page_shortcode') );

    add_shortcode( 'iteras-signup', array( $this, 'signup_shortcode') ); // deprecated
  }


  public function get_plugin_slug() {
    return $this->plugin_slug;
  }


  public static function get_instance() {
    if ( null == self::$instance ) {
      self::$instance = new self;
    }

    return self::$instance;
  }


  public static function activate( $network_wide ) {
    if ( function_exists( 'is_multisite' ) && is_multisite() ) {

      if ( $network_wide  ) {

	// Get all blog ids
	$blog_ids = self::get_blog_ids();

	foreach ( $blog_ids as $blog_id ) {

	  switch_to_blog( $blog_id );
	  self::single_activate();

	  restore_current_blog();
	}

      } else {
	self::single_activate();
      }

    } else {
      self::single_activate();
    }

  }


  public static function deactivate( $network_wide ) {

    if ( function_exists( 'is_multisite' ) && is_multisite() ) {

      if ( $network_wide ) {

	// Get all blog ids
	$blog_ids = self::get_blog_ids();

	foreach ( $blog_ids as $blog_id ) {

	  switch_to_blog( $blog_id );
	  self::single_deactivate();

	  restore_current_blog();

	}

      } else {
	self::single_deactivate();
      }

    } else {
      self::single_deactivate();
    }

  }


  public function activate_new_site( $blog_id ) {

    if ( 1 !== did_action( 'wpmu_new_blog' ) ) {
      return;
    }

    switch_to_blog( $blog_id );
    self::single_activate();
    restore_current_blog();

  }


  public static function uninstall() {
    delete_option(self::SETTINGS_KEY);
    //delete_metadata( 'post', null, Iteras_Admin::$POST_META_KEY, null, true );
  }


  private static function get_blog_ids() {

    global $wpdb;

    // get an array of blog ids
    $sql = "SELECT blog_id FROM $wpdb->blogs
			WHERE archived = '0' AND spam = '0'
			AND deleted = '0'";

    return $wpdb->get_col( $sql );

  }


  private static function single_activate() {
    self::migrate();
  }

  private static function migrate() {
    $settings = get_option(self::SETTINGS_KEY);
    if (empty($settings))
      return;

    $old_version = $settings['version'];
    $new_version = self::VERSION;

    if (!empty($settings) and version_compare($new_version, $old_version, "gt")) {
      // do version upgrades here
      if (version_compare($old_version, "0.3", "le")) {
        $settings['paywall_display_type'] = 'redirect';
        $settings['paywall_box'] = '';
        $settings['paywall_snippet_size'] = self::DEFAULT_ARTICLE_SNIPPET_SIZE;
      }
      if (version_compare($old_version, "0.4.5", "le")) {
        $settings['paywall_integration_method'] = 'auto';
      }
      if (version_compare($old_version, "1.0", "lt")) {
        $settings['api_key'] = '';
        $settings['paywalls'] = array();
      }
      if (version_compare($old_version, "1.2", "lt")) {
        $settings['paywall_server_side_validation'] = true;
      }

      wp_cache_delete(self::SETTINGS_KEY);
      $settings['version'] = $new_version;
      update_option(self::SETTINGS_KEY, $settings);
    }
  }

  public static function reset_plugin() {
    $posts = get_posts(array(
      'post_type' => 'post'
    ));

    foreach ($posts as $post) {
      delete_post_meta($post->ID, Iteras::POST_META_KEY);
    }

    update_option(self::SETTINGS_KEY, false);
  }

  public function migrate_posts_to_multi_paywall() {
    $posts = get_posts(array(
      'post_type' => 'post'
    ));

    $all_paywall_ids = $this->get_paywall_ids();
    foreach ($posts as $post) {
      $data = get_post_meta($post->ID, self::POST_META_KEY, true);
      _log($post->post_title);

      if (!isset($data)) {
        // pass
        _log("Post not paywalled");
      }
      elseif (is_array($data)) {
        // pass
        _log("Post paywall up-to-date");
      }
      elseif (in_array($data, array("user", "sub"))) {
        update_post_meta($post->ID, Iteras::POST_META_KEY, $all_paywall_ids);
        _log("Post paywall set to all paywalls");
      }
      elseif ($data === "") {
        update_post_meta($post->ID, Iteras::POST_META_KEY, array());
        _log("Post paywall set to no paywall");
      }
      else {
        delete_post_meta($post->ID, Iteras::POST_META_KEY);
        _log("Post paywall removed");
      }
    }
  }

  private static function single_deactivate() {
  }


  public function load_plugin_textdomain() {
    // Load the plugin text domain for translation.
    load_plugin_textdomain( $this->plugin_slug, false, plugin_basename(ITERAS_PLUGIN_PATH) . '/languages/' );
  }


  public function load_settings() {
    $settings = get_option(self::SETTINGS_KEY);

    if (empty($settings)) {
      $settings = array(
        'api_key' => "",
        'profile_name' => "", // outphase
        'paywall_id' => "", // outphase
        'subscribe_url' => "",
        'user_url' => "",
        'default_access' => "",
        'paywalls' => array(),
        'paywall_integration_method' => "auto",
        'paywall_server_side_validation' => true,
        'paywall_display_type' => "redirect",
        'paywall_box' => "",
        'paywall_snippet_size' => self::DEFAULT_ARTICLE_SNIPPET_SIZE,
        'version' => self::VERSION,
      );

      add_option(self::SETTINGS_KEY, $settings);
    }

    $this->settings = $settings;
  }


  public function save_settings($settings) {
    wp_cache_delete(self::SETTINGS_KEY);
    $settings['version'] = self::VERSION;
    update_option(self::SETTINGS_KEY, $settings);
    $this->settings = $settings;
  }


  public function get_paywall_ids() {
    $paywalls = $this->settings['paywalls'];

    $paywall_ids = array();
    // paywall id backwards compatibility
    if (!isset($paywalls) || !$paywalls || empty($paywalls)) {
      $id = array_get($this->settings, 'paywall_id', '');
      if ($id != "")
        $paywall_ids = array($id);
    }
    elseif (!empty($paywalls)) {
      $paywall_ids = array_map(function($i) { return $i['paywall_id']; }, $paywalls);
    }

    return $paywall_ids;
  }


  public function enqueue_styles() {
    global $wp_styles;
    wp_enqueue_style( $this->plugin_slug . '-plugin-styles', plugins_url( 'assets/css/public.css', __FILE__ ), array(), self::VERSION );
    wp_enqueue_style( $this->plugin_slug . '-plugin-styles-ie', plugins_url( 'assets/css/ie.css', __FILE__ ), array(), self::VERSION );
    $wp_styles->add_data( $this->plugin_slug . '-plugin-styles-ie', 'conditional', 'IE 9' );
  }


  public function enqueue_scripts() {
    $version = self::VERSION;
    // include the iteras javascript api
    $url = ITERAS_BASE_URL ."/static/api/iteras.js";
    if (ITERAS_DEBUG) {
      wp_enqueue_script( $this->plugin_slug . '-api-script-debug',  ITERAS_BASE_URL . "/static/api/debug.js");
      // cache buster
      $version = "".time();
    }

    wp_enqueue_script( $this->plugin_slug . '-api-script', $url, array(), $version );

    wp_enqueue_script( $this->plugin_slug . '-plugin-script-truncate', plugins_url( 'assets/js/truncate.js', __FILE__ ), array( 'jquery' ), $version );
    wp_enqueue_script( $this->plugin_slug . '-plugin-script-box', plugins_url( 'assets/js/box.js', __FILE__ ), array( 'jquery' ), $version );
  }

  public function potentially_paywall_content_filter($content) {
    if ( $this->settings['paywall_integration_method'] == "auto" ) {
      $content = $this->potentially_paywall_content($content);
    }
    return $content;
  }

  private function pass_authorized($pass, $restriction, $key) {
    // check signature
    $pos = strrpos($pass, "/");
    $data = substr($pass, 0, $pos);
    $sig = substr($pass, $pos + 1);
    $exp_sig = explode(":", $sig);
    $algo = $exp_sig[0];
    $hmac = $exp_sig[1];

    $algo = array_get(array(
      'sha1' => 'sha1',
      'sha256' => 'sha256'
    ), $algo);

    if (!$algo)
      return false;

    $computed_hmac = hash_hmac($algo, $data, $key);

    if ($computed_hmac !== false && $key && (function_exists("hash_equals") ? !hash_equals($computed_hmac, $hmac) : $computed_hmac != $hmac))
      return false;

    $parts = explode("|", $data);

    // check expiry
    $expiry = strtotime($parts[2]);
    if ($expiry === false || $expiry < time())
      return false;
    
    // check access
    if (count($parts) >= 2) {
      $access_levels = explode(",", $parts[0]);
      $paywall_ids = explode(",", $parts[1]);

      $access = array_combine($paywall_ids, $access_levels);

      foreach ($restriction as $r) {
        if (array_get($access, $r) == "sub") {
          return true;
        }
      }
    }
    return false;
  }

  public function potentially_paywall_content($content) {
    global $post;

    if (!is_singular() || !in_the_loop())
      return $content;

    $paywall_ids = get_post_meta( $post->ID, self::POST_META_KEY, true );

    // backwards compatibility
    if (!is_array($paywall_ids) && in_array($paywall_ids, array("user", "sub"))) {
      $paywall_ids = $this->get_paywall_ids();
    }

    $extra = "";
    // show message without paywall for editors
    if (current_user_can('edit_pages') && !empty($paywall_ids)) {
      $content = '<div class="iteras-paywall-notice"><b>'.__("This content is paywalled").'</b><br>'.__("You are seeing the content because you are logged into WordPress admin").'</div>'.$content;
    }
    // paywall the content
    else if (!empty($paywall_ids)) {
      if ($this->settings['paywall_display_type'] == "samepage") {

        if ($this->settings['paywall_box'])
          $box_content = do_shortcode($this->settings['paywall_box']);
        else
          $box_content = "<p>" + __("ITERAS plugin improperly configured. Paywall box content is missing", $this->plugin_slug) + "</p>";

        $box = sprintf(
          file_get_contents(plugin_dir_path( __FILE__ ) . 'views/box.php'),
          $this->settings['paywall_snippet_size'],
          $box_content
        );

        $extra = $box.'<script>Iteras.wall({ unauthorized: iterasPaywallContent, paywallid: '.json_encode($paywall_ids).' });</script>';

        /**
         * Filters the prepared paywall script before adding to the end of content
         *
         * @since 1.3.5
         *
         * @param string $extra The script with script tags included
         */
        $extra = apply_filters('after_paywall_script_prepared_except_redirect', $extra);
      }
      else {
        $extra = '<script>Iteras.wall({ redirect: "'.$this->settings['subscribe_url'].'", paywallid: '.json_encode($paywall_ids).' });</script>';
      }

      $truncate_class = "";
      if ($this->settings['paywall_server_side_validation'] &&
          !(isset($_COOKIE['iteraspass'])
         && $this->pass_authorized($_COOKIE['iteraspass'], $paywall_ids, $this->settings['api_key']))) {
        $content = truncate_html($content, array_get($this->settings, 'paywall_snippet_size', self::DEFAULT_ARTICLE_SNIPPET_SIZE));
        $truncate_class = "iteras-content-truncated";
        if (!isset($_COOKIE['iteraspass']))
          $truncate_class .= " iteras-no-pass";
        else
          $truncate_class .= " iteras-invalid-pass";
      }

      $content = '<div class="iteras-content-wrapper '.$truncate_class.'">'.$content.'</div>'.$extra;
    }
    
    return $content;
  }

  function combine_attributes($attrs) {
    if (!$attrs or empty($attrs))
      return "";

    $transformed = array();

    foreach ($attrs as $key => $value) {
      if (is_array($value)) {
        array_push($transformed, '"'.$key.'": '.json_encode($value));
      }
      elseif ($value) {
        array_push($transformed, '"'.$key.'": "'.$value.'"');
      }
    }

    if (!empty($transformed))
      return ", ".join(", ", $transformed);
    else
      return "";
  }


  // [iteras-paywall-content]...[/iteras-paywall-content]
  function paywall_content_shortcode($attrs, $content = null) {
    return $this->potentially_paywall_content($content);
  }


  // [iteras-ordering orderingid="3for1"]
  function ordering_shortcode($attrs) {
    return '<script>
      document.write(Iteras.orderingiframe({
        "profile": "'.$this->settings['profile_name'].'"'.$this->combine_attributes($attrs).'
      }));</script>';
  }

  // [iteras-signup signupid="3for1"]
  function signup_shortcode($attrs) {
    return '<script>
      document.write(Iteras.signupiframe({
        "profile": "'.$this->settings['profile_name'].'"'.$this->combine_attributes($attrs).'
      }));</script>';
  }


  // [iteras-paywall-login paywallid="abc123,def456"]
  function paywall_shortcode($attrs) {
    if (!empty($attrs) && in_array('paywallid', $attrs)) {
      $paywall_ids = $attrs['paywallid'];
      $paywall_ids = preg_replace('/\s*,\s*/', ',', filter_var($paywall_ids, FILTER_SANITIZE_STRING));
      $paywall_ids = explode(',', $paywall_ids);
      $attrs['paywallid'] = $paywall_ids;
    }
    else {
      $attrs['paywallid'] = $this->get_paywall_ids();
    }

    if (empty($attrs['paywallid'])) {
      return '<!-- ITERAS paywall enabled but not configured properly: missing paywalls, sync in settings -->';
    }
    else {
      return '<script>
      document.write(Iteras.paywalliframe({
        "profile": "'.$this->settings['profile_name'].'"'.$this->combine_attributes($attrs).'
      }));</script>';
    }
  }


  // [iteras-selfservice]
  function selfservice_shortcode($attrs) {
    return '<script>
      document.write(Iteras.selfserviceiframe({
        "profile": "'.$this->settings['profile_name'].'"'.$this->combine_attributes($attrs).'
      }));</script>';
  }

  // [iteras-return-to-page url='/?p=1']
  function return_to_page_shortcode($attrs) {
    $iterasnext = "iterasnext=" . urlencode($_SERVER["REQUEST_URI"]);

    $parsed = parse_url($attrs['url']);
    if (isset($parsed['query']))
      $parsed['query'] .= "&";
    $parsed['query'] .= $iterasnext;

    return unparse_url($parsed);
  }  
}

<?php
/**
 * Plugin Name: Wings & Wheels Registration Manager
 * Description: Handles registrations for Cars, Planes, Bikes, and Traders for the Wings & Wheels Henstridge event.
 * Version: 0.7
 * Author: Dave R
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Add reCAPTCHA keys (update with your actual keys)
if (!defined('RECAPTCHA_SITE_KEY')) {
    define('RECAPTCHA_SITE_KEY', '6LeYO_QqAAAAAPV0rEXQ1Y0EOJn1K_xZTrEqX0qr');
}
if (!defined('RECAPTCHA_SECRET_KEY')) {
    define('RECAPTCHA_SECRET_KEY', '6LeYO_QqAAAAAJ4q_wc-x_NAVV3Bwq5tSqiNsfU6');
}

class WW_Registration_Manager {
    private static $instance = null;
    private $table_name;

    // Categories
    private $categories = array('car', 'aircraft', 'motorcycle', 'trader');

    // Statuses
    private $statuses = array('pending', 'accepted', 'rejected', 'waitlisted', 'cancelled');

    private $default_caps = array(
        'car' => 200,
        'aircraft' => 50,
        'motorcycle' => 20,
        'trader' => 20
    );

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ww_registrations';

        register_activation_hook(__FILE__, array($this, 'activate'));

        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_shortcode('ww_registration', array($this, 'render_form_shortcode'));
        add_action('admin_post_ww_handle_form', array($this, 'handle_form_submission'));
        add_action('admin_post_nopriv_ww_handle_form', array($this, 'handle_form_submission'));

        add_action('wp_ajax_ww_deregister', array($this, 'handle_deregistration'));
        add_action('wp_ajax_nopriv_ww_deregister', array($this, 'handle_deregistration'));

        add_action('admin_post_ww_approve_registration', array($this, 'approve_registration'));
        add_action('admin_post_ww_reject_registration', array($this, 'reject_registration'));
        add_action('admin_post_ww_export_csv', array($this, 'export_csv'));
        // NEW: Action to handle deletion of rejected registrations
        add_action('admin_post_ww_delete_registration', array($this, 'delete_registration'));

        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_assets'));
    }

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$this->table_name} (
          id mediumint(9) NOT NULL AUTO_INCREMENT,
          category varchar(20) NOT NULL,
          status varchar(20) NOT NULL,
          name varchar(200) NOT NULL,
          location varchar(200) NOT NULL,
          email varchar(200) NOT NULL,
          contact_number varchar(50) NOT NULL,
          make_model varchar(200) NULL,
          vrn varchar(50) NULL,
          colour varchar(100) NULL,
          year_built varchar(20) NULL,
          interesting_fact text NULL,
          tail_number varchar(50) NULL,
          home_airfield varchar(200) NULL,
          arriving_from varchar(200) NULL,
          departing_to varchar(200) NULL,
          address text NULL,
          business_name varchar(200) NULL,
          vat_registered varchar(10) NULL,
          public_liability varchar(10) NULL,
          what_selling text NULL,
          charity_number varchar(50) NULL,
          anticipated_arrival_time varchar(50) NULL,
          agree_cost varchar(5) NOT NULL DEFAULT 'no',
          unique_hash varchar(50) NULL,
          date_submitted datetime DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id)
        ) $charset_collate;";
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta($sql);

        if (get_option('ww_event_date') === false) {
            add_option('ww_event_date', '2025-06-01');
        }
        if (get_option('ww_deregistration_expiry') === false) {
            add_option('ww_deregistration_expiry', '2025-05-31');
        }
        if (get_option('ww_entry_price') === false) {
            add_option('ww_entry_price', '10');
        }
        if (get_option('ww_pitch_fee') === false) {
            add_option('ww_pitch_fee', '30');
        }

        foreach ($this->categories as $cat) {
            if (get_option("ww_cap_{$cat}") === false) {
                add_option("ww_cap_{$cat}", $this->default_caps[$cat]);
            }
            if (get_option("ww_tnc_{$cat}") === false) {
                add_option("ww_tnc_{$cat}", "Default terms and conditions for $cat");
            }
            if (get_option("ww_email_accept_{$cat}") === false) {
                add_option("ww_email_accept_{$cat}", "Dear [NAME], you are accepted with your [CATEGORY]!");
            }
            if (get_option("ww_email_reject_{$cat}") === false) {
                add_option("ww_email_reject_{$cat}", "Dear [NAME], unfortunately we cannot accept your [CATEGORY].");
            }
            if (get_option("ww_email_waitlist_{$cat}") === false) {
                add_option("ww_email_waitlist_{$cat}", "Dear [NAME], you are on the waitlist for [CATEGORY]. We will contact you if a space opens up.");
            }
        }
        if (get_option('ww_email_thanks') === false) {
            add_option('ww_email_thanks', "Dear [NAME], thanks for registering for the event on [EVENT_DATE]. We'll be in touch.");
        }
    }

    public function enqueue_frontend_assets() {
        // Enqueue Bootstrap CSS and JS on the front end
        wp_enqueue_style('bootstrap-css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css', array(), '5.1.3');
        wp_enqueue_script('bootstrap-js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js', array('jquery'), '5.1.3', true);
        // Enqueue reCAPTCHA script using WordPress enqueue
        wp_enqueue_script('google-recaptcha', 'https://www.google.com/recaptcha/api.js', array(), null, true);
    }

    public function admin_enqueue_assets($hook) {
        // Enqueue Bootstrap and WordPress editor scripts on relevant admin pages
        if ($hook === 'toplevel_page_ww_registrations' || $hook === 'ww_settings') {
            wp_enqueue_style('bootstrap-css-admin', 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css', array(), '5.1.3');
            wp_enqueue_script('bootstrap-js-admin', 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js', array('jquery'), '5.1.3', true);

            // Enqueue WordPress editor scripts for the settings page
            wp_enqueue_editor();

            // Add our inline script after bootstrap-js-admin is enqueued
            $js = <<<JS
jQuery(document).ready(function($){
    $('.ww-view-details').on('click', function(){
        var btn = $(this);
        // Populate modal fields
        $('#ww_detail_id').text(btn.data('id'));
        $('#ww_detail_category').text(btn.data('category'));
        $('#ww_detail_status').text(btn.data('status'));
        $('#ww_detail_name').text(btn.data('name'));
        $('#ww_detail_location').text(btn.data('location'));
        $('#ww_detail_email').text(btn.data('email'));
        $('#ww_detail_contact').text(btn.data('contact'));
        $('#ww_detail_make_model').text(btn.data('make_model'));
        $('#ww_detail_vrn').text(btn.data('vrn'));
        $('#ww_detail_colour').text(btn.data('colour'));
        $('#ww_detail_year_built').text(btn.data('year_built'));
        $('#ww_detail_interesting_fact').text(btn.data('interesting_fact'));
        $('#ww_detail_tail_number').text(btn.data('tail_number'));
        $('#ww_detail_home_airfield').text(btn.data('home_airfield'));
        $('#ww_detail_arriving_from').text(btn.data('arriving_from'));
        $('#ww_detail_departing_to').text(btn.data('departing_to'));
        $('#ww_detail_address').text(btn.data('address'));
        $('#ww_detail_business_name').text(btn.data('business_name'));
        $('#ww_detail_public_liability').text(btn.data('public_liability'));
        $('#ww_detail_what_selling').text(btn.data('what_selling'));
        $('#ww_detail_charity_number').text(btn.data('charity_number'));
        $('#ww_detail_anticipated_arrival_time').text(btn.data('anticipated_arrival_time'));
        $('#ww_detail_agree_cost').text(btn.data('agree_cost'));
        $('#ww_detail_date_submitted').text(btn.data('date_submitted'));

        var modalEl = document.getElementById('wwDetailsModal');
        var modal = new bootstrap.Modal(modalEl, {});
        modal.show();
    });
});
JS;
            wp_add_inline_script('bootstrap-js-admin', $js);
        }
    }

    public function admin_menu() {
        add_menu_page('Wings & Wheels', 'W&W Registrations', 'manage_options', 'ww_registrations', array($this, 'render_dashboard'), 'dashicons-tickets', 26);
        add_submenu_page('ww_registrations', 'Settings', 'Settings', 'manage_options', 'ww_settings', array($this, 'render_settings_page'));
    }

    public function register_settings() {
        register_setting('ww_settings_group', 'ww_event_date');
        register_setting('ww_settings_group', 'ww_deregistration_expiry');
        register_setting('ww_settings_group', 'ww_entry_price');
        register_setting('ww_settings_group', 'ww_pitch_fee');

        foreach ($this->categories as $cat) {
            register_setting('ww_settings_group', "ww_cap_{$cat}");
            register_setting('ww_settings_group', "ww_tnc_{$cat}");
            register_setting('ww_settings_group', "ww_email_accept_{$cat}");
            register_setting('ww_settings_group', "ww_email_reject_{$cat}");
            register_setting('ww_settings_group', "ww_email_waitlist_{$cat}");
        }
        register_setting('ww_settings_group', 'ww_email_thanks');
    }

    private function get_fields_for_category($cat) {
        switch ($cat) {
            case 'car':
                return array('make_model','vrn','colour','year_built','interesting_fact');
            case 'aircraft':
                return array('make_model','tail_number','home_airfield','arriving_from','departing_to','year_built','interesting_fact','anticipated_arrival_time');
            case 'motorcycle':
                return array('make_model','vrn','colour','year_built','interesting_fact');
            case 'trader':
                return array('address','business_name','public_liability','what_selling','charity_number');
        }
        return array();
    }

    public function render_form_shortcode($atts) {
        $cat = isset($atts['type']) ? sanitize_key($atts['type']) : '';
        if (!in_array($cat, $this->categories)) {
            return "<p>Invalid category.</p>";
        }

        $event_date = get_option('ww_event_date');
        $event_year = date('Y', strtotime($event_date));
        $entry_price = get_option('ww_entry_price');
        $pitch_fee = get_option('ww_pitch_fee');
        $tnc = get_option("ww_tnc_{$cat}");
        $tnc_text = str_replace('[PITCH_FEE]', $pitch_fee, $tnc);

        ob_start();
        ?>
        <div class="container my-5">
            <div id="registration-alerts"></div>
            <form class="mb-5" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ww_handle_form">
                <input type="hidden" name="category" value="<?php echo esc_attr($cat); ?>">            

                <h2 class="mb-4"><?php echo ucfirst($cat); ?> Registration <?php echo esc_html($event_year); ?></h2>

                <!-- Personal Information Section -->
                <div class="row g-3">
                    <div class="col-md-6">
                        <h4>Personal Information</h4>
                        <div class="mb-3">
                            <label class="form-label">Name</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Contact Number</label>
                            <input type="text" name="contact_number" class="form-control" required>
                        </div>

                        <?php if ($cat === 'car' || $cat === 'motorcycle'): ?>
                            <div class="mb-3">
                                <label class="form-label">Home Town</label>
                                <input type="text" name="location" class="form-control" required>
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="location" value="">
                        <?php endif; ?>
                    </div>

                    <!-- Vehicle/Business Information Section -->
                    <div class="col-md-6">
                        <h4><?php 
                            switch($cat) {
                                case 'car':
                                case 'motorcycle':
                                    echo 'Vehicle Information';
                                    break;
                                case 'aircraft':
                                    echo 'Aircraft Information';
                                    break;
                                case 'trader':
                                    echo 'Business Information';
                                    break;
                            }
                        ?></h4>
                        <?php
                        $fields = $this->get_fields_for_category($cat);
                        foreach ($fields as $field) {
                            echo $this->render_field($field, $cat);
                        }

                        if ($cat === 'trader') {
                            echo '<div class="mb-3"><p>Pitch fee: £' . esc_html($pitch_fee) . '</p>';
                            echo '<div class="form-check">';
                            echo '<input class="form-check-input" type="checkbox" name="agree_cost" value="yes" id="agreeCost" required>';
                            echo '<label class="form-check-label" for="agreeCost">I agree to the £' . esc_html($pitch_fee) . ' pitch fee</label>';
                            echo '</div></div>';
                        }
                        ?>
                    </div>
                </div>

                <!-- Terms & Conditions Section -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="mb-3">
                            <a class="text-decoration-underline" data-bs-toggle="collapse" href="#tncCollapse" role="button" aria-expanded="false" aria-controls="tncCollapse">
                                View Terms & Conditions
                            </a><br>
                            <div class="collapse mt-2" id="tncCollapse">
                                <div class="card card-body">
                                    <?php echo wp_kses_post(wpautop($tnc_text)); ?>
                                    <button type="button" class="btn btn-secondary mt-3" data-bs-toggle="collapse" data-bs-target="#tncCollapse" aria-expanded="false" aria-controls="tncCollapse">
                                        Close
                                    </button>
                                </div>
                            </div>
                            <div class="form-check mt-2">
                                <input type="checkbox" name="accept_tnc" class="form-check-input" id="acceptTnc" required>
                                <label class="form-check-label" for="acceptTnc">I accept the Terms & Conditions</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Captcha and Submit Section -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="g-recaptcha mb-3" data-sitekey="<?php echo RECAPTCHA_SITE_KEY; ?>"></div>
                    </div>
                    <div class="col-md-6 d-flex align-items-center">
                        <button type="submit" class="btn btn-primary">Submit Registration</button>
                    </div>
                </div>
            </form>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('input[name="email"]').on('blur', function() {
                var email = $(this).val();
                var category = '<?php echo esc_js($cat); ?>';
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'check_email_exists',
                        email: email,
                        category: category
                    },
                    success: function(response) {
                        if(response.exists) {
                            alert('This email has already been registered for this category.');
                            $('button[type="submit"]').prop('disabled', true);
                        } else {
                            $('button[type="submit"]').prop('disabled', false);
                        }
                    }
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    private function render_field($field, $cat) {
        switch ($field) {
            case 'make_model':
                return '<div class="mb-3"><label class="form-label">Make & Model</label><input type="text" name="make_model" class="form-control" required></div>';
            case 'vrn':
                return '<div class="mb-3"><label class="form-label">Registration Number</label><input type="text" name="vrn" class="form-control"></div>';
            case 'colour':
                return '<div class="mb-3"><label class="form-label">Colour</label><input type="text" name="colour" class="form-control"></div>';
            case 'year_built':
                return '<div class="mb-3"><label class="form-label">Approx Year Built</label><input type="text" name="year_built" class="form-control"></div>';
            case 'interesting_fact':
                return '<div class="mb-3"><label class="form-label">Interesting Fact</label><textarea name="interesting_fact" class="form-control"></textarea></div>';
            case 'tail_number':
                return '<div class="mb-3"><label class="form-label">Tail Number</label><input type="text" name="tail_number" class="form-control"></div>';
            case 'home_airfield':
                return '<div class="mb-3"><label class="form-label">Home Airfield</label><input type="text" name="home_airfield" class="form-control"></div>';
            case 'arriving_from':
                return '<div class="mb-3"><label class="form-label">Arriving From</label><input type="text" name="arriving_from" class="form-control"></div>';
            case 'departing_to':
                return '<div class="mb-3"><label class="form-label">Departing To</label><input type="text" name="departing_to" class="form-control"></div>';
            case 'anticipated_arrival_time':
                return '<div class="mb-3"><label class="form-label">Anticipated Arrival Time Henstridge</label><input type="text" name="anticipated_arrival_time" class="form-control"></div>';
            case 'address':
                return '<div class="mb-3"><label class="form-label">Address</label><textarea name="address" class="form-control"></textarea></div>';
            case 'business_name':
                return '<div class="mb-3"><label class="form-label">Business Name</label><input type="text" name="business_name" class="form-control"></div>';
            case 'public_liability':
                return '<div class="mb-3"><label class="form-label">Public Liability Insurance</label><select name="public_liability" class="form-select"><option value="yes">Yes</option><option value="no">No</option></select></div>';
            case 'what_selling':
                return '<div class="mb-3"><label class="form-label">What will you be selling or promoting?</label><textarea name="what_selling" class="form-control"></textarea></div>';
            case 'charity_number':
                return '<div class="mb-3"><label class="form-label">If a charity, put Charity number here</label><input type="text" name="charity_number" class="form-control"></div>';
        }
        return '';
    }

    public function handle_form_submission() {
        if (!isset($_POST['category'])) {
            wp_die('Missing category');
        }

        $cat = sanitize_key($_POST['category']);
        if (!in_array($cat, $this->categories)) {
            wp_die('Invalid category');
        }

        // Add email check here
        $email = sanitize_email($_POST['email']);
        global $wpdb;
        
        // Check if email exists for this category
        $existing_registration = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
            WHERE email = %s 
            AND category = %s 
            AND status NOT IN ('rejected', 'cancelled')",
            $email,
            $cat
        ));

        if ($existing_registration) {
            // Redirect back to form with error message
            $redirect_url = add_query_arg(
                array(
                    'registration_error' => 'email_exists',
                    'category' => $cat
                ),
                wp_get_referer()
            );
            wp_safe_redirect($redirect_url);
            exit;
        }

        // Verify reCAPTCHA response
        $recaptcha_response = isset($_POST['g-recaptcha-response']) ? $_POST['g-recaptcha-response'] : '';
        if (empty($recaptcha_response)) {
            wp_die('Captcha verification failed.');
        }
        $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', array(
            'body' => array(
                'secret'   => RECAPTCHA_SECRET_KEY, // use the secret key constant here
                'response' => $recaptcha_response,
                'remoteip' => $_SERVER['REMOTE_ADDR']
            )
        ));
        $response_body = wp_remote_retrieve_body($response);
        $result = json_decode($response_body, true);
        if (empty($result['success']) || $result['success'] !== true) {
            wp_die('Captcha verification failed.');
        }

        $data = array(
            'name' => sanitize_text_field($_POST['name']),
            'location' => ($cat === 'car' || $cat === 'motorcycle') ? sanitize_text_field($_POST['location']) : '',
            'email' => $email,
            'contact_number' => sanitize_text_field($_POST['contact_number']),
            'category' => $cat,
            'status' => 'pending',
            'unique_hash' => wp_generate_password(20, false),
            'agree_cost' => 'no'
        );

        $fields = $this->get_fields_for_category($cat);
        foreach ($fields as $f) {
            $data[$f] = isset($_POST[$f]) ? sanitize_text_field($_POST[$f]) : '';
        }

        if ($cat === 'trader') {
            if (!isset($_POST['agree_cost']) || $_POST['agree_cost'] !== 'yes') {
                wp_die('You must agree to the pitch fee.');
            } else {
                $data['agree_cost'] = 'yes';
            }
        }

        $cap = get_option("ww_cap_{$cat}", $this->default_caps[$cat]);
        $count_accepted = $this->count_status($cat, 'accepted');
        if ($count_accepted >= $cap) {
            $data['status'] = 'waitlisted';
        }

        $this->insert_registration($data);

        if ($data['status'] == 'waitlisted') {
            $template = get_option("ww_email_waitlist_{$cat}");
        } else {
            $template = get_option("ww_email_thanks");
        }

        $this->send_email($data['email'], 'Registration Received', $this->parse_email_template($template, $data));

        wp_redirect(home_url('/thank-you/'));
        exit;
    }

    private function insert_registration($data) {
        global $wpdb;
        $insert_data = array(
            'category' => $data['category'],
            'status' => $data['status'],
            'name' => $data['name'],
            'location' => $data['location'],
            'email' => $data['email'],
            'contact_number' => $data['contact_number'],
            'make_model' => isset($data['make_model']) ? $data['make_model'] : '',
            'vrn' => isset($data['vrn']) ? $data['vrn'] : '',
            'colour' => isset($data['colour']) ? $data['colour'] : '',
            'year_built' => isset($data['year_built']) ? $data['year_built'] : '',
            'interesting_fact' => isset($data['interesting_fact']) ? $data['interesting_fact'] : '',
            'tail_number' => isset($data['tail_number']) ? $data['tail_number'] : '',
            'home_airfield' => isset($data['home_airfield']) ? $data['home_airfield'] : '',
            'arriving_from' => isset($data['arriving_from']) ? $data['arriving_from'] : '',
            'departing_to' => isset($data['departing_to']) ? $data['departing_to'] : '',
            'address' => isset($data['address']) ? $data['address'] : '',
            'business_name' => isset($data['business_name']) ? $data['business_name'] : '',
            'vat_registered' => '',
            'public_liability' => isset($data['public_liability']) ? $data['public_liability'] : '',
            'what_selling' => isset($data['what_selling']) ? $data['what_selling'] : '',
            'charity_number' => isset($data['charity_number']) ? $data['charity_number'] : '',
            'anticipated_arrival_time' => isset($data['anticipated_arrival_time']) ? $data['anticipated_arrival_time'] : '',
            'agree_cost' => $data['agree_cost'],
            'unique_hash' => $data['unique_hash']
        );

        global $wpdb;
        $wpdb->insert($this->table_name, $insert_data);
    }

    private function count_status($cat, $status) {
        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE category=%s AND status=%s", $cat, $status));
        return $count;
    }

    private function send_email($to, $subject, $message, $data = array()) {
        // Retrieve the image URL from settings
        $image_url = 'https://wingsandwheelshenstridge.com/wp-content/uploads/2024/09/wnwpng-300x121.png';
    
         // Start building the email content
    $email_content = '<html><body style="font-family: Arial, sans-serif; line-height: 1.6;">';

    // **Center the image**
    if ($image_url) {
        $email_content .= '<div style="text-align: center; margin-bottom: 20px;">';
        $email_content .= '<img src="' . esc_url($image_url) . '" alt="Wings & Wheels" style="max-width:200px;">';
        $email_content .= '</div>';
    }

    // **Main message**
    // Append the message directly without additional wrapping to preserve formatting
    $email_content .= $message;

    // **Footer**
    $current_year = date('Y');

    // Check if 'dereg_link' is available in $data
    $dereg_link = '';
    if (isset($data['dereg_link']) && !empty($data['dereg_link'])) {
        $dereg_link = '<a href="' . esc_url($data['dereg_link']) . '">Unsubscribe</a>';
    }

    $footer_message = '
        <hr>
        <p style="font-size: 0.9em; color: #666;">
            This email was intended for ' . esc_html($to) . '.<br>
            This email was sent from our secure WordPress server. All attachments are virus scanned and safe to open.
            This email is does not guarantee entry to the event. We reserve the right to refuse entry to any vehicle or trader.
        </p>
        <p style="font-size: 0.9em; color: #666;">
            You are receiving this email because you registered for the Wings & Wheels Henstridge event.<br>
            ' . ($dereg_link ? $dereg_link . ' · ' : '') . '<a href="' . esc_url(home_url('/contact/')) . '">Help</a>
        </p>
        <p style="font-size: 0.8em; color: #999;">
            &copy; ' . $current_year . ' Wings & Wheels Henstridge. All rights reserved.
        </p>
    ';

    $email_content .= '<div>' . $footer_message . '</div>';

    $email_content .= '</body></html>';

    // Set the headers for HTML content
    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: Wings & Wheels <bookings@wingsandwheelshenstridge.com>',
        'Reply-To: Wings & Wheels <bookings@wingsandwheelshenstridge.com>',
        'Return-Path: bookings@wingsandwheelshenstridge.com', // For bounces/errors
        'X-Mailer: PHP/' . phpversion(),
        'MIME-Version: 1.0'
    );

    // Use wp_mail to send the email
    wp_mail($to, $subject, $email_content, $headers);
}
    
    

    private function parse_email_template($template, $data) {
        $replacements = array(
            '[NAME]' => isset($data['name']) ? $data['name'] : '',
            '[CATEGORY]' => isset($data['category']) ? ucfirst($data['category']) : '',
            '[EVENT_DATE]' => get_option('ww_event_date'),
            '[ENTRY_PRICE]' => get_option('ww_entry_price'),
            '[PITCH_FEE]' => get_option('ww_pitch_fee'),
            '[EMAIL]' => isset($data['email']) ? $data['email'] : '',
            '[CONTACT_NUMBER]' => isset($data['contact_number']) ? $data['contact_number'] : '',
            '[LOCATION]' => isset($data['location']) ? $data['location'] : '',
            '[MAKE_MODEL]' => isset($data['make_model']) ? $data['make_model'] : '',
            '[COLOUR]' => isset($data['colour']) ? $data['colour'] : '',
            '[YEAR_BUILT]' => isset($data['year_built']) ? $data['year_built'] : '',
            '[INTERESTING_FACT]' => isset($data['interesting_fact']) ? $data['interesting_fact'] : '',
            '[HOME_AIRFIELD]' => isset($data['home_airfield']) ? $data['home_airfield'] : '',
            '[ARRIVING_FROM]' => isset($data['arriving_from']) ? $data['arriving_from'] : '',
            '[DEPARTING_TO]' => isset($data['departing_to']) ? $data['departing_to'] : '',
            '[ADDRESS]' => isset($data['address']) ? $data['address'] : '',
            '[BUSINESS_NAME]' => isset($data['business_name']) ? $data['business_name'] : '',
            '[PUBLIC_LIABILITY]' => isset($data['public_liability']) ? $data['public_liability'] : '',
            '[WHAT_SELLING]' => isset($data['what_selling']) ? $data['what_selling'] : '',
            '[CHARITY_NUMBER]' => isset($data['charity_number']) ? $data['charity_number'] : '',
            '[ANTICIPATED_ARRIVAL_TIME]' => isset($data['anticipated_arrival_time']) ? $data['anticipated_arrival_time'] : '',
            '[AGREE_COST]' => isset($data['agree_cost']) ? $data['agree_cost'] : '',
            '[UNIQUE_HASH]' => isset($data['unique_hash']) ? $data['unique_hash'] : '',
            '[DATE_SUBMITTED]' => isset($data['date_submitted']) ? $data['date_submitted'] : '',
            '[DEREG_LINK]' => isset($data['dereg_link']) ? $data['dereg_link'] : ''
        );
    
        foreach ($replacements as $key => $val) {
            $template = str_replace($key, $val, $template);
        }
    
        $template = wpautop($template);
        return $template;
    }
    

    public function handle_deregistration() {
    if (!isset($_GET['hash'])) {
        wp_die('No hash provided');
    }

    global $wpdb;
    $hash = sanitize_text_field($_GET['hash']);

    // Check if deregistration period has expired
    $exp = get_option('ww_deregistration_expiry');
    $now = current_time('Y-m-d');
    if (strtotime($now) > strtotime($exp)) {
        wp_die('Deregistration period has ended.');
    }

    // Fetch the record using the hash
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE unique_hash=%s", $hash));
    if (!$row || $row->status === 'cancelled') {
        wp_die('Invalid or already cancelled registration.');
    }

    // Update the status to "cancelled"
    $wpdb->update($this->table_name, array('status' => 'cancelled'), array('id' => $row->id));

    // Provide confirmation to the user
    wp_die('You have successfully deregistered. Your registration is now marked as cancelled.');
}


    public function approve_registration() {
        if (!current_user_can('manage_options')) wp_die('No access');
        $id = intval($_POST['id']);
        global $wpdb;
    
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id=%d", $id));
        if (!$row) wp_die('Not found');
    
        if ($row->status == 'accepted') {
            wp_redirect(admin_url('admin.php?page=ww_registrations'));
            exit;
        }
    
        $wpdb->update($this->table_name, array('status' => 'accepted'), array('id' => $id));
    
        $cat = $row->category;
        $template = get_option("ww_email_accept_{$cat}");
        $data = (array)$row;
        $data['dereg_link'] = add_query_arg(array('action' => 'ww_deregister', 'hash' => $row->unique_hash), site_url());
        $msg = $this->parse_email_template($template, $data) . "\nTo deregister: " . $data['dereg_link'];
        $this->send_email($row->email, 'You have been accepted!', nl2br(wp_kses_post($msg)));
    
        wp_redirect(admin_url('admin.php?page=ww_registrations'));
        exit;
    }
    

    public function reject_registration() {
        if (!current_user_can('manage_options')) wp_die('No access');
        $id = intval($_POST['id']);
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id=%d", $id));
        if (!$row) wp_die('Not found');

        if ($row->status == 'rejected') {
            wp_redirect(admin_url('admin.php?page=ww_registrations'));
            exit;
        }

        $wpdb->update($this->table_name, array('status' => 'rejected'), array('id' => $id));

        $cat = $row->category;
        $template = get_option("ww_email_reject_{$cat}");
        $data = (array)$row;
        $msg = $this->parse_email_template($template, $data);
        $this->send_email($row->email, 'Your registration was not accepted', nl2br(wp_kses_post($msg)));

        wp_redirect(admin_url('admin.php?page=ww_registrations'));
        exit;
    }

    public function export_csv() {
        if (!current_user_can('manage_options')) wp_die('No access');
    
        $cat = isset($_GET['cat']) ? sanitize_key($_GET['cat']) : 'all';
        if (!in_array($cat, $this->categories) && $cat != 'all') {
            wp_die('Invalid category');
        }
    
        global $wpdb;
        if ($cat == 'all') {
            $rows = $wpdb->get_results("SELECT * FROM {$this->table_name}");
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE category=%s", $cat));
        }
    
        // Generate the filename dynamically
        $current_date = date('Y-m-d'); // Format: YYYY-MM-DD
        $category_part = $cat === 'all' ? 'all' : $cat;
        $filename = "registration_{$category_part}_{$current_date}.csv";
    
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename={$filename}");
        $output = fopen('php://output', 'w');
    
        $cols = array(
            'id', 'category', 'status', 'name', 'location', 'email', 'contact_number',
            'make_model', 'vrn', 'colour', 'year_built', 'interesting_fact',
            'tail_number', 'home_airfield', 'arriving_from', 'departing_to',
            'address', 'business_name', 'vat_registered', 'public_liability',
            'what_selling', 'charity_number', 'anticipated_arrival_time',
            'agree_cost', 'unique_hash', 'date_submitted'
        );
        fputcsv($output, $cols);
    
        foreach ($rows as $r) {
            $line = array();
            foreach ($cols as $c) {
                $line[] = $r->$c;
            }
            fputcsv($output, $line);
        }
    
        fclose($output);
        exit;
    }
    

    public function render_dashboard() {
        if (!current_user_can('manage_options')) {
            wp_die('No access');
        }

        global $wpdb;

        $filter_cat = isset($_GET['filter_cat']) ? sanitize_key($_GET['filter_cat']) : '';
        $filter_status = isset($_GET['filter_status']) ? sanitize_key($_GET['filter_status']) : '';

        $where = '1=1';
        if ($filter_cat && in_array($filter_cat, $this->categories)) {
            $where .= $wpdb->prepare(" AND category=%s", $filter_cat);
        }
        if ($filter_status && in_array($filter_status, $this->statuses)) {
            $where .= $wpdb->prepare(" AND status=%s", $filter_status);
        }

        $rows = $wpdb->get_results("SELECT * FROM {$this->table_name} WHERE $where ORDER BY date_submitted DESC LIMIT 100");

        echo "<div class='wrap container-fluid'>";
        echo "<h1 class='my-4'>Wings & Wheels Registrations</h1>";
        echo "<form method='get' class='row g-3 align-items-center'><input type='hidden' name='page' value='ww_registrations'>";
        echo "<div class='col-auto'><select name='filter_cat' class='form-select'><option value=''>All Categories</option>";
        foreach ($this->categories as $c) {
            echo "<option value='$c' ".selected($filter_cat,$c,false).">".ucfirst($c)."</option>";
        }
        echo "</select></div>";

        echo "<div class='col-auto'><select name='filter_status' class='form-select'><option value=''>All Status</option>";
        foreach ($this->statuses as $s) {
            echo "<option value='$s' ".selected($filter_status,$s,false).">".ucfirst($s)."</option>";
        }
        echo "</select></div>";

        echo "<div class='col-auto'><button type='submit' class='btn btn-secondary'>Filter</button></div>";
        echo "</form>";

        echo "<table class='table table-striped table-hover table-bordered my-4'>";
        echo "<thead><tr><th>ID</th><th>Category</th><th>Make - Model</th><th>Status</th><th>Name</th><th>Email</th><th>Date</th><th>Actions</th></tr></thead><tbody>";
        if ($rows) {
            foreach ($rows as $r) {
                $data_attrs = sprintf(
                    'data-id="%d" data-category="%s" data-status="%s" data-name="%s" data-location="%s" data-email="%s" data-contact="%s" data-make_model="%s" data-vrn="%s" data-colour="%s" data-year_built="%s" data-interesting_fact="%s" data-tail_number="%s" data-home_airfield="%s" data-arriving_from="%s" data-departing_to="%s" data-address="%s" data-business_name="%s" data-public_liability="%s" data-what_selling="%s" data-charity_number="%s" data-anticipated_arrival_time="%s" data-agree_cost="%s" data-date_submitted="%s"',
                    $r->id,
                    esc_attr($r->category),
                    esc_attr($r->status),
                    esc_attr($r->name),
                    esc_attr($r->location),
                    esc_attr($r->email),
                    esc_attr($r->contact_number),
                    esc_attr($r->make_model),
                    esc_attr($r->vrn),
                    esc_attr($r->colour),
                    esc_attr($r->year_built),
                    esc_attr($r->interesting_fact),
                    esc_attr($r->tail_number),
                    esc_attr($r->home_airfield),
                    esc_attr($r->arriving_from),
                    esc_attr($r->departing_to),
                    esc_attr($r->address),
                    esc_attr($r->business_name),
                    esc_attr($r->public_liability),
                    esc_attr($r->what_selling),
                    esc_attr($r->charity_number),
                    esc_attr($r->anticipated_arrival_time),
                    esc_attr($r->agree_cost),
                    esc_attr($r->date_submitted)
                );

                echo "<tr>";
                echo "<td>{$r->id}</td>";
                echo "<td>".ucfirst($r->category)."</td>";
                echo "<td>{$r->make_model}</td>";
                echo "<td>".ucfirst($r->status)."</td>";
                echo "<td>{$r->name}</td>";
                echo "<td>{$r->email}</td>";
                echo "<td>{$r->date_submitted}</td>";
                echo "<td>";
                echo "<button type='button' class='btn btn-info btn-sm ww-view-details' {$data_attrs}>View</button> ";
                if ($r->status == 'pending' || $r->status == 'waitlisted') {
                    echo "<form style='display:inline;' method='post' action='".admin_url('admin-post.php')."'><input type='hidden' name='action' value='ww_approve_registration'><input type='hidden' name='id' value='{$r->id}'><button type='submit' class='btn btn-success btn-sm'>Approve</button></form> ";
                    echo "<form style='display:inline;' method='post' action='".admin_url('admin-post.php')."'><input type='hidden' name='action' value='ww_reject_registration'><input type='hidden' name='id' value='{$r->id}'><button type='submit' class='btn btn-danger btn-sm'>Reject</button></form> ";
                }
                // NEW: If status is rejected, show a Delete button.
                if ($r->status == 'rejected') {
                    echo "<form style='display:inline;' method='post' action='".admin_url('admin-post.php')."'>
                          <input type='hidden' name='action' value='ww_delete_registration'>
                          <input type='hidden' name='id' value='{$r->id}'>
                          <button type='submit' class='btn btn-warning btn-sm' onclick='return confirm(\"Are you sure you want to delete this registration?\");'>Delete</button>
                          </form>";
                }
                echo "</td>";
                echo "</tr>";
            }
        } else {
            echo "<tr><td colspan='8'>No registrations found.</td></tr>";
        }
        echo "</tbody></table>";

        echo "<h2>Export CSV</h2>";
        echo "<ul>";
        echo "<li><a class='text-decoration-underline' href='".admin_url('admin-post.php?action=ww_export_csv&cat=all')."'>Export All</a></li>";
        echo "<li><a class='text-decoration-underline' href='".admin_url('admin-post.php?action=ww_export_csv&cat=car')."'>Export Car</a></li>";
        echo "<li><a class='text-decoration-underline' href='".admin_url('admin-post.php?action=ww_export_csv&cat=motorcycle')."'>Export Motorcycle</a></li>";
        echo "<li><a class='text-decoration-underline' href='".admin_url('admin-post.php?action=ww_export_csv&cat=aircraft')."'>Export Aircraft</a></li>";
        echo "<li><a class='text-decoration-underline' href='".admin_url('admin-post.php?action=ww_export_csv&cat=trader')."'>Export Trader</a></li>";
        echo "</ul>";

        // Modal for viewing details
        ?>
        <div class="modal fade" id="wwDetailsModal" tabindex="-1" aria-labelledby="wwDetailsModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="wwDetailsModalLabel">Attendee Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <dl class="row">
                  <dt class="col-sm-3">ID</dt><dd class="col-sm-9" id="ww_detail_id"></dd>
                  <dt class="col-sm-3">Category</dt><dd class="col-sm-9" id="ww_detail_category"></dd>
                  <dt class="col-sm-3">Status</dt><dd class="col-sm-9" id="ww_detail_status"></dd>
                  <dt class="col-sm-3">Name</dt><dd class="col-sm-9" id="ww_detail_name"></dd>
                  <dt class="col-sm-3">Location/Home Town</dt><dd class="col-sm-9" id="ww_detail_location"></dd>
                  <dt class="col-sm-3">Email</dt><dd class="col-sm-9" id="ww_detail_email"></dd>
                  <dt class="col-sm-3">Contact Number</dt><dd class="col-sm-9" id="ww_detail_contact"></dd>
                  <dt class="col-sm-3">Make & Model</dt><dd class="col-sm-9" id="ww_detail_make_model"></dd>
                  <dt class="col-sm-3">VRN</dt><dd class="col-sm-9" id="ww_detail_vrn"></dd>
                  <dt class="col-sm-3">Colour</dt><dd class="col-sm-9" id="ww_detail_colour"></dd>
                  <dt class="col-sm-3">Year Built</dt><dd class="col-sm-9" id="ww_detail_year_built"></dd>
                  <dt class="col-sm-3">Interesting Fact</dt><dd class="col-sm-9" id="ww_detail_interesting_fact"></dd>
                  <dt class="col-sm-3">Tail Number</dt><dd class="col-sm-9" id="ww_detail_tail_number"></dd>
                  <dt class="col-sm-3">Home Airfield</dt><dd class="col-sm-9" id="ww_detail_home_airfield"></dd>
                  <dt class="col-sm-3">Arriving From</dt><dd class="col-sm-9" id="ww_detail_arriving_from"></dd>
                  <dt class="col-sm-3">Departing To</dt><dd class="col-sm-9" id="ww_detail_departing_to"></dd>
                  <dt class="col-sm-3">Address</dt><dd class="col-sm-9" id="ww_detail_address"></dd>
                  <dt class="col-sm-3">Business Name</dt><dd class="col-sm-9" id="ww_detail_business_name"></dd>
                  <dt class="col-sm-3">Public Liability</dt><dd class="col-sm-9" id="ww_detail_public_liability"></dd>
                  <dt class="col-sm-3">What Selling</dt><dd class="col-sm-9" id="ww_detail_what_selling"></dd>
                  <dt class="col-sm-3">Charity Number</dt><dd class="col-sm-9" id="ww_detail_charity_number"></dd>
                  <dt class="col-sm-3">Arrival Time</dt><dd class="col-sm-9" id="ww_detail_anticipated_arrival_time"></dd>
                  <dt class="col-sm-3">Agreed Cost</dt><dd class="col-sm-9" id="ww_detail_agree_cost"></dd>
                  <dt class="col-sm-3">Date Submitted</dt><dd class="col-sm-9" id="ww_detail_date_submitted"></dd>
                </dl>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              </div>
            </div>
          </div>
        </div>
        <?php
        echo "</div>";
    }

    // NEW: Function to delete rejected registrations
    public function delete_registration() {
        if (!current_user_can('manage_options')) {
            wp_die('No access');
        }
        if (!isset($_POST['id'])) {
            wp_die('No registration ID provided');
        }
        $id = intval($_POST['id']);
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id=%d", $id));
        if (!$row) {
            wp_die('Registration not found');
        }
        if ($row->status !== 'rejected') {
            wp_die('Only rejected registrations can be deleted.');
        }
        $deleted = $wpdb->delete($this->table_name, array('id' => $id), array('%d'));
        if ($deleted === false) {
            wp_die('Error deleting registration.');
        }
        wp_redirect(admin_url('admin.php?page=ww_registrations'));
        exit;
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die('No access');
        }
    
        echo "<div class='wrap container'>";
        echo "<h1 class='my-4'>Wings & Wheels Settings</h1>";
        echo "<form method='post' action='options.php' class='row g-3'>";
        settings_fields('ww_settings_group');
        do_settings_sections('ww_settings_group');
    
        /**
         * Start of Event Details and Category Limits Section
         */
        echo "<div class='row'>";
    
        /**
         * Event Details Column
         */
        echo "<div class='col-md-4'>";

        echo "<table class='form-table'>";
        echo "<tr><th scope='row'>Event Date</th><td><input type='date' name='ww_event_date' value='".esc_attr(get_option('ww_event_date'))."' required></td></tr>";
        echo "<tr><th scope='row'>Deregistration Expiry Date</th><td><input type='date' name='ww_deregistration_expiry' value='".esc_attr(get_option('ww_deregistration_expiry'))."' required></td></tr>";
        echo "<tr><th scope='row'>Entry Price (£)</th><td><input type='text' name='ww_entry_price' value='".esc_attr(get_option('ww_entry_price'))."' placeholder='e.g., 10' required></td></tr>";
        echo "<tr><th scope='row'>Pitch Fee (£)</th><td><input type='text' name='ww_pitch_fee' value='".esc_attr(get_option('ww_pitch_fee'))."' placeholder='e.g., 30' required></td></tr>";
        echo "</table>";

        echo "<h2>Category Limits</h2>";
        echo "<table class='form-table'>";
        foreach ($this->categories as $c) {
            echo "<tr><th scope='row'>".ucfirst($c)." Cap</th><td><input type='number' name='ww_cap_{$c}' value='".esc_attr(get_option("ww_cap_{$c}"))."' min='0' required></td></tr>";
        }
        echo "</table>";


       
    
        submit_button(); // End of Category Caps row
    
        /**
         * Email Templates Section
         */
        echo "<div class='col-12 mt-4'><h2>Email Templates</h2></div>";
        
        // Generic Thanks Template
        echo "<div class='col-12 mb-3'>
                <strong>Generic Thanks</strong><br>
                " . wp_editor(
                    get_option("ww_email_thanks"),
                    "ww_email_thanks",
                    array(
                        'textarea_name' => "ww_email_thanks",
                        'media_buttons' => false,
                        'textarea_rows' => 10,
                        'teeny'         => true,
                    )
                ) . "
              </div>";
              submit_button();
        
        // Email Templates for Each Category
        foreach ($this->categories as $c) {
            echo "<div class='col-12 mt-3'><h3>" . ucfirst($c) . " Emails</h3></div>";
            
            // Acceptance Email
            echo "<div class='col-12 mb-3'>
                    <strong>Acceptance Email:</strong><br>
                    " . wp_editor(
                        get_option("ww_email_accept_{$c}"),
                        "ww_email_accept_{$c}",
                        array(
                            'textarea_name' => "ww_email_accept_{$c}",
                            'media_buttons' => false,
                            'textarea_rows' => 10,
                            'teeny'         => true,
                        )
                    ) . "
                  </div>";
                  submit_button();
            
            // Rejection Email
            echo "<div class='col-12 mb-3'>
                    <strong>Rejection Email:</strong><br>
                    " . wp_editor(
                        get_option("ww_email_reject_{$c}"),
                        "ww_email_reject_{$c}",
                        array(
                            'textarea_name' => "ww_email_reject_{$c}",
                            'media_buttons' => false,
                            'textarea_rows' => 10,
                            'teeny'         => true,
                        )
                    ) . "
                  </div>";
                  submit_button();
            
            // Waitlist Email
            echo "<div class='col-12 mb-3'>
                    <strong>Waitlist Email:</strong><br>
                    " . wp_editor(
                        get_option("ww_email_waitlist_{$c}"),
                        "ww_email_waitlist_{$c}",
                        array(
                            'textarea_name' => "ww_email_waitlist_{$c}",
                            'media_buttons' => false,
                            'textarea_rows' => 10,
                            'teeny'         => true,
                        )
                    ) . "
                  </div>";
                  submit_button();
        }
    
        /**
         * Terms & Conditions Section
         */
        echo "<div class='col-12 mt-4'><h2>Terms & Conditions</h2></div>";
        
        foreach ($this->categories as $c) {
            echo "<div class='col-12 mb-3'>
                    <strong>" . ucfirst($c) . " T&Cs:</strong><br>
                    " . wp_editor(
                        get_option("ww_tnc_{$c}"),
                        "ww_tnc_{$c}",
                        array(
                            'textarea_name' => "ww_tnc_{$c}",
                            'media_buttons' => false,
                            'textarea_rows' => 15,
                            'teeny'         => false,
                        )
                    ) . "
                  </div>";
                  submit_button();
        }
    
        
    
        /**
         * Placeholders Information
         */

        echo "<div class='col-12 mt-4'><h2>Placeholders</h2></div>";
        echo "<div class='sticky-placeholder' style='position: fixed; bottom: 20px; left: 90%; transform: translateX(-50%); max-height: 10%; width: 90%; max-width: 600px; background-color: #f8f9fa; padding: 15px; border: 1px solid #dee2e6; border-radius: 5px; box-shadow: 0px 0px 10px rgba(0,0,0,0.1); z-index: 1000; overflow-x: auto;'>
        <h2>Placeholders</h2>
        <table class='table table-bordered mb-0'>
            <thead>
                <tr>
                    <th>Placeholder</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>[NAME]</td>
                    <td>The name of the registrant.</td>
                </tr>
                <tr>
                    <td>[CATEGORY]</td>
                    <td>The category of registration (e.g., car, aircraft).</td>
                </tr>
                <tr>
                    <td>[EVENT_DATE]</td>
                    <td>The date of the event.</td>
                </tr>
                <tr>
                    <td>[ENTRY_PRICE]</td>
                    <td>The entry price for the event.</td>
                </tr>
                <tr>
                    <td>[PITCH_FEE]</td>
                    <td>The pitch fee applicable for traders.</td>
                </tr>
                <tr>
                    <td>[EMAIL]</td>
                    <td>The registrant's email address.</td>
                </tr>
                <tr>
                    <td>[CONTACT_NUMBER]</td>
                    <td>The registrant's contact number.</td>
                </tr>
                <tr>
                    <td>[LOCATION]</td>
                    <td>The registrant's location or hometown.</td>
                </tr>
                <tr>
                    <td>[MAKE_MODEL]</td>
                    <td>Make and model of the vehicle or aircraft.</td>
                </tr>
                <tr>
                    <td>[COLOUR]</td>
                    <td>Colour of the vehicle or motorcycle.</td>
                </tr>
                <tr>
                    <td>[YEAR_BUILT]</td>
                    <td>Approximate year built.</td>
                </tr>
                <tr>
                    <td>[INTERESTING_FACT]</td>
                    <td>An interesting fact provided by the registrant.</td>
                </tr>
                <tr>
                    <td>[HOME_AIRFIELD]</td>
                    <td>Home airfield (for aircraft).</td>
                </tr>
                <tr>
                    <td>[ARRIVING_FROM]</td>
                    <td>Arriving from location (for aircraft).</td>
                </tr>
                <tr>
                    <td>[DEPARTING_TO]</td>
                    <td>Departing to location (for aircraft).</td>
                </tr>
                <tr>
                    <td>[ADDRESS]</td>
                    <td>Address (for traders).</td>
                </tr>
                <tr>
                    <td>[BUSINESS_NAME]</td>
                    <td>Business name (for traders).</td>
                </tr>
                <tr>
                    <td>[PUBLIC_LIABILITY]</td>
                    <td>Public liability insurance status (for traders).</td>
                </tr>
                <tr>
                    <td>[WHAT_SELLING]</td>
                    <td>Items being sold or promoted (for traders).</td>
                </tr>
                <tr>
                    <td>[CHARITY_NUMBER]</td>
                    <td>Charity number (if applicable).</td>
                </tr>
                <tr>
                    <td>[ANTICIPATED_ARRIVAL_TIME]</td>
                    <td>Anticipated arrival time at Henstridge.</td>
                </tr>
                <tr>
                    <td>[AGREE_COST]</td>
                    <td>Confirmation of agreeing to the cost (for traders).</td>
                </tr>
                <tr>
                    <td>[UNIQUE_HASH]</td>
                    <td>Unique hash for deregistration.</td>
                </tr>
                <tr>
                    <td>[DATE_SUBMITTED]</td>
                    <td>Date when the registration was submitted.</td>
                </tr>
                <tr>
                    <td>[DEREG_LINK]</td>
                    <td>Link for deregistration.</td>
                </tr>
            </tbody>
        </table>
      </div>";
    
        // End of the form
        echo "</form>";
        
        // End of the settings page container
        echo "</div>";
    }
    
}

WW_Registration_Manager::instance();
?>

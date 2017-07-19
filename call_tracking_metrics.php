<?php
/*
Plugin Name: CallTrackingMetrics
Plugin URI: https://www.calltrackingmetrics.com/
Description: View your CallTrackingMetrics daily call volume in your WordPress Dashboard, and integrate with Contact Form 7 and Gravity Forms
Author: CallTrackingMetrics
Version: 1.1.5
Author URI: https://www.calltrackingmetrics.com/
*/

class CallTrackingMetrics {

  public function __construct() {
    if (get_option("ctm_api_tracking_enabled") || !$this->authorized()) add_action('wp_head', array(&$this, 'print_tracking_script'), 10);
    add_action('admin_init', array(&$this, 'init_plugin'));
    add_action('init', array(&$this, 'form_init'));
    add_action('admin_menu', array(&$this, 'attach_ctm_settings'));
    
    add_filter('gform_confirmation', array(&$this, 'gf_confirmation'), 10, 1);
    if (get_option('ctm_api_cf7_enabled', true)) add_filter('wpcf7_contact_form_properties', array(&$this, 'cf7_confirmation'), 10, 1);
    
    $this->ctm_host = "https://api.calltrackingmetrics.com";
  }
  
  // quick link in the plugin folder
  function settings_link($links, $file) {
    $plugin = plugin_basename(__FILE__);
    if ($file != $plugin) return $links;
    $settings_link = '<a href="' . admin_url('options-general.php?page=call-tracking-metrics/call_tracking_metrics.php') . '">'  . esc_html(__('Settings', 'call-tracking-metrics')) . '</a>';
    array_unshift($links, $settings_link); 
    return $links; 
  }
  
  function init_plugin() {
    register_setting("call-tracking-metrics", "ctm_api_key");
    register_setting("call-tracking-metrics", "ctm_api_secret");
    register_setting("call-tracking-metrics", "ctm_api_active_key");
    register_setting("call-tracking-metrics", "ctm_api_active_secret");
    register_setting("call-tracking-metrics", "ctm_api_auth_account");
    register_setting("call-tracking-metrics", "ctm_api_connect_failed");
    register_setting("call-tracking-metrics", "ctm_api_stats");
    register_setting("call-tracking-metrics", "ctm_api_stats_expires");
    register_setting("call-tracking-metrics", "ctm_api_dashboard_enabled");
    register_setting("call-tracking-metrics", "ctm_api_tracking_enabled");
    register_setting("call-tracking-metrics", "ctm_api_cf7_enabled");
    register_setting("call-tracking-metrics", "ctm_api_gf_enabled");
    register_setting("call-tracking-metrics", "call_track_account_script");
    register_setting("call-tracking-metrics", "ctm_api_cf7_logs");
    register_setting("call-tracking-metrics", "ctm_api_gf_logs");
    
    unregister_setting("call-tracking-metrics", "ctm_api_auth_token");
    unregister_setting("call-tracking-metrics", "ctm_api_auth_expires");
    
    add_filter('admin_head', array(&$this, 'add_javascripts'));
    add_filter('plugin_action_links', array(&$this, 'settings_link'), 10, 2);
  }
  
  function dashboard_enabled() {
    return get_option('ctm_api_dashboard_enabled', true);
  }
  
  function tracking_enabled() {
    return get_option('ctm_api_tracking_enabled', true);
  }
  
  function cf7_enabled() {
    return get_option('ctm_api_cf7_enabled', true);
  }
  
  function gf_enabled() {
    return get_option('ctm_api_gf_enabled', true);
  }
  
  function cf7_active() {
    return is_plugin_active('contact-form-7/wp-contact-form-7.php');
  }
  
  function gf_active() {
    return is_plugin_active('gravityforms/gravityforms.php');
  }
  
  function form_init() {
    if ($this->cf7_enabled()) add_action('wpcf7_before_send_mail', array(&$this, 'submit_cf7'), 1, 1);
    if ($this->gf_enabled()) add_action('gform_after_submission', array(&$this, 'submit_gf'), 10, 2);
  }
  
  function add_javascripts() {
    global $parent_file;
    
    if ( $parent_file == 'index.php') {
      echo '<script src="//cdnjs.cloudflare.com/ajax/libs/highcharts/4.0.4/highcharts.js"></script>';
      echo '<script src="//cdnjs.cloudflare.com/ajax/libs/mustache.js/0.7.2/mustache.min.js"></script>';
    }
  }
  
  function get_tracking_script() {
    if (!$this->authorizing() || !$this->authorized()) return get_option('call_track_account_script');
    return '<script async src="//' . get_option('ctm_api_auth_account') . '.tctm.co/t.js"></script>';
  }
  
  function print_tracking_script() {
    if (!is_admin()) echo $this->get_tracking_script();
  }
  
  function attach_ctm_settings() {
    if (current_user_can('manage_options')) {
      add_options_page('CallTrackingMetrics', 'CallTrackingMetrics', 'administrator', __FILE__, array(&$this,'settings_page'));
      if ($this->dashboard_enabled())
        add_action('wp_dashboard_setup', array(&$this, 'install_dashboard_widget'));
    }
  }
  
  function install_dashboard_widget() {
    wp_add_dashboard_widget("ctm_dash", "CallTrackingMetrics", array(&$this, 'admin_dashboard_plugin'));
  }
  
  function cf7_confirmation($properties) {
    $properties['additional_settings'] .= "\non_sent_ok: \"try {__ctm.tracker.trackEvent(\"\", \" \", \"form\"); __ctm.tracker.popQueue(); } catch(e) { console.log(e); }\"\n";
    return $properties;
  }
  
  function gf_confirmation($confirmation) {
    if (is_array($confirmation)) {
      if ($confirmation["redirect"]) {
        $code = "window.location = \"" . $confirmation["redirect"] . "\";";
        $confirmation = "";
      } else return $confirmation;
    } else if (strpos($confirmation, "<script") !== false && strpos($confirmation, "function gf_ctm_redirect(") !== false) {
      $code = "gf_ctm_redirect_old();";
    } else $code = "";
    
    // parse out the account ID from the tracking script using regex, in case they used their own custom embed code rather than authenticating through the API
    if (preg_match('/(\d+).tctm.co\/t.js/', $this->get_tracking_script(), $matches) == 1)
      $tracker = $matches[1];
    else
      $this->gf_log("WARNING: Tracking code is missing in the plugin.");
    
    // all one line so WordPress doesn't insert <p> tags around the code and break it
    return $confirmation . "<script>(function() { if (window.location != window.parent.location) { var tracker = document.createElement('script'); tracker.setAttribute('src', '//$tracker.tctm.co/t.js'); document.head.appendChild(tracker); } __ctm_http_requests = []; (function(open) { XMLHttpRequest.prototype.open = function() { __ctm_http_requests.push(this); this.addEventListener(\"readystatechange\", function() { if (this.readyState == 4) { var index = __ctm_http_requests.indexOf(this); if (index > -1) __ctm_http_requests.splice(index, 1); } }, false); open.apply(this, arguments); }; })(XMLHttpRequest.prototype.open); window.__ctm_loaded = window.__ctm_loaded || []; window.__ctm_loaded.push(function() { try { __ctm.tracker.trackEvent('', ' ', 'form'); __ctm.tracker.popQueue(); } catch(e) {} if (typeof gf_ctm_redirect != 'undefined') { gf_ctm_redirect_old = gf_ctm_redirect; gf_ctm_redirect = function() {}; } var send_time = (new Date()).getTime(); var redirect = setInterval(function() { if (__ctm_http_requests.length == 0 || (new Date()).getTime() - send_time > 5000) { clearInterval(redirect); $code } }, 10); }); })();</script>";
  }
  
  function submit_cf7($form) {
    $this->cf7_log("Submitting...");
    
    $title = $form->title();
    $entry = $form->form_scan_shortcode();
    $data  = WPCF7_Submission::get_instance()->get_posted_data();
    $form_id = $data["_wpcf7_unit_tag"];
    
    $fields = array();
    $labels = array();
    $sublabels = array();
    
    foreach ($entry as $field) {
      if ($field["basetype"] == "tel") {
        $phone = $data[$field["name"]];
      } else if ($field["basetype"] == "email") {
        $email = $data[$field["name"]];
      } else if ($field["name"] == "your-name") {
        $name = $data[$field["name"]];
      } else if (in_array($field["basetype"], array("checkbox", "radio"))) {
        $fields[$field["name"]] = $data[$field["name"]];
      } else if ($field["basetype"] == "quiz") {
        $hash = $data["_wpcf7_quiz_answer_" . $field["name"]];
        foreach ($field["raw_values"] as $answer) {
          $answer_pos = strpos($answer, "|");
          if ($answer_pos !== false) {
            if ($hash == wp_hash(wpcf7_canonicalize(substr($answer, $answer_pos + 1)), 'wpcf7_quiz')) {
              $fields[$field["name"]] = $data[$field["name"]];
              $labels[$field["name"]] = substr($answer, 0, $answer_pos);
              break;
            }
          }
        }
      } else if ($field["name"] != "" && in_array($field["basetype"], array("text", "textarea", "select", "url", "number", "date"))) {
        $fields[$field["name"]] = $data[$field["name"]];
      }
    }
    
    $this->cf7_log("Title: " . $title . ", Name: " . $name . ", Phone: " . $phone . ", Email: " . $email);
    
    $this->send_formreactor(array(
      "type" => "Contact Form 7",
      "id" => $form_id,
      "title" => $title,
      "name" => $name,
      "phone" => $phone,
      "email" => $email,
      "fields" => $fields,
      "labels" => $labels,
      "sublabels" => $sublabels
    ));
  }
  
  function submit_gf($entry, $form) {
    $this->gf_log("Submitting...");
    
    if ($entry["form_id"] != $form["id"]) return;
    if (!$form["is_active"]) return;
    
    $country_code = "";
    $custom = array();
    foreach ($form["fields"] as $field) {
      if ($field["type"] == "name") {
        $name = trim($entry[$field["id"] . ".3"] . " " . $entry[$field["id"] . ".6"]);
      } else if ($field["type"] == "phone") {
        $phone = $entry[$field["id"]];
        if ($field["phoneFormat"] == "standard") $country_code = "1";
      } else if ($field["type"] == "email") {
        $email = $entry[$field["id"]];
      } else if (isset($field["id"]) && is_int($field["id"])) {
        $custom[$field["id"]] = $field;
      }
    }
    
    $this->gf_log("Title: " . $form["title"] . ", Name: " . $name . ", Phone: " . $phone . ", Email: " . $email);
    
    // phone numbers are required for FormReactors
    if (!isset($phone) || strlen($phone) <= 0) {
      $this->gf_log("No phone number set");
      return;
    }
    
    $fields = array();
    $labels = array();
    $sublabels = array();
    
    foreach ($entry as $field => $value) {
      $id = intval($field);
      if (!isset($custom[$id])) continue;
      
      $field = $custom[$id];
      $sublabel = NULL;
      
      // file uploads are not supported
      if ($field["type"] == "fileupload") continue;
      
      if ($field["type"] == "checkbox") {
        // checkboxes use separate "12.1" "12.2" IDs for each input in a list with ID = 12, but process all of them together
        unset($custom[$id]);
        
        $new_value = array();
        $sublabel = array();
        foreach ($field["inputs"] as $index => $checkbox) {
          if (isset($entry[$checkbox["id"]]) && $entry[$checkbox["id"]] == $field["choices"][$index]["value"]) {
            $new_value[] = $entry[$checkbox["id"]];
            $sublabel[] = $checkbox["label"];
          }
        }
        
        $value = $new_value;
        
      } else if ($field["type"] == "list") {
        $value = unserialize($value);
        if (!$value || count($value) == 0) continue;
        
        // transform this data from this:
        // ["field_9"]=> array(2) { [0]=> array(3) { ["Column 1"]=> string(4) "r1c1" ["Column 2"]=> string(4) "r1c2" ["Column 3"]=> string(0) "" } [1]=> array(3) { ["Column 1"]=> string(0) "" ["Column 2"]=> string(4) "r2c2" ["Column 3"]=> string(0) "" } } 
        // to this:
        // value: [0] => ["r1c1", "r1c2", ""], [1] => ["", "r2c2", ""]
        // sublabel: ["Column 1", "Column 2", "Column 3"]
        
        $sublabel = array();
        foreach ($value[0] as $label => $ignore) $sublabel[] = $label;
        
        $new_value = array();
        foreach ($value as $index => $row) {
          $new_row = array();
          foreach ($row as $label) $new_row[] = $label;
          $new_value[] = $new_row;
        }
        $value = $new_value;
        
      } else if (isset($field["choices"]) && is_array($field["choices"])) {
        // convert the value into an array
        $new_value = array();
        $pos = 0; $value_length = strlen($value);
        $sublabel = array();
        
        while ($pos < $value_length) {
          $best = NULL; $best_length = 0;
          
          foreach ($field["choices"] as $choice) {
            $choice_length = strlen($choice["value"]);
            if ($choice_length <= $value_length - $pos && substr_compare($value, $choice["value"], $pos, $choice_length) == 0) {
              if (!$best || $choice_length >= $best_length) {
                $best = $choice; $best_length = $choice_length;
              }
            }
          }
          if ($best) {
            $new_value[] = $best["value"];
            $sublabel[] = $best["text"];
            
            $pos += $best_length;
          } else if ($pos == 0 && $field["type"] == "radio" && $field["enableOtherChoice"] && $field["enableChoiceValue"]) {
            $new_value = $value;
            break;
          }
          
          // move pos up to past the next comma
          $new_pos = strpos($value, ",", $pos);
          if ($new_pos === FALSE) break;
          $pos = $new_pos + 1;
        }
        
        $value = $new_value;
        
      } else if (!is_string($value)) continue;
      
      $fields["field_" . $id] = $value;
      $labels["field_" . $id] = $field["label"];
      if ($sublabel) $sublabels["field_" . $id] = $sublabel;
    }
    
    $this->send_formreactor(array(
      "type" => "Gravity Forms",
      "id" => $form["id"],
      "title" => $form["title"],
      "name" => $name,
      "country_code" => $country_code,
      "phone" => $phone,
      "email" => $email,
      "fields" => $fields,
      "labels" => $labels,
      "sublabels" => $sublabels
    ));
  }
  
  public function send_formreactor($form) {
    $enabled = array(
      "Gravity Forms" => $this->gf_enabled(),
      "Contact Form 7" => $this->cf7_enabled()
    );
    if (!($form && isset($form["type"]) && isset($enabled[$form["type"]]) && $enabled[$form["type"]])) {
      $this->form_log($form["type"], "Form integration is not enabled");
      return;
    }
    
    // phone numbers are required for FormReactors
    if (!isset($form["phone"]) || strlen($form["phone"]) <= 0) {
      $this->form_log($form["type"], "No phone number set");
      return;
    }
    
    $form_reactor = array();
    if (isset($form["country_code"]))
      $form_reactor["country_code"] = $form["country_code"];
    $form_reactor["phone_number"] = $form["phone"];
    
    if (isset($form["name"]) && strlen($form["name"]) > 3)
      $form_reactor["caller_name"] = $form["name"];
    if (isset($form["email"]) && strlen($form["email"]) > 5)
      $form_reactor["email"] = $form["email"];
    
    $data = array();
    $data["form_reactor"] = $form_reactor;
    $data["field"] = $form["fields"];
    $data["label"] = $form["labels"];
    $data["labels"] = $form["sublabels"];
    $data["type"] = $form["type"];
    $data["id"] = $form["id"];
    $data["name"] = $form["title"];
    $data["__ctm_api_authorized__"] = "1";
    $data["visitor_sid"] = $_COOKIE["__ctmid"];
    
    // $data["callback_number"]
    // $data["authenticity_token"]
    // $data["visitor_sid"]
    
    $data_json = json_encode($data);
    
    //$this->debug($data_json);
    
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $this->ctm_host . "/api/v1/formreactor/" . $form["id"]);
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-Type: application/json", "Authorization: Basic " . base64_encode(get_option('ctm_api_key') . ":" . get_option('ctm_api_secret'))));
    curl_setopt($curl, CURLOPT_POST, strlen($data_json));
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data_json);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    
    $output = curl_exec($curl);
    
    $this->form_log($form["type"], "Form POST submission returned: " . $output);
    
    curl_close($curl);
  }
  
  function update_account() {
    if (get_option("ctm_api_auth_account") && get_option("ctm_api_key") == get_option("ctm_api_active_key") && get_option("ctm_api_secret") == get_option("ctm_api_active_secret") && $this->authorizing()) return;
    
    update_option("ctm_api_active_key", get_option("ctm_api_key"));
    update_option("ctm_api_active_secret", get_option("ctm_api_secret"));
    update_option("ctm_api_auth_account", "");
    update_option("ctm_api_stats", "");
    update_option("ctm_api_stats_expires", "");
    
    if (!$this->authorizing()) return;
    
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $this->ctm_host . "/api/v1/accounts/current.json");
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-Type: application/json", "Authorization: Basic " . base64_encode(get_option('ctm_api_key') . ":" . get_option('ctm_api_secret'))));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    $res = curl_exec($curl);
    if (!is_bool($res)) {
      $data = json_decode($res, true);
      if ($data && isset($data["account"]))
        update_option("ctm_api_auth_account", $data["account"]);
    }
    
    curl_close($curl);
  }
  
  function authorizing() {
    return (get_option("ctm_api_key") && get_option("ctm_api_secret"));
  }
  
  function authorized() {
    $this->update_account();
    return (get_option("ctm_api_auth_account") || !get_option("ctm_api_key") || !get_option("ctm_api_secret"));
  }
  
  function activate_msg() {
    return 'Enter your CallTrackingMetrics account details on the <a href="/wp-admin/options-general.php?page=call-tracking-metrics%2Fcall_tracking_metrics.php">Settings page</a> to get started.';
  }
  
  function unauthorized_msg() {
    return 'Invalid credentials. Please check your <a target="_blank" href="https://app.calltrackingmetrics.com/accounts/edit#account-api">Account Settings</a> and try again.';
  }
  
  // show a snapshot of recent call activity and aggregate stats
  function admin_dashboard_plugin() {
    $ctm_api_stats_expires = strtotime(get_option('ctm_api_stats_expires'));
    if (!$ctm_api_stats_expires || $ctm_api_stats_expires < time())
      $this->update_stats();
    
    $stats = get_option('ctm_api_stats');
    $dates = array();
    for ($count = 0; $count <= 30; ++$count)
      array_push($dates, date('Y-m-d', strtotime('-' . $count . ' days')));
    
    if (!$this->authorizing()) {
      echo $this->activate_msg();
    } else if (!$this->authorized()) {
      echo $this->unauthorized_msg();
    } else { ?>
    <div class="ctm-dash"
         data-dates='<?php echo json_encode($dates); ?>'
         data-today="<?php echo date('Y-m-d') ?>"
         data-start="<?php echo date('Y-m-d', strtotime('-30 days')); ?>"
         data-stats='<?php echo json_encode($stats)?>'>
    </div>
    <script id="ctm-dash-template" type="text/x-mustache">
      <div style="height:250px" class="stats"></div>
      <h3 class="ctm-stat total_calls">Total Calls: {{total_calls}}</h3>
      <h3 class="ctm-stat total_unique_calls">Total Callers: {{total_unique_calls}}</h3>
      <h3 class="ctm-stat average_call_length">Average Call Time: {{average_call_length}}</h3>
      <h3 class="ctm-stat top_call_source">Top Call Source: {{top_call_source}}</h3>
    </script>
    <script>
      if(!Array.prototype.indexOf){Array.prototype.indexOf=function(e){"use strict";if(this==null){throw new TypeError}var t=Object(this);var n=t.length>>>0;if(n===0){return-1}var r=0;if(arguments.length>1){r=Number(arguments[1]);if(r!=r){r=0}else if(r!=0&&r!=Infinity&&r!=-Infinity){r=(r>0||-1)*Math.floor(Math.abs(r))}}if(r>=n){return-1}var i=r>=0?r:Math.max(n-Math.abs(r),0);for(;i<n;i++){if(i in t&&t[i]===e){return i}}return-1}}
      jQuery(function($) {
        var dashTemplate = Mustache.compile($("#ctm-dash-template").html());
        var stats = $.parseJSON($("#ctm_dash .ctm-dash").attr("data-stats"));
        var startDate = $("#ctm_dash .ctm-dash").attr("data-start");
        var endDate = $("#ctm_dash .ctm-dash").attr("data-today");
        var categories = $.parseJSON($("#ctm_dash .ctm-dash").attr("data-dates")).reverse();

        $("#ctm_dash .ctm-dash").html(dashTemplate(stats));
        var data = [], calls = (stats && stats.stats) ? stats.stats.calls : [];
        for (var i = 0, len = categories.length; i < len; ++i) {
          data.push(0); // zero fill
        }
        for (var c in calls) {
          data[categories.indexOf(c)] = calls[c][0];
        }
        var series = [{
                        name: 'Calls', data: data,
                        pointInterval: 24 * 3600 * 1000,
                        pointStart: Date.parse(categories[0])
                      }];
        var chart = new Highcharts.Chart({
          credits: { enabled: false },
          chart: { type: 'column', renderTo: $("#ctm_dash .stats").get(0), plotBackgroundColor:null, backgroundColor: 'transparent' },
          yAxis: { min: 0, title: { text: "Calls" } },
          title: { text: 'Last 30 Days' },
          legend: { enabled: false },
          //tooltip: { formatter: function() { return '<b>'+ this.x +'</b><br/> '+ this.y; } },
          xAxis: {
            type: 'datetime',
            minRange: 30 * 24 * 3600000 // last 30 days
          },
          series: series
        });
      });
    </script>
    <?php } ?>
    <?php
  }
  
  function update_stats() {
    $this->update_account();
    
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $this->ctm_host . "/api/v1/accounts/" . get_option('ctm_api_auth_account') . "/reports.json");
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-Type: application/json", "Authorization: Basic " . base64_encode(get_option('ctm_api_key') . ":" . get_option('ctm_api_secret'))));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    
    $res = curl_exec($curl);
    if (!is_bool($res)) {
      update_option("ctm_api_stats", json_decode($res, true));
      update_option("ctm_api_stats_expires", date('Y-m-d H:i:s', strtotime('+10 minutes')));
    }
    
    curl_close($curl);
  }
  
  function debug($data) {
    ob_start(); var_dump($data); $contents = ob_get_contents(); ob_end_clean(); error_log($contents);
  }
  
  function cf7_log($message) {
    $logs = json_decode(get_option("ctm_api_cf7_logs"), true);
    if (!is_array($logs)) $logs = array();
    while (count($logs) >= 20) array_shift($logs);
    
    array_push($logs, array("message" => $message, "date" => date("c")));
    update_option("ctm_api_cf7_logs", json_encode($logs));
  }
  
  function gf_log($message) {
    $logs = json_decode(get_option("ctm_api_gf_logs"), true);
    if (!is_array($logs)) $logs = array();
    while (count($logs) >= 20) array_shift($logs);
    
    array_push($logs, array("message" => $message, "date" => date("c")));
    update_option("ctm_api_gf_logs", json_encode($logs));
  }
  
  function form_log($type, $message) {
    if ($type == "Contact Form 7") {
      $this->cf7_log($message);
    } else if ($type == "Gravity Forms") {
      $this->gf_log($message);
    }
  }
  
  function settings_page() {
    $authorizing = $this->authorizing();
    $authorized = $this->authorized();
    
?>
<style>
  #wpbody .wrap {
    font-family: 'Open Sans', Verdana, Arial, sans-serif;
    margin-top: 20px;
  }
  #ctm-logo {
    display: block;
    background: transparent url(https://d7p5n6bjn5dvo.cloudfront.net/images/logo2.png) no-repeat;
    width: 330px;
    max-width: 100%;
    height: 60px;
    background-size: contain;
  }
  #ctm-logo:focus {
    box-shadow: none;
  }
  span.hint {
    font-size: 12px;
    display: block;
    line-height: 1.5;
    padding-top: 2px;
    padding-bottom: 6px;
    color: #555;
  }
  #wpbody .ctm-card p {
    margin-top: 0;
  }
  .ctm-card {
    background-color: #fff;
    box-shadow: 0 0 3px rgba(0, 0, 0, 0.3);
    border-radius: 2px;
    padding: 20px 0 10px 0;
    max-width: 100%;
    margin-top: 0;
    border: none;
  }
  .ctm-field .ctm-field {
    margin-bottom: 10px;
  }
  h3 {
    margin: 7px;
    font-size: 18px;
    color: #222;
    line-height: 1.1;
  }
  h3 small {
    font-size: 65%;
    font-weight: normal;
    line-height: 1;
    color: #777;
  }
  form > .ctm-field + .ctm-field {
    margin-top: 25px;
  }
  input.regular-text {
    width: 100%;
    max-width: 400px;
    font-size: 13px;
    padding: 7px;
    border-radius: 4px;
    border: 1px solid #ccc;
    line-height: 16px;
    margin: 2px 0;
  }
  form {
    margin: 0;
    margin-bottom: 60px;
  }
  
  .ctm-card > *,
  .ctm-card > form,
  .ctm-card > input.string,
  .ctm-card > input.text,
  .ctm-card > input.password {
    margin-left: 3%;
    margin-right: 3%;
    max-width: calc(100% - 6%);
  }
  
  .ctm-card input[type=checkbox] + label {
    display: inline-block;
    padding-left: 5px;
  }
  
  .ctm-card > footer {
    border-top: solid 1px #E0E0E0;
    padding: 12px;
    margin: 0;
    margin-bottom: -10px;
    max-width: 100%;
    line-height: 35px;
  }
  
  .ctm-card > footer .button {
    margin-bottom: 0;
  }
  
  .ctm-card > footer > p, .ctm-card > footer > span {
    line-height: 140%;
  }
  
  .ctm-card > footer {
    margin-top: 20px;
  }
  div.ctm-error {
    display: block;
    width: 100%;
    background-color: rgba(255, 0, 0, 0.5);
    color: white;
    font-size: 120%;
    margin: 0;
    max-width: 100%;
    padding: 0;
    margin-bottom: 20px;
  }
  div.ctm-error a {
    color: white;
  }
  div.ctm-error a:focus {
    box-shadow: none;
  }
  div.ctm-error > span {
    display: block;
    padding: 13px;
    line-height: 1.4;
  }
  .ctm-button {
    font-size: 13px;
    border:none;
    padding:0 10px;
    color:#fff;
    
    line-height:30px;
    height:30px;
    position:relative;
    display:inline-block;
    
    vertical-align: baseline;
    outline: none;
    cursor: pointer;
    text-align: center;
    text-decoration: none;
    
    text-shadow:none;
    white-space: nowrap;
    
    background-color:#5bbcd2;
    
    -ms-user-select: none;
    -webkit-user-select: none;
    -khtml-user-select: none;
    -moz-user-select: none;
    -o-user-select: none;
    user-select: none;
    
    transition: background 0.5s;
  }
  .ctm-button.callout {
    background:#6F9DE3 repeat-x top left;
  }
  .ctm-button.callout:hover {
    background:#70A2F1 repeat-x top left;
  }
  a.ctm-button:hover, a.ctm-button:focus {
    box-shadow: none;
    color: white;
  }
  .ctm-btn {
    text-decoration: none;
    background-color: #f3f3f3;
    border-radius: 4px;
    color: black;
    padding: 2px 8px;
    line-height: 20px;
    margin: 0 2px;
  }
  .ctm-list {
    width: 100%;
    border: 1px solid #ccc;
    min-height: 100px;
    max-height: 400px;
    margin-bottom: 5px;
    overflow-y: scroll;
    overflow-x: hidden;
  }
  .ctm-row {
    width: 100%;
    font-size: 90%;
    line-height: 1.5;
    padding: 3px 5px;
  }
  .ctm-row:hover {
    background-color: #eee;
  }
  .ctm-date {
    display: block;
    font-size: 90%;
    color: #ccc;
  }
</style>
<div class="wrap">
  <a href="https://www.calltrackingmetrics.com" id="ctm-logo"></a>
  <form method="post" action="options.php">
    <?php wp_nonce_field('update-options'); ?>
    <input type="hidden" name="action" value="update" />
    <input type="hidden" name="page_options" value="ctm_api_key,ctm_api_secret,call_track_account_script,ctm_api_dashboard_enabled,ctm_api_tracking_enabled,ctm_api_cf7_enabled,ctm_api_gf_enabled" />
    
    <div class="ctm-field">
      <h3>Account <small>enter your account details to use this plugin</small></h3>
      <?php if (!$authorizing) { ?>
      <div class="ctm-card">
        <p style="margin-bottom:5px">Enter your <b>CallTrackingMetrics account details</b> below to get started.</p>
        <p><b>Don't have an account?</b> <b><a target="_blank" href="https://calltrackingmetrics.com/plans">Sign up</a></b> now &mdash; it only takes a few minutes.</p>
      <?php } else if (!$authorized) { ?>
      <div class="ctm-card" style="padding-top: 0">
        <div class="ctm-error"><span><?php echo $this->unauthorized_msg(); ?></span></div>
      <?php } else { ?>
      <div class="ctm-card">
      <?php } ?>
        <div class="ctm-field">
          <strong><label for="ctm_api_key"><?php _e("Access Key"); ?></label></strong><br/>
          <input class="regular-text" type="text" id="ctm_api_key" name="ctm_api_key" value="<?php echo get_option('ctm_api_key'); ?>" autocomplete="off"/>
        </div>
        <div class="ctm-field">
          <strong><label for="ctm_api_secret"><?php _e("Secret Key"); ?></label></strong><br/>
          <input class="regular-text" type="password" id="ctm_api_secret" name="ctm_api_secret" value="<?php echo get_option('ctm_api_secret'); ?>"/>
        </div>
        <span class="hint">These can found in the API Integration section of your <a target="_blank" href="https://app.calltrackingmetrics.com/accounts/edit#account-api">Account Settings</a>.</span>
        
        <footer>
          <input type="submit" class="ctm-button callout" value="<?php _e('Save Changes') ?>" />
        </footer>
      </div>
    </div>
    <div class="ctm-field"<?php if ($authorizing && $authorized) { ?> style="display:none"<?php } ?>>
      <h3>Tracking Code <small>Manually install the tracking code on this website</small></h3>
      <div class="ctm-card">
        <p>If you do not wish to use the API, you may manually enter your account's <a target="_blank" href="https://app.calltrackingmetrics.com/accounts/tracking_script_settings">tracking code</a> below.</p>
        <div class="ctm-field">
          <input type="text" id="call_track_account_script" class="regular-text" name="call_track_account_script" value="<?php echo htmlspecialchars(get_option("call_track_account_script")); ?>" style="width:100%;text-align:center;padding:7px;font-size:13px;max-width:400px">
        </div>
        <footer>
          <input type="submit" class="ctm-button callout" value="<?php _e('Save Changes') ?>" />
        </footer>
      </div>
    </div>
    <div class="ctm-field"<?php if (!$authorizing || !$authorized) { ?> style="display:none"<?php } ?>>
      <h3>Settings <small>customize the behavior of this plugin</small></h3>
      <div class="ctm-card">
        <div class="ctm-field">
          <label><input type="checkbox" id="ctm_api_dashboard_enabled" name="ctm_api_dashboard_enabled" value='1' <?php echo checked(1, $this->dashboard_enabled(), false) ?>/>Show <b>call statistics</b> in the WordPress Dashboard</label>
          <span class="hint">Displays a simple widget in your <a href="/wp-admin/index.php">WordPress Dashboard</a> showing call volume by day for the last 30 days.</span>
        </div>
        <div class="ctm-field">
          <label><input type="checkbox" id="ctm_api_tracking_enabled" name="ctm_api_tracking_enabled" value='1' <?php echo checked(1, $this->tracking_enabled(), false) ?>/>Install the <b>tracking code</b> on this website automatically</label>
          <span class="hint">This code can also be found on your <a target="_blank" href="https://app.calltrackingmetrics.com/accounts/tracking_script_settings">Tracking Code</a> page.</span>
          <?php if ($authorizing && $authorized && get_option('ctm_api_auth_account')) { ?><input type="text" class="regular-text" value='<?php echo $this->get_tracking_script(); ?>' disabled style="width:100%;text-align:center;padding:7px;font-size:13px;max-width:400px"><?php } ?>
        </div>
        
        <footer>
          <input type="submit" class="ctm-button callout" value="<?php _e('Save Changes') ?>" />
        </footer>
      </div>
    </div>
    <div class="ctm-field"<?php if (!$authorizing || !$authorized) { ?> style="display:none"<?php } ?>>
      <h3>Integrations <small>send your WordPress forms into CallTrackingMetrics automatically</small></h3>
      <div class="ctm-card">
        <p>These integrations do not require <i>any extra setup</i> &mdash; simply create a form with a phone number field, <b>submit the form at least once</b>, and <a href="https://app.calltrackingmetrics.com/form_reactors">a FormReactor will appear in your CallTrackingMetrics account</a> automatically so you can customize how to react to form submissions.</p>
        <div class="ctm-field">
          <label><input type="checkbox" id="ctm_api_cf7_enabled" name="ctm_api_cf7_enabled" value='1' <?php echo checked(1, $this->cf7_enabled(), false) ?>/>Enable <b>Contact Form 7</b> integration</label>
          <span class="hint">Contact Form 7 uses a simple markup structure to embed forms anywhere on your WordPress website.</span>
          <span class="hint"><?php if (!$this->cf7_active()) { ?><a class="ctm-btn" href="https://wordpress.org/plugins/contact-form-7/">Install</a><?php } else { ?><a class="ctm-btn" href="/wp-admin/admin.php?page=wpcf7">Settings</a> <a class="ctm-btn" href="https://wordpress.org/plugins/contact-form-7/">Website</a><?php } ?> <a class="ctm-btn" href="http://contactform7.com/support/">Support</a><?php if ($this->cf7_active()) { ?> <a class="ctm-btn" id="ctm-cf7-logs-btn" href="#">Logs <span>&#9662;</span></a><?php } ?></span>
        </div>
        <div class="ctm-field" id="ctm-cf7-logs-list" style="display:none">
          <div class="ctm-list" data-logs="<?php echo htmlspecialchars(get_option("ctm_api_cf7_logs")) ?>"></div>
        </div>
        <div class="ctm-field">
          <label><input type="checkbox" id="ctm_api_gf_enabled" name="ctm_api_gf_enabled" value='1' <?php echo checked(1, $this->gf_enabled(), false) ?>/>Enable <b>Gravity Forms</b> integration</label>
          <span class="hint">Gravity Forms are created using a drag-and-drop editor with support for over 30 input types.</span>
          <span class="hint"><?php if (!$this->gf_active()) { ?><a class="ctm-btn" href="http://www.gravityforms.com/">Install</a><?php } else { ?><a class="ctm-btn" href="/wp-admin/admin.php?page=gf_settings">Settings</a> <a class="ctm-btn" href="http://www.gravityforms.com/">Website</a><?php } ?> <a class="ctm-btn" href="https://www.gravityhelp.com/support/">Support</a><?php if ($this->gf_active()) { ?> <a class="ctm-btn" id="ctm-gf-logs-btn" href="#">Logs <span>&#9662;</span></a><?php } ?></span>
        </div>
        <div class="ctm-field" id="ctm-gf-logs-list" style="display:none">
          <div class="ctm-list" data-logs="<?php echo htmlspecialchars(get_option("ctm_api_gf_logs")) ?>"></div>
        </div>
        <footer>
          <input type="submit" class="ctm-button callout" value="<?php _e('Save Changes') ?>" />
        </footer>
        
        <script>
          function install_logs(log_btn, log_list) {
            var btn = document.getElementById(log_btn);
            var list = document.getElementById(log_list);
            var list_elem = list.getElementsByClassName("ctm-list")[0];
            
            var logs = JSON.parse(list_elem.getAttribute("data-logs") || "[]");
            for (var i = logs.length - 1; i >= 0; i--) {
              var row = document.createElement("div");
              row.className = "ctm-row";
              row.textContent = logs[i].message;
              row.innerHTML = row.innerHTML + "<span class='ctm-date'>" + logs[i].date + "</span>";
              list_elem.appendChild(row);
            }
            
            btn.addEventListener("click", function(e) {
              e.preventDefault();
              
              if (list.style.display == "none") {
                list.style.display = "block";
                btn.getElementsByTagName("span")[0].innerHTML = "&#x00D7;";
              } else {
                list.style.display = "none";
                btn.getElementsByTagName("span")[0].innerHTML = "&#9662;";
              }
            });
          }
          install_logs("ctm-cf7-logs-btn", "ctm-cf7-logs-list");
          install_logs("ctm-gf-logs-btn", "ctm-gf-logs-list");
        </script>
      </div>
    </div>
  </form>
</div>
<?php
  }
}

function create_call_tracking_metrics() {
  $call_tracking_metrics = new CallTrackingMetrics();
}

add_action('plugins_loaded', "create_call_tracking_metrics");


/*
var __jctm_ready = __jctm_ready || [];
<a dataid="FRT472ABB2C5B9B141AD5E26548EB63AFE3BAC1799AB6BDB656" class="ctm-call-widget" href="https://app.calltrackingmetrics.com/form_reactors/FRT472ABB2C5B9B141AD5E26548EB63AFE3BAC1799AB6BDB656">Call Us</a>
<script defer async dataid="FRT472ABB2C5B9B141AD5E26548EB63AFE3BAC1799AB6BDB656" src="https://dwklcmio8m2n2.cloudfront.net/assets/form_reactors.js"></script>
<script>
  __jctm_ready.push(function(__jctm) {
     __jctm.bind("success"
  });
</script>

var actin = null;
while( action = __jctm_ready.pop() ) {
  action()
}
*/

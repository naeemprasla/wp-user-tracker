<?php
/*
Plugin Name: Enhanced User Activity Tracker with File Change Detection and Auto-Cleanup
Description: Tracks user actions including post changes, settings, user management, theme/plugin modifications, login events (excluding subscribers), code file changes, and auto-deletes logs older than a week.
Version: 1.4
Author: Ne
*/

define('UAT_TABLE_NAME', 'wp_user_activity_log');

// Activation Hook: Create the database table and schedule the cleanup event
register_activation_hook(__FILE__, 'uat_activate_plugin');
function uat_activate_plugin() {
    uat_create_table();
    if (!wp_next_scheduled('uat_cleanup_old_logs')) {
        wp_schedule_event(time(), 'daily', 'uat_cleanup_old_logs');
    }
}

// Deactivation Hook: Remove the scheduled event
register_deactivation_hook(__FILE__, 'uat_deactivate_plugin');
function uat_deactivate_plugin() {
    wp_clear_scheduled_hook('uat_cleanup_old_logs');
}

// Function to create the database table
function uat_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'user_activity_log';

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        action VARCHAR(50) NOT NULL,
        details TEXT,
        ip_address VARCHAR(45) NOT NULL,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
    ) " . $wpdb->get_charset_collate() . ";";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Log function
function uat_log_activity($user_id, $action, $details = '') {
    global $wpdb;
    $table_name = $wpdb->prefix . 'user_activity_log';
    $ip_address = $_SERVER['REMOTE_ADDR'];

    $wpdb->insert($table_name, [
        'user_id' => $user_id,
        'action' => $action,
        'details' => $details,
        'ip_address' => $ip_address,
    ]);
}

// Auto-delete logs older than one week
add_action('uat_cleanup_old_logs', 'uat_delete_old_logs');
function uat_delete_old_logs() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'user_activity_log';
    $wpdb->query("DELETE FROM $table_name WHERE timestamp < NOW() - INTERVAL 7 DAY");
}

// Track login events, excluding subscribers
add_action('wp_login', function($user_login, $user) {
    if (!in_array('subscriber', (array) $user->roles)) {
        uat_log_activity($user->ID, 'login');
    }
}, 10, 2);

// Track post/page/custom post creation, updates, drafts, and deletions
add_action('save_post', function($post_id, $post, $update) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    $user_id = get_current_user_id();
    $status = $post->post_status;
    $action = $update ? ($status === 'draft' ? 'post_drafted' : 'post_updated') : 'post_created';
    $details = "Post ID: $post_id, Title: {$post->post_title}, Status: $status";

    uat_log_activity($user_id, $action, $details);
}, 10, 3);

add_action('before_delete_post', function($post_id) {
    $user_id = get_current_user_id();
    $post = get_post($post_id);
    $details = "Post ID: $post_id, Title: {$post->post_title}";

    uat_log_activity($user_id, 'post_deleted', $details);
});

// Track WordPress settings changes
add_action('update_option', function($option, $old_value, $new_value) {
    $user_id = get_current_user_id();
    $action = $option;
    $details = "<div>Wordpress Setting Updated</div> <div><label>OLD: </label><br /><textarea style='resize:none' rows='3' readonly>".json_encode($old_value)."</textarea></div> <div><label>New: </label><br /><textarea style='resize:none' rows='3' readonly>".json_encode($new_value)."</textarea></div>";

    uat_log_activity($user_id, $action, $details);
}, 10, 3);

// Track user creation, updates, and deletions
add_action('user_register', function($user_id) {
    uat_log_activity($user_id, 'user_created', "User ID: $user_id");
});

add_action('profile_update', function($user_id, $old_user_data) {
    uat_log_activity($user_id, 'user_updated', "User ID: $user_id");
}, 10, 2);

add_action('delete_user', function($user_id) {
    uat_log_activity($user_id, 'user_deleted', "User ID: $user_id");
});

// Track plugin/theme installations and deletions
add_action('upgrader_process_complete', function($upgrader, $options) {
    $user_id = get_current_user_id();
    
    if ($options['type'] === 'plugin') {
        $action = $options['action'] === 'install' ? 'plugin_installed' : 'plugin_deleted';
        $details = implode(', ', $options['plugins']);
    } elseif ($options['type'] === 'theme') {
        $action = $options['action'] === 'install' ? 'theme_installed' : 'theme_deleted';
        $details = implode(', ', $options['themes']);
    }

    if (isset($action)) {
        uat_log_activity($user_id, $action, $details);
    }
}, 10, 2);

// Add an admin page to view the activity log
add_action('admin_menu', function() {
    add_menu_page(
        'User Activity Log',
        'User Activity Log',
        'manage_options',
        'user-activity-log',
        'uat_display_activity_log'
    );
});

// Display activity log in the admin dashboard
function uat_display_activity_log() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'user_activity_log';
    
    $logs = $wpdb->get_results("SELECT * FROM $table_name ORDER BY timestamp DESC LIMIT 100");

    echo '<div class="wrap">';
    echo '<h1>User Activity Log</h1>';
    echo '<table class="widefat fixed" cellspacing="0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Details</th>
                    <th>IP Address</th>
                    <th>Timestamp</th>
                </tr>
            </thead>
            <tbody>';
    
    foreach ($logs as $log) {
        $user_info = get_userdata($log->user_id);
        $username = $user_info ? $user_info->user_login : 'Unknown User';
        
        echo "<tr>
                <td>" . esc_html($log->id) . "</td>
                <td>" . esc_html($username) . "</td>
                <td>" . esc_html($log->action) . "</td>
                <td>" . $log->details . "</td>
                <td>" . esc_html($log->ip_address) . "</td>
                <td>" . esc_html($log->timestamp) . "</td>
              </tr>";
    }
    
    echo '</tbody></table>';
    echo '</div>';
}

// Track code file changes in themes and plugins
add_action('admin_init', function() {
    $user_id = get_current_user_id();
    $directories = [
        ABSPATH . 'wp-content/themes/',
        ABSPATH . 'wp-content/plugins/',
    ];

    // Retrieve stored last modified times
    $stored_times = get_option('uat_file_mod_times', []);

    foreach ($directories as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile()) {
                $file_path = $file->getPathname();
                $file_name = basename($file_path); // Get just the file name
                $last_modified = $file->getMTime();

                // Check if last modified time has changed
                if (isset($stored_times[$file_path]) && $stored_times[$file_path] !== $last_modified) {
                    // Wrap the file name in an anchor tag with the full file path as the href
                    $file_link = '<a href="' . $file_path . '" target="_blank">' . esc_html($file_name) . '</a>';
                    uat_log_activity($user_id, 'code_modified', "File: $file_link");
                }

                // Update stored last modified time
                $stored_times[$file_path] = $last_modified;
            }
        }
    }

    // Save updated modification times
    update_option('uat_file_mod_times', $stored_times);
});



// Add custom cron interval for every minute
function uat_custom_cron_intervals($schedules) {
    $schedules['minute'] = [
        'interval' => 60,  // 60 seconds = 1 minute
        'display' => __('Every Minute'),
    ];
    return $schedules;
}
add_filter('cron_schedules', 'uat_custom_cron_intervals');

// Schedule the cron event on plugin activation
function uat_schedule_active_user_tracking() {
    if (!wp_next_scheduled('uat_check_active_users')) {
        wp_schedule_event(time(), 'minute', 'uat_check_active_users'); // Set to run every minute
    }
}
add_action('wp', 'uat_schedule_active_user_tracking');

// Clear the scheduled event on plugin deactivation
function uat_clear_scheduled_cron() {
    $timestamp = wp_next_scheduled('uat_check_active_users');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'uat_check_active_users');
    }
}
register_deactivation_hook(__FILE__, 'uat_clear_scheduled_cron');

// Track active users
function uat_check_active_users() {
    // Check if the user is logged in and is an admin
    if (is_user_logged_in() && current_user_can('administrator')) {
        $user_id = get_current_user_id();
        $current_time = current_time('mysql');
        
        // Update user last active time
        update_user_meta($user_id, '_last_active_time', $current_time);
    }
}
add_action('uat_check_active_users', 'uat_check_active_users');

// Add a widget to the WordPress admin dashboard to display active users
function uat_add_dashboard_widget() {
    wp_add_dashboard_widget(
        'uat_active_users',                 // Widget ID
        'Currently Active Users',           // Widget title
        'uat_display_active_users'          // Function that displays active users
    );
}
add_action('wp_dashboard_setup', 'uat_add_dashboard_widget');

// Display the active users in the widget
function uat_display_active_users() {
    global $wpdb;

    // Get the current time and subtract 10 minutes
    $time_limit = date('Y-m-d H:i:s', strtotime('-10 minutes', current_time('timestamp')));

    // Get users who were active in the last 10 minutes
    $users = get_users([
        'meta_key' => '_last_active_time',
        'meta_value' => $time_limit,
        'meta_compare' => '>=',
        'orderby' => 'meta_value',
        'order' => 'DESC',
    ]);

    if (empty($users)) {
        echo '<p>No active users found.</p>';
        return;
    }

    echo '<ul>';
    foreach ($users as $user) {
        $last_active_time = get_user_meta($user->ID, '_last_active_time', true);
        $formatted_time = date('Y-m-d H:i:s', strtotime($last_active_time));

        echo '<li>';
        echo '<strong>' . esc_html($user->display_name) . '</strong> - Last active: ' . $formatted_time;
        echo '</li>';
    }
    echo '</ul>';
}

// Enqueue custom JavaScript to track real-time active users
function uat_enqueue_dashboard_script() {
    if (is_admin()) {
        wp_enqueue_script('uat-dashboard-script', plugin_dir_url(__FILE__) . 'js/active-users.js', ['jquery'], null, true);

        // Localize the script to pass Ajax URL
        wp_localize_script('uat-dashboard-script', 'uat_ajax_object', [
            'ajax_url' => admin_url('admin-ajax.php')
        ]);
    }
}
add_action('admin_enqueue_scripts', 'uat_enqueue_dashboard_script');



// Handle the AJAX request to update the active time of the user
function uat_update_user_activity() {
    if (is_user_logged_in()) {
        // Update the last active time in user meta for the logged-in user
        $user_id = get_current_user_id();
        $current_time = current_time('mysql');

        // Update the last active time for the user
        update_user_meta($user_id, '_last_active_time', $current_time);
    }

    // Respond with a success message
    wp_send_json_success();
}
add_action('wp_ajax_update_user_activity', 'uat_update_user_activity');

// Fetch active users based on the last active time
function uat_get_active_users() {
    global $wpdb;

    // Get current time minus 10 minutes (you can adjust this to fit your needs)
    $time_limit = date('Y-m-d H:i:s', strtotime('-10 minutes', current_time('timestamp')));

    // Query users who were active in the last 10 minutes
    $users = get_users([
        'meta_key' => '_last_active_time',
        'meta_value' => $time_limit,
        'meta_compare' => '>=',
        'orderby' => 'meta_value',
        'order' => 'DESC',
    ]);

    $active_users = [];
    foreach ($users as $user) {
        $last_active_time = get_user_meta($user->ID, '_last_active_time', true);
        $active_users[] = [
            'display_name' => $user->display_name,
            'last_active' => date('Y-m-d H:i:s', strtotime($last_active_time)),
        ];
    }

    // Return the list of active users
    wp_send_json_success(['users' => $active_users]);
}
add_action('wp_ajax_get_active_users', 'uat_get_active_users');
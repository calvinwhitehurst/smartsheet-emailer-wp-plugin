<?php
/*
Plugin Name: Send Smartsheet Portal Link
Description: Sends portal link emails based on Smartsheet data with webhook automation and service-specific email flows
Version: 3.2
Author: Calvin Whitehurst
*/

defined('ABSPATH') or die('No script kiddies please!');

// === REGISTER REST ROUTES ===
add_action('rest_api_init', function () {
    // Manual email endpoint (keep for testing)
    register_rest_route('custom-api/v1', '/send-portal-link', [
        'methods' => 'GET',
        'callback' => 'send_portal_link_email',
        'permission_callback' => '__return_true',
    ]);

    // Webhook endpoint for Smartsheet
    register_rest_route('smartsheet/v1', '/webhook', [
        'methods' => ['POST', 'GET'],
        'callback' => 'spl_handle_webhook',
        'permission_callback' => '__return_true',
    ]);
});

// === REGISTER CRON HOOKS ===
add_action('spl_send_reminder', 'spl_send_reminder_email', 10, 3);

// === GET SUPPORTED SERVICE TYPES ===
function spl_get_service_types() {
    return ['pepe', 'ime', 'ffde'];
}

// === WEBHOOK HANDLER ===
function spl_handle_webhook($request) {
    // Handle GET requests for testing
    if ($request->get_method() === 'GET') {
        return new WP_REST_Response([
            'status' => 'Webhook endpoint is working',
            'timestamp' => current_time('mysql')
        ], 200);
    }
    
    // Handle Smartsheet challenge
    $challenge = $request->get_header('smartsheet-hook-challenge');
    if ($challenge) {
        wp_send_json(['smartsheetHookResponse' => $challenge], 200);
        exit;
    }
    
    // Handle webhook events
    $body = $request->get_json_params();
    if (!$body) {
        $raw_body = $request->get_body();
        $body = json_decode($raw_body, true);
    }
    
    if (!$body || !isset($body['events'])) {
        error_log('SPL: No events in webhook payload');
        return new WP_REST_Response(['error' => 'No events found'], 400);
    }

    $checkbox_column_id = get_option('spl_checkbox_column_id');
    if (!$checkbox_column_id) {
        error_log('SPL: Checkbox column ID not configured');
        return new WP_REST_Response(['error' => 'Checkbox column not configured'], 400);
    }

    $processed_events = 0;
    foreach ($body['events'] as $event) {
        // Check if this is a checkbox change event (can be 'cellModified' or 'updated')
        if (($event['eventType'] === 'cellModified' || $event['eventType'] === 'updated') && isset($event['columnId'])) {
            if ($event['columnId'] == $checkbox_column_id) {
                $rowId = $event['rowId'];
                
                // Send initial email and schedule reminders
                $result = spl_process_email_flow($rowId);
                if ($result) {
                    error_log("SPL: Email flow started for row: $rowId");
                    $processed_events++;
                } else {
                    error_log("SPL: Failed to start email flow for row: $rowId");
                }
            }
        }
    }

    return new WP_REST_Response(['status' => 'success', 'processed' => $processed_events], 200);
}

// === PROCESS EMAIL FLOW ===
function spl_process_email_flow($row_id) {
    // Get row data first
    $data = spl_get_row_data($row_id);
    if (!$data) {
        error_log("SPL: Failed to get row data for: $row_id");
        return false;
    }

    // Determine service type
    $service_type = spl_determine_service_type($data);
    if (!$service_type) {
        error_log("SPL: Could not determine service type for row: $row_id");
        return false;
    }

    error_log("SPL: Processing $service_type email flow for row: $row_id");

    // Send initial email if enabled
    if (get_option("spl_{$service_type}_initial_email_enabled", false)) {
        $result = spl_send_email_template($service_type, 'initial', $data);
        if (!$result) {
            error_log("SPL: Failed to send $service_type initial email for row: $row_id");
            return false;
        }
    }

    // Schedule reminder emails if enabled and we have eval date/time
    if (!empty($data['eval_date']) && !empty($data['eval_time'])) {
        $eval_datetime = $data['eval_date'] . ' ' . $data['eval_time'];
        
        // Schedule 48 hour reminder
        if (get_option("spl_{$service_type}_48hour_email_enabled", false)) {
            $send_time = strtotime($eval_datetime . ' -48 hours');
            if ($send_time > time()) {
                wp_schedule_single_event($send_time, 'spl_send_reminder', [$row_id, $service_type, '48hour']);
            }
        }
        
        // Schedule 24 hour reminder
        if (get_option("spl_{$service_type}_24hour_email_enabled", false)) {
            $send_time = strtotime($eval_datetime . ' -24 hours');
            if ($send_time > time()) {
                wp_schedule_single_event($send_time, 'spl_send_reminder', [$row_id, $service_type, '24hour']);
            }
        }
    }

    return true;
}

// === DETERMINE SERVICE TYPE ===
function spl_determine_service_type($data) {
    if (empty($data['service_type'])) {
        return false;
    }

    $service_value = strtolower(trim($data['service_type']));
    
    // Map service type values to our internal keys
    $service_mapping = [
        'pepe' => 'pepe',
        'ime' => 'ime', 
        'ffde' => 'ffde'
    ];

    return $service_mapping[$service_value] ?? false;
}

// === SEND REMINDER EMAIL ===
function spl_send_reminder_email($row_id, $service_type, $email_type) {
    $data = spl_get_row_data($row_id);
    if (!$data) {
        error_log("SPL: Failed to get row data for reminder: $row_id");
        return;
    }

    $result = spl_send_email_template($service_type, $email_type, $data);
    if ($result) {
        error_log("SPL: $service_type $email_type reminder sent successfully for row: $row_id");
    } else {
        error_log("SPL: Failed to send $service_type $email_type reminder for row: $row_id");
    }
}

// === GET ROW DATA FROM SMARTSHEET ===
function spl_get_row_data($row_id) {
    $sheet_id = get_option('spl_sheet_id');
    $api_token = get_option('spl_api_token');

    if (!$sheet_id || !$api_token) {
        error_log('SPL: Missing Smartsheet configuration');
        return false;
    }

    // Get only the specific columns we need to minimize data transfer
    $needed_columns = [
        get_option('spl_email_column_id'),
        get_option('spl_first_name_column_id'),
        get_option('spl_client_name_column_id'),
        get_option('spl_eval_time_column_id'),
        get_option('spl_eval_date_column_id'),
        get_option('spl_pearson_column_id'),
        get_option('spl_zoom_column_id'),
        get_option('spl_talogy_column_id'),
        get_option('spl_service_type_column_id')
    ];
    
    // Remove empty column IDs
    $needed_columns = array_filter($needed_columns);
    
    if (empty($needed_columns)) {
        error_log('SPL: No column IDs configured');
        return false;
    }

    // Fetch ONLY the specific row with ONLY the columns we need
    $column_list = implode(',', $needed_columns);
    $url = "https://api.smartsheet.com/2.0/sheets/$sheet_id/rows/$row_id?include=columns&columnIds=$column_list";
    
    $response = wp_remote_get($url, [
        'headers' => ['Authorization' => "Bearer $api_token"],
        'timeout' => 15
    ]);

    if (is_wp_error($response)) {
        error_log('SPL: Failed to fetch row data: ' . $response->get_error_message());
        return false;
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        error_log("SPL: API returned error code: $response_code");
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!$body || !isset($body['cells'])) {
        error_log('SPL: Invalid response from Smartsheet');
        return false;
    }

    // Extract data using configurable column IDs
    return spl_extract_row_data($body['cells']);
}

// === MANUAL EMAIL ENDPOINT (for testing) ===
function send_portal_link_email($request) {
    $row_id = sanitize_text_field($request->get_param('row_id'));
    if (!$row_id) {
        return new WP_REST_Response(['error' => 'Missing row_id'], 400);
    }

    $result = spl_process_email_flow($row_id);
    
    if ($result) {
        return new WP_REST_Response(['success' => true, 'message' => 'Email flow started successfully'], 200);
    } else {
        return new WP_REST_Response(['error' => 'Failed to start email flow'], 500);
    }
}

// === EXTRACT DATA FROM SMARTSHEET ROW ===
function spl_extract_row_data($cells) {
    $data = [
        'email' => '',
        'first_name' => '',
        'client_name' => '',
        'eval_time' => '',
        'eval_date' => '',
        'pearson_link' => '',
        'zoom_link' => '',
        'talogy_link' => '',
        'service_type' => ''
    ];

    $column_mapping = [
        'spl_email_column_id' => 'email',
        'spl_first_name_column_id' => 'first_name',
        'spl_client_name_column_id' => 'client_name',
        'spl_eval_time_column_id' => 'eval_time',
        'spl_eval_date_column_id' => 'eval_date',
        'spl_pearson_column_id' => 'pearson_link',
        'spl_zoom_column_id' => 'zoom_link',
        'spl_talogy_column_id' => 'talogy_link',
        'spl_service_type_column_id' => 'service_type'
    ];

    foreach ($cells as $cell) {
        if (!isset($cell['columnId']) || !isset($cell['value'])) continue;

        foreach ($column_mapping as $setting_key => $data_key) {
            $column_id = get_option($setting_key);
            if ($column_id && $cell['columnId'] == $column_id) {
                $data[$data_key] = $cell['value'];
                break;
            }
        }
    }

    return $data;
}

// === SEND EMAIL TEMPLATE ===
function spl_send_email_template($service_type, $email_type, $data) {
    // Check if this email type is enabled for this service
    if (!get_option("spl_{$service_type}_{$email_type}_email_enabled", false)) {
        return false;
    }

    // Get template and subject
    $template = get_option("spl_{$service_type}_{$email_type}_email_template", '');
    $subject = get_option("spl_{$service_type}_{$email_type}_email_subject", '');

    if (empty($template) || empty($subject)) {
        error_log("SPL: Missing template or subject for $service_type $email_type email");
        return false;
    }

    // Validate required fields
    $required = ['email', 'first_name', 'client_name', 'eval_time', 'eval_date', 'pearson_link', 'zoom_link', 'talogy_link'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            error_log("SPL: Missing required field: $field");
            return false;
        }
    }

    // Replace placeholders in subject
    $subject = spl_replace_placeholders($subject, $data);
    
    // Replace placeholders in template
    $message = spl_replace_placeholders($template, $data);

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . get_option('spl_from_name', 'Calvin Whitehurst') . ' <' . get_option('spl_from_email', 'calvin@calvinwhitehurst.com') . '>'
    ];

    $email_sent = wp_mail($data['email'], $subject, $message, $headers);

    if (!$email_sent) {
        error_log("SPL: Failed to send $service_type $email_type email to: {$data['email']}");
        return false;
    }

    return true;
}

// === REPLACE PLACEHOLDERS ===
function spl_replace_placeholders($template, $data) {
    $placeholders = [
        '{first_name}' => $data['first_name'],
        '{client_name}' => $data['client_name'],
        '{eval_time}' => $data['eval_time'],
        '{eval_date}' => $data['eval_date'],
        '{pearson_link}' => $data['pearson_link'],
        '{zoom_link}' => $data['zoom_link'],
        '{talogy_link}' => $data['talogy_link'],
        '{email}' => $data['email'],
        '{service_type}' => $data['service_type']
    ];

    return str_replace(array_keys($placeholders), array_values($placeholders), $template);
}

// === GET DEFAULT EMAIL TEMPLATES ===
function spl_get_default_templates() {
    return [
        'pepe' => [
            'initial' => [
                'subject' => 'Your Secure Client Portal Link - PePe Evaluation',
                'template' => '<html><head><body></body></html>'
            ],
            '48hour' => [
                'subject' => 'Reminder: PePe Evaluation in 48 hours',
                'template' => '<html><head><body></body></html>'
            ],
            '24hour' => [
                'subject' => 'URGENT: PePe Evaluation Tomorrow',
                'template' => '<html><head><body></body></html>'
            ]
        ],
        'ime' => [
            'initial' => [
                'subject' => 'Your Independent Medical Examination (IME) Information',
                'template' => '<html><head><body></body></html>'
            ],
            '48hour' => [
                'subject' => 'Reminder: IME Appointment in 48 hours',
                'template' => '<html><head><body></body></html>'
            ],
            '24hour' => [
                'subject' => 'REMINDER: IME Appointment Tomorrow',
                'template' => '<html><head><body></body></html>'
            ]
        ],
        'ffde' => [
            'initial' => [
                'subject' => 'Your Fitness for Duty Evaluation (FFDE) Schedule',
                'template' => '<html><head><body></body></html>'
            ],
            '48hour' => [
                'subject' => 'Reminder: FFDE Evaluation in 48 hours',
                'template' => '<html><head><body></body></html>'
            ],
            '24hour' => [
                'subject' => 'IMPORTANT: FFDE Evaluation Tomorrow',
                'template' => '<html><head><body></body></html>'
            ]
        ]
    ];
}

// === CREATE WEBHOOK ===
function spl_create_webhook() {
    $token = get_option('spl_api_token');
    $sheet_id = get_option('spl_sheet_id');
    $callback_url = get_rest_url(null, 'smartsheet/v1/webhook');

    if (!$token || !$sheet_id) {
        add_settings_error('spl_messages', 'spl_error', 'API Token and Sheet ID are required.', 'error');
        return;
    }

    $callback_url = str_replace('http://', 'https://', $callback_url);
    
    $webhook_data = [
        'name' => 'Portal Link Webhook - ' . date('Y-m-d H:i:s'),
        'callbackUrl' => $callback_url,
        'scope' => 'sheet',
        'scopeObjectId' => intval($sheet_id),
        'events' => ['*.*'],
        'version' => 1
    ];

    $response = wp_remote_post('https://api.smartsheet.com/2.0/webhooks', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode($webhook_data),
        'timeout' => 30
    ]);

    if (is_wp_error($response)) {
        add_settings_error('spl_messages', 'spl_error', 'Failed to create webhook: ' . $response->get_error_message(), 'error');
    } else {
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code === 200) {
            $webhook_data = json_decode($response_body, true);
            $webhook_id = $webhook_data['result']['id'] ?? null;
            if ($webhook_id) {
                update_option('spl_webhook_id', $webhook_id);
                add_settings_error('spl_messages', 'spl_success', "Webhook created successfully! ID: $webhook_id", 'updated');
                
                // Enable the webhook
                spl_enable_webhook($webhook_id);
            }
        } else {
            add_settings_error('spl_messages', 'spl_error', "Failed to create webhook. Response: $response_code", 'error');
        }
    }
}

// === ENABLE WEBHOOK ===
function spl_enable_webhook($webhook_id = null) {
    $token = get_option('spl_api_token');
    if (!$webhook_id) {
        $webhook_id = get_option('spl_webhook_id');
    }
    
    if (!$token || !$webhook_id) {
        return false;
    }

    $response = wp_remote_request("https://api.smartsheet.com/2.0/webhooks/$webhook_id", [
        'method' => 'PUT',
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode(['enabled' => true]),
        'timeout' => 30
    ]);

    return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
}

// === DELETE WEBHOOK ===
function spl_delete_webhook() {
    $token = get_option('spl_api_token');
    $webhook_id = get_option('spl_webhook_id');
    
    if (!$token || !$webhook_id) {
        add_settings_error('spl_messages', 'spl_error', 'No webhook to delete.', 'error');
        return;
    }

    $response = wp_remote_request("https://api.smartsheet.com/2.0/webhooks/$webhook_id", [
        'method' => 'DELETE',
        'headers' => ['Authorization' => 'Bearer ' . $token],
        'timeout' => 30
    ]);

    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        delete_option('spl_webhook_id');
        add_settings_error('spl_messages', 'spl_success', 'Webhook deleted successfully!', 'updated');
    } else {
        add_settings_error('spl_messages', 'spl_error', 'Failed to delete webhook.', 'error');
    }
}

// === TEST API CONNECTION ===
function spl_test_api_connection() {
    $api_token = get_option('spl_api_token');
    if (!$api_token) return false;

    $response = wp_remote_get('https://api.smartsheet.com/2.0/users/me', [
        'headers' => ['Authorization' => "Bearer $api_token"],
        'timeout' => 15
    ]);

    return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
}

// === GET SHEET COLUMNS ===
function spl_get_sheet_columns() {
    $api_token = get_option('spl_api_token');
    $sheet_id = get_option('spl_sheet_id');
    
    if (!$api_token || !$sheet_id) return false;

    $response = wp_remote_get("https://api.smartsheet.com/2.0/sheets/$sheet_id/columns", [
        'headers' => ['Authorization' => "Bearer $api_token"],
        'timeout' => 15
    ]);

    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['data'] ?? false;
    }
    return false;
}

// === ADD SETTINGS PAGE ===
add_action('admin_menu', function () {
    add_options_page(
        'Smartsheet Portal Link Settings',
        'Smartsheet Portal Link',
        'manage_options',
        'smartsheet-portal-link',
        'spl_settings_page'
    );
});

// === REGISTER SETTINGS ===
add_action('admin_init', function () {
    register_setting('spl_settings', 'spl_api_token');
    register_setting('spl_settings', 'spl_sheet_id');
    register_setting('spl_settings', 'spl_from_name');
    register_setting('spl_settings', 'spl_from_email');
    register_setting('spl_settings', 'spl_webhook_id');
    
    // Column ID settings
    register_setting('spl_settings', 'spl_checkbox_column_id');
    register_setting('spl_settings', 'spl_email_column_id');
    register_setting('spl_settings', 'spl_first_name_column_id');
    register_setting('spl_settings', 'spl_client_name_column_id');
    register_setting('spl_settings', 'spl_eval_time_column_id');
    register_setting('spl_settings', 'spl_eval_date_column_id');
    register_setting('spl_settings', 'spl_pearson_column_id');
    register_setting('spl_settings', 'spl_zoom_column_id');
    register_setting('spl_settings', 'spl_talogy_column_id');
    register_setting('spl_settings', 'spl_service_type_column_id');
    
    // Email flow settings for each service type
    $service_types = spl_get_service_types();
    $email_types = ['initial', '48hour', '24hour'];
    
    foreach ($service_types as $service) {
        foreach ($email_types as $email_type) {
            register_setting('spl_settings', "spl_{$service}_{$email_type}_email_enabled");
            register_setting('spl_settings', "spl_{$service}_{$email_type}_email_subject");
            register_setting('spl_settings', "spl_{$service}_{$email_type}_email_template");
        }
    }
});

// === SETTINGS PAGE ===
function spl_settings_page() {
    // Handle action buttons
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['spl_test_connection'])) {
            check_admin_referer('spl_test_connection_action');
            if (spl_test_api_connection()) {
                add_settings_error('spl_messages', 'spl_success', 'API connection test successful!', 'updated');
            } else {
                add_settings_error('spl_messages', 'spl_error', 'API connection test failed.', 'error');
            }
        }

        if (isset($_POST['spl_show_columns'])) {
            check_admin_referer('spl_show_columns_action');
            $columns = spl_get_sheet_columns();
            if ($columns) {
                $column_info = '';
                foreach ($columns as $column) {
                    $column_info .= "'{$column['title']}' (ID: {$column['id']}) | ";
                }
                add_settings_error('spl_messages', 'spl_info', "Columns: $column_info", 'updated');
            } else {
                add_settings_error('spl_messages', 'spl_error', 'Failed to get columns.', 'error');
            }
        }

        if (isset($_POST['spl_create_webhook'])) {
            check_admin_referer('spl_create_webhook_action');
            spl_create_webhook();
        }

        if (isset($_POST['spl_delete_webhook'])) {
            check_admin_referer('spl_delete_webhook_action');
            spl_delete_webhook();
        }
    }

    ?>
    <div class="wrap">
        <h1>Smartsheet Portal Link Settings</h1>
        
        <?php settings_errors('spl_messages'); ?>

        <!-- Action Buttons -->
        <h2>Testing & Management</h2>
        <table style="border-spacing: 10px; margin-bottom: 30px;">
            <tr>
                <td>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('spl_test_connection_action'); ?>
                        <input type="submit" name="spl_test_connection" class="button" value="Test API Connection" />
                    </form>
                </td>
                <td>Test if your API token is valid</td>
            </tr>
            <tr>
                <td>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('spl_show_columns_action'); ?>
                        <input type="submit" name="spl_show_columns" class="button" value="Show Columns" />
                    </form>
                </td>
                <td>Display all columns with their IDs</td>
            </tr>
            <tr>
                <td>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('spl_create_webhook_action'); ?>
                        <input type="submit" name="spl_create_webhook" class="button button-primary" value="Create Webhook" />
                    </form>
                </td>
                <td>Create webhook for automatic email sending</td>
            </tr>
            <tr>
                <td>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('spl_delete_webhook_action'); ?>
                        <input type="submit" name="spl_delete_webhook" class="button" value="Delete Webhook" />
                    </form>
                </td>
                <td>Remove current webhook</td>
            </tr>
        </table>

        <!-- Settings Form -->
        <form method="post" action="options.php">
            <?php settings_fields('spl_settings'); ?>
            
            <h2>Basic Settings</h2>
            <table class="form-table">
                <tr>
                    <th>Smartsheet API Token</th>
                    <td><input type="password" name="spl_api_token" value="<?php echo esc_attr(get_option('spl_api_token')); ?>" size="60" /></td>
                </tr>
                <tr>
                    <th>Sheet ID</th>
                    <td><input type="text" name="spl_sheet_id" value="<?php echo esc_attr(get_option('spl_sheet_id')); ?>" size="20" /></td>
                </tr>
                <tr>
                    <th>From Name</th>
                    <td><input type="text" name="spl_from_name" value="<?php echo esc_attr(get_option('spl_from_name', 'Email Sender')); ?>" size="40" /></td>
                </tr>
                <tr>
                    <th>From Email</th>
                    <td><input type="email" name="spl_from_email" value="<?php echo esc_attr(get_option('spl_from_email', 'email@website.com')); ?>" size="40" /></td>
                </tr>
            </table>

            <h2>Column Configuration</h2>
            <p>Use "Show Columns" button above to see all available columns and their IDs</p>
            <table class="form-table">
                <tr>
                    <th>Checkbox Column ID</th>
                    <td><input type="text" name="spl_checkbox_column_id" value="<?php echo esc_attr(get_option('spl_checkbox_column_id')); ?>" size="20" />
                    <p class="description">The column ID for "Links added to sheet?" checkbox</p></td>
                </tr>
                <tr>
                    <th>Service Type Column ID</th>
                    <td><input type="text" name="spl_service_type_column_id" value="<?php echo esc_attr(get_option('spl_service_type_column_id')); ?>" size="20" />
                    <p class="description">The column ID for "Service Type" dropdown</p></td>
                </tr>
                <tr>
                    <th>Email Column ID</th>
                    <td><input type="text" name="spl_email_column_id" value="<?php echo esc_attr(get_option('spl_email_column_id')); ?>" size="20" />
                    <p class="description">Evaluee email address column</p></td>
                </tr>
                <tr>
                    <th>First Name Column ID</th>
                    <td><input type="text" name="spl_first_name_column_id" value="<?php echo esc_attr(get_option('spl_first_name_column_id')); ?>" size="20" /></td>
                </tr>
                <tr>
                    <th>Client Name Column ID</th>
                    <td><input type="text" name="spl_client_name_column_id" value="<?php echo esc_attr(get_option('spl_client_name_column_id')); ?>" size="20" /></td>
                </tr>
                <tr>
                    <th>Eval Time Column ID</th>
                    <td><input type="text" name="spl_eval_time_column_id" value="<?php echo esc_attr(get_option('spl_eval_time_column_id')); ?>" size="20" /></td>
                </tr>
                <tr>
                    <th>Eval Date Column ID</th>
                    <td><input type="text" name="spl_eval_date_column_id" value="<?php echo esc_attr(get_option('spl_eval_date_column_id')); ?>" size="20" /></td>
                </tr>
                <tr>
                    <th>Pearson Link Column ID</th>
                    <td><input type="text" name="spl_pearson_column_id" value="<?php echo esc_attr(get_option('spl_pearson_column_id')); ?>" size="20" /></td>
                </tr>
                <tr>
                    <th>Zoom Link Column ID</th>
                    <td><input type="text" name="spl_zoom_column_id" value="<?php echo esc_attr(get_option('spl_zoom_column_id')); ?>" size="20" /></td>
                </tr>
                <tr>
                    <th>Talogy Link Column ID</th>
                    <td><input type="text" name="spl_talogy_column_id" value="<?php echo esc_attr(get_option('spl_talogy_column_id')); ?>" size="20" /></td>
                </tr>
            </table>

            <h2>PePe Email Configuration</h2>
            <p><strong>Available placeholders:</strong> {first_name}, {client_name}, {eval_date}, {eval_time}, {pearson_link}, {zoom_link}, {talogy_link}, {email}, {service_type}</p>
            
            <h3>Initial Email (Sent Immediately)</h3>
            <table class="form-table">
                <tr>
                    <th>Enable Initial Email</th>
                    <td><input type="checkbox" name="spl_pepe_initial_email_enabled" value="1" <?php checked(get_option('spl_pepe_initial_email_enabled', false)); ?> /></td>
                </tr>
                <tr>
                    <th>Subject Line</th>
                    <td><input type="text" name="spl_pepe_initial_email_subject" value="<?php echo esc_attr(get_option('spl_pepe_initial_email_subject', '')); ?>" size="80" /></td>
                </tr>
                <tr>
                    <th>Email Template</th>
                    <td><textarea name="spl_pepe_initial_email_template" rows="10" cols="100"><?php echo esc_textarea(get_option('spl_pepe_initial_email_template', '')); ?></textarea></td>
                </tr>
            </table>

            <h3>48 Hour Reminder Email</h3>
            <table class="form-table">
                <tr>
                    <th>Enable 48 Hour Reminder</th>
                    <td><input type="checkbox" name="spl_pepe_48hour_email_enabled" value="1" <?php checked(get_option('spl_pepe_48hour_email_enabled', false)); ?> /></td>
                </tr>
                <tr>
                    <th>Subject Line</th>
                    <td><input type="text" name="spl_pepe_48hour_email_subject" value="<?php echo esc_attr(get_option('spl_pepe_48hour_email_subject', '')); ?>" size="80" /></td>
                </tr>
                <tr>
                    <th>Email Template</th>
                    <td><textarea name="spl_pepe_48hour_email_template" rows="10" cols="100"><?php echo esc_textarea(get_option('spl_pepe_48hour_email_template', '')); ?></textarea></td>
                </tr>
            </table>

            <h3>24 Hour Reminder Email</h3>
            <table class="form-table">
                <tr>
                    <th>Enable 24 Hour Reminder</th>
                    <td><input type="checkbox" name="spl_pepe_24hour_email_enabled" value="1" <?php checked(get_option('spl_pepe_24hour_email_enabled', false)); ?> /></td>
                </tr>
                <tr>
                    <th>Subject Line</th>
                    <td><input type="text" name="spl_pepe_24hour_email_subject" value="<?php echo esc_attr(get_option('spl_pepe_24hour_email_subject', '')); ?>" size="80" /></td>
                </tr>
                <tr>
                    <th>Email Template</th>
                    <td><textarea name="spl_pepe_24hour_email_template" rows="10" cols="100"><?php echo esc_textarea(get_option('spl_pepe_24hour_email_template', '')); ?></textarea></td>
                </tr>
            </table>

            <h2>IME Email Configuration</h2>
            
            <h3>Initial Email (Sent Immediately)</h3>
            <table class="form-table">
                <tr>
                    <th>Enable Initial Email</th>
                    <td><input type="checkbox" name="spl_ime_initial_email_enabled" value="1" <?php checked(get_option('spl_ime_initial_email_enabled', false)); ?> /></td>
                </tr>
                <tr>
                    <th>Subject Line</th>
                    <td><input type="text" name="spl_ime_initial_email_subject" value="<?php echo esc_attr(get_option('spl_ime_initial_email_subject', '')); ?>" size="80" /></td>
                </tr>
                <tr>
                    <th>Email Template</th>
                    <td><textarea name="spl_ime_initial_email_template" rows="10" cols="100"><?php echo esc_textarea(get_option('spl_ime_initial_email_template', '')); ?></textarea></td>
                </tr>
            </table>

            <h3>48 Hour Reminder Email</h3>
            <table class="form-table">
                <tr>
                    <th>Enable 48 Hour Reminder</th>
                    <td><input type="checkbox" name="spl_ime_48hour_email_enabled" value="1" <?php checked(get_option('spl_ime_48hour_email_enabled', false)); ?> /></td>
                </tr>
                <tr>
                    <th>Subject Line</th>
                    <td><input type="text" name="spl_ime_48hour_email_subject" value="<?php echo esc_attr(get_option('spl_ime_48hour_email_subject', '')); ?>" size="80" /></td>
                </tr>
                <tr>
                    <th>Email Template</th>
                    <td><textarea name="spl_ime_48hour_email_template" rows="10" cols="100"><?php echo esc_textarea(get_option('spl_ime_48hour_email_template', '')); ?></textarea></td>
                </tr>
            </table>

            <h3>24 Hour Reminder Email</h3>
            <table class="form-table">
                <tr>
                    <th>Enable 24 Hour Reminder</th>
                    <td><input type="checkbox" name="spl_ime_24hour_email_enabled" value="1" <?php checked(get_option('spl_ime_24hour_email_enabled', false)); ?> /></td>
                </tr>
                <tr>
                    <th>Subject Line</th>
                    <td><input type="text" name="spl_ime_24hour_email_subject" value="<?php echo esc_attr(get_option('spl_ime_24hour_email_subject', '')); ?>" size="80" /></td>
                </tr>
                <tr>
                    <th>Email Template</th>
                    <td><textarea name="spl_ime_24hour_email_template" rows="10" cols="100"><?php echo esc_textarea(get_option('spl_ime_24hour_email_template', '')); ?></textarea></td>
                </tr>
            </table>

            <h2>FFDE Email Configuration</h2>
            
            <h3>Initial Email (Sent Immediately)</h3>
            <table class="form-table">
                <tr>
                    <th>Enable Initial Email</th>
                    <td><input type="checkbox" name="spl_ffde_initial_email_enabled" value="1" <?php checked(get_option('spl_ffde_initial_email_enabled', false)); ?> /></td>
                </tr>
                <tr>
                    <th>Subject Line</th>
                    <td><input type="text" name="spl_ffde_initial_email_subject" value="<?php echo esc_attr(get_option('spl_ffde_initial_email_subject', '')); ?>" size="80" /></td>
                </tr>
                <tr>
                    <th>Email Template</th>
                    <td><textarea name="spl_ffde_initial_email_template" rows="10" cols="100"><?php echo esc_textarea(get_option('spl_ffde_initial_email_template', '')); ?></textarea></td>
                </tr>
            </table>

            <h3>48 Hour Reminder Email</h3>
            <table class="form-table">
                <tr>
                    <th>Enable 48 Hour Reminder</th>
                    <td><input type="checkbox" name="spl_ffde_48hour_email_enabled" value="1" <?php checked(get_option('spl_ffde_48hour_email_enabled', false)); ?> /></td>
                </tr>
                <tr>
                    <th>Subject Line</th>
                    <td><input type="text" name="spl_ffde_48hour_email_subject" value="<?php echo esc_attr(get_option('spl_ffde_48hour_email_subject', '')); ?>" size="80" /></td>
                </tr>
                <tr>
                    <th>Email Template</th>
                    <td><textarea name="spl_ffde_48hour_email_template" rows="10" cols="100"><?php echo esc_textarea(get_option('spl_ffde_48hour_email_template', '')); ?></textarea></td>
                </tr>
            </table>

            <h3>24 Hour Reminder Email</h3>
            <table class="form-table">
                <tr>
                    <th>Enable 24 Hour Reminder</th>
                    <td><input type="checkbox" name="spl_ffde_24hour_email_enabled" value="1" <?php checked(get_option('spl_ffde_24hour_email_enabled', false)); ?> /></td>
                </tr>
                <tr>
                    <th>Subject Line</th>
                    <td><input type="text" name="spl_ffde_24hour_email_subject" value="<?php echo esc_attr(get_option('spl_ffde_24hour_email_subject', '')); ?>" size="80" /></td>
                </tr>
                <tr>
                    <th>Email Template</th>
                    <td><textarea name="spl_ffde_24hour_email_template" rows="10" cols="100"><?php echo esc_textarea(get_option('spl_ffde_24hour_email_template', '')); ?></textarea></td>
                </tr>
            </table>

            <?php submit_button('Save All Settings'); ?>
        </form>
    </div>
    <?php
}

// Plugin activation
register_activation_hook(__FILE__, function() {
    add_option('spl_api_token', '');
    add_option('spl_sheet_id', '');
    add_option('spl_from_name', '');
    add_option('spl_from_email', '');
    add_option('spl_webhook_id', '');
    
    // Set default column IDs 
    add_option('spl_email_column_id', '');
    add_option('spl_first_name_column_id', '');
    add_option('spl_client_name_column_id', '');
    add_option('spl_eval_time_column_id', '');
    add_option('spl_eval_date_column_id', '');
    add_option('spl_pearson_column_id', '');
    add_option('spl_zoom_column_id', '');
    add_option('spl_talogy_column_id', '');
    add_option('spl_checkbox_column_id', '');
    add_option('spl_service_type_column_id', '');
    
    // Initialize all service type email settings as disabled by default
    $service_types = ['pepe', 'ime', 'ffde'];
    $email_types = ['initial', '48hour', '24hour'];
    
    foreach ($service_types as $service) {
        foreach ($email_types as $email_type) {
            add_option("spl_{$service}_{$email_type}_email_enabled", false);
            add_option("spl_{$service}_{$email_type}_email_subject", '');
            add_option("spl_{$service}_{$email_type}_email_template", '');
        }
    }
    
    // Load default templates
    $defaults = spl_get_default_templates();
    foreach ($defaults as $service => $email_types) {
        foreach ($email_types as $email_type => $template) {
            update_option("spl_{$service}_{$email_type}_email_subject", $template['subject']);
            update_option("spl_{$service}_{$email_type}_email_template", $template['template']);
        }
    }
});
?>
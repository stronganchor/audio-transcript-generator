<?php
/*
Plugin Name: AI Audio Transcription Interface
Plugin URI: https://stronganchortech.com
Description: A plugin to handle audio transcription using the AssemblyAI API via a URL input field, with GPT-4o-mini post-processing.
Version: 1.8.7
Author: Strong Anchor Tech
Author URI: https://stronganchortech.com
*/

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Include the plugin update checker
require_once plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/stronganchor/audio-transcript-generator', // GitHub repository URL
    __FILE__,                                        // Full path to the main plugin file
    'audio-transcript-generator'                                  // Plugin slug
);

// Set the branch to "main"
$myUpdateChecker->setBranch('main');

// Add a meta box with the transcription shortcode to the post edit page
function whisper_add_transcription_meta_box() {
    add_meta_box(
        'transcription_meta_box', // Meta box ID
        'Audio Transcription', // Title of the meta box
        'whisper_render_transcription_meta_box', // Callback function
        ['post', 'transcription', 'sermon', 'sermons', 'wpfc_sermon', 'podcast'], // Post types where the meta box should appear
        'normal', // Context where the box should appear (normal, side, etc.)
        'high' // Priority of the meta box
    );
}
add_action('add_meta_boxes', 'whisper_add_transcription_meta_box');

// Render the transcription form meta box
function whisper_render_transcription_meta_box($post) {
    // Try to find an audio URL in the post content or metadata
    $audio_url = whisper_find_audio_url($post->ID);
    ?>
    <div id="transcriptionFormContainer">
        <h2>Enter a URL to an audio file for transcription</h2>

        <!-- URL input, auto-populated if found -->
        <label for="audio_url">Enter URL:</label>
        <input type="url" id="audio_url" name="audio_url" placeholder="https://example.com/audio.mp3" value="<?php echo esc_attr($audio_url); ?>" required>

        <button type="button" id="transcribeButton">Transcribe</button>
    </div>
    <div id="transcriptionStatus"></div>
    <div id="transcriptionResult"></div>
    <?php
}

// Helper function to find an audio URL in the post content or metadata
function whisper_find_audio_url($post_id) {
    // Retrieve the post content
    $post_content = get_post_field('post_content', $post_id);

    // Define the regex pattern for matching audio URLs
    $audio_url_pattern = '/https?:\/\/[^\s"\'<>]+?\.(mp3|wav|ogg)/i';

    // 1. Check for an audio URL in the post content
    if (preg_match($audio_url_pattern, $post_content, $matches)) {
        return esc_url_raw($matches[0]); // Return the first matched URL
    }

    // 2. Retrieve all metadata for the post
    $all_meta = get_post_meta($post_id);

    // Iterate through each meta key and its associated values
    foreach ($all_meta as $meta_key => $meta_values) {
        foreach ($meta_values as $meta_value) {
            // Ensure the meta value is a string before applying regex
            if (is_string($meta_value) && preg_match($audio_url_pattern, $meta_value, $matches)) {
                return esc_url_raw($matches[0]); // Return the first matched URL
            }
        }
    }

    // 3. If no audio URL is found, return an empty string
    return '';
}


// Function to render the transcription shortcode form below the title
function whisper_transcription_admin_shortcode_after_title($content) {
    $screen = get_current_screen();
    
    // Only display on the transcription post list page
    if ($screen && $screen->post_type === 'transcription' && $screen->base === 'edit') {
        // Build the form HTML
        $shortcode_output = '<div class="wrap">';
        $shortcode_output .= '<h2>Submit Audio for Transcription</h2>';
        $shortcode_output .= do_shortcode('[whisper_audio_transcription]');
        $shortcode_output .= '</div>';

        // Add the form after the main title
        add_action('in_admin_header', function () use ($shortcode_output) {
            echo $shortcode_output;
        });
    }
}

// Hook into 'load-edit.php' to place the shortcode after the title on the transcription list page
add_action('load-edit.php', 'whisper_transcription_admin_shortcode_after_title');

// Handle transcription saving via AJAX
add_action('wp_ajax_save_transcription', 'save_transcription_callback');
add_action('wp_ajax_nopriv_save_transcription', 'save_transcription_callback');

function save_transcription_callback() {
    try {
        if (isset($_POST['transcription']) && isset($_POST['audio_url']) && isset($_POST['post_id'])) {
            $transcription_text = sanitize_text_field($_POST['transcription']);
            $audio_url = sanitize_text_field($_POST['audio_url']);
            $post_id = intval($_POST['post_id']); // Get the current post ID
            
            // Extract the file name from the audio URL
            $audio_file_name = basename(parse_url($audio_url, PHP_URL_PATH)); // This will give you "example.mp3"

            // Send transcription text to OpenAI for post-processing
            $processed_transcription = process_transcription_with_gpt($transcription_text);

            // Insert the processed transcription as a new post with the audio file name as the title
            $new_post_id = wp_insert_post([
                'post_title' => $audio_file_name,  // Set the title as the audio file name
                'post_content' => $processed_transcription,
                'post_status' => 'publish',
                'post_type' => 'transcription',
            ]);

            // If the new transcription post is created, append the transcription to the current post
            if ($new_post_id) {
                // Get the existing post content to append the transcription
                $current_post = get_post($post_id);

                if ($current_post) {
                    // Use wp_update_post and force the update to append content
                    $new_content = $current_post->post_content . "\n\n" . '<!-- Audio Transcription -->' . "\n" . $processed_transcription;
                    
                    // Prepare post data for updating (force updating, bypassing any potential post lock)
                    $updated_post = [
                        'ID' => $post_id,
                        'post_content' => $new_content,
                    ];
                    
                    // Use wp_update_post to save the changes (bypass the post lock)
                    remove_action('wp_insert_post', 'wp_save_post_revision'); // Prevent revision creation
                    wp_update_post($updated_post);
                    add_action('wp_insert_post', 'wp_save_post_revision'); // Re-enable revision creation after updating
                    
                    wp_send_json_success(['new_post_id' => $new_post_id, 'message' => 'Transcription post created and appended to the original post.']);
                } else {
                    wp_send_json_error(['message' => 'Original post not found.']);
                }
            } else {
                wp_send_json_error(['message' => 'Failed to create transcription post']);
            }
        } else {
            wp_send_json_error(['message' => 'No transcription text, audio URL, or post ID provided']);
        }
    } catch (Exception $e) {
        error_log("Error in save_transcription_callback: " . $e->getMessage());
        wp_send_json_error(['message' => 'An error occurred: ' . $e->getMessage()]);
    }
}

// Enqueue the script for frontend and admin
function enqueue_transcription_script() {
    $relative_path = 'js/assemblyai-transcription.js';
    $asset_version = filemtime(plugin_dir_path(__FILE__) . $relative_path);

    // Enqueue the script for frontend and admin
    wp_enqueue_script('assemblyai-transcription', plugin_dir_url(__FILE__) . $relative_path, [], $asset_version, true);

    // Localize the script to pass in AJAX URL and API keys
    wp_localize_script('assemblyai-transcription', 'assemblyai_settings', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'assemblyai_api_key' => get_option('assemblyai_api_key'),
        'post_id' => get_the_ID(),
    ]);
}
add_action('wp_enqueue_scripts', 'enqueue_transcription_script');
add_action('admin_enqueue_scripts', 'enqueue_transcription_script');

// Function to process transcription text with GPT-4o-mini (using OpenAI API)
function process_transcription_with_gpt($transcription_text) {
    error_log("[" . date('Y-m-d H:i:s') . "] Starting process_transcription_with_gpt");

    $api_key = get_option('openai_api_key');
    $api_endpoint = 'https://api.openai.com/v1/chat/completions';

    $messages = [
        [
            'role' => 'system',
            'content' => 'You are an expert text editor specializing in correcting transcription errors.'
        ],
        [
            'role' => 'user',
            'content' => "Perform basic editing tasks on this speech transcript. Don't change wording, just update the punctuation and spelling and add paragraph breaks where necessary.\n\n" . $transcription_text,
        ],
    ];

    $postData = [
        'model' => 'gpt-4o-mini',
        'messages' => $messages,
        'temperature' => 0.7,
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);

    // Set longer timeouts for cURL
    curl_setopt($ch, CURLOPT_TIMEOUT, 600); // Total execution time
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60); // Connection timeout

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json',
    ]);

    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));

    error_log("[" . date('Y-m-d H:i:s') . "] Executing OpenAI API post-processing request");

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $curl_error = curl_error($ch);
        error_log("[" . date('Y-m-d H:i:s') . "] cURL error in OpenAI post-processing request: $curl_error");
        // Return original transcription if error occurs
        return $transcription_text;
    }

    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded_response = json_decode($response, true);

    if ($http_status != 200) {
        // Log the error response
        error_log("[" . date('Y-m-d H:i:s') . "] OpenAI API error: " . json_encode($decoded_response));
        // Return original transcription if error occurs
        return $transcription_text;
    }

    if (isset($decoded_response['choices'][0]['message']['content'])) {
        $processed_text = $decoded_response['choices'][0]['message']['content'];
        error_log("[" . date('Y-m-d H:i:s') . "] OpenAI processing completed");
        return $processed_text;
    } else {
        error_log("[" . date('Y-m-d H:i:s') . "] Unexpected OpenAI API response: " . json_encode($decoded_response));
        // Return original transcription if unexpected response
        return $transcription_text;
    }
}

// Shortcode function to display URL input for transcription
function whisper_audio_transcription_shortcode($atts) {
    ob_start();
    ?>
    <form id="transcriptionForm">
        <h2>Enter a URL to an audio file for transcription</h2>
        
        <!-- URL input -->
        <label for="audio_url">Enter URL:</label>
        <input type="url" id="audio_url" name="audio_url" placeholder="https://example.com/audio.mp3" required>

        <button type="button" id="transcribeButton">Transcribe</button>

        <!-- Hidden message to be displayed after submission -->
        <div id="transcriptionStatus" style="display:none; margin-top: 15px;">
            The audio file has been submitted for transcription. This may take a few minutes. You can navigate away and come back to this page to check for updates, or wait here.
        </div>
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode('whisper_audio_transcription', 'whisper_audio_transcription_shortcode');

// Register custom post type for transcriptions
function whisper_register_transcription_post_type() {
    $args = [
        'public' => true,
        'label'  => 'Transcriptions',
        'supports' => ['title', 'editor', 'author'],
        'rewrite' => ['slug' => 'transcription'],
    ];
    register_post_type('transcription', $args);
}
add_action('init', 'whisper_register_transcription_post_type');

// Add an admin menu item for plugin settings
function whisper_audio_transcription_menu() {
    add_options_page('Audio Transcription Settings', 'Audio Transcription', 'manage_options', 'whisper-audio-transcription', 'whisper_audio_transcription_settings_page');
}
add_action('admin_menu', 'whisper_audio_transcription_menu');

// Render the settings page
function whisper_audio_transcription_settings_page() {
    ?>
    <div class="wrap">
        <h1>Audio Transcription Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('whisper_audio_transcription_options_group');
            do_settings_sections('whisper_audio_transcription');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// Register and define the settings
function whisper_audio_transcription_settings_init() {
    register_setting('whisper_audio_transcription_options_group', 'openai_api_key');
    register_setting('whisper_audio_transcription_options_group', 'assemblyai_api_key');

    add_settings_section('whisper_audio_transcription_main_section', 'Main Settings', 'whisper_audio_transcription_section_text', 'whisper_audio_transcription');

    add_settings_field('openai_api_key', 'OpenAI API Key', 'whisper_audio_transcription_setting_input_openai', 'whisper_audio_transcription', 'whisper_audio_transcription_main_section');
    add_settings_field('assemblyai_api_key', 'AssemblyAI API Key', 'whisper_audio_transcription_setting_input_assemblyai', 'whisper_audio_transcription', 'whisper_audio_transcription_main_section');
}
add_action('admin_init', 'whisper_audio_transcription_settings_init');

function whisper_audio_transcription_section_text() {
    echo '<p>Enter your API keys here.</p>';
}

function whisper_audio_transcription_setting_input_openai() {
    $api_key = get_option('openai_api_key');
    echo "<input id='openai_api_key' name='openai_api_key' type='password' value='" . esc_attr($api_key) . "' />";
}

function whisper_audio_transcription_setting_input_assemblyai() {
    $api_key = get_option('assemblyai_api_key');
    echo "<input id='assemblyai_api_key' name='assemblyai_api_key' type='password' value='" . esc_attr($api_key) . "' />";
}

<?php
/*
Plugin Name: AI Audio Transcription Interface
Plugin URI: https://stronganchortech.com
Description: A plugin to handle audio transcription using the AssemblyAI API via a URL input field, with GPT-4o-mini post-processing.
Version: 1.9.0
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

            // Insert the transcription as a new post without GPT processing
            $new_post_id = wp_insert_post([
                'post_title' => $audio_file_name,  // Set the title as the audio file name
                'post_content' => $transcription_text,
                'post_status' => 'publish',
                'post_type' => 'transcription',
            ]);

            // If the new transcription post is created, append the transcription to the current post
            if ($new_post_id) {
                // Get the existing post content to append the transcription
                $current_post = get_post($post_id);

                if ($current_post) {
                    // Use wp_update_post and force the update to append content
                    $new_content = $current_post->post_content . "\n\n" . '<h3>Audio Transcript</h3>' . "\n" . $transcription_text;
                    
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

// New AJAX handler to save processed transcription
add_action('wp_ajax_save_processed_transcription', 'save_processed_transcription_callback');
add_action('wp_ajax_nopriv_save_processed_transcription', 'save_processed_transcription_callback');

function save_processed_transcription_callback() {
    try {
        if (isset($_POST['processed_transcription']) && isset($_POST['audio_url']) && isset($_POST['post_id'])) {
            // Use sanitize_textarea_field to preserve line breaks
            // Alternatively, use wp_kses_post to allow basic HTML
            $processed_transcription = sanitize_textarea_field($_POST['processed_transcription']);
            // $processed_transcription = wp_kses_post($_POST['processed_transcription']);
            
            $audio_url = sanitize_text_field($_POST['audio_url']);
            $post_id = intval($_POST['post_id']); // Get the current post ID
            
            // Extract the file name from the audio URL
            $audio_file_name = basename(parse_url($audio_url, PHP_URL_PATH)); // This will give you "example.mp3"

            // Insert the processed transcription as a new post
            $new_post_id = wp_insert_post([
                'post_title' => $audio_file_name,  // Set the title as the audio file name
                'post_content' => $processed_transcription,
                'post_status' => 'publish',
                'post_type' => 'transcription',
            ]);

            // If the new transcription post is created, append the processed transcription to the current post
            if ($new_post_id) {
                // Get the existing post content to append the processed transcription
                $current_post = get_post($post_id);

                if ($current_post) {
                    // Use wp_update_post and force the update to append content
                    $new_content = $current_post->post_content . "\n\n" . '<h3>Processed Audio Transcript</h3>' . "\n" . $processed_transcription;
                    
                    // Prepare post data for updating (force updating, bypassing any potential post lock)
                    $updated_post = [
                        'ID' => $post_id,
                        'post_content' => $new_content,
                    ];
                    
                    // Use wp_update_post to save the changes (bypass the post lock)
                    remove_action('wp_insert_post', 'wp_save_post_revision'); // Prevent revision creation
                    wp_update_post($updated_post);
                    add_action('wp_insert_post', 'wp_save_post_revision'); // Re-enable revision creation after updating
                    
                    wp_send_json_success(['new_post_id' => $new_post_id, 'message' => 'Processed transcription post created and appended to the original post.']);
                } else {
                    wp_send_json_error(['message' => 'Original post not found.']);
                }
            } else {
                wp_send_json_error(['message' => 'Failed to create processed transcription post']);
            }
        } else {
            wp_send_json_error(['message' => 'No processed transcription text, audio URL, or post ID provided']);
        }
    } catch (Exception $e) {
        error_log("Error in save_processed_transcription_callback: " . $e->getMessage());
        wp_send_json_error(['message' => 'An error occurred: ' . $e->getMessage()]);
    }
}

// Enqueue the script for frontend and admin
function enqueue_transcription_script() {
    $relative_path = 'js/assemblyai-transcription.js';
    $asset_version = filemtime(plugin_dir_path(__FILE__) . $relative_path);

    // Enqueue the script for frontend and admin
    wp_enqueue_script('assemblyai-transcription', plugin_dir_url(__FILE__) . $relative_path, ['jquery'], $asset_version, true);

    // Localize the script to pass in AJAX URL and API keys
    wp_localize_script('assemblyai-transcription', 'assemblyai_settings', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'assemblyai_api_key' => get_option('assemblyai_api_key'),
        'openai_api_key' => get_option('openai_api_key'), // Added OpenAI API Key
        'post_id' => get_the_ID(),
    ]);
}
add_action('wp_enqueue_scripts', 'enqueue_transcription_script');
add_action('admin_enqueue_scripts', 'enqueue_transcription_script');

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

        <!-- Status div -->
        <div id="transcriptionStatus" style="display:none; margin-top: 15px;">
            Starting transcription...
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

<?php
/*
Plugin Name: AI Audio Transcription Interface
Plugin URI: https://stronganchortech.com
Description: A plugin to handle audio transcription using the AssemblyAI API via a URL input field, with GPT-4o-mini post-processing.
Version: 1.9.4
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
        'transcription_meta_box',
        'Audio Transcription',
        'whisper_render_transcription_meta_box',
        ['post', 'transcription', 'sermon', 'sermons', 'wpfc_sermon', 'podcast'],
        'normal',
        'high'
    );
}
add_action('add_meta_boxes', 'whisper_add_transcription_meta_box');

// Render the transcription form meta box
function whisper_render_transcription_meta_box($post) {
    $audio_url = whisper_find_audio_url($post->ID);
    ?>
    <div id="transcriptionFormContainer">
        <h2>Enter a URL to an audio file for transcription</h2>

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
    $post_content = get_post_field('post_content', $post_id);

    $audio_url_pattern = '/https?:\/\/[^\s"\'<>]+?\.(mp3|wav|ogg)/i';

    if (preg_match($audio_url_pattern, $post_content, $matches)) {
        return esc_url_raw($matches[0]);
    }

    $all_meta = get_post_meta($post_id);
    foreach ($all_meta as $meta_key => $meta_values) {
        foreach ($meta_values as $meta_value) {
            if (is_string($meta_value) && preg_match($audio_url_pattern, $meta_value, $matches)) {
                return esc_url_raw($matches[0]);
            }
        }
    }
    return '';
}

// Function to render the transcription shortcode form below the title
function whisper_transcription_admin_shortcode_after_title($content) {
    $screen = get_current_screen();
    if ($screen && $screen->post_type === 'transcription' && $screen->base === 'edit') {
        $shortcode_output = '<div class="wrap">';
        $shortcode_output .= '<h2>Submit Audio for Transcription</h2>';
        $shortcode_output .= do_shortcode('[whisper_audio_transcription]');
        $shortcode_output .= '</div>';

        add_action('in_admin_header', function () use ($shortcode_output) {
            echo $shortcode_output;
        });
    }
}
add_action('load-edit.php', 'whisper_transcription_admin_shortcode_after_title');

// Handle transcription saving via AJAX
add_action('wp_ajax_save_transcription', 'save_transcription_callback');
add_action('wp_ajax_nopriv_save_transcription', 'save_transcription_callback');
function save_transcription_callback() {
    try {
        if (isset($_POST['transcription']) && isset($_POST['audio_url']) && isset($_POST['post_id'])) {
            $transcription_text = sanitize_text_field($_POST['transcription']);
            $audio_url = sanitize_text_field($_POST['audio_url']);
            $post_id = intval($_POST['post_id']);
            $audio_file_name = basename(parse_url($audio_url, PHP_URL_PATH));

            $new_post_id = wp_insert_post([
                'post_title' => $audio_file_name,
                'post_content' => $transcription_text,
                'post_status' => 'publish',
                'post_type' => 'transcription',
            ]);

            if ($new_post_id) {
                $current_post = get_post($post_id);
                if ($current_post) {
                    $new_content = $current_post->post_content . "\n\n" . '<h3>Audio Transcript</h3>' . "\n" . $transcription_text;
                    $updated_post = [
                        'ID' => $post_id,
                        'post_content' => $new_content,
                    ];
                    remove_action('wp_insert_post', 'wp_save_post_revision');
                    wp_update_post($updated_post);
                    add_action('wp_insert_post', 'wp_save_post_revision');

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
            $processed_transcription = sanitize_textarea_field($_POST['processed_transcription']);
            $audio_url = sanitize_text_field($_POST['audio_url']);
            $post_id = intval($_POST['post_id']);
            $audio_file_name = basename(parse_url($audio_url, PHP_URL_PATH));

            $new_post_id = wp_insert_post([
                'post_title' => $audio_file_name,
                'post_content' => $processed_transcription,
                'post_status' => 'publish',
                'post_type' => 'transcription',
            ]);

            if ($new_post_id) {
                $current_post = get_post($post_id);
                if ($current_post) {
                    $new_content = $current_post->post_content . "\n\n" . '<h3>Audio Transcript</h3>' . "\n" . $processed_transcription;
                    $updated_post = [
                        'ID' => $post_id,
                        'post_content' => $new_content,
                    ];
                    remove_action('wp_insert_post', 'wp_save_post_revision');
                    wp_update_post($updated_post);
                    add_action('wp_insert_post', 'wp_save_post_revision');

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

    // Localize without exposing OpenAI API key
    wp_localize_script('assemblyai-transcription', 'assemblyai_settings', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'assemblyai_api_key' => get_option('assemblyai_api_key'),
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
        
        <label for="audio_url">Enter URL:</label>
        <input type="url" id="audio_url" name="audio_url" placeholder="https://example.com/audio.mp3" required>

        <button type="button" id="transcribeButton">Transcribe</button>

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

/**
 * New AJAX handler to process transcription with GPT server-side.
 * The OpenAI API key is never exposed to the browser.
 */
add_action('wp_ajax_process_openai_transcription', 'process_openai_transcription_callback');
add_action('wp_ajax_nopriv_process_openai_transcription', 'process_openai_transcription_callback');
function process_openai_transcription_callback() {
    try {
        // Must have the transcriptionText parameter.
        if (empty($_POST['transcriptionText'])) {
            wp_send_json_error(['message' => 'No transcription text provided']);
        }

        $transcriptionText = sanitize_textarea_field($_POST['transcriptionText']);
        $openai_api_key = get_option('openai_api_key');
        if (empty($openai_api_key)) {
            wp_send_json_error(['message' => 'OpenAI API key not configured']);
        }

        // Build prompt (same logic from the old JS).
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are an expert text editor specializing in correcting transcription errors.'
            ],
            [
                'role' => 'user',
                'content' => "Perform basic editing tasks on this speech transcript. Don't change wording, just update the punctuation and spelling and add paragraph breaks where necessary.\n\n{$transcriptionText}",
            ],
        ];

        $postData = [
            'model' => 'gpt-4o-mini',
            'messages' => $messages,
            'temperature' => 0.7,
        ];

        // Make the request to OpenAI from the server (not from the browser).
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $openai_api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode($postData),
            'timeout' => 30, // You can adjust if needed
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($decoded['error'])) {
            wp_send_json_error(['message' => $decoded['error']['message']]);
        }

        if (!empty($decoded['choices'][0]['message']['content'])) {
            $processedText = $decoded['choices'][0]['message']['content'];
            wp_send_json_success(['processed_text' => $processedText]);
        } else {
            wp_send_json_error(['message' => 'Unexpected response from OpenAI']);
        }
    } catch (Exception $e) {
        wp_send_json_error(['message' => 'OpenAI error: ' . $e->getMessage()]);
    }
}

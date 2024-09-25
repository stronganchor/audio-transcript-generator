<?php
/*
Plugin Name: AssemblyAI Audio Transcription Interface
Plugin URI: https://stronganchortech.com
Description: A plugin to handle audio transcription using the AssemblyAI API via a URL input field.
Version: 1.6.8
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

// Include the WP Background Processing library
require_once plugin_dir_path(__FILE__) . 'includes/wp-background-processing.php';

// Handle transcription saving via AJAX
add_action('wp_ajax_save_transcription', 'save_transcription_callback');
add_action('wp_ajax_nopriv_save_transcription', 'save_transcription_callback');

function save_transcription_callback() {
    if (isset($_POST['transcription'])) {
        $transcription_text = sanitize_text_field($_POST['transcription']);

        // Insert the transcription as a new post
        $post_id = wp_insert_post([
            'post_title' => 'Transcription',
            'post_content' => $transcription_text,
            'post_status' => 'publish',
            'post_type' => 'transcription',
        ]);

        if ($post_id) {
            wp_send_json_success(['post_id' => $post_id]);
        } else {
            wp_send_json_error(['message' => 'Failed to create transcription post']);
        }
    } else {
        wp_send_json_error(['message' => 'No transcription text provided']);
    }
}

// Enqueue the script for frontend
function enqueue_transcription_script() {
    $relative_path = 'js/assemblyai-transcription.js';
    $asset_version = filemtime(plugin_dir_path(__FILE__) . $relative_path);
    wp_enqueue_script('assemblyai-transcription', plugin_dir_url(__FILE__) . $relative_path, [], $asset_version, true);

    wp_localize_script('assemblyai-transcription', 'assemblyai_settings', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'assemblyai_api_key' => get_option('assemblyai_api_key'),
    ]);
}
add_action('wp_enqueue_scripts', 'enqueue_transcription_script');

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
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode('whisper_audio_transcription', 'whisper_audio_transcription_shortcode');

// Register custom post type for transcriptions (unchanged)
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
?>

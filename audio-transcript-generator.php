<?php
/*
Plugin Name: Whisper Audio Transcription Interface
Plugin URI: https://stronganchortech.com
Description: A plugin to handle audio transcription using the Whisper API, now with enhanced error handling and dynamic post titles.
Version: 1.3.0
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

// Optional: If you're using a private repository, specify the access token like this:
// $myUpdateChecker->setAuthentication('your-token-here');

// Include the WP Background Processing library
require_once plugin_dir_path(__FILE__) . 'includes/wp-background-processing.php';

// Extend the WP_Background_Process class
class Whisper_Transcription_Process extends WP_Background_Process {

    protected $action = 'whisper_transcription_process';

    // Task to process each item in the queue
    protected function task($item) {
        // $item contains the path to the audio file
        $audioPath = $item['audio_path'];
        $user_id = $item['user_id'];
        $transcription_post_id = $item['post_id'];

        // Handle the transcription
        $transcription = whisper_handle_audio_transcription($audioPath, $error_response);

        // Update the post with the transcription
        if ($transcription) {
            // Extract the first 10 words for the post title
            $title = wp_trim_words($transcription, 10, '...');

            wp_update_post([
                'ID' => $transcription_post_id,
                'post_title' => $title,
                'post_content' => wp_kses_post($transcription),
                'post_status' => 'publish',
            ]);
        } else {
            // Update the post to include the raw error response
            $error_message = 'There was an error processing your transcription. Raw response: ' . esc_html($error_response);

            wp_update_post([
                'ID' => $transcription_post_id,
                'post_content' => $error_message,
                'post_status' => 'publish',
            ]);
        }

        // Delete the audio file after processing
        if (file_exists($audioPath)) {
            unlink($audioPath);
        }

        // Return false to remove the item from the queue
        return false;
    }

    // Complete process
    protected function complete() {
        parent::complete();
        // Perform any actions when the entire queue is complete
    }
}

// Initialize the background process
$whisper_transcription_process = new Whisper_Transcription_Process();

// Function to handle audio transcription (used in background processing)
function whisper_handle_audio_transcription($audioPath, &$error_response = '') {
    error_log("[" . date('Y-m-d H:i:s') . "] Starting whisper_handle_audio_transcription");

    // Check the file size
    $fileSize = filesize($audioPath);
    error_log("[" . date('Y-m-d H:i:s') . "] File size: $fileSize bytes");

    // If the file size is greater than 25 MB, compress it
    if ($fileSize > 25 * 1024 * 1024) { // 25 MB in bytes
        error_log("[" . date('Y-m-d H:i:s') . "] File size > 25MB, starting compression");

        $uploads_dir = wp_upload_dir();
        $compressed_audio_path = $uploads_dir['path'] . '/compressed_' . basename($audioPath);

        $compressionResult = compress_audio_file($audioPath, $compressed_audio_path);

        if (!$compressionResult['success']) {
            error_log("[" . date('Y-m-d H:i:s') . "] Error compressing audio file: " . $compressionResult['message']);
            $error_response = 'Error compressing audio file: ' . $compressionResult['message'];
            return false;
        }

        // Use the compressed audio file for transcription
        $audioPath = $compressed_audio_path;

        error_log("[" . date('Y-m-d H:i:s') . "] Compression completed");
    } else {
        error_log("[" . date('Y-m-d H:i:s') . "] File size <= 25MB, no compression needed");
    }

    error_log("[" . date('Y-m-d H:i:s') . "] Sending audio file to OpenAI");

    $response = send_audio_file($audioPath);

    error_log("[" . date('Y-m-d H:i:s') . "] Received response from OpenAI");

    if (isset($response['text'])) {
        return $response['text'];
    } else {
        $error_response = json_encode($response);
        error_log("[" . date('Y-m-d H:i:s') . "] Error in transcription: " . $error_response);
        return false;
    }
}

// Function to compress audio using FFmpeg (same as before)
function compress_audio_file($inputPath, $outputPath) {
    // Escape shell arguments to prevent command injection
    $escapedInputPath = escapeshellarg($inputPath);
    $escapedOutputPath = escapeshellarg($outputPath);

    // FFmpeg command to compress the audio file
    $command = "ffmpeg -i $escapedInputPath -ab 64k -ar 44100 -y $escapedOutputPath 2>&1";

    // Execute the command and capture the output
    exec($command, $output, $return_var);

    // Check if the command was successful
    if ($return_var !== 0) {
        // Return error message
        return ['success' => false, 'message' => implode("\n", $output)];
    }

    // Return success
    return ['success' => true];
}

// Function to send audio file to the API (modified to return errors)
function send_audio_file($audioPath) {
    error_log("[" . date('Y-m-d H:i:s') . "] Starting send_audio_file");

    $api_key = get_option('openai_api_key');
    $api_endpoint = 'https://api.openai.com/v1/audio/transcriptions';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);

    // Set longer timeouts for cURL
    curl_setopt($ch, CURLOPT_TIMEOUT, 600); // Total execution time
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60); // Connection timeout

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: multipart/form-data'
    ]);

    $postData = [
        'file' => new CURLFile($audioPath),
        'model' => 'whisper-1'
    ];

    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

    error_log("[" . date('Y-m-d H:i:s') . "] Executing cURL request to OpenAI");

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $curl_error = curl_error($ch);
        error_log("[" . date('Y-m-d H:i:s') . "] cURL error: $curl_error");
        return ['error' => $curl_error];
    }

    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    error_log("[" . date('Y-m-d H:i:s') . "] cURL request completed with status $http_status");

    $decoded_response = json_decode($response, true);

    if ($http_status != 200) {
        // Return error response
        return $decoded_response ? $decoded_response : ['error' => 'Unexpected error occurred'];
    }

    return $decoded_response;
}

// Shortcode function to display the upload form and handle the transcription (unchanged)
function whisper_audio_transcription_shortcode($atts) {
    ob_start();

    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['audio_file']) && $_FILES['audio_file']['error'] == UPLOAD_ERR_OK) {
        $uploads_dir = wp_upload_dir();
        $uploaded_file_path = $uploads_dir['path'] . '/' . basename($_FILES['audio_file']['name']);

        if (move_uploaded_file($_FILES['audio_file']['tmp_name'], $uploaded_file_path)) {
            // Create a custom post to store the transcription
            $transcription_post_id = wp_insert_post([
                'post_title' => 'Audio Transcription',
                'post_content' => 'Your transcription is being processed...',
                'post_status' => 'draft',
                'post_author' => get_current_user_id(),
                'post_type' => 'transcription',
            ]);

            // Enqueue the background task
            global $whisper_transcription_process;
            $whisper_transcription_process->push_to_queue([
                'audio_path' => $uploaded_file_path,
                'user_id' => get_current_user_id(),
                'post_id' => $transcription_post_id,
            ]);
            $whisper_transcription_process->save()->dispatch();

            echo '<p>Your transcription is being processed. Please check back later.</p>';
            echo '<p><a href="' . get_permalink($transcription_post_id) . '">View Transcription</a></p>';
        } else {
            echo '<p>There was an error uploading the file.</p>';
        }
    } else {
        ?>
        <form method="post" enctype="multipart/form-data">
            <h2>Upload an Audio File</h2>
            <input type="file" name="audio_file" accept="audio/*" required>
            <input type="submit" value="Transcribe">
        </form>
        <?php
    }

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

// Add an admin menu item for plugin settings (unchanged)
function whisper_audio_transcription_menu() {
    add_options_page('Whisper Audio Transcription Settings', 'Whisper Audio Transcription', 'manage_options', 'whisper-audio-transcription', 'whisper_audio_transcription_settings_page');
}
add_action('admin_menu', 'whisper_audio_transcription_menu');

// Render the settings page (unchanged)
function whisper_audio_transcription_settings_page() {
    ?>
    <div class="wrap">
        <h1>Whisper Audio Transcription Settings</h1>
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

// Register and define the settings (unchanged)
function whisper_audio_transcription_settings_init() {
    register_setting('whisper_audio_transcription_options_group', 'openai_api_key');

    add_settings_section('whisper_audio_transcription_main_section', 'Main Settings', 'whisper_audio_transcription_section_text', 'whisper_audio_transcription');

    add_settings_field('openai_api_key', 'OpenAI API Key', 'whisper_audio_transcription_setting_input', 'whisper_audio_transcription', 'whisper_audio_transcription_main_section');
}
add_action('admin_init', 'whisper_audio_transcription_settings_init');

function whisper_audio_transcription_section_text() {
    echo '<p>Enter your OpenAI API key here.</p>';
}

function whisper_audio_transcription_setting_input() {
    $api_key = get_option('openai_api_key');
    echo "<input id='openai_api_key' name='openai_api_key' type='password' value='" . esc_attr($api_key) . "' />";
}
?>

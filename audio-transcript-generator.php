<?php
/*
Plugin Name: Whisper Audio Transcription Interface
Plugin URI: https://stronganchortech.com
Description: A plugin to handle audio transcription using the Whisper API, now with audio compression using FFmpeg for large files.
Version: 1.1.0
Author: Strong Anchor Tech
Author URI: https://stronganchortech.com
*/

// Function to compress audio using FFmpeg
function compress_audio_file($inputPath, $outputPath) {
    // Escape shell arguments to prevent command injection
    $escapedInputPath = escapeshellarg($inputPath);
    $escapedOutputPath = escapeshellarg($outputPath);

    // FFmpeg command to compress the audio file
    // Adjust the bitrate and sample rate as needed for compression
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

// Function to send audio file to the API
function send_audio_file($audioPath) {
    $api_key = get_option('openai_api_key');
    $api_endpoint = 'https://api.openai.com/v1/audio/transcriptions';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: multipart/form-data'
    ]);

    $postData = [
        'file' => new CURLFile($audioPath),
        'model' => 'whisper-1'
    ];

    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    }
    curl_close($ch);

    return json_decode($response, true);
}

// Function to handle audio transcription
function handle_audio_transcription($audioPath) {
    // Check the file size
    $fileSize = filesize($audioPath);

    // If the file size is greater than 25 MB, compress it
    if ($fileSize > 25 * 1024 * 1024) { // 25 MB in bytes
        $uploads_dir = wp_upload_dir();
        $compressed_audio_path = $uploads_dir['path'] . '/compressed_' . basename($audioPath);

        $compressionResult = compress_audio_file($audioPath, $compressed_audio_path);

        if (!$compressionResult['success']) {
            return 'Error compressing audio file: ' . esc_html($compressionResult['message']);
        }

        // Use the compressed audio file for transcription
        $audioPath = $compressed_audio_path;
    }

    $response = send_audio_file($audioPath);
    if (isset($response['text'])) {
        return $response['text'];
    } else {
        return 'Error in transcription: ' . json_encode($response);
    }
}

// Shortcode function to display the upload form and handle the transcription
function whisper_audio_transcription_shortcode($atts) {
    ob_start();

    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['audio_file']) && $_FILES['audio_file']['error'] == UPLOAD_ERR_OK) {
        $uploads_dir = wp_upload_dir();
        $uploaded_file_path = $uploads_dir['path'] . '/' . basename($_FILES['audio_file']['name']);

        if (move_uploaded_file($_FILES['audio_file']['tmp_name'], $uploaded_file_path)) {
            $transcription = handle_audio_transcription($uploaded_file_path);
            echo '<h2>Transcription:</h2>';
            echo '<p>' . esc_html($transcription) . '</p>';
        } else {
            echo '<p>There was an error uploading the file.</p>';
        }
    } elseif ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['audio_url'])) {
        $audio_url = esc_url_raw($_POST['audio_url']);
        $audio_content = file_get_contents($audio_url);

        if ($audio_content === FALSE) {
            echo '<p>There was an error downloading the audio file.</p>';
        } else {
            $uploads_dir = wp_upload_dir();
            $audio_file_path = $uploads_dir['path'] . '/temp_audio_file.mp3';

            if (file_put_contents($audio_file_path, $audio_content)) {
                $transcription = handle_audio_transcription($audio_file_path);
                echo '<h2>Transcription:</h2>';
                echo '<p>' . esc_html($transcription) . '</p>';
            } else {
                echo '<p>There was an error saving the downloaded audio file.</p>';
            }
        }
    }

    ?>
    <form method="post" enctype="multipart/form-data">
        <h2>Upload an Audio File</h2>
        <input type="file" name="audio_file" accept="audio/*">
        <input type="submit" value="Transcribe">
    </form>
    <h2>Or Enter an Audio File URL</h2>
    <form method="post">
        <input type="url" name="audio_url" placeholder="https://example.com/audiofile.mp3">
        <input type="submit" value="Transcribe">
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode('whisper_audio_transcription', 'whisper_audio_transcription_shortcode');

// Add an admin menu item for plugin settings
function whisper_audio_transcription_menu() {
    add_options_page('Whisper Audio Transcription Settings', 'Whisper Audio Transcription', 'manage_options', 'whisper-audio-transcription', 'whisper_audio_transcription_settings_page');
}
add_action('admin_menu', 'whisper_audio_transcription_menu');

// Render the settings page
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

// Register and define the settings
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

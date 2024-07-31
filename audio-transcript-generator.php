<?php
/*
Plugin Name: ChatGPT Audio Transcription Interface
Plugin URI: https://stronganchortech.com
Description: A plugin to handle audio transcription using the ChatGPT API for arbitrarily large audio files.
Version: 1.0
Author: Strong Anchor Tech
Author URI: https://stronganchortech.com
*/

// Ensure the getID3 library is loaded
require_once 'getID3/getid3.php';

// Function to send audio chunk to the API
function send_audio_chunk($audioPath) {
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

// Function to merge transcriptions
function merge_transcriptions($transcriptions, $overlapDuration) {
    $mergedTranscription = '';
    $previousEnd = '';

    foreach ($transcriptions as $index => $transcription) {
        if ($index > 0) {
            $previousEnd = substr($transcriptions[$index - 1], -$overlapDuration);
            $transcription = str_replace($previousEnd, '', $transcription);
        }
        $mergedTranscription .= $transcription;
    }

    return $mergedTranscription;
}

// Example usage with already split audio files
function handle_audio_transcription($audioChunks) {
    $transcriptions = [];

    foreach ($audioChunks as $chunk) {
        $response = send_audio_chunk($chunk);
        $transcriptions[] = $response['transcription'];
    }

    $mergedTranscription = merge_transcriptions($transcriptions, 10); // Assuming 10 characters overlap
    return $mergedTranscription;
}

// Example hook to process audio transcription (adjust this according to your needs)
function process_audio_transcription() {
    $audioChunks = ['chunk1.mp3', 'chunk2.mp3', 'chunk3.mp3']; // Paths to audio chunks
    $transcription = handle_audio_transcription($audioChunks);
    echo $transcription;
}

// Add an admin menu item for plugin settings
function chatgpt_audio_transcription_menu() {
    add_options_page('ChatGPT Audio Transcription Settings', 'ChatGPT Audio Transcription', 'manage_options', 'chatgpt-audio-transcription', 'chatgpt_audio_transcription_settings_page');
}
add_action('admin_menu', 'chatgpt_audio_transcription_menu');

// Render the settings page
function chatgpt_audio_transcription_settings_page() {
    ?>
    <div class="wrap">
        <h1>ChatGPT Audio Transcription Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('chatgpt_audio_transcription_options_group');
            do_settings_sections('chatgpt_audio_transcription');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// Register and define the settings
function chatgpt_audio_transcription_settings_init() {
    register_setting('chatgpt_audio_transcription_options_group', 'openai_api_key');

    add_settings_section('chatgpt_audio_transcription_main_section', 'Main Settings', 'chatgpt_audio_transcription_section_text', 'chatgpt_audio_transcription');

    add_settings_field('openai_api_key', 'OpenAI API Key', 'chatgpt_audio_transcription_setting_input', 'chatgpt_audio_transcription', 'chatgpt_audio_transcription_main_section');
}
add_action('admin_init', 'chatgpt_audio_transcription_settings_init');

function chatgpt_audio_transcription_section_text() {
    echo '<p>Enter your OpenAI API key here.</p>';
}

function chatgpt_audio_transcription_setting_input() {
    $api_key = get_option('openai_api_key');
    echo "<input id='openai_api_key' name='openai_api_key' type='text' value='" . esc_attr($api_key) . "' />";
}
?>

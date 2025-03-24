<?php
/*
Plugin Name: AI Audio Transcription Interface
Plugin URI: https://stronganchortech.com
Description: A plugin to handle audio transcription using the AssemblyAI API via a URL input field.
Version: 1.9.8
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
    'https://github.com/stronganchor/audio-transcript-generator',
    __FILE__,
    'audio-transcript-generator'
);
$myUpdateChecker->setBranch('main');

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

function whisper_find_audio_url($post_id) {
    $post_content = get_post_field('post_content', $post_id);
    $audio_url_pattern = '/https?:\/\/[^\s"\'<>]+?\.(mp3|wav|ogg)/i';
    if (preg_match($audio_url_pattern, $post_content, $matches)) {
        return esc_url_raw($matches[0]);
    }
    $all_meta = get_post_meta($post_id);
    foreach ($all_meta as $meta_values) {
        foreach ($meta_values as $meta_value) {
            if (is_string($meta_value) && preg_match($audio_url_pattern, $meta_value, $matches)) {
                return esc_url_raw($matches[0]);
            }
        }
    }
    return '';
}

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
                'post_title'   => $audio_file_name,
                'post_content' => $transcription_text,
                'post_status'  => 'publish',
                'post_type'    => 'transcription',
            ]);
            if ($new_post_id) {
                $current_post = get_post($post_id);
                if ($current_post) {
                    $new_content = $current_post->post_content . "\n\n" . '<h3>Audio Transcript</h3>' . "\n" . $transcription_text;
                    $updated_post = [
                        'ID'           => $post_id,
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

function enqueue_transcription_script() {
    $relative_path = 'js/assemblyai-transcription.js';
    $asset_version = filemtime(plugin_dir_path(__FILE__) . $relative_path);
    wp_enqueue_script(
        'assemblyai-transcription',
        plugin_dir_url(__FILE__) . $relative_path,
        ['jquery'],
        $asset_version,
        true
    );
    $assemblyai_api = '';
    if (current_user_can('manage_options')) {
        $assemblyai_api = get_option('assemblyai_api_key');
    }
    wp_localize_script('assemblyai-transcription', 'assemblyai_settings', [
        'ajax_url'           => admin_url('admin-ajax.php'),
        'assemblyai_api_key' => $assemblyai_api,
        'post_id'            => get_the_ID(),
    ]);
}
add_action('wp_enqueue_scripts', 'enqueue_transcription_script');
add_action('admin_enqueue_scripts', 'enqueue_transcription_script');

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

function whisper_register_transcription_post_type() {
    $args = [
        'public'   => true,
        'label'    => 'Transcriptions',
        'supports' => ['title', 'editor', 'author'],
        'rewrite'  => ['slug' => 'transcription'],
    ];
    register_post_type('transcription', $args);
}
add_action('init', 'whisper_register_transcription_post_type');

function whisper_audio_transcription_menu() {
    add_options_page('Audio Transcription Settings', 'Audio Transcription', 'manage_options', 'whisper-audio-transcription', 'whisper_audio_transcription_settings_page');
}
add_action('admin_menu', 'whisper_audio_transcription_menu');

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

function whisper_audio_transcription_settings_init() {
    register_setting('whisper_audio_transcription_options_group', 'assemblyai_api_key');
    add_settings_section('whisper_audio_transcription_main_section', 'Main Settings', 'whisper_audio_transcription_section_text', 'whisper_audio_transcription');
    add_settings_field('assemblyai_api_key', 'AssemblyAI API Key', 'whisper_audio_transcription_setting_input_assemblyai', 'whisper_audio_transcription', 'whisper_audio_transcription_main_section');
}
add_action('admin_init', 'whisper_audio_transcription_settings_init');

function whisper_audio_transcription_section_text() {
    echo '<p>Enter your API key here.</p>';
}

function whisper_audio_transcription_setting_input_assemblyai() {
    $api_key = get_option('assemblyai_api_key');
    echo "<input id='assemblyai_api_key' name='assemblyai_api_key' type='password' value='" . esc_attr($api_key) . "' />";
}
?>

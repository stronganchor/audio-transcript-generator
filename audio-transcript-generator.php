<?php
/*
Plugin Name: AI Audio Transcription Interface
Plugin URI: https://stronganchortech.com
Description: A plugin to handle audio transcription using the AssemblyAI API via a URL input field.
Version: 2.0.3
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

function whisper_get_supported_post_types() {
    return ['post', 'transcription', 'sermon', 'sermons', 'wpfc_sermon', 'podcast'];
}

function whisper_add_transcription_meta_box() {
    add_meta_box(
        'transcription_meta_box',
        'Audio Transcription',
        'whisper_render_transcription_meta_box',
        whisper_get_supported_post_types(),
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

function whisper_transcription_lock_ttl() {
    return apply_filters('whisper_transcription_lock_ttl', HOUR_IN_SECONDS);
}

function whisper_get_transcription_lock() {
    return get_transient('whisper_transcription_lock');
}

function whisper_set_transcription_lock($lock_data) {
    set_transient('whisper_transcription_lock', $lock_data, whisper_transcription_lock_ttl());
}

function whisper_clear_transcription_lock() {
    delete_transient('whisper_transcription_lock');
}

function whisper_get_legacy_transcription_word_threshold() {
    $threshold = intval(apply_filters('whisper_legacy_transcription_word_threshold', 800));
    return max(1, $threshold);
}

function whisper_detect_legacy_transcription($post_id) {
    $post = get_post($post_id);
    if (!$post || !is_string($post->post_content)) {
        return [
            'has_marker'     => false,
            'is_possible'    => false,
            'word_count'     => 0,
            'word_threshold' => whisper_get_legacy_transcription_word_threshold(),
            'notes'          => [],
        ];
    }

    $content = $post->post_content;
    $stripped_content = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($content)));
    $word_count = str_word_count($stripped_content);
    $word_threshold = whisper_get_legacy_transcription_word_threshold();

    $heading_pattern = '/<h[1-6][^>]*>\s*audio\s+transcript(?:ion)?\s*<\/h[1-6]>/i';
    $text_pattern = '/\baudio\s+transcript(?:ion)?\b/i';
    $has_heading_marker = preg_match($heading_pattern, $content) === 1;
    $has_text_marker = preg_match($text_pattern, $stripped_content) === 1;
    $has_marker = $has_heading_marker || $has_text_marker;

    $is_long_post = $word_count >= $word_threshold;
    $is_possible = $has_marker || $is_long_post;

    $notes = [];
    if ($has_marker) {
        $notes[] = 'Legacy marker found: "Audio Transcript" heading/text.';
    }
    if ($is_long_post) {
        $notes[] = 'Possible legacy transcription: post length - ' . number_format_i18n($word_count) . ' words (threshold: ' . number_format_i18n($word_threshold) . ').';
    }

    return [
        'has_marker'     => $has_marker,
        'is_possible'    => $is_possible,
        'word_count'     => $word_count,
        'word_threshold' => $word_threshold,
        'notes'          => $notes,
    ];
}

function whisper_get_transcribable_posts() {
    $query = new WP_Query([
        'post_type'      => whisper_get_supported_post_types(),
        'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'fields'         => 'ids',
    ]);

    $items = [];
    foreach ($query->posts as $post_id) {
        $audio_url = whisper_find_audio_url($post_id);
        if (!$audio_url) {
            continue;
        }
        $post_type = get_post_type($post_id);
        $post_type_obj = get_post_type_object($post_type);
        $transcription_post_id = intval(get_post_meta($post_id, '_whisper_transcription_post_id', true));
        $transcribed_at = get_post_meta($post_id, '_whisper_transcribed_at', true);
        $legacy_detection = whisper_detect_legacy_transcription($post_id);

        $items[] = [
            'post_id'               => $post_id,
            'title'                 => get_the_title($post_id),
            'post_type'             => $post_type,
            'post_type_label'       => $post_type_obj ? $post_type_obj->labels->singular_name : $post_type,
            'audio_url'             => $audio_url,
            'edit_link'             => get_edit_post_link($post_id, 'raw'),
            'view_link'             => get_permalink($post_id),
            'transcription_post_id' => $transcription_post_id,
            'transcription_link'    => $transcription_post_id ? get_permalink($transcription_post_id) : '',
            'transcribed_at'        => $transcribed_at,
            'legacy_marker_found'   => $legacy_detection['has_marker'],
            'legacy_possible'       => $legacy_detection['is_possible'],
            'legacy_word_count'     => $legacy_detection['word_count'],
            'legacy_word_threshold' => $legacy_detection['word_threshold'],
            'legacy_notes'          => $legacy_detection['notes'],
        ];
    }

    return $items;
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

function whisper_audio_transcription_admin_page_menu() {
    add_menu_page(
        'Audio Transcriptions',
        'Audio Transcriptions',
        'manage_options',
        'whisper-audio-transcriptions',
        'whisper_render_admin_transcriptions_page',
        'dashicons-media-audio',
        25
    );
}
add_action('admin_menu', 'whisper_audio_transcription_admin_page_menu');

function whisper_render_admin_transcriptions_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'whisper'));
    }

    $items = whisper_get_transcribable_posts();
    $lock = whisper_get_transcription_lock();
    $lock_message = 'No transcription is currently running.';
    if ($lock && isset($lock['post_id'])) {
        $locked_post = get_post($lock['post_id']);
        $locked_title = $locked_post ? $locked_post->post_title : 'Unknown post';
        $locked_user = '';
        if (!empty($lock['user_id'])) {
            $user = get_userdata($lock['user_id']);
            if ($user) {
                $locked_user = $user->display_name;
            }
        }
        $started_at = !empty($lock['started_at']) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $lock['started_at']) : '';
        $lock_parts = ['Transcription in progress'];
        $lock_parts[] = $locked_title;
        if ($locked_user) {
            $lock_parts[] = 'by ' . $locked_user;
        }
        if ($started_at) {
            $lock_parts[] = 'started at ' . $started_at;
        }
        $lock_message = implode(' - ', $lock_parts) . '.';
    }
    ?>
    <div class="wrap whisper-admin-page">
        <h1>Audio Transcriptions</h1>
        <p>Only one transcription can run at a time. Select a post below to start a new transcription.</p>
        <div id="whisper-global-status" class="notice notice-info">
            <p><?php echo esc_html($lock_message); ?></p>
        </div>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Type</th>
                    <th>Audio URL</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)) : ?>
                    <tr>
                        <td colspan="5">No audio URLs were found in supported post types.</td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($items as $item) : ?>
                        <?php
                        $transcribed_at_display = '';
                        if (!empty($item['transcribed_at'])) {
                            $timestamp = strtotime($item['transcribed_at']);
                            if ($timestamp) {
                                $transcribed_at_display = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
                            }
                        }
                        $legacy_notes = !empty($item['legacy_notes']) && is_array($item['legacy_notes']) ? $item['legacy_notes'] : [];
                        $status_text = 'Not transcribed';
                        $status_meta_lines = [];

                        if ($transcribed_at_display) {
                            $status_text = 'Transcribed';
                            $status_meta_lines[] = 'Last transcribed: ' . $transcribed_at_display;
                        } elseif (!empty($item['legacy_marker_found'])) {
                            $status_text = 'Likely legacy transcription';
                            $status_meta_lines = $legacy_notes;
                        } elseif (!empty($item['legacy_possible'])) {
                            $status_text = 'Possible legacy transcription';
                            $status_meta_lines = $legacy_notes;
                            if (empty($status_meta_lines)) {
                                $status_meta_lines[] = 'Possible legacy transcription: post length - ' . number_format_i18n($item['legacy_word_count']) . ' words (threshold: ' . number_format_i18n($item['legacy_word_threshold']) . ').';
                            }
                        }

                        $has_existing_transcription = $transcribed_at_display || !empty($item['legacy_possible']);
                        ?>
                        <tr data-post-id="<?php echo esc_attr($item['post_id']); ?>" data-post-link="<?php echo esc_url($item['view_link']); ?>">
                            <td>
                                <strong><a href="<?php echo esc_url($item['edit_link']); ?>"><?php echo esc_html($item['title']); ?></a></strong>
                            </td>
                            <td><?php echo esc_html($item['post_type_label']); ?></td>
                            <td><code class="whisper-audio-url"><?php echo esc_html($item['audio_url']); ?></code></td>
                            <td class="whisper-status">
                                <div class="whisper-status-text">
                                    <?php echo esc_html($status_text); ?>
                                </div>
                                <?php if (!empty($status_meta_lines)) : ?>
                                    <?php foreach ($status_meta_lines as $status_meta_line) : ?>
                                        <div class="whisper-status-meta"><?php echo esc_html($status_meta_line); ?></div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <div class="whisper-status-links">
                                    <?php if (!empty($item['view_link'])) : ?>
                                        <a class="whisper-view-post" href="<?php echo esc_url($item['view_link']); ?>" target="_blank" rel="noopener">View post</a>
                                    <?php endif; ?>
                                    <?php if (!empty($item['transcription_link'])) : ?>
                                        <a class="whisper-view-transcription" href="<?php echo esc_url($item['transcription_link']); ?>" target="_blank" rel="noopener">View transcription</a>
                                    <?php endif; ?>
                                </div>
                                <div class="whisper-progress">
                                    <div class="whisper-progress-bar" style="width: 0%;"></div>
                                </div>
                            </td>
                            <td class="whisper-admin-actions">
                                <button
                                    type="button"
                                    class="button button-primary whisper-admin-transcribe"
                                    data-post-id="<?php echo esc_attr($item['post_id']); ?>"
                                    data-audio-url="<?php echo esc_attr($item['audio_url']); ?>"
                                >
                                    <?php echo $has_existing_transcription ? 'Re-transcribe' : 'Transcribe'; ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

add_action('wp_ajax_save_transcription', 'save_transcription_callback');
add_action('wp_ajax_nopriv_save_transcription', 'save_transcription_callback');
add_action('wp_ajax_whisper_acquire_transcription_lock', 'whisper_acquire_transcription_lock');
add_action('wp_ajax_whisper_release_transcription_lock', 'whisper_release_transcription_lock');

function whisper_acquire_transcription_lock() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'You do not have permission to start a transcription.'], 403);
    }

    check_ajax_referer('whisper_transcription_lock', 'nonce');

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (!$post_id) {
        wp_send_json_error(['message' => 'Invalid post ID.'], 400);
    }

    $existing = whisper_get_transcription_lock();
    if ($existing && isset($existing['post_id'])) {
        $locked_post = get_post($existing['post_id']);
        $locked_title = $locked_post ? $locked_post->post_title : 'Unknown post';
        wp_send_json_error([
            'message' => 'Another transcription is already running.',
            'lock'    => $existing,
            'title'   => $locked_title,
        ], 409);
    }

    $lock = [
        'post_id'    => $post_id,
        'user_id'    => get_current_user_id(),
        'started_at' => time(),
    ];
    whisper_set_transcription_lock($lock);

    wp_send_json_success(['lock' => $lock]);
}

function whisper_release_transcription_lock() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'You do not have permission to release the transcription lock.'], 403);
    }

    check_ajax_referer('whisper_transcription_lock', 'nonce');

    $existing = whisper_get_transcription_lock();
    if ($existing && !empty($existing['user_id']) && intval($existing['user_id']) !== get_current_user_id()) {
        wp_send_json_error(['message' => 'Only the active user can release the transcription lock.'], 403);
    }

    whisper_clear_transcription_lock();
    wp_send_json_success(['message' => 'Lock released.']);
}

function save_transcription_callback() {
    try {
        if (isset($_POST['transcription']) && isset($_POST['audio_url']) && isset($_POST['post_id'])) {
            $transcription_text = sanitize_textarea_field($_POST['transcription']);
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
                    update_post_meta($post_id, '_whisper_transcription_post_id', $new_post_id);
                    $transcribed_at = current_time('mysql');
                    update_post_meta($post_id, '_whisper_transcribed_at', $transcribed_at);
                    update_post_meta($post_id, '_whisper_transcribed_by', get_current_user_id());
                    wp_send_json_success([
                        'new_post_id'         => $new_post_id,
                        'message'             => 'Transcription post created and appended to the original post.',
                        'post_id'             => $post_id,
                        'post_link'           => get_permalink($post_id),
                        'transcription_link'  => get_permalink($new_post_id),
                        'transcribed_at'      => $transcribed_at,
                    ]);
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
        'lock_nonce'         => current_user_can('manage_options') ? wp_create_nonce('whisper_transcription_lock') : '',
    ]);
}
add_action('wp_enqueue_scripts', 'enqueue_transcription_script');
add_action('admin_enqueue_scripts', 'enqueue_transcription_script');

function whisper_enqueue_admin_transcriptions_assets($hook) {
    if ($hook !== 'toplevel_page_whisper-audio-transcriptions') {
        return;
    }

    $script_path = 'js/assemblyai-admin.js';
    $style_path = 'css/assemblyai-admin.css';
    $script_version = filemtime(plugin_dir_path(__FILE__) . $script_path);
    $style_version = filemtime(plugin_dir_path(__FILE__) . $style_path);

    wp_enqueue_script(
        'assemblyai-admin',
        plugin_dir_url(__FILE__) . $script_path,
        ['jquery'],
        $script_version,
        true
    );
    wp_enqueue_style(
        'assemblyai-admin',
        plugin_dir_url(__FILE__) . $style_path,
        [],
        $style_version
    );

    $lock = whisper_get_transcription_lock();
    wp_localize_script('assemblyai-admin', 'assemblyai_admin', [
        'ajax_url'           => admin_url('admin-ajax.php'),
        'assemblyai_api_key' => current_user_can('manage_options') ? get_option('assemblyai_api_key') : '',
        'lock_nonce'         => wp_create_nonce('whisper_transcription_lock'),
        'current_lock'       => $lock,
    ]);
}
add_action('admin_enqueue_scripts', 'whisper_enqueue_admin_transcriptions_assets');

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

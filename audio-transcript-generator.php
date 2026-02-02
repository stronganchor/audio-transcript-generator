<?php
/*
Plugin Name: AI Audio Transcription Interface
Plugin URI: https://stronganchortech.com
Description: A plugin to handle audio transcription using the AssemblyAI API via a URL input field.
Version: 2.0.4
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

function whisper_get_background_batch_hook_name() {
    return 'whisper_background_batch_tick';
}

function whisper_get_background_batch_frequency_options() {
    return [
        'whisper_every_5_minutes'  => 'Every 5 minutes',
        'whisper_every_15_minutes' => 'Every 15 minutes',
        'whisper_every_30_minutes' => 'Every 30 minutes',
        'hourly'                   => 'Hourly',
        'twicedaily'               => 'Twice daily',
        'daily'                    => 'Daily',
    ];
}

function whisper_register_background_batch_schedules($schedules) {
    $custom_schedules = [
        'whisper_every_5_minutes' => [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => __('Every 5 minutes', 'whisper'),
        ],
        'whisper_every_15_minutes' => [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => __('Every 15 minutes', 'whisper'),
        ],
        'whisper_every_30_minutes' => [
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display'  => __('Every 30 minutes', 'whisper'),
        ],
    ];

    return array_merge($schedules, $custom_schedules);
}
add_filter('cron_schedules', 'whisper_register_background_batch_schedules');

function whisper_get_background_batch_enabled() {
    return get_option('whisper_background_batch_enabled', '0') === '1';
}

function whisper_get_background_batch_frequency() {
    $frequency = sanitize_key((string) get_option('whisper_background_batch_frequency', 'hourly'));
    $options = whisper_get_background_batch_frequency_options();
    if (!isset($options[$frequency])) {
        return 'hourly';
    }

    return $frequency;
}

function whisper_get_background_batch_state() {
    $state = get_option('whisper_background_batch_state', []);
    return is_array($state) ? $state : [];
}

function whisper_set_background_batch_state($state) {
    update_option('whisper_background_batch_state', $state, false);
}

function whisper_clear_background_batch_state() {
    delete_option('whisper_background_batch_state');
}

function whisper_get_assemblyai_api_key() {
    return trim((string) get_option('assemblyai_api_key', ''));
}

function whisper_log_background_batch_error($message) {
    error_log('[Whisper Background Batch] ' . $message);
}

function whisper_sync_background_batch_schedule() {
    $hook_name = whisper_get_background_batch_hook_name();
    $is_enabled = whisper_get_background_batch_enabled();
    $frequency = whisper_get_background_batch_frequency();
    $next_scheduled = wp_next_scheduled($hook_name);

    if (!$is_enabled) {
        if ($next_scheduled) {
            wp_clear_scheduled_hook($hook_name);
        }
        whisper_clear_background_batch_state();
        return;
    }

    if ($next_scheduled && wp_get_schedule($hook_name) !== $frequency) {
        wp_clear_scheduled_hook($hook_name);
        $next_scheduled = false;
    }

    if (!$next_scheduled) {
        wp_schedule_event(time() + MINUTE_IN_SECONDS, $frequency, $hook_name);
    }
}
add_action('init', 'whisper_sync_background_batch_schedule', 20);

function whisper_background_batch_settings_updated(...$args) {
    whisper_sync_background_batch_schedule();
}
add_action('update_option_whisper_background_batch_enabled', 'whisper_background_batch_settings_updated', 10, 3);
add_action('update_option_whisper_background_batch_frequency', 'whisper_background_batch_settings_updated', 10, 3);
add_action('add_option_whisper_background_batch_enabled', 'whisper_background_batch_settings_updated', 10, 1);
add_action('add_option_whisper_background_batch_frequency', 'whisper_background_batch_settings_updated', 10, 1);

function whisper_audio_transcription_deactivate() {
    wp_clear_scheduled_hook(whisper_get_background_batch_hook_name());
    whisper_clear_background_batch_state();
}
register_deactivation_hook(__FILE__, 'whisper_audio_transcription_deactivate');

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

function whisper_get_background_batch_candidate() {
    $page = 1;
    $posts_per_page = 25;

    do {
        $query = new WP_Query([
            'post_type'      => whisper_get_supported_post_types(),
            'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => $posts_per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'     => '_whisper_transcribed_at',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => '_whisper_transcribed_at',
                    'value'   => '',
                    'compare' => '=',
                ],
            ],
        ]);

        if (empty($query->posts)) {
            break;
        }

        foreach ($query->posts as $post_id) {
            $audio_url = whisper_find_audio_url($post_id);
            if (!$audio_url) {
                continue;
            }

            $transcription_post_id = intval(get_post_meta($post_id, '_whisper_transcription_post_id', true));
            if ($transcription_post_id > 0) {
                continue;
            }

            $failed_at = intval(get_post_meta($post_id, '_whisper_background_batch_failed_at', true));
            if ($failed_at > 0 && $failed_at > (time() - DAY_IN_SECONDS)) {
                continue;
            }

            $legacy_detection = whisper_detect_legacy_transcription($post_id);
            if (!empty($legacy_detection['is_possible'])) {
                continue;
            }

            return [
                'post_id'   => $post_id,
                'audio_url' => $audio_url,
            ];
        }

        $page++;
    } while ($page <= intval($query->max_num_pages));

    return null;
}

function whisper_assemblyai_api_request($method, $endpoint, $api_key, $body = null) {
    $request_args = [
        'method'  => strtoupper($method),
        'timeout' => 20,
        'headers' => [
            'authorization' => $api_key,
            'content-type'  => 'application/json',
        ],
    ];

    if (!is_null($body)) {
        $request_args['body'] = wp_json_encode($body);
    }

    $url = 'https://api.assemblyai.com/v2/' . ltrim($endpoint, '/');
    $response = wp_remote_request($url, $request_args);
    if (is_wp_error($response)) {
        return $response;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $raw_body = wp_remote_retrieve_body($response);
    $data = json_decode($raw_body, true);

    if ($status_code < 200 || $status_code >= 300) {
        $error_message = 'AssemblyAI request failed with status code ' . intval($status_code) . '.';
        if (is_array($data) && !empty($data['error'])) {
            $error_message = sanitize_text_field($data['error']);
        }

        return new WP_Error('whisper_assemblyai_http_error', $error_message);
    }

    if (!is_array($data)) {
        return new WP_Error('whisper_assemblyai_invalid_response', 'AssemblyAI returned an unexpected response.');
    }

    if (!empty($data['error'])) {
        return new WP_Error('whisper_assemblyai_error', sanitize_text_field($data['error']));
    }

    return $data;
}

function whisper_save_transcription_result($post_id, $audio_url, $transcription_text, $transcribed_by = 0) {
    $post_id = intval($post_id);
    if (!$post_id) {
        return new WP_Error('whisper_invalid_post', 'Invalid post ID.');
    }

    $audio_url = esc_url_raw((string) $audio_url);
    if (!$audio_url) {
        return new WP_Error('whisper_invalid_audio_url', 'Invalid audio URL.');
    }

    $transcription_text = trim((string) $transcription_text);
    if ($transcription_text === '') {
        return new WP_Error('whisper_empty_transcript', 'Transcription text is empty.');
    }

    $current_post = get_post($post_id);
    if (!$current_post) {
        return new WP_Error('whisper_missing_post', 'Original post not found.');
    }

    $audio_file_name = basename((string) parse_url($audio_url, PHP_URL_PATH));
    if (!$audio_file_name) {
        $audio_file_name = 'audio-transcript-' . $post_id;
    }

    $new_post_id = wp_insert_post([
        'post_title'   => sanitize_text_field($audio_file_name),
        'post_content' => $transcription_text,
        'post_status'  => 'publish',
        'post_type'    => 'transcription',
    ], true);

    if (is_wp_error($new_post_id)) {
        return $new_post_id;
    }

    $new_content = $current_post->post_content . "\n\n" . '<h3>Audio Transcript</h3>' . "\n" . $transcription_text;
    remove_action('wp_insert_post', 'wp_save_post_revision');
    $update_result = wp_update_post([
        'ID'           => $post_id,
        'post_content' => $new_content,
    ], true);
    add_action('wp_insert_post', 'wp_save_post_revision');

    if (is_wp_error($update_result)) {
        wp_delete_post($new_post_id, true);
        return $update_result;
    }

    $transcribed_at = current_time('mysql');
    update_post_meta($post_id, '_whisper_transcription_post_id', $new_post_id);
    update_post_meta($post_id, '_whisper_transcribed_at', $transcribed_at);
    update_post_meta($post_id, '_whisper_transcribed_by', max(0, intval($transcribed_by)));
    delete_post_meta($post_id, '_whisper_background_batch_error');
    delete_post_meta($post_id, '_whisper_background_batch_failed_at');

    return [
        'new_post_id'        => intval($new_post_id),
        'message'            => 'Transcription post created and appended to the original post.',
        'post_id'            => $post_id,
        'post_link'          => get_permalink($post_id),
        'transcription_link' => get_permalink($new_post_id),
        'transcribed_at'     => $transcribed_at,
    ];
}

function whisper_get_completed_transcription_text($transcript_id, $api_key, $fallback_text = '') {
    $paragraph_response = whisper_assemblyai_api_request('GET', '/transcript/' . rawurlencode($transcript_id) . '/paragraphs', $api_key);
    if (!is_wp_error($paragraph_response) && !empty($paragraph_response['paragraphs']) && is_array($paragraph_response['paragraphs'])) {
        $paragraphs = [];
        foreach ($paragraph_response['paragraphs'] as $paragraph) {
            $text = isset($paragraph['text']) ? trim((string) $paragraph['text']) : '';
            if ($text !== '') {
                $paragraphs[] = $text;
            }
        }

        if (!empty($paragraphs)) {
            return implode("\n\n", $paragraphs);
        }
    }

    return trim((string) $fallback_text);
}

function whisper_start_background_batch_transcription($candidate, $api_key) {
    $post_id = !empty($candidate['post_id']) ? intval($candidate['post_id']) : 0;
    $audio_url = !empty($candidate['audio_url']) ? esc_url_raw($candidate['audio_url']) : '';
    if (!$post_id || !$audio_url) {
        return;
    }

    $lock = [
        'post_id'    => $post_id,
        'user_id'    => 0,
        'started_at' => time(),
        'source'     => 'background_batch',
    ];
    whisper_set_transcription_lock($lock);

    try {
        $response = whisper_assemblyai_api_request('POST', '/transcript', $api_key, [
            'audio_url'      => $audio_url,
            'speaker_labels' => true,
            'punctuate'      => true,
            'format_text'    => true,
        ]);

        if (is_wp_error($response)) {
            whisper_log_background_batch_error('Failed to submit transcript job for post ' . $post_id . ': ' . $response->get_error_message());
            update_post_meta($post_id, '_whisper_background_batch_error', $response->get_error_message());
            update_post_meta($post_id, '_whisper_background_batch_failed_at', time());
            return;
        }

        if (empty($response['id'])) {
            whisper_log_background_batch_error('AssemblyAI did not return a transcript ID for post ' . $post_id . '.');
            update_post_meta($post_id, '_whisper_background_batch_error', 'AssemblyAI did not return a transcript ID.');
            update_post_meta($post_id, '_whisper_background_batch_failed_at', time());
            return;
        }

        whisper_set_background_batch_state([
            'post_id'       => $post_id,
            'audio_url'     => $audio_url,
            'transcript_id' => sanitize_text_field($response['id']),
            'status'        => 'submitted',
            'started_at'    => time(),
            'last_checked'  => time(),
        ]);
    } finally {
        whisper_clear_transcription_lock();
    }
}

function whisper_process_background_batch_state($state, $api_key) {
    $post_id = !empty($state['post_id']) ? intval($state['post_id']) : 0;
    $transcript_id = !empty($state['transcript_id']) ? sanitize_text_field($state['transcript_id']) : '';
    if (!$post_id || !$transcript_id) {
        whisper_clear_background_batch_state();
        return;
    }

    if (!get_post($post_id)) {
        whisper_clear_background_batch_state();
        return;
    }

    if (get_post_meta($post_id, '_whisper_transcribed_at', true)) {
        whisper_clear_background_batch_state();
        return;
    }

    $lock = [
        'post_id'    => $post_id,
        'user_id'    => 0,
        'started_at' => time(),
        'source'     => 'background_batch',
    ];
    whisper_set_transcription_lock($lock);

    try {
        $response = whisper_assemblyai_api_request('GET', '/transcript/' . rawurlencode($transcript_id), $api_key);
        if (is_wp_error($response)) {
            whisper_log_background_batch_error('Failed to check transcript status for post ' . $post_id . ': ' . $response->get_error_message());
            return;
        }

        $status = !empty($response['status']) ? strtolower((string) $response['status']) : 'processing';
        if ($status === 'completed') {
            $audio_url = !empty($state['audio_url']) ? esc_url_raw($state['audio_url']) : whisper_find_audio_url($post_id);
            $transcript_text = whisper_get_completed_transcription_text($transcript_id, $api_key, !empty($response['text']) ? (string) $response['text'] : '');
            if (!$audio_url || $transcript_text === '') {
                $error_message = 'Missing audio URL or transcript text for post ' . $post_id . '.';
                whisper_log_background_batch_error($error_message);
                update_post_meta($post_id, '_whisper_background_batch_error', $error_message);
                update_post_meta($post_id, '_whisper_background_batch_failed_at', time());
                whisper_clear_background_batch_state();
                return;
            }

            $save_result = whisper_save_transcription_result($post_id, $audio_url, $transcript_text, 0);
            if (is_wp_error($save_result)) {
                $error_message = 'Failed to save transcript for post ' . $post_id . ': ' . $save_result->get_error_message();
                whisper_log_background_batch_error($error_message);
                update_post_meta($post_id, '_whisper_background_batch_error', $error_message);
                update_post_meta($post_id, '_whisper_background_batch_failed_at', time());
                whisper_clear_background_batch_state();
                return;
            }

            whisper_clear_background_batch_state();
            return;
        }

        if ($status === 'failed') {
            $error_message = !empty($response['error']) ? sanitize_text_field($response['error']) : 'AssemblyAI marked the transcript as failed.';
            whisper_log_background_batch_error('Transcript failed for post ' . $post_id . ': ' . $error_message);
            update_post_meta($post_id, '_whisper_background_batch_error', $error_message);
            update_post_meta($post_id, '_whisper_background_batch_failed_at', time());
            whisper_clear_background_batch_state();
            return;
        }

        $state['status'] = sanitize_key($status);
        $state['last_checked'] = time();
        whisper_set_background_batch_state($state);
    } finally {
        whisper_clear_transcription_lock();
    }
}

function whisper_run_background_batch_tick($force = false) {
    if (!$force && !whisper_get_background_batch_enabled()) {
        return [
            'status' => 'disabled',
        ];
    }

    $api_key = whisper_get_assemblyai_api_key();
    if ($api_key === '') {
        return [
            'status' => 'missing_api_key',
        ];
    }

    if (whisper_get_transcription_lock()) {
        return [
            'status' => 'locked',
        ];
    }

    $state = whisper_get_background_batch_state();
    if (!empty($state['post_id']) && !empty($state['transcript_id'])) {
        $post_id = intval($state['post_id']);
        whisper_process_background_batch_state($state, $api_key);
        $updated_state = whisper_get_background_batch_state();
        if (empty($updated_state['post_id'])) {
            if (get_post_meta($post_id, '_whisper_transcribed_at', true)) {
                return [
                    'status'  => 'completed',
                    'post_id' => $post_id,
                ];
            }

            if (get_post_meta($post_id, '_whisper_background_batch_error', true)) {
                return [
                    'status'  => 'failed',
                    'post_id' => $post_id,
                ];
            }
        }

        return [
            'status'  => 'checked_in_progress',
            'post_id' => $post_id,
        ];
    }

    $candidate = whisper_get_background_batch_candidate();
    if ($candidate) {
        $post_id = intval($candidate['post_id']);
        whisper_start_background_batch_transcription($candidate, $api_key);
        $updated_state = whisper_get_background_batch_state();
        if (!empty($updated_state['post_id'])) {
            return [
                'status'  => 'started',
                'post_id' => $post_id,
            ];
        }

        return [
            'status'  => 'submit_failed',
            'post_id' => $post_id,
        ];
    }

    return [
        'status' => 'no_candidate',
    ];
}
add_action(whisper_get_background_batch_hook_name(), 'whisper_run_background_batch_tick');

function whisper_get_background_batch_run_notice($status, $post_id = 0) {
    $status = sanitize_key((string) $status);
    $post_title = $post_id ? get_the_title($post_id) : '';
    $message_title = $post_title ? '"' . $post_title . '"' : 'the selected post';

    switch ($status) {
        case 'started':
            return [
                'class'   => 'notice-success',
                'message' => 'Background batch submitted a new transcription for ' . $message_title . '.',
            ];
        case 'checked_in_progress':
            return [
                'class'   => 'notice-info',
                'message' => 'Background batch checked an in-progress transcription for ' . $message_title . '.',
            ];
        case 'completed':
            return [
                'class'   => 'notice-success',
                'message' => 'Background batch completed transcription for ' . $message_title . '.',
            ];
        case 'failed':
            return [
                'class'   => 'notice-error',
                'message' => 'Background batch hit an error while processing ' . $message_title . '. Check the post meta/logs for details.',
            ];
        case 'submit_failed':
            return [
                'class'   => 'notice-error',
                'message' => 'Background batch could not submit a new transcription for ' . $message_title . '.',
            ];
        case 'locked':
            return [
                'class'   => 'notice-warning',
                'message' => 'Another transcription is currently running. Try again after it finishes.',
            ];
        case 'missing_api_key':
            return [
                'class'   => 'notice-error',
                'message' => 'Cannot run background batch now: missing AssemblyAI API key.',
            ];
        case 'disabled':
            return [
                'class'   => 'notice-info',
                'message' => 'Automatic background transcription is currently disabled.',
            ];
        case 'no_candidate':
            return [
                'class'   => 'notice-info',
                'message' => 'No eligible posts were found for background transcription.',
            ];
        default:
            return [
                'class'   => 'notice-info',
                'message' => 'Background batch run completed.',
            ];
    }
}

function whisper_handle_run_background_batch_now() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to run the background batch.', 'whisper'));
    }

    check_admin_referer('whisper_run_background_batch_now');
    $result = whisper_run_background_batch_tick(true);
    $status = !empty($result['status']) ? sanitize_key($result['status']) : 'unknown';
    $redirect_url = add_query_arg([
        'page'                     => 'whisper-audio-transcription',
        'whisper_batch_run_status' => $status,
    ], admin_url('options-general.php'));

    if (!empty($result['post_id'])) {
        $redirect_url = add_query_arg('whisper_batch_post_id', intval($result['post_id']), $redirect_url);
    }

    wp_safe_redirect($redirect_url);
    exit;
}
add_action('admin_post_whisper_run_background_batch_now', 'whisper_handle_run_background_batch_now');

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
            $save_result = whisper_save_transcription_result($post_id, $audio_url, $transcription_text, get_current_user_id());
            if (is_wp_error($save_result)) {
                wp_send_json_error(['message' => $save_result->get_error_message()]);
            }

            wp_send_json_success($save_result);
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
    $assemblyai_api = current_user_can('manage_options') ? whisper_get_assemblyai_api_key() : '';
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
        'assemblyai_api_key' => current_user_can('manage_options') ? whisper_get_assemblyai_api_key() : '',
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
    $run_status = isset($_GET['whisper_batch_run_status']) ? sanitize_key(wp_unslash($_GET['whisper_batch_run_status'])) : '';
    $run_post_id = isset($_GET['whisper_batch_post_id']) ? intval($_GET['whisper_batch_post_id']) : 0;
    $run_notice = $run_status ? whisper_get_background_batch_run_notice($run_status, $run_post_id) : null;
    ?>
    <div class="wrap">
        <h1>Audio Transcription Settings</h1>
        <?php if ($run_notice && !empty($run_notice['message'])) : ?>
            <div class="notice <?php echo esc_attr($run_notice['class']); ?> is-dismissible">
                <p><?php echo esc_html($run_notice['message']); ?></p>
            </div>
        <?php endif; ?>
        <form method="post" action="options.php">
            <?php
            settings_fields('whisper_audio_transcription_options_group');
            do_settings_sections('whisper_audio_transcription');
            submit_button();
            ?>
        </form>

        <hr />
        <h2>Background Batch Tools</h2>
        <p>Run one immediate batch cycle without waiting for the next cron schedule.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('whisper_run_background_batch_now'); ?>
            <input type="hidden" name="action" value="whisper_run_background_batch_now" />
            <?php submit_button('Run Batch Now', 'secondary', 'whisper_run_batch_now', false); ?>
        </form>
    </div>
    <?php
}

function whisper_audio_transcription_settings_init() {
    register_setting('whisper_audio_transcription_options_group', 'assemblyai_api_key');
    register_setting('whisper_audio_transcription_options_group', 'whisper_background_batch_enabled', 'whisper_sanitize_background_batch_enabled');
    register_setting('whisper_audio_transcription_options_group', 'whisper_background_batch_frequency', 'whisper_sanitize_background_batch_frequency');

    add_settings_section('whisper_audio_transcription_main_section', 'Main Settings', 'whisper_audio_transcription_section_text', 'whisper_audio_transcription');
    add_settings_field('assemblyai_api_key', 'AssemblyAI API Key', 'whisper_audio_transcription_setting_input_assemblyai', 'whisper_audio_transcription', 'whisper_audio_transcription_main_section');
    add_settings_field('whisper_background_batch_enabled', 'Automatic Background Transcription', 'whisper_audio_transcription_setting_input_background_batch_enabled', 'whisper_audio_transcription', 'whisper_audio_transcription_main_section');
    add_settings_field('whisper_background_batch_frequency', 'Background Batch Frequency', 'whisper_audio_transcription_setting_input_background_batch_frequency', 'whisper_audio_transcription', 'whisper_audio_transcription_main_section');
}
add_action('admin_init', 'whisper_audio_transcription_settings_init');

function whisper_audio_transcription_section_text() {
    echo '<p>Enter your API key and configure optional automatic background transcription.</p>';
}

function whisper_sanitize_background_batch_enabled($value) {
    return !empty($value) ? '1' : '0';
}

function whisper_sanitize_background_batch_frequency($value) {
    $frequency = sanitize_key((string) $value);
    $options = whisper_get_background_batch_frequency_options();
    if (!isset($options[$frequency])) {
        return 'hourly';
    }

    return $frequency;
}

function whisper_audio_transcription_setting_input_assemblyai() {
    $api_key = whisper_get_assemblyai_api_key();
    echo "<input id='assemblyai_api_key' name='assemblyai_api_key' type='password' value='" . esc_attr($api_key) . "' />";
}

function whisper_audio_transcription_setting_input_background_batch_enabled() {
    $enabled = whisper_get_background_batch_enabled();
    ?>
    <label for="whisper_background_batch_enabled">
        <input name="whisper_background_batch_enabled" type="hidden" value="0" />
        <input id="whisper_background_batch_enabled" name="whisper_background_batch_enabled" type="checkbox" value="1" <?php checked($enabled); ?> />
        Automatically transcribe the most recent eligible post in the background.
    </label>
    <p class="description">Posts flagged as likely legacy transcriptions are skipped automatically.</p>
    <?php
}

function whisper_audio_transcription_setting_input_background_batch_frequency() {
    $selected_frequency = whisper_get_background_batch_frequency();
    $frequency_options = whisper_get_background_batch_frequency_options();
    $state = whisper_get_background_batch_state();
    ?>
    <select id="whisper_background_batch_frequency" name="whisper_background_batch_frequency">
        <?php foreach ($frequency_options as $frequency_value => $frequency_label) : ?>
            <option value="<?php echo esc_attr($frequency_value); ?>" <?php selected($selected_frequency, $frequency_value); ?>>
                <?php echo esc_html($frequency_label); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php

    $next_run = wp_next_scheduled(whisper_get_background_batch_hook_name());
    if ($next_run) {
        echo '<p class="description">Next scheduled run: ' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_run)) . '.</p>';
    } else {
        echo '<p class="description">No batch run is currently scheduled. Save settings to apply changes.</p>';
    }

    if (!empty($state['post_id'])) {
        $post_title = get_the_title(intval($state['post_id']));
        $status = !empty($state['status']) ? sanitize_text_field($state['status']) : 'processing';
        if ($post_title) {
            echo '<p class="description">Current background job: ' . esc_html($post_title) . ' (' . esc_html($status) . ').</p>';
        }
    }
}
?>

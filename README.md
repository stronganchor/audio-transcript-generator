# AI Audio Transcription Interface

A WordPress plugin that submits an audio file URL to AssemblyAI for transcription, polls for completion in the browser, then saves the transcript into WordPress.

## Features
- URL-based transcription from the post editor or a shortcode.
- Prefills the audio URL by scanning post content and post meta for mp3/wav/ogg links.
- Creates a "Transcriptions" custom post type for saved transcripts.
- Appends the transcript to the original post content.
- Optional background batch mode that auto-transcribes one eligible post per cron run.
- Speaker labels enabled by default.
- GitHub-based update checking via the bundled plugin update checker.

## Requirements
- A WordPress site with this plugin installed and activated.
- An AssemblyAI account and API key.
- An audio file accessible by URL (mp3, wav, or ogg).

## Installation
1. Copy this plugin folder into `wp-content/plugins/audio-transcript-generator`.
2. Activate the plugin in WordPress: Plugins -> Installed Plugins -> Activate.

## Configuration
1. In WordPress admin, go to Settings -> Audio Transcription.
2. Enter your AssemblyAI API key and save.
3. (Optional) Enable "Automatic Background Transcription" and choose a frequency.
4. (Optional) Use "Run Batch Now" to execute one background cycle immediately.
5. Use "Cancel Background Batch" if a background run appears stalled.
6. The API key is stored in the WordPress options table as `assemblyai_api_key`.

Background batch notes:
- It processes the most recent eligible post that has an audio URL and is not already transcribed.
- It skips posts detected as likely legacy transcriptions.
- It starts one new AssemblyAI job, then performs frequent follow-up checks while that job is in progress.
- Manual kickoffs are disabled while another transcription process is active.

Note: The API key is only injected into pages for users with the `manage_options` capability. Non-admin users will not be able to start transcriptions from the UI without code changes.

## Usage

### Post editor meta box
A meta box titled "Audio Transcription" appears on the following post types by default:
- `post`
- `transcription`
- `sermon`
- `sermons`
- `wpfc_sermon`
- `podcast`

Steps:
1. Open a supported post type in the editor.
2. Paste an audio URL in the meta box (or use a prefilled URL).
3. Click "Transcribe".
4. Keep the page open while the status updates.

### Shortcode
Add the shortcode below to a post or page:

```
[whisper_audio_transcription]
```

This renders the same URL input and "Transcribe" button on the front end. The API key is only available to users with `manage_options`.

## How it works (data flow)
1. The browser submits the audio URL to AssemblyAI:
   - `POST https://api.assemblyai.com/v2/transcript`
2. The browser polls the transcript status every 5 seconds:
   - `GET https://api.assemblyai.com/v2/transcript/{id}`
3. When complete, the browser sends the transcript to WordPress:
   - `POST admin-ajax.php?action=save_transcription`
4. WordPress creates a new `transcription` post and appends the text to the original post content.

Optional background mode:
1. WordPress cron selects the most recent eligible post.
2. It submits the audio URL to AssemblyAI and stores the transcript ID.
3. On later cron runs, it checks status and saves the transcript when complete.

## Data storage
- Transcripts are saved as plain text (sanitized) in WordPress post content.
- A new `transcription` post is created with the audio file name as the title.
- The transcript is appended to the original post body with an "Audio Transcript" heading.
- Background mode state is tracked in `whisper_background_batch_state`.

## APIs and services used
- AssemblyAI Transcript API (client-side requests from the browser).
- WordPress AJAX (`admin-ajax.php`) for saving transcripts.
- GitHub update checks via the bundled plugin update checker.

## Security considerations
- The AssemblyAI API key is exposed to the browser for admin users. Only use this in trusted admin contexts.
- The `save_transcription` AJAX endpoint is registered for unauthenticated requests (`wp_ajax_nopriv_save_transcription`) and does not include nonce or capability checks. If you need stricter controls, add a nonce and capability verification in `save_transcription_callback`.

## Customization
- Change supported post types in `whisper_add_transcription_meta_box` in `audio-transcript-generator.php`.
- Change polling interval in `js/assemblyai-transcription.js` (currently 5 seconds).
- Disable speaker labels by removing `speaker_labels: true` in `js/assemblyai-transcription.js`.

## Troubleshooting
- "Transcription request failed": check your AssemblyAI API key and account status.
- No transcript appears: confirm your audio URL is reachable and ends with `.mp3`, `.wav`, or `.ogg`.
- Shortcode works only for admins by default: the API key is not injected for non-admin users.

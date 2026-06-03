# AI Audio Transcription Interface

A WordPress plugin that submits an audio file URL to AssemblyAI through WordPress, polls for completion through authenticated AJAX, then saves the transcript into WordPress.

## Features
- URL-based transcription from the post editor or a shortcode.
- Prefills the audio URL by scanning post content and post meta for mp3/wav/ogg links.
- Creates a "Transcriptions" custom post type for saved transcripts.
- Appends the transcript to the original post content.
- Saves AssemblyAI paragraph timings and highlights the active transcript paragraph during audio playback, adapting the highlight color to the page text color.
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
- Opening the plugin's transcription admin pages checks due in-progress jobs, so a missed WP-Cron follow-up can self-heal while an admin is monitoring it.
- "Run Batch Now" starts a new batch when idle and checks the current in-progress batch when one already exists.
- Manual kickoffs and status checks are disabled while another transcription process is actively locked.

Note: Transcription requests require the `manage_options` capability. The AssemblyAI API key stays on the WordPress server and is not injected into browser JavaScript.

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

This renders the same URL input and "Transcribe" button on the front end. Starting a transcription still requires a logged-in user with `manage_options`.

## How it works (data flow)
1. The browser sends the audio URL to an authenticated WordPress AJAX endpoint:
   - `POST admin-ajax.php?action=whisper_start_assemblyai_transcript`
2. WordPress submits the request to AssemblyAI and returns a transcript ID.
3. The browser polls an authenticated WordPress AJAX endpoint every 5 seconds:
   - `POST admin-ajax.php?action=whisper_get_assemblyai_transcript`
4. When complete, WordPress fetches paragraph formatting and timings from AssemblyAI.
5. The browser sends the transcript and paragraph timing data to WordPress:
   - `POST admin-ajax.php?action=save_transcription`
6. WordPress creates a new `transcription` post and appends the text to the original post content.

Optional background mode:
1. WordPress cron selects the most recent eligible post.
2. It submits the audio URL to AssemblyAI and stores the transcript ID.
3. On later cron runs, it checks status, fetches paragraph timings, and saves the transcript when complete.
4. If a cron follow-up is missed, loading the plugin's admin pages checks due in-progress jobs as a fallback.

## Data storage
- Transcripts with paragraph timings are saved as escaped HTML spans with `data-whisper-start` and `data-whisper-end` attributes.
- If paragraph timings are unavailable, transcripts fall back to plain sanitized text.
- A new `transcription` post is created with the audio file name as the title.
- The transcript is appended to the original post body with an "Audio Transcript" heading.
- Timing metadata is also stored in `_whisper_transcript_segments`.
- Background mode state is tracked in `whisper_background_batch_state`.

## APIs and services used
- AssemblyAI Transcript API (server-side requests from WordPress).
- WordPress AJAX (`admin-ajax.php`) for starting, polling, and saving transcripts.
- GitHub update checks via the bundled plugin update checker.

## Security considerations
- The AssemblyAI API key is stored in WordPress options and used only server-side.
- The transcription and save AJAX endpoints require the `manage_options` capability and WordPress nonces.

## Customization
- Change supported post types in `whisper_add_transcription_meta_box` in `audio-transcript-generator.php`.
- Change polling interval in `js/assemblyai-transcription.js` (currently 5 seconds).
- Disable speaker labels by changing the `speaker_labels` request option in `audio-transcript-generator.php`.

## Troubleshooting
- "Transcription request failed": check your AssemblyAI API key and account status.
- No transcript appears: confirm your audio URL is reachable and ends with `.mp3`, `.wav`, or `.ogg`.
- Shortcode works only for admins by default: the transcription AJAX endpoints require `manage_options`.

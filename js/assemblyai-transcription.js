document.addEventListener('DOMContentLoaded', function() {
    // Check if we are in the admin panel (post editor) or the frontend
    const transcribeButton = document.querySelector('#whisper_transcribe_button') || document.querySelector('#transcribeButton');
    const statusDiv = document.querySelector('#whisper_transcription_status') || document.querySelector('#transcriptionForm');

    if (transcribeButton) {
        transcribeButton.addEventListener('click', async function() {
            const audioUrl = document.querySelector('#whisper_transcription_url')?.value || document.querySelector('#audio_url')?.value;
            const apiKey = assemblyai_settings.assemblyai_api_key;
            const postId = assemblyai_settings.post_id || null;

            if (!audioUrl) {
                alert('Please enter a valid URL.');
                return;
            }

            statusDiv.innerHTML = 'Starting transcription...';

            try {
                const params = {
                    audio_url: audioUrl,
                    speaker_labels: true,
                };

                // Send request to AssemblyAI
                const response = await fetch('https://api.assemblyai.com/v2/transcript', {
                    method: 'POST',
                    headers: {
                        'authorization': apiKey,
                        'content-type': 'application/json',
                    },
                    body: JSON.stringify(params),
                });

                const data = await response.json();
                if (data.error) {
                    statusDiv.innerHTML = `Transcription request failed: ${data.error}`;
                    return;
                }

                console.log('Transcription initiated, polling for completion:', data);
                statusDiv.innerHTML = 'Transcription started...';

                // Poll for transcription completion
                const transcriptId = data.id;
                await pollTranscriptionStatus(apiKey, transcriptId, postId, statusDiv);

            } catch (error) {
                console.error('Error during transcription request:', error);
                statusDiv.innerHTML = 'An error occurred during transcription.';
            }
        });
    }

    // Polling function
    async function pollTranscriptionStatus(apiKey, transcriptId, postId, statusDiv) {
        let transcriptionCompleted = false;

        while (!transcriptionCompleted) {
            const pollResponse = await fetch(`https://api.assemblyai.com/v2/transcript/${transcriptId}`, {
                method: 'GET',
                headers: {
                    'authorization': apiKey,
                    'content-type': 'application/json',
                },
            });

            const pollData = await pollResponse.json();

            if (pollData.status === 'completed') {
                transcriptionCompleted = true;
                console.log('Transcription completed:', pollData.text);
                statusDiv.innerHTML = 'Transcription completed. Appending to post content...';
                
                // Append the transcription to the post content if we are in admin
                if (postId) {
                    await appendTranscriptionToPost(pollData.text, postId);
                    statusDiv.innerHTML = 'Transcription appended to post content.';
                }
            } else if (pollData.status === 'failed') {
                console.error(`Transcription failed: ${pollData.error}`);
                statusDiv.innerHTML = `Transcription failed: ${pollData.error}`;
                transcriptionCompleted = true;
            } else {
                console.log(`Transcription status: ${pollData.status}. Polling again in 5 seconds...`);
                statusDiv.innerHTML = `Transcription status: ${pollData.status}. Polling again...`;
                await new Promise(resolve => setTimeout(resolve, 5000)); // Wait 5 seconds before polling again
            }
        }
    }

    // Function to append transcription to the post content
    async function appendTranscriptionToPost(transcriptionText, postId) {
        try {
            const response = await fetch(assemblyai_settings.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'append_transcription_to_post',
                    transcription: transcriptionText,
                    post_id: postId
                }),
            });

            const result = await response.json();
            if (result.success) {
                console.log('Transcription appended to post successfully:', result);
            } else {
                console.error('Failed to append transcription to post:', result);
            }
        } catch (error) {
            console.error('Error appending transcription to post:', error);
        }
    }
});

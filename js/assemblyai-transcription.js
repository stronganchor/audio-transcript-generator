document.addEventListener('DOMContentLoaded', function() {
    const transcriptionButton = document.querySelector('#transcribeButton');
    const statusDiv = document.createElement('div');
    const transcriptionContainer = document.createElement('div');
    
    document.body.appendChild(statusDiv);
    document.body.appendChild(transcriptionContainer);

    if (transcriptionButton) {
        transcriptionButton.addEventListener('click', async function() {
            statusDiv.innerHTML = 'Starting transcription...';
            transcriptionContainer.innerHTML = ''; 

            const audioUrl = document.querySelector('#audio_url').value;
            const apiKey = assemblyai_settings.assemblyai_api_key;

            if (!audioUrl) {
                alert('Please enter a valid URL.');
                return;
            }

            try {
                const params = {
                    audio_url: audioUrl,
                    speaker_labels: true,
                };

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

                const transcriptId = data.id;
                pollTranscriptionStatus(apiKey, transcriptId, audioUrl);

            } catch (error) {
                console.error('Error during transcription request:', error);
                statusDiv.innerHTML = 'An error occurred during transcription.';
            }
        });
    }

    async function pollTranscriptionStatus(apiKey, transcriptId, audioUrl) {
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
                statusDiv.innerHTML = 'Transcription completed!';
                transcriptionContainer.innerHTML = `<h2>Transcription Result:</h2><p>${pollData.text}</p>`;
                saveTranscriptionToWordPress(pollData.text, audioUrl);

            } else if (pollData.status === 'failed') {
                statusDiv.innerHTML = `Transcription failed: ${pollData.error}`;
                transcriptionCompleted = true;
            } else {
                statusDiv.innerHTML = `Transcription in progress: ${pollData.status}. Polling again...`;
                await new Promise(resolve => setTimeout(resolve, 5000));
            }
        }
    }
    
    // Function to save transcription to WordPress and append to current post content
    async function saveTranscriptionToWordPress(transcriptionText, audioUrl) {
        const postId = assemblyai_settings.post_id; // Get the current post ID
    
        const response = await fetch(assemblyai_settings.ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'save_transcription',
                transcription: transcriptionText,
                audio_url: audioUrl,
                post_id: postId // Pass the post ID for appending
            }),
        });
    
        const result = await response.json();
        if (result.success) {
            console.log('Transcription saved and appended to post:', result);
        } else {
            console.error('Failed to save transcription:', result);
        }
    }
});

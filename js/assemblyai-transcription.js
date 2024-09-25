document.addEventListener('DOMContentLoaded', function() { 
    const transcriptionButton = document.querySelector('#transcribeButton');
    const statusDiv = document.createElement('div');
    const transcriptionContainer = document.createElement('div');
    
    // Add a status div to the page to show progress
    document.body.appendChild(statusDiv);
    document.body.appendChild(transcriptionContainer);

    if (transcriptionButton) {
        transcriptionButton.addEventListener('click', async function() {
            // Reset the status div
            statusDiv.innerHTML = 'Starting transcription...';
            transcriptionContainer.innerHTML = ''; // Clear previous transcription

            const audioUrl = document.querySelector('#audio_url').value; // URL from user input
            const apiKey = assemblyai_settings.assemblyai_api_key;
            const postId = assemblyai_settings.post_id; // Get the current post ID

            if (!audioUrl) {
                alert('Please enter a valid URL.');
                return;
            }

            // Disable input and button
            transcriptionButton.disabled = true;
            document.querySelector('#audio_url').disabled = true;

            // Inform the user the transcription is in progress and they can navigate away
            statusDiv.innerHTML = 'The audio file has been submitted for transcription. This may take a few minutes. You can navigate away and come back to this page to check for updates, or wait here.';

            try {
                const params = {
                    audio_url: audioUrl,
                    speaker_labels: true,
                };

                // Send request to start transcription
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
                    transcriptionButton.disabled = false;  // Enable the button again
                    document.querySelector('#audio_url').disabled = false;  // Enable input
                    return;
                }

                console.log('Transcription initiated, polling for completion:', data);

                // Poll for status until transcription is complete
                const transcriptId = data.id;
                pollTranscriptionStatus(apiKey, transcriptId, audioUrl, postId);

            } catch (error) {
                console.error('Error during transcription request:', error);
                statusDiv.innerHTML = 'An error occurred during transcription.';
                transcriptionButton.disabled = false;  // Enable the button again
                document.querySelector('#audio_url').disabled = false;  // Enable input
            }
        });
    }

    // Polling function for transcription status
    async function pollTranscriptionStatus(apiKey, transcriptId, audioUrl, postId) {
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
                console.log('Transcription completed:', pollData.text);
                // Display the transcription on the page
                transcriptionContainer.innerHTML = `<h2>Transcription Result:</h2><p>${pollData.text}</p>`;
                // Save the transcription to WordPress and append it to the current post
                saveTranscriptionToWordPress(pollData.text, audioUrl, postId);
    
            } else if (pollData.status === 'failed') {
                console.error(`Transcription failed: ${pollData.error}`);
                statusDiv.innerHTML = `Transcription failed: ${pollData.error}`;
                transcriptionCompleted = true;
            } else {
                console.log(`Transcription status: ${pollData.status}. Polling again in 5 seconds...`);
                statusDiv.innerHTML = `Transcription in progress: ${pollData.status}. Polling again...`;
                await new Promise(resolve => setTimeout(resolve, 5000)); // Wait 5 seconds before polling again
            }
        }
    }

    // Function to save transcription to WordPress and append to current post content
    async function saveTranscriptionToWordPress(transcriptionText, audioUrl, postId) {
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

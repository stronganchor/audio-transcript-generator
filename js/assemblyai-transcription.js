document.addEventListener('DOMContentLoaded', function() {
    const transcriptionButton = document.querySelector('#transcribeButton');
    
    if (transcriptionButton) {
        transcriptionButton.addEventListener('click', async function() {
            const audioUrl = 'https://storage.googleapis.com/aai-web-samples/5_common_sports_injuries.mp3'; // Replace with your actual audio file URL
            const apiKey = assemblyai_settings.assemblyai_api_key;

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

                if (data.status === 'error') {
                    console.error(`Transcription failed: ${data.error} Raw response: ${data}`);
                    return;
                }

                console.log('Transcription completed:', data);
                data.utterances.forEach(utterance => {
                    console.log(`Speaker ${utterance.speaker}: ${utterance.text}`);
                });

                // Send the transcription data back to WordPress to save as a post
                saveTranscriptionToWordPress(data.text);

            } catch (error) {
                console.error('Error during transcription:', error);
            }
        });
    }

    async function saveTranscriptionToWordPress(transcriptionText) {
        const response = await fetch(assemblyai_settings.ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'save_transcription',
                transcription: transcriptionText
            }),
        });

        const result = await response.json();
        if (result.success) {
            console.log('Transcription saved to WordPress post:', result);
        } else {
            console.error('Failed to save transcription:', result);
        }
    }
});

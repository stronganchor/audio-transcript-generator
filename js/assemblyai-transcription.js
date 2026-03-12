document.addEventListener('DOMContentLoaded', function() { 
    const transcriptionButton = document.querySelector('#transcribeButton');
    const statusDiv = document.querySelector('#transcriptionStatus');
    const transcriptionContainer = document.querySelector('#transcriptionResult');
    
    if (transcriptionButton) {
        transcriptionButton.addEventListener('click', async function() {
            statusDiv.style.display = 'block';
            statusDiv.innerHTML = 'Starting transcription...';
            transcriptionContainer.innerHTML = '';

            const audioUrl = document.querySelector('#audio_url').value;
            const assemblyApiKey = assemblyai_settings.assemblyai_api_key;
            const postId = assemblyai_settings.post_id;

            if (!audioUrl) {
                alert('Please enter a valid URL.');
                statusDiv.style.display = 'none';
                return;
            }

            transcriptionButton.disabled = true;
            document.querySelector('#audio_url').disabled = true;

            let lockAcquired = false;
            try {
                lockAcquired = await acquireLock(postId);
                if (!lockAcquired) {
                    transcriptionButton.disabled = false;
                    document.querySelector('#audio_url').disabled = false;
                    statusDiv.innerHTML = 'Another transcription is already running.';
                    return;
                }

                const params = {
                    audio_url: audioUrl,
                    speaker_labels: true,
                    punctuate: true,
                    format_text: true,
                };

                const response = await fetch('https://api.assemblyai.com/v2/transcript', {
                    method: 'POST',
                    headers: {
                        'authorization': assemblyApiKey,
                        'content-type': 'application/json',
                    },
                    body: JSON.stringify(params),
                });

                const data = await response.json();

                if (data.error) {
                    transcriptionButton.disabled = false;
                    document.querySelector('#audio_url').disabled = false;
                    statusDiv.innerHTML = `Transcription request failed: ${data.error}`;
                    return;
                }

                console.log('Transcription initiated, polling for completion:', data);
                statusDiv.innerHTML = `Transcription process initiated. This may take a few minutes. Please keep this window open.`;

                const transcriptId = data.id;
                await pollTranscriptionStatus(assemblyApiKey, transcriptId, audioUrl, postId);

            } catch (error) {
                console.error('Error during transcription request:', error);
                transcriptionButton.disabled = false;
                document.querySelector('#audio_url').disabled = false;
                statusDiv.innerHTML = `An error occurred during transcription: ${error}`;
            } finally {
                if (lockAcquired) {
                    await releaseLock();
                }
            }
        });
    }

    async function pollTranscriptionStatus(apiKey, transcriptId, audioUrl, postId) {
        let transcriptionCompleted = false;
    
        while (!transcriptionCompleted) {
            try {
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
                    statusDiv.innerHTML += `<br>Transcription completed. Formatting paragraphs...`;
                    console.log('Transcription completed:', pollData.text);
                    let formattedText = null;
                    try {
                        formattedText = await fetchParagraphs(apiKey, transcriptId);
                    } catch (error) {
                        console.error('Error fetching formatted paragraphs:', error);
                        statusDiv.innerHTML += `<br>Could not format paragraphs; saving raw transcript.`;
                    }
                    const textToSave = formattedText || pollData.text || '';
                    await saveTranscription(textToSave, audioUrl, postId);
                } else if (pollData.status === 'failed') {
                    console.error(`Transcription failed: ${pollData.error}`);
                    statusDiv.innerHTML = `Transcription failed: ${pollData.error}`;
                    transcriptionCompleted = true;
                    transcriptionButton.disabled = false;
                    document.querySelector('#audio_url').disabled = false;
                } else {
                    console.log(`Transcription status: ${pollData.status}. Checking again in 5 seconds...`);
                    statusDiv.innerHTML = `${statusDiv.innerHTML}<br>Status: ${pollData.status}. Checking again...`;
                    await new Promise(resolve => setTimeout(resolve, 5000));
                }
            } catch (error) {
                console.error('Error while polling transcription status:', error);
                statusDiv.innerHTML = `Error while polling transcription status: ${error}`;
                transcriptionCompleted = true;
                transcriptionButton.disabled = false;
                document.querySelector('#audio_url').disabled = false;
            }
        }
    }

    async function saveTranscription(transcriptionText, audioUrl, postId) {
        try {
            const response = await fetch(assemblyai_settings.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'save_transcription',
                    transcription: transcriptionText,
                    audio_url: audioUrl,
                    post_id: postId,
                    nonce: assemblyai_settings.save_nonce || ''
                }),
            });
        
            const result = await response.json();
            if (result.success) {
                console.log('Transcription saved and appended to post:', result);
                statusDiv.innerHTML += `<br>Transcription saved successfully! Refreshing the page...`;
                setTimeout(() => {
                    location.reload();
                }, 3000);
            } else {
                console.error('Failed to save transcription:', result);
                statusDiv.innerHTML += `<br>Failed to save transcription: ${result.data || 'Unknown error'}`;
                transcriptionButton.disabled = false;
                document.querySelector('#audio_url').disabled = false;
            }
        } catch (error) {
            console.error('Error while saving transcription to WordPress:', error);
            statusDiv.innerHTML += `<br>Error while saving transcription: ${error}`;
            transcriptionButton.disabled = false;
            document.querySelector('#audio_url').disabled = false;
        }  
    }

    async function fetchParagraphs(apiKey, transcriptId) {
        const response = await fetch(`https://api.assemblyai.com/v2/transcript/${transcriptId}/paragraphs`, {
            method: 'GET',
            headers: {
                'authorization': apiKey,
                'content-type': 'application/json',
            },
        });
        const data = await response.json();
        if (data.error) {
            throw new Error(data.error);
        }
        if (!data.paragraphs || !Array.isArray(data.paragraphs)) {
            return null;
        }
        const paragraphs = data.paragraphs
            .map(p => (p && p.text ? p.text.trim() : ''))
            .filter(Boolean);
        if (!paragraphs.length) {
            return null;
        }
        return paragraphs.join('\n\n');
    }

    async function acquireLock(postId) {
        if (!assemblyai_settings.lock_nonce) {
            return true;
        }
        const response = await fetch(assemblyai_settings.ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'whisper_acquire_transcription_lock',
                post_id: postId,
                nonce: assemblyai_settings.lock_nonce,
            }),
        });

        const result = await response.json();
        return !!result.success;
    }

    async function releaseLock() {
        if (!assemblyai_settings.lock_nonce) {
            return;
        }
        try {
            await fetch(assemblyai_settings.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'whisper_release_transcription_lock',
                    nonce: assemblyai_settings.lock_nonce,
                }),
            });
        } catch (error) {
            console.error('Failed to release transcription lock:', error);
        }
    }
});

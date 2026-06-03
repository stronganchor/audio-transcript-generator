document.addEventListener('DOMContentLoaded', function() {
    const transcriptionButton = document.querySelector('#transcribeButton');
    const statusDiv = document.querySelector('#transcriptionStatus');
    const transcriptionContainer = document.querySelector('#transcriptionResult');

    if (transcriptionButton) {
        transcriptionButton.addEventListener('click', async function() {
            statusDiv.style.display = 'block';
            setStatus('Starting transcription...');
            if (transcriptionContainer) {
                transcriptionContainer.textContent = '';
            }

            const audioUrl = document.querySelector('#audio_url').value;
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
                    setStatus('Another transcription is already running.');
                    return;
                }

                const transcriptId = await createTranscript(audioUrl);
                setStatus('Transcription process initiated. This may take a few minutes. Please keep this window open.');
                await pollTranscriptionStatus(transcriptId, audioUrl, postId);
            } catch (error) {
                console.error('Error during transcription request:', error);
                transcriptionButton.disabled = false;
                document.querySelector('#audio_url').disabled = false;
                setStatus(`An error occurred during transcription: ${errorMessage(error)}`);
            } finally {
                if (lockAcquired) {
                    await releaseLock();
                }
            }
        });
    }

    async function createTranscript(audioUrl) {
        const response = await fetch(assemblyai_settings.ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'whisper_start_assemblyai_transcript',
                audio_url: audioUrl,
                nonce: assemblyai_settings.api_nonce || '',
            }),
        });

        const result = await response.json();
        if (!result.success) {
            throw new Error(wpErrorMessage(result, 'Transcription request failed.'));
        }
        if (!result.data || !result.data.transcript_id) {
            throw new Error('No transcript ID returned.');
        }

        return result.data.transcript_id;
    }

    async function pollTranscriptionStatus(transcriptId, audioUrl, postId) {
        let transcriptionCompleted = false;

        while (!transcriptionCompleted) {
            try {
                const result = await fetchTranscriptStatus(transcriptId);
                const pollData = result.data || {};

                if (pollData.status === 'completed') {
                    transcriptionCompleted = true;
                    appendStatus('Transcription completed. Formatting paragraphs...');
                    const textToSave = pollData.text || '';
                    await saveTranscription(textToSave, audioUrl, postId, pollData.segments || []);
                } else if (pollData.status === 'failed') {
                    console.error(`Transcription failed: ${pollData.error || 'Unknown error'}`);
                    setStatus(`Transcription failed: ${pollData.error || 'Unknown error'}`);
                    transcriptionCompleted = true;
                    transcriptionButton.disabled = false;
                    document.querySelector('#audio_url').disabled = false;
                } else {
                    console.log(`Transcription status: ${pollData.status}. Checking again in 5 seconds...`);
                    appendStatus(`Status: ${pollData.status || 'processing'}. Checking again...`);
                    await new Promise(resolve => setTimeout(resolve, 5000));
                }
            } catch (error) {
                console.error('Error while polling transcription status:', error);
                setStatus(`Error while polling transcription status: ${errorMessage(error)}`);
                transcriptionCompleted = true;
                transcriptionButton.disabled = false;
                document.querySelector('#audio_url').disabled = false;
            }
        }
    }

    async function fetchTranscriptStatus(transcriptId) {
        const response = await fetch(assemblyai_settings.ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'whisper_get_assemblyai_transcript',
                transcript_id: transcriptId,
                nonce: assemblyai_settings.api_nonce || '',
            }),
        });

        const result = await response.json();
        if (!result.success) {
            throw new Error(wpErrorMessage(result, 'Unable to check transcription status.'));
        }

        return result;
    }

    async function saveTranscription(transcriptionText, audioUrl, postId, transcriptSegments) {
        try {
            const response = await fetch(assemblyai_settings.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'save_transcription',
                    transcription: transcriptionText,
                    transcription_segments: JSON.stringify(transcriptSegments || []),
                    audio_url: audioUrl,
                    post_id: postId,
                    nonce: assemblyai_settings.save_nonce || ''
                }),
            });

            const result = await response.json();
            if (result.success) {
                console.log('Transcription saved and appended to post:', result);
                appendStatus('Transcription saved successfully! Refreshing the page...');
                setTimeout(() => {
                    location.reload();
                }, 3000);
            } else {
                console.error('Failed to save transcription:', result);
                appendStatus(`Failed to save transcription: ${wpErrorMessage(result, 'Unknown error')}`);
                transcriptionButton.disabled = false;
                document.querySelector('#audio_url').disabled = false;
            }
        } catch (error) {
            console.error('Error while saving transcription to WordPress:', error);
            appendStatus(`Error while saving transcription: ${errorMessage(error)}`);
            transcriptionButton.disabled = false;
            document.querySelector('#audio_url').disabled = false;
        }
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

    function setStatus(message) {
        if (statusDiv) {
            statusDiv.textContent = message;
        }
    }

    function appendStatus(message) {
        if (!statusDiv) {
            return;
        }
        statusDiv.appendChild(document.createElement('br'));
        statusDiv.appendChild(document.createTextNode(message));
    }

    function errorMessage(error) {
        return error && error.message ? error.message : String(error || 'Unknown error');
    }

    function wpErrorMessage(result, fallback) {
        if (result && result.data && result.data.message) {
            return result.data.message;
        }
        if (result && typeof result.data === 'string') {
            return result.data;
        }
        return fallback;
    }
});

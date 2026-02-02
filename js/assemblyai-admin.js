document.addEventListener('DOMContentLoaded', function() {
    if (!window.assemblyai_admin) {
        return;
    }

    const buttons = document.querySelectorAll('.whisper-admin-transcribe');
    const globalStatus = document.querySelector('#whisper-global-status');
    let active = false;

    if (!buttons.length) {
        return;
    }

    const currentLock = assemblyai_admin.current_lock || null;
    if (currentLock && currentLock.post_id) {
        setGlobalStatus('Another transcription is already running.');
        setButtonsEnabled(false);
    }

    buttons.forEach(button => {
        button.addEventListener('click', () => startTranscription(button));
    });

    function setGlobalStatus(message) {
        if (!globalStatus) {
            return;
        }
        const paragraph = globalStatus.querySelector('p');
        if (paragraph) {
            paragraph.textContent = message;
        }
    }

    function setButtonsEnabled(enabled) {
        buttons.forEach(button => {
            button.disabled = !enabled;
        });
    }

    function setRowActive(row, isActive) {
        if (!row) {
            return;
        }
        if (isActive) {
            row.classList.add('whisper-row-active');
        } else {
            row.classList.remove('whisper-row-active');
        }
    }

    function setRowStatus(row, message) {
        const statusText = row.querySelector('.whisper-status-text');
        if (statusText) {
            statusText.textContent = message;
        }
    }

    function setRowProgress(row, percent) {
        const bar = row.querySelector('.whisper-progress-bar');
        if (bar) {
            bar.style.width = `${percent}%`;
        }
    }

    function updateLinks(row, postLink, transcriptionLink) {
        const viewPostLink = row.querySelector('.whisper-view-post');
        if (viewPostLink && postLink) {
            viewPostLink.href = postLink;
            viewPostLink.style.display = 'inline';
        } else if (!viewPostLink && postLink) {
            const linksContainer = row.querySelector('.whisper-status-links');
            if (linksContainer) {
                const link = document.createElement('a');
                link.className = 'whisper-view-post';
                link.href = postLink;
                link.target = '_blank';
                link.rel = 'noopener';
                link.textContent = 'View post';
                linksContainer.appendChild(link);
            }
        }

        if (transcriptionLink) {
            let viewTranscriptLink = row.querySelector('.whisper-view-transcription');
            if (!viewTranscriptLink) {
                const linksContainer = row.querySelector('.whisper-status-links');
                if (linksContainer) {
                    viewTranscriptLink = document.createElement('a');
                    viewTranscriptLink.className = 'whisper-view-transcription';
                    viewTranscriptLink.target = '_blank';
                    viewTranscriptLink.rel = 'noopener';
                    viewTranscriptLink.textContent = 'View transcription';
                    linksContainer.appendChild(viewTranscriptLink);
                }
            }
            if (viewTranscriptLink) {
                viewTranscriptLink.href = transcriptionLink;
            }
        }
    }

    async function startTranscription(button) {
        if (active) {
            return;
        }

        const row = button.closest('tr');
        if (!row) {
            return;
        }

        const audioUrl = button.dataset.audioUrl;
        const postId = button.dataset.postId;
        if (!audioUrl || !postId) {
            setRowStatus(row, 'Missing audio URL or post ID.');
            return;
        }

        if (!assemblyai_admin.assemblyai_api_key) {
            setRowStatus(row, 'Missing AssemblyAI API key.');
            return;
        }

        active = true;
        setButtonsEnabled(false);
        setRowActive(row, true);
        setRowProgress(row, 5);
        setRowStatus(row, 'Acquiring transcription lock...');
        setGlobalStatus('Preparing transcription...');

        let lockAcquired = false;
        try {
            lockAcquired = await acquireLock(postId);
            if (!lockAcquired) {
                setRowStatus(row, 'Another transcription is already running.');
                return;
            }

            setRowStatus(row, 'Submitting audio for transcription...');
            setRowProgress(row, 15);

            const transcriptId = await createTranscript(audioUrl);
            setRowStatus(row, 'Transcription started.');
            setRowProgress(row, 25);

            await pollTranscript(transcriptId, audioUrl, postId, row, button);
        } catch (error) {
            console.error('Admin transcription error:', error);
            setRowStatus(row, `Error: ${error.message || error}`);
            setGlobalStatus('Transcription failed.');
        } finally {
            if (lockAcquired) {
                await releaseLock();
            }
            setRowActive(row, false);
            setButtonsEnabled(true);
            active = false;
        }
    }

    async function acquireLock(postId) {
        const response = await fetch(assemblyai_admin.ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'whisper_acquire_transcription_lock',
                post_id: postId,
                nonce: assemblyai_admin.lock_nonce,
            }),
        });

        const result = await response.json();
        if (!result.success) {
            return false;
        }
        return true;
    }

    async function releaseLock() {
        try {
            await fetch(assemblyai_admin.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'whisper_release_transcription_lock',
                    nonce: assemblyai_admin.lock_nonce,
                }),
            });
        } catch (error) {
            console.error('Failed to release transcription lock:', error);
        }
    }

    async function createTranscript(audioUrl) {
        const response = await fetch('https://api.assemblyai.com/v2/transcript', {
            method: 'POST',
            headers: {
                'authorization': assemblyai_admin.assemblyai_api_key,
                'content-type': 'application/json',
            },
            body: JSON.stringify({
                audio_url: audioUrl,
                speaker_labels: true,
                punctuate: true,
                format_text: true,
            }),
        });

        const data = await response.json();
        if (data.error) {
            throw new Error(data.error);
        }
        if (!data.id) {
            throw new Error('No transcript ID returned.');
        }
        return data.id;
    }

    async function pollTranscript(transcriptId, audioUrl, postId, row, button) {
        let completed = false;
        let progress = 25;

        while (!completed) {
            const pollResponse = await fetch(`https://api.assemblyai.com/v2/transcript/${transcriptId}`, {
                method: 'GET',
                headers: {
                    'authorization': assemblyai_admin.assemblyai_api_key,
                    'content-type': 'application/json',
                },
            });

            const pollData = await pollResponse.json();
            if (pollData.error) {
                throw new Error(pollData.error);
            }

            if (pollData.status === 'completed') {
                completed = true;
                setRowStatus(row, 'Formatting transcript...');
                setRowProgress(row, 90);
                const formattedText = await fetchParagraphs(transcriptId);
                await saveTranscription(formattedText || pollData.text || '', audioUrl, postId, row, button);
                setRowProgress(row, 100);
                setGlobalStatus('Transcription completed.');
            } else if (pollData.status === 'failed') {
                completed = true;
                setRowStatus(row, `Transcription failed: ${pollData.error || 'Unknown error'}`);
                setRowProgress(row, 100);
                setGlobalStatus('Transcription failed.');
            } else {
                progress = bumpProgress(progress, pollData.status);
                setRowStatus(row, `Status: ${pollData.status}. Checking again...`);
                setRowProgress(row, progress);
                await sleep(5000);
            }
        }
    }

    function bumpProgress(current, status) {
        const cap = status === 'queued' ? 40 : 90;
        return Math.min(cap, current + 5);
    }

    async function fetchParagraphs(transcriptId) {
        const response = await fetch(`https://api.assemblyai.com/v2/transcript/${transcriptId}/paragraphs`, {
            method: 'GET',
            headers: {
                'authorization': assemblyai_admin.assemblyai_api_key,
                'content-type': 'application/json',
            },
        });

        const data = await response.json();
        if (data.error) {
            return null;
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

    async function saveTranscription(transcriptionText, audioUrl, postId, row, button) {
        setRowStatus(row, 'Saving transcript to WordPress...');
        const response = await fetch(assemblyai_admin.ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'save_transcription',
                transcription: transcriptionText,
                audio_url: audioUrl,
                post_id: postId,
            }),
        });

        const result = await response.json();
        if (!result.success) {
            throw new Error(result.data && result.data.message ? result.data.message : 'Failed to save transcription.');
        }

        setRowStatus(row, 'Transcription saved.');
        updateLinks(row, result.data.post_link, result.data.transcription_link);
        if (button) {
            button.textContent = 'Re-transcribe';
        }
    }

    function sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
});

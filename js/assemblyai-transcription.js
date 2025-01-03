document.addEventListener('DOMContentLoaded', function() { 
    const transcriptionButton = document.querySelector('#transcribeButton');
    const statusDiv = document.querySelector('#transcriptionStatus'); // Reference to the status div
    const transcriptionContainer = document.querySelector('#transcriptionResult'); // Container for transcription result
    
    if (transcriptionButton) {
        transcriptionButton.addEventListener('click', async function() {
            // Reset the status div and transcription container
            statusDiv.style.display = 'block';
            statusDiv.innerHTML = 'Starting transcription...';
            transcriptionContainer.innerHTML = ''; // Clear previous transcription

            const audioUrl = document.querySelector('#audio_url').value; // URL from user input
            const assemblyApiKey = assemblyai_settings.assemblyai_api_key;
            const openaiApiKey = assemblyai_settings.openai_api_key; // OpenAI API Key
            const postId = assemblyai_settings.post_id; // Get the current post ID

            if (!audioUrl) {
                alert('Please enter a valid URL.');
                statusDiv.style.display = 'none';
                return;
            }

            // Disable input and button
            transcriptionButton.disabled = true;
            document.querySelector('#audio_url').disabled = true;

            try {
                const params = {
                    audio_url: audioUrl,
                    speaker_labels: true,
                };

                // Send request to start transcription
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
                    transcriptionButton.disabled = false;  // Enable the button again
                    document.querySelector('#audio_url').disabled = false;  // Enable input
                    statusDiv.innerHTML = `Transcription request failed: ${data.error}`;
                    return;
                }

                console.log('Transcription initiated, polling for completion:', data);
                statusDiv.innerHTML = `Transcription process initiated.  This may take a few minutes.  Please keep this window open.`;

                // Poll for status until transcription is complete
                const transcriptId = data.id;
                pollTranscriptionStatus(assemblyApiKey, transcriptId, audioUrl, postId, openaiApiKey);

            } catch (error) {
                console.error('Error during transcription request:', error);
                transcriptionButton.disabled = false;  // Enable the button again
                document.querySelector('#audio_url').disabled = false;  // Enable input
                statusDiv.innerHTML = `An error occurred during transcription: ${error}`;
            }
        });
    }

    // Polling function for transcription status
    async function pollTranscriptionStatus(apiKey, transcriptId, audioUrl, postId, openaiApiKey) {
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
                    statusDiv.innerHTML += `<br>Transcription completed.`;
                    console.log('Transcription completed:', pollData.text);

                    // Start GPT post-processing
                    await processTranscriptionWithGPT(pollData.text, openaiApiKey, audioUrl, postId);
                } else if (pollData.status === 'failed') {
                    console.error(`Transcription failed: ${pollData.error}`);
                    statusDiv.innerHTML = `Transcription failed: ${pollData.error}`;
                    transcriptionCompleted = true;
                    // Re-enable input and button
                    transcriptionButton.disabled = false;
                    document.querySelector('#audio_url').disabled = false;
                } else {
                    console.log(`Transcription status: ${pollData.status}. Checking again in 5 seconds...`);
                    statusDiv.innerHTML = `${statusDiv.innerHTML}<br>Status: ${pollData.status}. Checking again...`;
                    await new Promise(resolve => setTimeout(resolve, 5000)); // Wait 5 seconds before polling again
                }
            } catch (error) {
                console.error('Error while polling transcription status:', error);
                statusDiv.innerHTML = `Error while polling transcription status: ${error}`;
                transcriptionCompleted = true;
                // Re-enable input and button
                transcriptionButton.disabled = false;
                document.querySelector('#audio_url').disabled = false;
            }
        }
    }

    // Function to process transcription with GPT-4o-mini (using OpenAI API)
    async function processTranscriptionWithGPT(transcriptionText, openaiApiKey, audioUrl, postId) {
        try {
            statusDiv.innerHTML += `<br>Sending transcript to OpenAI to edit punctuation and spelling...`;

            const messages = [
                {
                    'role': 'system',
                    'content': 'You are an expert text editor specializing in correcting transcription errors.'
                },
                {
                    'role': 'user',
                    'content': `Perform basic editing tasks on this speech transcript. Don't change wording, just update the punctuation and spelling and add paragraph breaks where necessary.\n\n${transcriptionText}`,
                },
            ];

            const postData = {
                'model': 'gpt-4o-mini',
                'messages': messages,
                'temperature': 0.7,
            };

            const response = await fetch('https://api.openai.com/v1/chat/completions', {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${openaiApiKey}`,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(postData),
            });

            const data = await response.json();

            if (data.error) {
                console.error(`OpenAI API error: ${data.error.message}`);
                statusDiv.innerHTML += `<br>Post-processing failed: ${data.error.message}`;
                // Re-enable input and button
                transcriptionButton.disabled = false;
                document.querySelector('#audio_url').disabled = false;
                return;
            }

            if (data.choices && data.choices[0].message.content) {
                const processedText = data.choices[0].message.content;
                statusDiv.innerHTML += `<br>Post-processing completed. Saving transcription...`;
                console.log('Post-processed transcription:', processedText);

                // Save the processed transcription to WordPress
                await saveProcessedTranscription(processedText, audioUrl, postId);
            } else {
                console.error('Unexpected OpenAI API response:', data);
                statusDiv.innerHTML += `<br>Unexpected response from post-processing.`;
                // Re-enable input and button
                transcriptionButton.disabled = false;
                document.querySelector('#audio_url').disabled = false;
            }

        } catch (error) {
            console.error('Error during GPT post-processing:', error);
            statusDiv.innerHTML += `<br>Error during post-processing: ${error}`;
            // Re-enable input and button
            transcriptionButton.disabled = false;
            document.querySelector('#audio_url').disabled = false;
        }
    }

    // Function to save processed transcription to WordPress and append to current post content
    async function saveProcessedTranscription(processedText, audioUrl, postId) {
        try {
            const response = await fetch(assemblyai_settings.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'save_processed_transcription',
                    processed_transcription: processedText,
                    audio_url: audioUrl,
                    post_id: postId // Pass the post ID for appending
                }),
            });
        
            const result = await response.json();
            if (result.success) {
                console.log('Processed transcription saved and appended to post:', result);
                statusDiv.innerHTML += `<br>Processed transcription saved successfully! Refreshing the page...`;
                // Refresh the page after a short delay to show the updated content
                setTimeout(() => {
                    location.reload();
                }, 3000); // 3-second delay
            } else {
                console.error('Failed to save processed transcription:', result);
                statusDiv.innerHTML += `<br>Failed to save processed transcription: ${result.data || 'Unknown error'}`;
                // Re-enable input and button
                transcriptionButton.disabled = false;
                document.querySelector('#audio_url').disabled = false;
            }
        } catch (error) {
            console.error('Error while saving processed transcription to WordPress:', error);
            statusDiv.innerHTML += `<br>Error while saving processed transcription: ${error}`;
            // Re-enable input and button
            transcriptionButton.disabled = false;
            document.querySelector('#audio_url').disabled = false;
        }  
    }
});

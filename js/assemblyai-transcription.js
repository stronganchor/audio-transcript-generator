document.addEventListener('DOMContentLoaded', function() {
    const transcriptionForm = document.querySelector('#transcriptionForm');
    const transcriptionButton = document.querySelector('#transcribeButton');
    const uploadOption = document.querySelector('#uploadOption');
    const urlOption = document.querySelector('#urlOption');
    const fileUploadSection = document.querySelector('#fileUploadSection');
    const urlSection = document.querySelector('#urlSection');
    
    // Show/hide file upload or URL input based on the selected option
    uploadOption.addEventListener('change', function() {
        fileUploadSection.style.display = 'block';
        urlSection.style.display = 'none';
    });
    
    urlOption.addEventListener('change', function() {
        fileUploadSection.style.display = 'none';
        urlSection.style.display = 'block';
    });

    if (transcriptionButton) {
        transcriptionButton.addEventListener('click', async function() {
            const apiKey = assemblyai_settings.assemblyai_api_key;

            if (uploadOption.checked) {
                // Handle file upload
                const audioFile = document.querySelector('#audio_file').files[0];
                if (!audioFile) {
                    alert('Please upload an audio file.');
                    return;
                }
                const fileUrl = await uploadFileToServer(audioFile);
                if (!fileUrl) {
                    alert('File upload failed.');
                    return;
                }
                console.log('File uploaded. Now sending to AssemblyAI.');
                await sendToAssemblyAI(fileUrl, apiKey);
            } else if (urlOption.checked) {
                // Handle URL input
                const audioUrl = document.querySelector('#audio_url').value;
                if (!audioUrl) {
                    alert('Please enter a valid URL.');
                    return;
                }
                console.log('Sending URL to AssemblyAI.');
                await sendToAssemblyAI(audioUrl, apiKey);
            }
        });
    }

    // Function to upload the file to the server
    async function uploadFileToServer(file) {
        const formData = new FormData();
        formData.append('audio_file', file);
        formData.append('action', 'upload_audio_file'); // This tells WordPress which AJAX action to use

        try {
            const response = await fetch(assemblyai_settings.ajax_url, {
                method: 'POST',
                body: formData,
            });
            const result = await response.json();
            if (result.success) {
                console.log('File uploaded successfully:', result.file_url);
                return result.file_url; // Return the file URL on the server
            } else {
                console.error('File upload failed:', result.message);
                return null;
            }
        } catch (error) {
            console.error('Error uploading file:', error);
            return null;
        }
    }

    // Function to send the audio file URL to AssemblyAI
    async function sendToAssemblyAI(audioUrl, apiKey) {
        try {
            const params = {
                audio_url: audioUrl,
                speaker_labels: true,
            };

            // Send request to AssemblyAI to transcribe the file
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
                console.error(`Transcription request failed: ${data.error}`);
                return;
            }

            console.log('Transcription initiated, polling for completion:', data);

            // Poll for transcription completion
            const transcriptId = data.id;
            await pollTranscriptionStatus(apiKey, transcriptId);

        } catch (error) {
            console.error('Error sending request to AssemblyAI:', error);
        }
    }

    // Polling function (same as before)
    async function pollTranscriptionStatus(apiKey, transcriptId) {
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
                // Optionally save to WordPress
                // saveTranscriptionToWordPress(pollData.text); // Commented out post-processing for now
            } else if (pollData.status === 'failed') {
                console.error(`Transcription failed: ${pollData.error}`);
                transcriptionCompleted = true;
            } else {
                console.log(`Transcription status: ${pollData.status}. Polling again in 5 seconds...`);
                await new Promise(resolve => setTimeout(resolve, 5000)); // Wait 5 seconds before polling again
            }
        }
    }
});

import openai
import subprocess
import os
import sys
import re

# Ensure your OPENAI_API_KEY environment variable is set
api_key = os.getenv("OPENAI_API_KEY")
if api_key is None:
    # Prompt the user for the API key
    api_key = input("Enter your API key: ")
    # Set the environment variable for the current process
    os.environ['OPENAI_API_KEY'] = api_key

def transcribe_audio_to_text(audio_file_path):
    """
    Transcribes audio to text using OpenAI's whisper model.
    """
    try:
        from openai import OpenAI
        client = OpenAI()
        
        print(f"Transcribing this file to text: {audio_file_path}\n")
        
        if not os.path.isfile(audio_file_path):
            print("Error: The file does not exist. Please check the path and try again.")
            sys.exit(1)
            
        # Open the audio file in binary read mode
        with open(audio_file_path, "rb") as audio_file:
            transcript = client.audio.transcriptions.create(
                model="whisper-1", 
                file=audio_file,
                response_format="text"
            )
        
        return transcript

    except Exception as e:
        # Handle any other exceptions
        return f"An exception occurred: {str(e)}"

def insert_paragraph_breaks(text):
    processed_text = ''
    remaining_text = text
    error_messages = ''
    
    print("Adding paragraph breaks...\n")
    while remaining_text:
        first200WordsArray = remaining_text.split(' ')[:200]
        first200Words = ' '.join(first200WordsArray)

        prompt = f"Given the following text, identify the number of sentences that should form the first paragraph. Provide a single number between 1 and 6 as your response, with no other commentary.\n\n{first200Words}"

        from openai import OpenAI
        client = OpenAI()

        response = client.chat.completions.create(
            model="gpt-3.5-turbo",
            temperature=0,
            messages=[
                {"role": "user", "content": prompt}
            ]
        )

        response_text = response.choices[0].message.content
        matches = re.findall(r'\b[1-6]\b', response_text)
        if not matches:
            error_messages += f"Error: Received unexpected response format from the API: {response} For text: {first200Words}\n"
            length = 2
        else:
            length = int(matches[0])

        sentences = re.split(r'(?<=[.!?])\s+', remaining_text, maxsplit=length)
        paragraph = ' '.join(sentences[:length])
        processed_text += f"{paragraph}\n\n"

        remaining_text = ' '.join(sentences[length:])

    if error_messages:
        processed_text += f"\n\n Error messages: {error_messages}"

    return processed_text

def compress_audio(audio_file_path):
    print("Compressing audio file...")
    # Extract the directory, filename, and extension
    dir_path, filename = os.path.split(audio_file_path)
    filename_without_ext, extension = os.path.splitext(filename)

    # Set the output file path with 'compressed_' prefix
    output_file = os.path.join(dir_path, f'compressed_{filename_without_ext}{extension}')

    # Use FFmpeg to compress the audio file to the target bitrate of 64 kbps
    command = ['ffmpeg', '-i', audio_file_path, '-b:a', '64k', output_file]

    subprocess.run(command)

    print(f"Compression complete: {output_file}")
    return output_file

def main():
    audio_file_path = input("Please enter the path to your audio file: ").strip('"').strip("'")

    if not os.path.isfile(audio_file_path):
        print("Error: The file does not exist. Please check the path and try again.")
        sys.exit(1)
        
    # Check file size and compress it if it's over 26 MB
    file_size = os.path.getsize(audio_file_path)
    if file_size > 26 * 1024 * 1024:  # 26 MB in bytes
        compressed_file_path = compress_audio(audio_file_path)
        if not os.path.isfile(compressed_file_path):
            print(f"Error: something went wrong when fetching the compressed file at path: {compressed_file_path}")
            sys.exit(1)
        else: 
            transcription = transcribe_audio_to_text(compressed_file_path)
    else:
        transcription = transcribe_audio_to_text(audio_file_path)
        
    if "An exception occurred" not in transcription:
        processed_text = insert_paragraph_breaks(transcription)
        print(processed_text)
    else:
        print(transcription)

if __name__ == '__main__':
    main()

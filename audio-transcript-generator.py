import openai
import os
import re

# Ensure your OPENAI_API_KEY environment variable is set
openai.api_key = os.getenv("OPENAI_API_KEY")

def transcribe_audio_to_text(audio_file_path):
    """
    Transcribes audio to text using OpenAI's whisper model.
    """
    try:
        from openai import OpenAI
        client = OpenAI()
            
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

    while remaining_text:
        first200WordsArray = remaining_text.split(' ')[:200]
        first200Words = ' '.join(first200WordsArray)

        prompt = f"Given the following text, identify the number of sentences that should form the first paragraph. Provide a single number between 1 and 5 as your response, with no other commentary.\n\n{first200Words}"

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
        matches = re.findall(r'\b[1-5]\b', response_text)
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

def main():
    audio_file_path = input("Please enter the path to your audio file: ").strip('"').strip("'")

    if not os.path.isfile(audio_file_path):
        print("Error: The file does not exist. Please check the path and try again.")
    else:
        transcription = transcribe_audio_to_text(audio_file_path)
        if "An exception occurred" not in transcription:
            processed_text = insert_paragraph_breaks(transcription)
            print(processed_text)
        else:
            print(transcription)

if __name__ == '__main__':
    main()

import openai
import os

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

def insert_paragraph_breaks(text, sentences_per_paragraph=4):
    """
    Inserts paragraph breaks into text after a certain number of sentences.
    """
    sentences = text.split('. ')
    paragraphs = []
    paragraph = []
    
    for sentence in sentences:
        paragraph.append(sentence)
        if len(paragraph) >= sentences_per_paragraph:
            paragraphs.append(' '.join(paragraph))
            paragraph = []
    
    # Add any remaining sentences as a final paragraph
    if paragraph:
        paragraphs.append(' '.join(paragraph))
    
    return '\n\n'.join(paragraphs)
    
def main():
    # Prompt the user for the path to the audio file
    audio_file_path = input("Please enter the path to your audio file: ")
    
    # Strip quotation marks from the path for safety
    audio_file_path = audio_file_path.strip('"').strip("'")
    
    # Check if the file exists before proceeding
    if not os.path.isfile(audio_file_path):
        print("Error: The file does not exist. Please check the path and try again.")
        return
    
    transcribed_text = transcribe_audio_to_text(audio_file_path)

    if "Error" not in transcribed_text:
        processed_text = insert_paragraph_breaks(transcribed_text)
        print(processed_text)
    else:
        print(transcribed_text)

if __name__ == '__main__':
    # Prompt the user for the path to the audio file
    audio_file_path = input("Please enter the path to your audio file: ").strip('"').strip("'")

    # Verify that the file exists
    if not os.path.isfile(audio_file_path):
        print("Error: The file does not exist. Please check the path and try again.")
    else:
        # Transcribe the audio file to text
        transcription = transcribe_audio_to_text(audio_file_path)
        print(transcription)

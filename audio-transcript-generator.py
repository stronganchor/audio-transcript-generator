import tkinter as tk
from tkinter import filedialog 
from tkinter import ttk  # Import ttk for styled widgets
import openai
import subprocess
import os
import sys
import re

# Ensure your OPENAI_API_KEY environment variable is set
api_key = os.getenv("OPENAI_API_KEY")

def transcribe_audio_to_text(audio_file_path, result_text, window):
  """
  Transcribes audio to text using OpenAI's whisper model.
  """
  try:
    from openai import OpenAI
    client = OpenAI()
    
    result_text.set(result_text.get() + f"Transcribing file to text: {audio_file_path}\n")
    window.update_idletasks()
    
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

def insert_paragraph_breaks(text, result_text, window):
    processed_text = ''
    remaining_text = text
    error_messages = ''
    
    result_text.set(result_text.get() + "Adding paragraph breaks...\n")
    window.update_idletasks()
    
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

def compress_audio(audio_file_path, result_text, window):
    result_text.set("Compressing audio file...\n")
    window.update_idletasks()
    
    # Extract the directory, filename, and extension
    dir_path, filename = os.path.split(audio_file_path)
    filename_without_ext, extension = os.path.splitext(filename)

    # Set the output file path with 'compressed_' prefix
    output_file = os.path.join(dir_path, f'compressed_{filename_without_ext}{extension}')
    
    # Construct the path to ffmpeg.exe
    script_dir = os.path.dirname(os.path.abspath(__file__))  # Get the script's directory
    ffmpeg_path = os.path.join(script_dir, 'ffmpeg', 'bin', 'ffmpeg.exe')

    # Build the command with the full ffmpeg path
    command = [ffmpeg_path, '-i', audio_file_path, '-b:a', '64k', output_file]

    subprocess.run(command)

    result_text.set(f"Compression complete: {output_file}\n")
    window.update_idletasks()
    return output_file

def select_audio_file(file_path_var):
    audio_file_path = filedialog.askopenfilename(filetypes=[("Audio Files", ("*.mp3", "*.wav", "*.ogg", "*.m4a"))])
    if audio_file_path:
        file_path_var.set(audio_file_path)


def process_audio(file_path_var, api_key_entry, result_text, window):    
    # Get the API Key, either from the stored one or the entry box
    new_api_key = api_key_entry.get()
    if new_api_key:
        global api_key 
        api_key = new_api_key
        os.environ['OPENAI_API_KEY'] = api_key
    elif not api_key:
        result_text.set("Please enter your OpenAI API key.")
        return

    audio_file_path = file_path_var.get()
    if not audio_file_path:
        result_text.set("Please select an audio file.")
        return

    # Compression handling
    file_size = os.path.getsize(audio_file_path)
    if file_size > 26 * 1024 * 1024:
      compressed_file_path = compress_audio(audio_file_path, result_text, window)
      if not compressed_file_path:
        result_text.set("Error: Compression failed. Please try again with a different file.")
        return
      else:
        audio_file_path = compressed_file_path

    # Transcription
    transcription = transcribe_audio_to_text(audio_file_path, result_text, window)

    # Paragraph Insertion
    if "An exception occurred" not in transcription:
        processed_text = insert_paragraph_breaks(transcription, result_text, window)
    else:
        processed_text = transcription

    result_text.set(processed_text)

def main():
    # Tkinter setup
    window = tk.Tk()
    window.title("Audio Transcription Tool")

    # Frame for API Key Input 
    api_key_frame = tk.Frame(window, padx=10, pady=5)  # Padding for the frame
    api_key_frame.pack()
    tk.Label(api_key_frame, text="OpenAI API Key:").pack(side=tk.LEFT, padx=5)  # Padding for the label
    api_key_entry = tk.Entry(api_key_frame)
    api_key_entry.pack(side=tk.LEFT)

    # Frame for File Selection
    file_frame = tk.Frame(window, padx=10, pady=5)
    file_frame.pack()
    file_path_var = tk.StringVar()
    tk.Label(file_frame, text="Select Audio File:").pack(side=tk.LEFT, padx=5)
    tk.Label(file_frame, textvariable=file_path_var).pack(side=tk.LEFT)
    tk.Button(file_frame, text="Browse", command=lambda: select_audio_file(file_path_var)).pack(side=tk.LEFT, padx=5)

    # Frame for Processing
    process_frame = tk.Frame(window, padx=15, pady=10) 
    process_frame.pack()
    result_text = tk.StringVar()
    tk.Button(process_frame, text="Process", padx=15, pady=8,
              command=lambda: process_audio(file_path_var, api_key_entry, result_text, window)).pack()

    # Frame for Results (with scrollbar and max height)
    result_scroll_frame = ttk.Frame(window)  
    result_scroll_frame.pack()

    canvas = tk.Canvas(result_scroll_frame)
    scrollbar = ttk.Scrollbar(result_scroll_frame, orient="vertical", command=canvas.yview) 
    result_frame = ttk.Frame(canvas, height=200)  # Maximum height

    result_label = tk.Label(result_frame, textvariable=result_text, wraplength=400) 
    result_label.pack(pady=5)

    # Configure scrolling behavior
    scrollbar.pack(side=tk.RIGHT, fill="y")
    canvas.pack(side=tk.LEFT, fill="both", expand=True)
    canvas.create_window((0, 0), window=result_frame, anchor='nw')
    result_frame.bind("<Configure>", lambda event, canvas=canvas: canvas.configure(scrollregion=canvas.bbox("all")))
    
    # Button for copying results text
    copy_button_frame = tk.Frame(window)
    copy_button_frame.pack()
    tk.Button(copy_button_frame, text="Copy Text", command=lambda: window.clipboard_append(result_text.get())).pack(pady=5)
            
    # Pre-populate API key entry if it exists in the environment
    if api_key:
        api_key_entry.insert(0, api_key)
    
    window.mainloop() 

if __name__ == '__main__':
    main()

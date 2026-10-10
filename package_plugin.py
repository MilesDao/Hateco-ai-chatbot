import os
import zipfile

def create_plugin_zip(source_dir, output_filename):
    # Directories and files to exclude from the zip
    exclude_dirs = {'.venv', 'eval', '.git', '.codegraph', '.vscode', '__pycache__', 'tests', 'docs'}
    exclude_files = {'.gitignore', os.path.basename(output_filename)}

    print(f"Creating {output_filename}...")
    
    with zipfile.ZipFile(output_filename, 'w', zipfile.ZIP_DEFLATED) as zipf:
        for root, dirs, files in os.walk(source_dir):
            # Modify dirs in-place to exclude unwanted directories
            dirs[:] = [d for d in dirs if d not in exclude_dirs]
            
            for file in files:
                if file in exclude_files or file.endswith('.zip') or file.endswith('.log') or file.endswith('.py'):
                    continue
                    
                file_path = os.path.join(root, file)
                # Calculate the relative path to keep the directory structure inside the zip
                rel_path = os.path.relpath(file_path, source_dir)
                
                print(f"Adding: {rel_path}")
                zipf.write(file_path, rel_path)
                
    print(f"\nSuccess! Created {output_filename}")

if __name__ == "__main__":
    source = r"e:\User\documentaries\USTH_codes\Hateco-AI-Chatbot"
    output = r"e:\User\documentaries\USTH_codes\Hateco-AI-Chatbot\hateco-chatbot-updated.zip"
    create_plugin_zip(source, output)

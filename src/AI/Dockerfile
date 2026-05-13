FROM python:3.9-slim

WORKDIR /app

# Install system dependencies if needed (spacy may need build-essential for some components)
RUN apt-get update && apt-get install -y \
    build-essential \
    && rm -rf /var/lib/apt/lists/*

# Copy requirements and install
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy the rest of the code
COPY . .

# Ensure the model directory exists (it should be copied, but just in case)
# If the model isn't trained yet, you'd run: RUN python train_ner.py

# Expose port
EXPOSE 8080

# Run the server
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8080"]

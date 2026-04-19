FROM python:3.11-slim

WORKDIR /app

ENV PIP_INDEX_URL=https://download.pytorch.org/whl/cu121
ENV PIP_EXTRA_INDEX_URL=https://pypi.org/simple

COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

COPY app.py ./

EXPOSE 8000
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]

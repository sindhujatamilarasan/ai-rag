FROM python:3.14-slim

WORKDIR /app

# Requirements mattum mudhalla copy: code maarum bodhu Docker indha layer-a
# cache-la irundhu edukkum, thirumba pip install panna vendaam.
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

EXPOSE 8001

# --reload illa: adhu development-ku mattum, container-la CPU waste.
# --host 0.0.0.0 mandatory: 127.0.0.1-na container-ukku veliya reach aagaadhu.
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8001"]
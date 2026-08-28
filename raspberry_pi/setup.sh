#!/usr/bin/env bash
set -euo pipefail

sudo apt update
sudo apt install -y python3-venv python3-dev build-essential cmake libopenblas-dev liblapack-dev libjpeg-dev
python3 -m venv .venv
. .venv/bin/activate
python -m pip install --upgrade pip
python -m pip install -r requirements.txt
cp config.example.json config.json
mkdir -p known_faces
echo "Setup complete. Edit config.json and add one face image per student ID to known_faces/."
#!/usr/bin/env python3
"""
Music/speech classification of a service recording in 5 s windows.

Runs the AudioSet-trained Audio Spectrogram Transformer over the whole file
and reports, per window, the sigmoid scores of the labels structure detection
reads. The model weights are baked into the image at MODEL_REVISION, so the
script never fetches at runtime (HF_HUB_OFFLINE=1).

Usage:
    python3 classify_audio.py <audio_path> [--batch-size N]

Output (stdout):
    JSON: {"model", "model_revision", "window_seconds", "audio_seconds",
           "input_sha256", "preprocessing", "runtime",
           "windows": [{"start", "end", "music", "speech", "singing", "choir"}]}

Errors are written to stderr with a non-zero exit code; nothing is written to
stdout unless the whole file was classified.
"""

import argparse
import hashlib
import json
import os
import platform
import subprocess
import sys

os.environ.setdefault("HF_HUB_OFFLINE", "1")
os.environ.setdefault("TRANSFORMERS_NO_ADVISORY_WARNINGS", "1")

MODEL_ID = "MIT/ast-finetuned-audioset-10-10-0.4593"
MODEL_REVISION = "f826b80d28226b62986cc218e5cec390b1096902"
SAMPLE_RATE = 16000
WINDOW_SECONDS = 5
LABELS = {
    "music": "Music",
    "speech": "Speech",
    "singing": "Singing",
    "choir": "Choir",
}


def fail(message: str) -> None:
    print(message, file=sys.stderr)
    sys.exit(1)


def sha256_of(path: str) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)

    return digest.hexdigest()


def ffmpeg_version() -> str:
    output = subprocess.run(["ffmpeg", "-version"], capture_output=True, text=True, check=True).stdout

    return output.splitlines()[0] if output else "unknown"


def decode(path: str, arguments: list) -> "numpy.ndarray":
    import numpy as np

    result = subprocess.run(["ffmpeg", *arguments], capture_output=True, check=False)
    if result.returncode != 0:
        fail(f"ffmpeg failed to decode {path}: {result.stderr.decode(errors='replace').strip()}")

    return np.frombuffer(result.stdout, dtype=np.float32).copy()


def classify(audio_path: str, batch_size: int) -> None:
    if not os.path.isfile(audio_path):
        fail(f"Audio file not found: {audio_path}")

    try:
        import numpy as np
        import torch
        import torchaudio
        import transformers
        from transformers import ASTFeatureExtractor, ASTForAudioClassification
    except ImportError as error:
        fail(f"Missing dependency: {error}")

    arguments = ["-nostdin", "-v", "error", "-i", audio_path, "-f", "f32le", "-ac", "1", "-ar", str(SAMPLE_RATE), "-"]
    audio = decode(audio_path, arguments)
    if audio.size == 0:
        fail(f"No audio decoded from {audio_path}")

    audio_seconds = audio.size / SAMPLE_RATE

    try:
        extractor = ASTFeatureExtractor.from_pretrained(MODEL_ID, revision=MODEL_REVISION)
        model = ASTForAudioClassification.from_pretrained(MODEL_ID, revision=MODEL_REVISION).eval()
    except Exception as error:
        fail(f"Failed to load {MODEL_ID}@{MODEL_REVISION}: {error}")

    label_ids = {v: k for k, v in model.config.id2label.items()}
    missing = [label for label in LABELS.values() if label not in label_ids]
    if missing:
        fail(f"Model has no label(s): {', '.join(missing)}")

    columns = {key: label_ids[label] for key, label in LABELS.items()}
    window_samples = WINDOW_SECONDS * SAMPLE_RATE
    starts = list(range(0, audio.size, window_samples))
    windows = []

    for offset in range(0, len(starts), batch_size):
        batch_starts = starts[offset:offset + batch_size]
        chunks = [audio[start:start + window_samples] for start in batch_starts]
        inputs = extractor(chunks, sampling_rate=SAMPLE_RATE, return_tensors="pt")

        with torch.inference_mode():
            scores = torch.sigmoid(model(**inputs).logits)

        for row, start in enumerate(batch_starts):
            window = {
                "start": start / SAMPLE_RATE,
                "end": round(min(start + window_samples, audio.size) / SAMPLE_RATE, 3),
            }
            for key, column in columns.items():
                window[key] = round(float(scores[row, column]), 4)
            windows.append(window)

    result = {
        "model": MODEL_ID,
        "model_revision": MODEL_REVISION,
        "window_seconds": WINDOW_SECONDS,
        "audio_seconds": round(audio_seconds, 3),
        "input_sha256": sha256_of(audio_path),
        "preprocessing": {
            "decoder": ffmpeg_version(),
            "arguments": [arg if arg != audio_path else "<input>" for arg in arguments],
            "sample_rate": SAMPLE_RATE,
            "channels": 1,
            "final_window": "shorter; padded by the feature extractor",
        },
        "runtime": {
            "python": platform.python_version(),
            "numpy": np.__version__,
            "torch": torch.__version__,
            "torchaudio": torchaudio.__version__,
            "transformers": transformers.__version__,
            "device": "cpu",
            "threads": torch.get_num_threads(),
            "batch_size": batch_size,
        },
        "windows": windows,
    }

    print(json.dumps(result))


def main() -> None:
    parser = argparse.ArgumentParser(description="Music/speech classification in 5 s windows")
    parser.add_argument("audio_path", help="Path to the audio file")
    parser.add_argument("--batch-size", type=int, default=16, help="Windows per forward pass (default: 16)")
    args = parser.parse_args()

    if args.batch_size < 1:
        fail("--batch-size must be at least 1")

    classify(args.audio_path, args.batch_size)


if __name__ == "__main__":
    main()

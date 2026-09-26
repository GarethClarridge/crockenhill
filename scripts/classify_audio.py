#!/usr/bin/env python3
"""
Music/speech classification of a service recording in 5 s windows.

Runs the AudioSet-trained Audio Spectrogram Transformer over the whole file
and reports, per window, the sigmoid scores of the labels structure detection
reads. The model weights are baked into the image at MODEL_REVISION, so the
script never fetches at runtime (HF_HUB_OFFLINE=1).

Usage:
    python3 classify_audio.py <audio_path> [--batch-size N] [--device auto|cpu|mps]

On the Mac the same code runs as a service (classify_audio_server.py); the
app container runs it as this command, on CPU.

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


class ClassificationError(Exception):
    """A file that cannot be classified, or a model that cannot be used."""


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
        raise ClassificationError(f"ffmpeg failed to decode {path}: {result.stderr.decode(errors='replace').strip()}")

    return np.frombuffer(result.stdout, dtype=np.float32).copy()


def resolve_device(requested: str) -> str:
    import torch

    if requested == "auto":
        return "mps" if torch.backends.mps.is_available() else "cpu"

    if requested == "mps" and not torch.backends.mps.is_available():
        raise ClassificationError("--device mps requested but MPS is not available")

    return requested


class Classifier:
    """The model and feature extractor, loaded once and reused for every file."""

    def __init__(self, device: str = "auto"):
        try:
            import torch
            from transformers import ASTFeatureExtractor, ASTForAudioClassification
        except ImportError as error:
            raise ClassificationError(f"Missing dependency: {error}") from error

        self.device = resolve_device(device)

        try:
            self.extractor = ASTFeatureExtractor.from_pretrained(MODEL_ID, revision=MODEL_REVISION)
            self.model = ASTForAudioClassification.from_pretrained(MODEL_ID, revision=MODEL_REVISION).eval().to(self.device)
        except Exception as error:
            raise ClassificationError(f"Failed to load {MODEL_ID}@{MODEL_REVISION}: {error}") from error

        label_ids = {v: k for k, v in self.model.config.id2label.items()}
        missing = [label for label in LABELS.values() if label not in label_ids]
        if missing:
            raise ClassificationError(f"Model has no label(s): {', '.join(missing)}")

        self.columns = {key: label_ids[label] for key, label in LABELS.items()}

    def classify(self, audio_path: str, batch_size: int) -> dict:
        import numpy as np
        import torch
        import torchaudio
        import transformers

        if not os.path.isfile(audio_path):
            raise ClassificationError(f"Audio file not found: {audio_path}")

        arguments = ["-nostdin", "-v", "error", "-i", audio_path, "-f", "f32le", "-ac", "1", "-ar", str(SAMPLE_RATE), "-"]
        audio = decode(audio_path, arguments)
        if audio.size == 0:
            raise ClassificationError(f"No audio decoded from {audio_path}")

        window_samples = WINDOW_SECONDS * SAMPLE_RATE
        starts = list(range(0, audio.size, window_samples))
        windows = []

        for offset in range(0, len(starts), batch_size):
            batch_starts = starts[offset:offset + batch_size]
            chunks = [audio[start:start + window_samples] for start in batch_starts]
            inputs = self.extractor(chunks, sampling_rate=SAMPLE_RATE, return_tensors="pt")

            with torch.inference_mode():
                logits = self.model(**{key: value.to(self.device) for key, value in inputs.items()}).logits
                scores = torch.sigmoid(logits).cpu()

            for row, start in enumerate(batch_starts):
                window = {
                    "start": start / SAMPLE_RATE,
                    "end": round(min(start + window_samples, audio.size) / SAMPLE_RATE, 3),
                }
                for key, column in self.columns.items():
                    window[key] = round(float(scores[row, column]), 4)
                windows.append(window)

        return {
            "model": MODEL_ID,
            "model_revision": MODEL_REVISION,
            "window_seconds": WINDOW_SECONDS,
            "audio_seconds": round(audio.size / SAMPLE_RATE, 3),
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
                "device": self.device,
                "threads": torch.get_num_threads(),
                "batch_size": batch_size,
            },
            "windows": windows,
        }


def main() -> None:
    parser = argparse.ArgumentParser(description="Music/speech classification in 5 s windows")
    parser.add_argument("audio_path", help="Path to the audio file")
    parser.add_argument("--batch-size", type=int, default=16, help="Windows per forward pass (default: 16)")
    parser.add_argument("--device", choices=["auto", "cpu", "mps"], default="auto", help="auto uses MPS when available (default: auto)")
    args = parser.parse_args()

    try:
        if args.batch_size < 1:
            raise ClassificationError("--batch-size must be at least 1")

        result = Classifier(args.device).classify(args.audio_path, args.batch_size)
    except ClassificationError as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)

    print(json.dumps(result))


if __name__ == "__main__":
    main()

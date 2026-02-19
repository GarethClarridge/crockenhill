#!/usr/bin/env python3
"""
Speaker embedding extraction script using Resemblyzer.

Usage:
    python3 extract_embedding.py extract <audio_path> [--duration SECONDS]

Output (stdout):
    JSON: {"embedding": [...], "duration_used": <float>}

Errors are written to stderr with a non-zero exit code.
"""

import argparse
import json
import sys


def extract(audio_path: str, duration: float) -> None:
    import os
    os.environ.setdefault("LIBROSA_CACHE_LEVEL", "0")

    try:
        from resemblyzer import VoiceEncoder, preprocess_wav
        import numpy as np
        from pathlib import Path
    except ImportError as e:
        print(f"Missing dependency: {e}", file=sys.stderr)
        sys.exit(1)

    wav_path = Path(audio_path)

    if not wav_path.exists():
        print(f"Audio file not found: {audio_path}", file=sys.stderr)
        sys.exit(1)

    try:
        wav = preprocess_wav(wav_path)
    except Exception as e:
        print(f"Failed to preprocess audio: {e}", file=sys.stderr)
        sys.exit(1)

    # Limit to extraction_duration seconds worth of samples (16kHz after preprocessing)
    sample_rate = 16000
    max_samples = int(duration * sample_rate)
    wav_segment = wav[:max_samples]
    duration_used = len(wav_segment) / sample_rate

    try:
        # Keep stdout reserved for JSON so the caller can decode reliably.
        encoder = VoiceEncoder(verbose=False)
        embedding = encoder.embed_utterance(wav_segment)
    except Exception as e:
        print(f"Failed to extract embedding: {e}", file=sys.stderr)
        sys.exit(1)

    result = {
        "embedding": embedding.tolist(),
        "duration_used": round(duration_used, 2),
    }

    print(json.dumps(result))


def main() -> None:
    parser = argparse.ArgumentParser(description="Speaker embedding extraction")
    subparsers = parser.add_subparsers(dest="command", required=True)

    extract_parser = subparsers.add_parser("extract", help="Extract speaker embedding")
    extract_parser.add_argument("audio_path", help="Path to the audio file")
    extract_parser.add_argument(
        "--duration",
        type=float,
        default=60.0,
        help="Duration in seconds to use for extraction (default: 60)",
    )

    args = parser.parse_args()

    if args.command == "extract":
        extract(args.audio_path, args.duration)


if __name__ == "__main__":
    main()

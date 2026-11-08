#!/usr/bin/env python3
"""
Voice / instrument separation of a stretch of a service recording, around where a song ends.

Runs Demucs (htdemucs, the 4-source hybrid transformer) over [start, end] of the recording and
splits it in two: the voice, and everything else summed (the instruments). Writes the instrument
track as FLAC to <out_dir>/instruments.flac, and prints JSON with each track's level every 0.1 s.

Where a song runs into speech, the voice track shows where the singing stops and the talking
starts, and the instrument track shows where the outro dies away under the talking. The song clip
can then keep the instruments past the last sung word with the talking taken out (RA3, video plan
§4.1d, heard 11-08). The model weights are baked into the image, so nothing is fetched at runtime.

Usage:
    python3 separate_song_end.py <audio_path> <start> <end> <out_dir> [--device cpu|mps]

Output (stdout):
    JSON: {"model", "model_signature", "sample_rate", "start", "end", "frame_seconds",
           "voice_db": [...], "instruments_db": [...], "instruments_path", "runtime"}
Errors are written to stderr with a non-zero exit code; nothing is written to stdout unless the
whole stretch was separated.
"""
import argparse
import json
import os
import platform
import subprocess
import sys
import time

# The image bakes the weights here (Dockerfile); a container started before that keeps them there too.
if "TORCH_HOME" not in os.environ and os.path.isdir("/opt/torch"):
    os.environ["TORCH_HOME"] = "/opt/torch"

MODEL = "htdemucs"
# The checkpoint htdemucs loads (demucs 4.x remote/htdemucs.yaml); a different one is another model.
MODEL_SIGNATURE = "955717e8"
SAMPLE_RATE = 44100
FRAME_SECONDS = 0.1
# Below this a frame reads as silent; log10 of zero is not a level.
FLOOR_DB = -120.0


class SeparationError(Exception):
    """A stretch that cannot be separated."""


def read_audio(path: str, start: float, end: float):
    import numpy as np

    result = subprocess.run(
        ["ffmpeg", "-v", "error", "-ss", f"{start:.3f}", "-t", f"{end - start:.3f}", "-i", path,
         "-vn", "-ac", "2", "-ar", str(SAMPLE_RATE), "-f", "f32le", "-"],
        capture_output=True,
    )
    if result.returncode != 0:
        raise SeparationError(f"ffmpeg could not read {path}: {result.stderr.decode(errors='replace').strip()}")
    samples = np.frombuffer(result.stdout, dtype=np.float32)
    if samples.size < SAMPLE_RATE * 2:
        raise SeparationError(f"less than a second of audio in {start:.3f}-{end:.3f}")

    return samples.reshape(-1, 2).T.copy()


def levels(track) -> list:
    import numpy as np

    mono = track.mean(axis=0)
    frame = int(SAMPLE_RATE * FRAME_SECONDS)
    count = mono.size // frame
    rms = np.sqrt(np.mean(mono[: count * frame].reshape(count, frame) ** 2, axis=1))

    return [round(float(max(FLOOR_DB, 20 * np.log10(value))) if value > 0 else FLOOR_DB, 1) for value in rms]


def write_flac(track, path: str) -> None:
    import numpy as np

    data = np.ascontiguousarray(track.T.astype(np.float32)).tobytes()
    result = subprocess.run(
        ["ffmpeg", "-v", "error", "-y", "-f", "f32le", "-ar", str(SAMPLE_RATE), "-ac", "2", "-i", "-",
         "-c:a", "flac", "-sample_fmt", "s16", path],
        input=data, capture_output=True,
    )
    if result.returncode != 0:
        raise SeparationError(f"ffmpeg could not write {path}: {result.stderr.decode(errors='replace').strip()}")


def separate(audio_path: str, start: float, end: float, out_dir: str, device: str) -> dict:
    import torch
    from demucs.apply import apply_model
    from demucs.pretrained import get_model

    if not os.path.isfile(audio_path):
        raise SeparationError(f"audio not found: {audio_path}")
    if end <= start:
        raise SeparationError("end must be after start")
    os.makedirs(out_dir, exist_ok=True)
    clock = time.time()
    torch.manual_seed(0)
    model = get_model(MODEL)
    model.eval()
    mix = torch.from_numpy(read_audio(audio_path, start, end))
    # As the demucs command does: separate the normalised mix, then restore its scale.
    reference = mix.mean(0)
    mean, std = reference.mean(), reference.std() + 1e-8
    with torch.no_grad():
        sources = apply_model(model, ((mix - mean) / std)[None], device=device, progress=False)[0]
    sources = sources * std + mean
    vocals = sources[model.sources.index("vocals")]
    instruments = sum(sources[index] for index, name in enumerate(model.sources) if name != "vocals")
    instruments_path = os.path.join(out_dir, "instruments.flac")
    write_flac(instruments.numpy(), instruments_path)

    return {
        "model": MODEL,
        "model_signature": MODEL_SIGNATURE,
        "sample_rate": SAMPLE_RATE,
        "start": round(start, 3),
        "end": round(start + mix.shape[1] / SAMPLE_RATE, 3),
        "frame_seconds": FRAME_SECONDS,
        "voice_db": levels(vocals.numpy()),
        "instruments_db": levels(instruments.numpy()),
        "instruments_path": instruments_path,
        "runtime": {"device": device, "seconds": round(time.time() - clock, 2), "python": platform.python_version(),
                    "torch": torch.__version__},
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Voice / instrument separation around a song end")
    parser.add_argument("audio_path")
    parser.add_argument("start", type=float)
    parser.add_argument("end", type=float)
    parser.add_argument("out_dir")
    parser.add_argument("--device", default="cpu", choices=["cpu", "mps"])
    args = parser.parse_args()
    try:
        payload = separate(args.audio_path, args.start, args.end, args.out_dir, args.device)
    except SeparationError as exception:
        print(str(exception), file=sys.stderr)
        return 2
    except Exception as exception:  # noqa: BLE001 - the caller needs the reason, whatever failed
        print(f"{type(exception).__name__}: {exception}", file=sys.stderr)
        return 1
    print(json.dumps(payload))

    return 0


if __name__ == "__main__":
    sys.exit(main())

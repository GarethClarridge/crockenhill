#!/bin/sh
# FFmpeg stub script for testing.
# When extracting metadata, it looks for a corresponding .stub file
# (e.g., /tmp/input.mp4 -> /tmp/input.mp4.stub) and copies its content
# to the output log file specified in the command.

LOG_PATH=""
INPUT_PATH=""

# Parse arguments to find input file and metadata log path
while [ $# -gt 0 ]; do
    case "$1" in
        -i)
            shift
            INPUT_PATH="$1"
            ;;
        *metadata=mode=print:file=*)
            # Extract path between 'file=' and next comma or end of string
            LOG_PATH=$(echo "$1" | sed -n 's/.*metadata=mode=print:file=\([^,]*\).*/\1/p')
            ;;
    esac
    shift
done

if [ -n "$LOG_PATH" ]; then
    # Ensure directory for LOG_PATH exists
    mkdir -p "$(dirname "$LOG_PATH")"

    if [ -n "$INPUT_PATH" ] && [ -f "${INPUT_PATH}.stub" ]; then
        # Copy the pre-defined stub output to the expected log path
        cp "${INPUT_PATH}.stub" "$LOG_PATH"
    else
        # Default behavior: create an empty file
        touch "$LOG_PATH"
    fi
fi

# Simulate progress output if requested (needed for some service logic)
if echo "$@" | grep -q "\-progress pipe:1"; then
    echo "out_time_us=20000000"
fi

exit 0

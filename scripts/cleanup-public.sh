#!/bin/bash
#
# Cleanup script for /public directory
# Removes accumulated cruft, unused assets, and development files
#
# Usage:
#   ./scripts/cleanup-public.sh          # Dry run - shows what would be deleted
#   ./scripts/cleanup-public.sh --execute # Actually delete files
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
PUBLIC_DIR="$PROJECT_ROOT/public"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

DRY_RUN=true
TOTAL_SIZE=0

if [[ "$1" == "--execute" ]]; then
    DRY_RUN=false
fi

echo ""
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}  Public Directory Cleanup Script${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

if $DRY_RUN; then
    echo -e "${YELLOW}DRY RUN MODE - No files will be deleted${NC}"
    echo -e "${YELLOW}Run with --execute to actually delete files${NC}"
else
    echo -e "${RED}EXECUTE MODE - Files will be permanently deleted${NC}"
    read -p "Are you sure you want to continue? (y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Aborted."
        exit 1
    fi
fi
echo ""

# Function to get size of file/directory
get_size() {
    if [[ -e "$1" ]]; then
        if [[ -d "$1" ]]; then
            du -sk "$1" 2>/dev/null | cut -f1
        else
            stat -f%z "$1" 2>/dev/null || echo 0
        fi
    else
        echo 0
    fi
}

# Function to format bytes
format_size() {
    local size=$1
    if [[ $size -ge 1048576 ]]; then
        echo "$(echo "scale=1; $size/1048576" | bc) MB"
    elif [[ $size -ge 1024 ]]; then
        echo "$(echo "scale=1; $size/1024" | bc) KB"
    else
        echo "$size bytes"
    fi
}

# Function to delete file or directory
delete_item() {
    local path="$1"
    local description="$2"

    if [[ -e "$path" ]]; then
        local size
        if [[ -d "$path" ]]; then
            size=$(($(get_size "$path") * 1024))
        else
            size=$(get_size "$path")
        fi
        TOTAL_SIZE=$((TOTAL_SIZE + size))

        if $DRY_RUN; then
            echo -e "  ${YELLOW}[DRY RUN]${NC} Would delete: $path ($(format_size $size))"
        else
            rm -rf "$path"
            echo -e "  ${GREEN}[DELETED]${NC} $path ($(format_size $size))"
        fi
    fi
}

# ============================================
# 1. OS Metadata Files (.DS_Store, Thumbs.db)
# ============================================
echo -e "${BLUE}1. Removing OS metadata files...${NC}"

while IFS= read -r -d '' file; do
    delete_item "$file" "OS metadata"
done < <(find "$PUBLIC_DIR" -name ".DS_Store" -print0 2>/dev/null)

while IFS= read -r -d '' file; do
    delete_item "$file" "Windows thumbnail cache"
done < <(find "$PUBLIC_DIR" -name "Thumbs.db" -print0 2>/dev/null)

echo ""

# ============================================
# 2. Empty Directories
# ============================================
echo -e "${BLUE}2. Removing empty directories...${NC}"

delete_item "$PUBLIC_DIR/stylesheets" "Empty legacy CSS directory"

echo ""

# ============================================
# 3. Vite Development Files
# ============================================
echo -e "${BLUE}3. Removing Vite development files...${NC}"

delete_item "$PUBLIC_DIR/hot" "Vite dev server marker"

echo ""

# ============================================
# 4. Unused Social Icons (not referenced in codebase)
# ============================================
echo -e "${BLUE}4. Removing unused social icons directory...${NC}"
echo -e "   ${YELLOW}(Verified: no references found in codebase)${NC}"

delete_item "$PUBLIC_DIR/images/social-icons" "Unused social icons (38 files from 2020)"

echo ""

# ============================================
# 5. Unused NonBlur Images (not referenced in codebase)
# ============================================
echo -e "${BLUE}5. Removing unused NonBlur images...${NC}"
echo -e "   ${YELLOW}(Verified: no references found in codebase)${NC}"

delete_item "$PUBLIC_DIR/images/homepage/NonBlur" "Unused NonBlur image variants"

echo ""

# ============================================
# 6. Local Sermon MP3s (stored in cloud bucket in production)
# ============================================
echo -e "${BLUE}6. Removing local sermon MP3s...${NC}"
echo -e "   ${YELLOW}(Production uses cloud bucket storage)${NC}"

if [[ -d "$PUBLIC_DIR/media/sermons" ]]; then
    while IFS= read -r -d '' file; do
        delete_item "$file" "Local sermon audio"
    done < <(find "$PUBLIC_DIR/media/sermons" -name "*.mp3" -print0 2>/dev/null)
fi

echo ""

# ============================================
# Summary
# ============================================
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}  Summary${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

if $DRY_RUN; then
    echo -e "${YELLOW}Total space that would be freed: $(format_size $TOTAL_SIZE)${NC}"
    echo ""
    echo -e "Run ${GREEN}./scripts/cleanup-public.sh --execute${NC} to delete these files."
else
    echo -e "${GREEN}Total space freed: $(format_size $TOTAL_SIZE)${NC}"
    echo ""
    echo -e "${GREEN}Cleanup complete!${NC}"
fi

echo ""

# ============================================
# Recommendations
# ============================================
echo -e "${BLUE}Additional recommendations:${NC}"
echo ""
echo "  1. Add these to .gitignore if not already present:"
echo "     .DS_Store"
echo "     Thumbs.db"
echo "     public/hot"
echo ""
echo "  2. Verify the storage symlink is correct:"
echo "     ls -la public/storage"
echo ""
echo "  3. Consider removing old image variants:"
echo "     public/images/church_blur_old.png"
echo "     public/images/headings/*/old-*"
echo ""

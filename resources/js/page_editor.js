import showdown from 'showdown';

document.addEventListener('DOMContentLoaded', () => {
    const markdownInput = document.getElementById('markdown-input');
    const renderedContent = document.getElementById('rendered-content');
    const headingImageInput = document.getElementById('heading-image');
    const headingPictureImg = document.getElementById('headingpicture'); // Might be null

    // Initialize Showdown converter
    const converter = new showdown.Converter();

    // Markdown preview functionality
    if (markdownInput && renderedContent) {
        const renderMarkdown = () => {
            renderedContent.innerHTML = converter.makeHtml(markdownInput.value);
        };

        markdownInput.addEventListener('focus', () => {
            // Optionally clear preview on focus, or leave as is
            // renderedContent.innerHTML = '';
        });
        markdownInput.addEventListener('blur', renderMarkdown);

        // Initial render on page load if markdown exists (e.g., on edit page)
        if (markdownInput.value.trim() !== '') {
            renderMarkdown();
        }
    }

    // File preview functionality
    if (headingImageInput) {
        headingImageInput.addEventListener('change', function(event) {
            if (event.target.files && event.target.files[0]) {
                const reader = new FileReader();
                reader.onload = (e) => {
                    if (headingPictureImg) { // Check if the preview img element exists
                        headingPictureImg.src = e.target.result;
                    } else {
                        // If no specific preview image element on create page,
                        // one could be dynamically created, or this feature
                        // might only be fully available on the edit page.
                        // For now, it just won't show a preview on create page
                        // if 'headingpicture' img tag is not present.
                        console.log('Note: Heading image preview element not found. Preview will not be shown.');
                    }
                };
                reader.readAsDataURL(event.target.files[0]);
            }
        });
    }
});

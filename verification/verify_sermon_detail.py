from playwright.sync_api import sync_playwright
import os

def run_cuj(page):
    # Login as admin
    print("Logging in...")
    page.goto("http://localhost:8000/login")
    page.wait_for_timeout(500)
    page.get_by_label("Email").fill("admin@example.com")
    page.get_by_label("Password").fill("password")
    page.get_by_role("button", name="Log in").click()
    page.wait_for_timeout(1000)

    # Navigate to the test sermon page
    print("Navigating to sermon page...")
    page.goto("http://localhost:8000/christ/sermons/2010/12/test-sermon")
    page.wait_for_timeout(1000)

    # Verify the segment count is displayed correctly
    # It should say "Total Segments: 5"
    print("Verifying segment count...")
    segments_text = page.get_by_text("Total Segments: 5")
    if segments_text.is_visible():
        print("Successfully verified segment count: 5")
    else:
        print("Failed to verify segment count")
        # Let's take a screenshot anyway to see what happened
        page.screenshot(path="verification/screenshots/error.png")
        return

    # Scroll down to see the processing info
    page.evaluate("window.scrollTo(0, document.body.scrollHeight)")
    page.wait_for_timeout(500)

    # Take screenshot at the key moment
    page.screenshot(path="verification/screenshots/verification.png")
    page.wait_for_timeout(1000)  # Hold final state for the video

if __name__ == "__main__":
    os.makedirs("verification/videos", exist_ok=True)
    os.makedirs("verification/screenshots", exist_ok=True)
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(
            record_video_dir="verification/videos"
        )
        page = context.new_page()
        try:
            run_cuj(page)
        finally:
            context.close()  # MUST close context to save the video
            browser.close()

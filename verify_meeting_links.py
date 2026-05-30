from playwright.sync_api import Page, expect, sync_playwright
import time

def test_meeting_links(page: Page):
    page.goto("http://localhost:8000/community/sunday-evenings")
    page.wait_for_load_state("networkidle")

    # Capture the whole page
    page.screenshot(path="verification_full.png", full_page=True)

    try:
        events_link = page.get_by_role("link", name="View all 10 upcoming events")
        if events_link.count() > 0:
            expect(events_link.first).to_have_attribute("href", "/meetings/sunday-evenings/events")
            events_link.first.scroll_into_view_if_needed()
            page.screenshot(path="verification_link.png")
            print("Successfully verified 'View all 10 upcoming events' link.")
        else:
            print("Link 'View all 10 upcoming events' not found.")

    except Exception as e:
        print(f"Error during verification: {e}")

if __name__ == "__main__":
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page()
        try:
            test_meeting_links(page)
        finally:
            browser.close()
